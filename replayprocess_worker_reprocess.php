<?php
/*
 * Replay Process Worker
 * Downloads and Processes replays one at a time locally
 */

namespace Fizzik;

require_once 'lib/AWS/aws-autoloader.php';
require_once 'includes/include.php';
require_once 'includes/Hotsapi.php';
require_once 'includes/ReplayParser.php';
require_once 'includes/MMRCalculator.php';

use Fizzik\Database\MySqlDatabase;
use Fizzik\Utility\Console;
use Fizzik\Utility\FileHandling;
use Fizzik\Utility\OS;
use Fizzik\Utility\SleepHandler;
use Fizzik\Utility\AssocArray;

set_time_limit(0);
date_default_timezone_set(HotstatusPipeline::REPLAY_TIMEZONE);

$db = new MysqlDatabase();
$creds = Credentials::getCredentialsForUser(Credentials::USER_REPLAYPROCESS);
HotstatusPipeline::hotstatus_mysql_connect($db, $creds);
$db->setEncoding(HotstatusPipeline::DATABASE_CHARSET);

//Aws
$awsCreds = new \Aws\Credentials\Credentials($creds[Credentials::KEY_AWS_KEY], $creds[Credentials::KEY_AWS_SECRET]);
$sdk = new \Aws\Sdk([
    'region' => $creds[Credentials::KEY_AWS_REPLAYREGION],
    'version' => 'latest',
    'credentials' => $awsCreds
]);
$s3 = $sdk->createS3();

//Constants and qol
const MYSQL_ERROR_SLEEP_DURATION = 60; //seconds
const NORMAL_EXECUTION_SLEEP_DURATION = 1000; //microseconds (1ms = 1000)
const SLEEP_DURATION = 5; //seconds
const MINI_SLEEP_DURATION = 1; //seconds
const UNLOCK_DEFAULT_DURATION = 5; //Must be unlocked for atleast 5 seconds
const UNLOCK_PARSING_DURATION = 120; //Must be unlocked for atleast 2 minutes while parsing status
const E = PHP_EOL;
$sleep = new SleepHandler();
$console = new Console();
$linux = OS::getOS() == OS::OS_LINUX;

//Prepare statements

$db->prepare("GetPipelineConfig",
    "SELECT `min_replay_date`, `max_replay_date` FROM `pipeline_config` WHERE `id` = ? LIMIT 1");
$db->bind("GetPipelineConfig", "i", $r_pipeline_config_id);

$db->prepare("TouchReplay",
    "UPDATE `replays` SET `lastused` = ? WHERE `id` = ?");
$db->bind("TouchReplay", "ii", $r_timestamp, $r_id);

$db->prepare("UpdateReplayStatus",
    "UPDATE replays SET status = ?, lastused = ? WHERE id = ? LIMIT 1");
$db->bind("UpdateReplayStatus", "iii", $r_status, $r_timestamp, $r_id);

$db->prepare("UpdateReplayStatusError",
    "UPDATE replays SET error = ?, status = ?, lastused = ? WHERE id = ? LIMIT 1");
$db->bind("UpdateReplayStatusError", "siii", $r_error, $r_status, $r_timestamp, $r_id);

$db->prepare("UpdateReplayParsed",
    "UPDATE replays SET status = ?, lastused = ? WHERE id = ? LIMIT 1");
$db->bind("UpdateReplayParsed", "iii", $r_status, $r_timestamp, $r_id);

$db->prepare("UpdateReplayParsedError",
    "UPDATE replays SET match_id = ?, file = NULL, error = ?, status = ?, lastused = ? WHERE id = ? LIMIT 1");
$db->bind("UpdateReplayParsedError", "isiii", $r_match_id, $r_error, $r_status, $r_timestamp, $r_id);

$db->prepare("SelectNextReplayWithStatus-Unlocked",
    "SELECT `id`, `match_id` FROM `replays` WHERE `status` = ? AND `lastused` <= ? ORDER BY `match_date` ASC, `id` ASC LIMIT 1");
$db->bind("SelectNextReplayWithStatus-Unlocked", "ii", $r_status, $r_timestamp);

$db->prepare("stats_replays_processed_total",
    "UPDATE `pipeline_analytics` SET `val_int` = `val_int` + ? WHERE `key_name` = 'replays_reparsed_total' LIMIT 1");
$db->bind("stats_replays_processed_total", "i", $r_replays_processed_total);

$db->prepare("stats_replays_errors_total",
    "UPDATE `pipeline_analytics` SET `val_int` = `val_int` + ? WHERE `key_name` = 'replays_reparsed_errors_total' LIMIT 1");
$db->bind("stats_replays_errors_total", "i", $r_replays_errors_total);

$db->prepare("GetMatch",
    "SELECT * FROM `matches` WHERE `id` = ? LIMIT 1");
$db->bind("GetMatch", "i", $r_match_id);

/*
 * Use Secondary Binding technique to get around Mysql 5.7.14 Bug when using ON DUPLICATE KEY UPDATE and VALUES() function on text/blob columns
 */
$db->prepare("+=:players",
    "INSERT INTO `rp_players` "
    . "(`id`, `name`, `tag`, `region`, `account_level`) "
    . "VALUES (?, ?, ?, ?, ?) "
    . "ON DUPLICATE KEY UPDATE "
    . "`name` = ?, `account_level` = GREATEST(`account_level`, VALUES(`account_level`))");
$db->bind("+=:players",
    "isiiis",
    $r_player_id, $r_name, $r_tag, $r_region, $r_account_level,

    $r_name);

$db->prepare("+=:players_heroes",
    "INSERT INTO rp_players_heroes "
    . "(id, region, hero, hero_level) "
    . "VALUES (?, ?, ?, ?) "
    . "ON DUPLICATE KEY UPDATE "
    . "hero_level = GREATEST(hero_level, VALUES(hero_level))");
$db->bind("+=:players_heroes",
    "iisi",
    $r_player_id, $r_region, $r_hero, $r_hero_level);

$db->prepare("+=:players_matches",
    "INSERT INTO rp_players_matches "
    . "(id, region, match_id, date) "
    . "VALUES (?, ?, ?, ?)");
$db->bind("+=:players_matches",
    "iiis",
    $r_player_id, $r_region, $r_match_id, $r_date);

$db->prepare("??:players_matches_recent_granular",
    "SELECT medals, talents, builds, parties FROM rp_players_matches_recent_granular "
    . "WHERE id = ? AND region = ? AND date_end = ? AND hero = ? AND map = ? AND gameType = ? FOR UPDATE");
$db->bind("??:players_matches_recent_granular",
    "iissss", $r_player_id, $r_region, $r_date_end, $r_hero, $r_map, $r_gameType);

$db->prepare("+=:players_matches_recent_granular",
    "INSERT INTO rp_players_matches_recent_granular "
    . "(id, region, date_end, hero, gameType, map, played, won, time_played, stats_kills, stats_assists, stats_deaths, stats_siege_damage, stats_hero_damage, 
    stats_structure_damage, stats_healing, stats_damage_taken, stats_merc_camps, stats_exp_contrib, stats_best_killstreak, stats_time_spent_dead, medals, talents, 
    builds, parties) "
    . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) "
    . "ON DUPLICATE KEY UPDATE "
    . "played = played + VALUES(played), won = won + VALUES(won), time_played = time_played + VALUES(time_played), 
    stats_kills = stats_kills + VALUES(stats_kills), stats_assists = stats_assists + VALUES(stats_assists), stats_deaths = stats_deaths + VALUES(stats_deaths), 
    stats_siege_damage = stats_siege_damage + VALUES(stats_siege_damage), stats_hero_damage = stats_hero_damage + VALUES(stats_hero_damage), 
    stats_structure_damage = stats_structure_damage + VALUES(stats_structure_damage), stats_healing = stats_healing + VALUES(stats_healing), 
    stats_damage_taken = stats_damage_taken + VALUES(stats_damage_taken), stats_merc_camps = stats_merc_camps + VALUES(stats_merc_camps), 
    stats_exp_contrib = stats_exp_contrib + VALUES(stats_exp_contrib), stats_best_killstreak = GREATEST(stats_best_killstreak, VALUES(stats_best_killstreak)), 
    stats_time_spent_dead = stats_time_spent_dead + VALUES(stats_time_spent_dead), medals = VALUES(medals), talents = VALUES(talents), builds = VALUES(builds), 
    parties = VALUES(parties)");
$db->bind("+=:players_matches_recent_granular",
    "iissssiiiiiiiiiiiiiiissss",
    $r_player_id, $r_region, $r_date_end, $r_hero, $r_gameType, $r_map, $r_played, $r_won, $r_time_played, $r_stats_kills, $r_stats_assists, $r_stats_deaths,
    $r_stats_siege_damage, $r_stats_hero_damage, $r_stats_structure_damage, $r_stats_healing, $r_stats_damage_taken, $r_stats_merc_camps, $r_stats_exp_contrib,
    $r_stats_best_killstreak, $r_stats_time_spent_dead, $r_medals, $r_talents, $r_builds, $r_parties);

$db->prepare("+=:players_matches_total",
    "INSERT INTO rp_players_matches_total "
    . "(id, region, hero, gameType, map, played, won, time_played, time_played_silenced, medals) "
    . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) "
    . "ON DUPLICATE KEY UPDATE "
    . "played = played + VALUES(played), won = won + VALUES(won), time_played = time_played + VALUES(time_played), 
    time_played_silenced = time_played_silenced + VALUES(time_played_silenced), medals = VALUES(medals)");
$db->bind("+=:players_matches_total",
    "iisssiiiis",
    $r_player_id, $r_region, $r_hero, $r_gameType, $r_map, $r_played, $r_won, $r_time_played, $r_time_played_silenced, $r_medals);

$db->prepare("+=:players_mmr",
    "INSERT INTO rp_players_mmr "
    . "(id, region, season, gameType, rating, mu, sigma) "
    . "VALUES (?, ?, ?, ?, ?, ?, ?) "
    . "ON DUPLICATE KEY UPDATE "
    . "rating = VALUES(rating), mu = VALUES(mu), sigma = VALUES(sigma)");
$db->bind("+=:players_mmr",
    "iissidd",
    $r_player_id, $r_region, $r_season, $r_gameType, $r_rating, $r_mu, $r_sigma);

$db->prepare("ensureTalentBuild",
    "INSERT INTO heroes_builds (hero, build, talents) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE hero = hero");
$db->bind("ensureTalentBuild", "sss", $r_hero, $r_build, $r_build_talents);

$db->prepare("ensurePlayerParty",
    "INSERT INTO rp_players_parties (id, region, party, players) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = id");
$db->bind("ensurePlayerParty", "iiss", $r_player_id, $r_region, $r_party, $r_players);


/*
 * Updates the all relevant player and hero tables and rows with data from the match
 * All Per Player/Hero updates are done as transactions, to prevent partial updates.
 * Returns TRUE on complete success, FALSE if any errors occurred
 */
function updatePlayersAndHeroes(&$match, $seasonid) {
    global $db, $r_player_id, $r_name, $r_tag, $r_region, $r_account_level, $r_hero, $r_hero_level, $r_match_id, $r_date,
           $r_date_end, $r_hero, $r_gameType, $r_map, $r_played, $r_won, $r_time_played,
           $r_stats_kills, $r_stats_assists, $r_stats_deaths, $r_stats_siege_damage, $r_stats_hero_damage, $r_stats_structure_damage,
           $r_stats_healing, $r_stats_damage_taken, $r_stats_merc_camps, $r_stats_exp_contrib, $r_stats_best_killstreak,
           $r_stats_time_spent_dead, $r_medals, $r_talents, $r_builds, $r_parties, $r_build, $r_build_talents, $r_party, $r_players, $r_time_played_silenced,
           $r_season, $r_rating, $r_mu, $r_sigma, $r_banned, $r_mmr_average, $r_range_match_length, $r_range_hero_level, $r_matchup_friends, $r_matchup_foes;

    $isodate = HotstatusPipeline::getISOYearWeekDayForDateTime($match['date']);

    $team0Old = $match['mmr']['0']['old']['rating'];
    $team0OldRating = (is_numeric($team0Old)) ? ($team0Old) : (0);

    $team1Old = $match['mmr']['1']['old']['rating'];
    $team1OldRating = (is_numeric($team1Old)) ? ($team1Old) : (0);

    $r_mmr_average = HotstatusPipeline::getFixedAverageMMRForMatch($team0OldRating, $team1OldRating);

    $r_range_match_length = HotstatusPipeline::getRangeNameForMatchLength($match['match_length']);

    //Build Team Structure For Reference
    $teamplayers = [];
    $teamplayers[0] = [];
    $teamplayers[1] = [];
    foreach ($match['players'] as $player) {
        $team = $player['team'];
        $teamplayers[$team][] = $player;
    }

    try {
        //Update Players and Heroes
        foreach ($match['players'] as $player) {
            //Qol
            $team = $player['team'];
            $otherteam = ($team === 0) ? (1) : (0);
            $winInc = ($player['team'] === $match['winner']) ? (1) : (0);
            $timeSilenced = ($player['silenced'] === 1) ? ($match['match_length']) : (0);
            $mmr = $player['mmr']['new'];
            $talents = $player['talents'];

            /*
             * +=:players
             */
            $r_player_id = $player['id'];
            $r_name = $player['name'];
            $r_tag = $player['tag'];
            $r_region = $match['region'];
            $r_account_level = $player['account_level'];

            $db->execute("+=:players");

            /*
             * +=:players_heroes
             */
            $r_hero = $player['hero'];
            $r_hero_level = $player['hero_level'];

            $db->execute("+=:players_heroes");

            /*
             * +=:players_matches
             */
            $r_match_id = $match['id'];
            $r_date = $match['date'];

            $db->execute("+=:players_matches");

            /*
             * ??:players_matches_recent_granular
             * +=:players_matches_recent_granular
             */
            //Construct initial json and ensure hash rows
            $g_medals = [];
            foreach ($player['stats']['medals'] as $medal) {
                $g_medals[$medal] = [
                    "count" => 1
                ];
            }

            $g_talents = [];
            $g_builds = [];
            if (count($talents) > 0) {
                foreach ($talents as $talent) {
                    $g_talents[$talent] = [
                        "played" => 1,
                        "won" => $winInc
                    ];
                }

                $r_build = HotstatusPipeline::getTalentBuildHash($talents);
                $r_build_talents = json_encode($talents);

                $db->execute("ensureTalentBuild");

                $g_builds[$r_build] = [
                    "played" => 1,
                    "won" => $winInc
                ];
            }

            $g_parties = [];
            if (count($player['party']) > 0) {
                $r_party = HotstatusPipeline::getPerPlayerPartyHash($player['party']);
                $r_players = json_encode(HotstatusPipeline::getPlayerIdArrayFromPlayerPartyRelationArray($match['players'], $player['party']));

                $db->execute("ensurePlayerParty");

                $g_parties[$r_party] = [
                    "played" => 1,
                    "won" => $winInc
                ];
            }

            $g_matchup_friends = [];
            foreach($teamplayers[$team] as $teamplayer) {
                if ($teamplayer['id'] !== $player['id']) {
                    $g_matchup_friends[$teamplayer['hero']] = [
                        "played" => 1,
                        "won" => $winInc
                    ];
                }
            }

            $g_matchup_foes = [];
            foreach($teamplayers[$otherteam] as $teamplayer) {
                $g_matchup_foes[$teamplayer['hero']] = [
                    "played" => 1,
                    "won" => $winInc
                ];
            }


            //Set key params
            $r_date_end = $isodate['date_end'];
            $r_map = $match['map'];
            $r_gameType = $match['type'];

            //Check if row exists to increment json
            $p_res = $db->execute("??:players_matches_recent_granular");
            $p_res_rows = $db->countResultRows($p_res);
            if ($p_res_rows > 0) {
                //Row exists, use its json values to increment constructed json
                $row = $db->fetchArray($p_res);

                //Aggregate Sum
                $aggr_medals = [];
                $row_medals = json_decode($row['medals'], true);
                AssocArray::aggregate($aggr_medals, $g_medals, $row_medals, AssocArray::AGGREGATE_SUM);
                $r_medals = json_encode($aggr_medals);

                $aggr_talents = [];
                $row_talents = json_decode($row['talents'], true);
                AssocArray::aggregate($aggr_talents, $g_talents, $row_talents, AssocArray::AGGREGATE_SUM);
                $r_talents = json_encode($aggr_talents);

                $aggr_builds = [];
                $row_builds = json_decode($row['builds'], true);
                AssocArray::aggregate($aggr_builds, $g_builds, $row_builds, AssocArray::AGGREGATE_SUM);
                $r_builds = json_encode($aggr_builds);

                $aggr_parties = [];
                $row_parties = json_decode($row['parties'], true);
                AssocArray::aggregate($aggr_parties, $g_parties, $row_parties, AssocArray::AGGREGATE_SUM);
                $r_parties = json_encode($aggr_parties);
            }
            else {
                $r_medals = json_encode($g_medals);
                $r_talents = json_encode($g_talents);
                $r_builds = json_encode($g_builds);
                $r_parties = json_encode($g_parties);
            }
            $db->freeResult($p_res);

            //Set main params
            $r_played = 1;
            $r_won = $winInc;
            $r_time_played = $match['match_length'];
            $r_stats_kills = $player['stats']['kills'];
            $r_stats_assists = $player['stats']['assists'];
            $r_stats_deaths = $player['stats']['deaths'];
            $r_stats_siege_damage = $player['stats']['siege_damage'];
            $r_stats_hero_damage = $player['stats']['hero_damage'];
            $r_stats_structure_damage = $player['stats']['structure_damage'];
            $r_stats_healing = $player['stats']['healing'];
            $r_stats_damage_taken = $player['stats']['damage_taken'];
            $r_stats_merc_camps = $player['stats']['merc_camps'];
            $r_stats_exp_contrib = $player['stats']['exp_contrib'];
            $r_stats_best_killstreak = $player['stats']['best_killstreak'];
            $r_stats_time_spent_dead = $player['stats']['time_spent_dead'];

            $db->execute("+=:players_matches_recent_granular");

            /*
             * +=:players_matches_total
             */
            $r_time_played_silenced = $timeSilenced;

            $db->execute("+=:players_matches_total");

            /*
             * +=:players_mmr
             */
            $r_season = $seasonid;
            $r_rating = $mmr['rating'];
            $r_mu = $mmr['mu'];
            $r_sigma = $mmr['sigma'];

            $db->execute("+=:players_mmr");
        }

        echo "Processed ".count($match['players'])." Players...".E;

        $ret = true;
    }
    catch (\Exception $e) {
        $ret = false;
    }

    return $ret;
}

//Begin main script
echo '--------------------------------------'.E
    .'Replay process <<REPROCESS WORKER>> has started'.E
    .'--------------------------------------'.E;

//Look for replays to parse and handle
while (true) {
    //Get pipeline configuration
    $r_pipeline_config_id = HotstatusPipeline::$pipeline_config[HotstatusPipeline::PIPELINE_CONFIG_DEFAULT]['id'];
    $pipeconfigresult = $db->execute("GetPipelineConfig");
    $pipeconfigresrows = $db->countResultRows($pipeconfigresult);
    if ($pipeconfigresrows > 0) {
        $pipeconfig = $db->fetchArray($pipeconfigresult);

        $replaymindate = $pipeconfig['min_replay_date'];
        $replaymaxdate = $pipeconfig['max_replay_date'];

        $db->freeResult($pipeconfigresult);

        //Check for unlocked failed worker processing
        $r_status = HotstatusPipeline::REPLAY_STATUS_REPARSING;
        $r_timestamp = time() - UNLOCK_PARSING_DURATION;
        $result = $db->execute("SelectNextReplayWithStatus-Unlocked");
        $resrows = $db->countResultRows($result);
        if ($resrows > 0) {
            //Found a failed replay worker process, reset it to queued
            $row = $db->fetchArray($result);

            echo 'Found a failed worker process at replay #' . $row['id'] . ', resetting status to \'' . HotstatusPipeline::REPLAY_STATUS_PARSED . '\'...' . E;

            $r_id = $row['id'];
            $r_status = HotstatusPipeline::REPLAY_STATUS_PARSED;
            $r_timestamp = time();

            $db->execute("UpdateReplayStatus");
        }
        else {
            //No Worker Processing previously failed, look for unlocked queued replay to process
            echo "Finding parsed replay to reparse...".E;
            $r_status = HotstatusPipeline::REPLAY_STATUS_PARSED;
            $r_timestamp = time() - UNLOCK_DEFAULT_DURATION;
            $queuedResult = $db->execute("SelectNextReplayWithStatus-Unlocked");
            $queuedResultRows = $db->countResultRows($queuedResult);
            if ($queuedResultRows > 0) {
                //Found a queued unlocked replay for download, softlock for processing and process it
                $row = $db->fetchArray($queuedResult);

                $r_id = $row['id'];
                $r_status = HotstatusPipeline::REPLAY_STATUS_REPARSING;
                $r_timestamp = time();

                $db->execute("UpdateReplayStatus");

                echo "Flagging replay as reparsing before trying to obtain lock...".E;

                //Set lock id
                $replayLockId = "hotstatus_reparsing_$r_id";

                //Obtain lock
                $replayLocked = $db->lock($replayLockId, 0);

                if ($replayLocked) {
                  echo "Obtained lock 'hotstatus_reparsing_$r_id'...".E;
                    /*
                     * PARSE PORTION OF PROCESSING
                     */
                    $r_match_id = $row['match_id'];

                  echo "Getting match data...".E;

                    $match_result = $db->execute("GetMatch");
                    $match_result_rows = $db->countResultRows($match_result);
                    if ($match_result_rows > 0) {
                        $match_row = $db->fetchArray($match_result);

                        //Build match parse structure
                        $parse = [];
                        $parse['id'] = $match_row['id'];
                        $parse['type'] = $match_row['type'];
                        $parse['map'] = $match_row['map'];
                        $parse['date'] = $match_row['date'];
                        $parse['match_length'] = $match_row['match_length'];
                        $parse['version'] = $match_row['version'];
                        $parse['region'] = $match_row['region'];
                        $parse['winner'] = $match_row['winner'];
                        $parse['players'] = json_decode($match_row['players'], true);
                        $parse['bans'] = json_decode($match_row['bans'], true);
                        $parse['team_level'] = json_decode($match_row['team_level'], true);
                        $parse['mmr'] = json_decode($match_row['mmr'], true);

                        $seasonid = HotstatusPipeline::getSeasonStringForDateTime($parse['date']);

                        //Begin full parse transaction
                        $db->transaction_begin();

                        echo 'Reparsing replay #' . $r_id . '...                                       ' . E;

                        //No error parsing match, continue with upserting of players, heroes
                        $success_playersAndHeroes = updatePlayersAndHeroes($parse, $seasonid);

                        $hadError = !$success_playersAndHeroes;

                        $errorstr = "";
                        if (!$success_playersAndHeroes) $errorstr .= "Players";

                        if (!$hadError) {
                            //Flag replay as fully reprocessed
                            $r_status = HotstatusPipeline::REPLAY_STATUS_REPARSED;
                            $r_timestamp = time();

                            $db->execute("UpdateReplayParsed");

                            //Commit Transaction
                            $db->transaction_commit();

                            //Track stats
                            $r_replays_processed_total = 1;
                            $db->execute("stats_replays_processed_total");

                            echo 'Successfully processed replay #' . $r_id . '...' . E . E;
                        }
                        else {
                            //Rollback Transaction
                            $db->transaction_rollback();

                            //Flag replay reparse error
                            $r_status = HotstatusPipeline::REPLAY_STATUS_REPARSE_ERROR;
                            $r_timestamp = time();
                            $r_error = "Semi-Parsed, Missed: " . $errorstr;

                            $db->execute("UpdateReplayStatusError");

                            //Track stats
                            $r_replays_errors_total = 1;
                            $db->execute("stats_replays_errors_total");

                            echo 'Could not successfully reparse replay #' . $r_id . '. Mysql had trouble with portions of : ' . $errorstr . '...' . E . E;
                        }
                    }
                    else {
                        //No match found with match id
                        $db->transaction_rollback();

                         //Flag replay reparse error
                         $r_status = HotstatusPipeline::REPLAY_STATUS_REPARSE_ERROR;
                         $r_timestamp = time();
                         $r_error = "No match found with match_id $r_match_id";

                         $db->execute("UpdateReplayStatusError");

                         //Track stats
                         $r_replays_errors_total = 1;
                         $db->execute("stats_replays_errors_total");

                         echo 'Could not successfully reparse replay #' . $r_id . '...' . E . E;
                    }

                    $db->freeResult($match_result);

                    //Release lock
                    $db->unlock($replayLockId);
                }
                else {
                    //Could not attain lock on replay, immediately continue
                     echo "Could not obtain lock 'hotstatus_reparsing_$r_id'...".E.E;
                }
            }
            else {
                //No unlocked queued replays to process, sleep
                $dots = $console->animateDotDotDot();
                echo "No unlocked parsed replays found$dots                           \r";

                $sleep->add(SLEEP_DURATION);
            }

            $db->freeResult($queuedResult);
        }

        $db->freeResult($result);
    }
    else {
        //Could not find config
        $dots = $console->animateDotDotDot();
        echo "Could not retrieve pipeline configuration$dots                           \r";

        $sleep->add(CONFIG_ERROR_SLEEP_DURATION);
    }

    //Default sleep
    $sleep->add(NORMAL_EXECUTION_SLEEP_DURATION, true, true);

    $sleep->execute();
}

?>