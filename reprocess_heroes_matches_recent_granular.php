<?php
/*
 * Replay Process Parse
 * In charge of looking for downloaded replays and parsing them to insert their data into a database
 */

namespace Fizzik;

require_once 'includes/include.php';

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
$db->connect($creds[Credentials::KEY_DB_HOSTNAME], $creds[Credentials::KEY_DB_USER], $creds[Credentials::KEY_DB_PASSWORD], $creds[Credentials::KEY_DB_DATABASE]);
$db->setEncoding(HotstatusPipeline::DATABASE_CHARSET);

//Constants and qol
const MYSQL_ERROR_SLEEP_DURATION = 60; //seconds
const SLEEP_DURATION = 5; //seconds
const MINI_SLEEP_DURATION = 1; //seconds
const UNLOCK_DEFAULT_DURATION = 5; //Must be unlocked for atleast 5 seconds
const UNLOCK_PARSING_DURATION = 120; //Must be unlocked for atleast 2 minutes while parsing status
const E = PHP_EOL;
$sleep = new SleepHandler();
$console = new Console();

//Prepare statements


$db->prepare("UpdateReplayStatus",
    "UPDATE replays SET status = ?, lastused = ? WHERE id = ?");
$db->bind("UpdateReplayStatus", "sii", $r_status, $r_timestamp, $r_id);

$db->prepare("UpdateReplayStatusError",
    "UPDATE replays SET error = ?, status = ?, lastused = ? WHERE id = ?");
$db->bind("UpdateReplayStatusError", "ssii", $r_error, $r_status, $r_timestamp, $r_id);

$db->prepare("SelectNextReplayWithStatus-Unlocked",
    "SELECT * FROM replays WHERE status = ? AND lastused <= ? ORDER BY match_date ASC, id ASC LIMIT 1");
$db->bind("SelectNextReplayWithStatus-Unlocked", "si", $r_status, $r_timestamp);

$db->prepare("GetHeroNameFromAttribute",
    "SELECT `name` FROM herodata_heroes WHERE name_attribute = ?");
$db->bind("GetHeroNameFromAttribute", "s", $r_name_attribute);

$db->prepare("??:heroes_matches_recent_granular",
    "SELECT medals, talents, builds, matchup_friends, matchup_foes FROM heroes_matches_recent_granular "
    . "WHERE hero = ? AND year = ? AND week = ? AND day = ? AND map = ? AND gameType = ? AND mmr_average = ? AND range_match_length = ? AND range_hero_level = ? FOR UPDATE");
$db->bind("??:heroes_matches_recent_granular",
    "siiississ", $r_hero, $r_year, $r_week, $r_day, $r_map, $r_gameType, $r_mmr_average, $r_range_match_length, $r_range_hero_level);

$db->prepare("+=:heroes_matches_recent_granular",
    "INSERT INTO heroes_matches_recent_granular "
    . "(hero, year, week, day, date_end, gameType, map, mmr_average, range_match_length, range_hero_level, played, won, time_played, stats_kills, stats_assists, 
    stats_deaths, stats_siege_damage, stats_hero_damage, stats_structure_damage, stats_healing, stats_damage_taken, stats_merc_camps, stats_exp_contrib, stats_best_killstreak, 
    stats_time_spent_dead, medals, talents, builds, matchup_friends, matchup_foes) "
    . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) "
    . "ON DUPLICATE KEY UPDATE "
    . "date_end = VALUES(date_end), played = played + VALUES(played), won = won + VALUES(won), time_played = time_played + VALUES(time_played), 
    stats_kills = stats_kills + VALUES(stats_kills), stats_assists = stats_assists + VALUES(stats_assists), stats_deaths = stats_deaths + VALUES(stats_deaths), 
    stats_siege_damage = stats_siege_damage + VALUES(stats_siege_damage), stats_hero_damage = stats_hero_damage + VALUES(stats_hero_damage), 
    stats_structure_damage = stats_structure_damage + VALUES(stats_structure_damage), stats_healing = stats_healing + VALUES(stats_healing), 
    stats_damage_taken = stats_damage_taken + VALUES(stats_damage_taken), stats_merc_camps = stats_merc_camps + VALUES(stats_merc_camps), 
    stats_exp_contrib = stats_exp_contrib + VALUES(stats_exp_contrib), stats_best_killstreak = GREATEST(stats_best_killstreak, VALUES(stats_best_killstreak)), 
    stats_time_spent_dead = stats_time_spent_dead + VALUES(stats_time_spent_dead), medals = VALUES(medals), talents = VALUES(talents), builds = VALUES(builds), 
    matchup_friends = VALUES(matchup_friends), matchup_foes = VALUES(matchup_foes)");
$db->bind("+=:heroes_matches_recent_granular",
    "siiisssissiiiiiiiiiiiiiiisssss",
    $r_hero, $r_year, $r_week, $r_day, $r_date_end, $r_gameType, $r_map, $r_mmr_average, $r_range_match_length, $r_range_hero_level, $r_played, $r_won,
    $r_time_played, $r_stats_kills, $r_stats_assists, $r_stats_deaths, $r_stats_siege_damage, $r_stats_hero_damage, $r_stats_structure_damage, $r_stats_healing,
    $r_stats_damage_taken, $r_stats_merc_camps, $r_stats_exp_contrib, $r_stats_best_killstreak, $r_stats_time_spent_dead, $r_medals, $r_talents, $r_builds,
    $r_matchup_friends, $r_matchup_foes);

$db->prepare("trackHeroBan",
    "INSERT INTO heroes_bans_recent_granular "
    . "(hero, year, week, day, date_end, gameType, map, mmr_average, banned) "
    . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) "
    . "ON DUPLICATE KEY UPDATE "
    . "banned = banned + VALUES(banned)");
$db->bind("trackHeroBan",
    "siiisssii",
    $r_hero, $r_year, $r_week, $r_day, $r_date_end, $r_gameType, $r_map, $r_mmr_average, $r_banned);

$db->prepare("ensureTalentBuild",
    "INSERT INTO heroes_builds (hero, build, talents) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE hero = hero");
$db->bind("ensureTalentBuild", "sss", $r_hero, $r_build, $r_build_talents);

$db->prepare("ensurePlayerParty",
    "INSERT INTO players_parties (id, party, players) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE id = id");
$db->bind("ensurePlayerParty", "iss", $r_player_id, $r_party, $r_players);

$db->prepare("GetMatch",
    "SELECT * FROM `matches` WHERE `id` = ? LIMIT 1");
$db->bind("GetMatch", "i", $r_match_id);

//Helper functions

function updateHeroes(&$match, &$bannedHeroes) {
    global $db, $r_player_id, $r_name, $r_tag, $r_region, $r_account_level, $r_hero, $r_hero_level, $r_match_id, $r_date,
           $r_year, $r_week, $r_day, $r_date_end, $r_hero, $r_gameType, $r_map, $r_played, $r_won, $r_time_played,
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
            $talents = $player['talents'];


            $r_player_id = $player['id'];
            $r_name = $player['name'];
            $r_tag = $player['tag'];
            $r_region = $match['region'];
            $r_account_level = $player['account_level'];
            $r_hero = $player['hero'];
            $r_hero_level = $player['hero_level'];
            $r_match_id = $match['id'];
            $r_date = $match['date'];

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
            $r_year = $isodate['year'];
            $r_week = $isodate['week'];
            $r_day = $isodate['day'];
            $r_map = $match['map'];
            $r_gameType = $match['type'];


            //Set main params
            $r_date_end = $isodate['date_end'];
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

            $r_time_played_silenced = $timeSilenced;

            /*
             * ??:heroes_matches_recent_granular
             * +=:heroes_matches_recent_granular
             */

            //Set Key Params
            $r_range_hero_level = HotstatusPipeline::getRangeNameForHeroLevel($player['hero_level']);

            //Check if row exists to increment json
            $h_res = $db->execute("??:heroes_matches_recent_granular");

            $h_res_rows = $db->countResultRows($h_res);
            if ($h_res_rows > 0) {
                //Row exists, use its json values to increment constructed json
                $row = $db->fetchArray($h_res);

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

                $aggr_matchup_friends = [];
                $row_matchup_friends = json_decode($row['matchup_friends'], true);
                AssocArray::aggregate($aggr_matchup_friends, $g_matchup_friends, $row_matchup_friends, AssocArray::AGGREGATE_SUM);
                $r_matchup_friends = json_encode($aggr_matchup_friends);

                $aggr_matchup_foes = [];
                $row_matchup_foes = json_decode($row['matchup_foes'], true);
                AssocArray::aggregate($aggr_matchup_foes, $g_matchup_foes, $row_matchup_foes, AssocArray::AGGREGATE_SUM);
                $r_matchup_foes = json_encode($aggr_matchup_foes);
            }
            else {
                $r_medals = json_encode($g_medals);
                $r_talents = json_encode($g_talents);
                $r_builds = json_encode($g_builds);
                $r_matchup_friends = json_encode($g_matchup_friends);
                $r_matchup_foes = json_encode($g_matchup_foes);
            }
            $db->freeResult($h_res);

            //Set main params
            $r_hero = $player['hero'];

            $db->execute("+=:heroes_matches_recent_granular");
        }

        echo "Processed ".count($match['players'])." Players and Heroes...".E;

        //Handle Bans
        foreach ($bannedHeroes as $heroban) {
            $r_hero = $heroban;
            $r_year = $isodate['year'];
            $r_week = $isodate['week'];
            $r_day = $isodate['day'];
            $r_date_end = $isodate['date_end'];
            $r_map = $match['map'];
            $r_gameType = $match['type'];
            $r_banned = 1;

            $db->execute("trackHeroBan");
        }

        echo "Processed ".count($bannedHeroes)." Herobans...".E;

        $ret = true;
    }
    catch (\Exception $e) {
        $ret = false;
    }

    return $ret;
}

//Begin main script
echo '--------------------------------------'.E
    .'Replay process <<PARSE>> has started'.E
    .'--------------------------------------'.E;

//Look for replays to parse and handle
while (true) {
    //Check for unlocked failed replay reparses
    $r_status = HotstatusPipeline::REPLAY_STATUS_REPARSING;
    $r_timestamp = time() - UNLOCK_PARSING_DURATION;
    $result = $db->execute("SelectNextReplayWithStatus-Unlocked");
    $resrows = $db->countResultRows($result);
    if ($resrows > 0) {
        //Found a failed replay reparse, reset it to parsed
        $row = $db->fetchArray($result);

        echo 'Found a failed match reparse at match #' . $row['match_id'] . ', resetting status to \'' . HotstatusPipeline::REPLAY_STATUS_PARSED . '\'...'.E;

        $r_id = $row['id'];
        $r_status = HotstatusPipeline::REPLAY_STATUS_PARSED;
        $r_timestamp = time();

        $db->execute("UpdateReplayStatus");
    }
    else {
        //No replay reparsing has previously failed, look for an unlocked parsed replay to reparse
        $r_status = HotstatusPipeline::REPLAY_STATUS_PARSED;
        $r_timestamp = time() - UNLOCK_DEFAULT_DURATION;
        $result2 = $db->execute("SelectNextReplayWithStatus-Unlocked");
        $resrows2 = $db->countResultRows($result2);
        if ($resrows2 > 0) {
            //Found an unlocked parse replay, reparse it
            $row = $db->fetchArray($result2);

            $r_match_id = $row['match_id'];

            $r_id = $row['id'];
            $r_status = HotstatusPipeline::REPLAY_STATUS_REPARSING;
            $r_timestamp = time();

            $db->execute("UpdateReplayStatus");

            //Set lock id
            $replayLockId = "hotstatus_reparseReplay_$r_id";

            //Obtain lock
            $replayLocked = $db->lock($replayLockId, 0);

            //Begin full parse transaction
            $db->transaction_begin();

            if ($replayLocked) {
                echo 'Reparsing match #' . $r_match_id . '...                                       ' . E;

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

                    //Error handler
                    $mysqlError = FALSE;
                    $mysqlErrorMsg = "";

                    //Collect mapping of ban attributes to hero names
                    $bannedHeroes = [];
                    try {
                        foreach ($parse['bans'] as $teambans) {
                            foreach ($teambans as $heroban) {
                                $r_name_attribute = $heroban;

                                $result3 = $db->execute("GetHeroNameFromAttribute");
                                $resrows3 = $db->countResultRows($result3);
                                if ($resrows3 > 0) {
                                    $row2 = $db->fetchArray($result3);

                                    $bannedHeroes[] = $row2['name'];
                                }
                                $db->freeResult($result3);
                            }
                        }
                    }
                    catch (\Exception $e) {
                        $mysqlError = TRUE;
                        $mysqlErrorMsg = "Collect Hero Bans: " . $e->getMessage();
                    }

                    if ($mysqlError === FALSE) {
                        //No error parsing match, continue with upserting of players, heroes
                        $success_Heroes = updateHeroes($parse, $bannedHeroes);

                        $hadError = !$success_Heroes;

                        $errorstr = "";
                        if (!$success_Heroes) $errorstr .= "Heroes";

                        if (!$hadError) {
                            //Flag match as fully reparsed
                            $r_id = $row['id'];
                            $r_status = HotstatusPipeline::REPLAY_STATUS_REPARSED;
                            $r_timestamp = time();

                            $db->execute("UpdateReplayStatus");

                            //Commit Transaction
                            $db->transaction_commit();

                            echo 'Successfully reparsed match #' . $r_match_id . '...' . E . E;
                        }
                        else {
                            //Rollback Transaction
                            $db->transaction_rollback();

                            //Flag match as partially reparsed, due to some mysql error
                            $r_id = $row['id'];
                            $r_status = HotstatusPipeline::REPLAY_STATUS_REPARSE_ERROR;
                            $r_timestamp = time();
                            $r_error = "Semi-Reparsed, Missed: " . $errorstr;

                            $db->execute("UpdateReplayStatusError");

                            echo 'Could not successfully reparse match #' . $r_match_id . '. Mysql had trouble with portions of : ' . $errorstr . '...' . E . E;
                        }
                    }
                    else {
                        //Rollback Transaction
                        $db->transaction_rollback();

                        //Encountered an mysql error reparsing match, output it, and flag replay as 'reparse_error'
                        $r_id = $row['id'];
                        $r_status = HotstatusPipeline::REPLAY_STATUS_REPARSE_ERROR;
                        $r_timestamp = time();
                        $r_error = $mysqlErrorMsg;

                        $db->execute("UpdateReplayStatusError");

                        echo 'Mysql threw exception during reparse operations for match #' . $r_match_id . ', Error : "' . $mysqlErrorMsg . '"...' . E . E;

                        $sleep->add(MINI_SLEEP_DURATION);
                    }
                }
                else {
                    //Rollback Transaction
                    $db->transaction_rollback();

                    //Encountered an error reparsing match, output it and flag replay as 'reparse_error'
                    $r_id = $row['id'];
                    $r_status = HotstatusPipeline::REPLAY_STATUS_REPARSE_ERROR;
                    $r_timestamp = time();
                    $r_error = "Could not find match_id: " . $r_match_id;

                    $db->execute("UpdateReplayStatusError");

                    echo 'Failed to reparse match #' . $r_match_id . ', Error : "' . $r_error . '"...' . E . E;

                    $sleep->add(MINI_SLEEP_DURATION);
                }

                //Release lock
                $db->unlock($replayLockId);
            }
            else {
                //Could not attain lock on replay, immediately continue
                $db->transaction_rollback();
            }
        }
        else {
            $db->transaction_commit();

            //No unlocked parsed replays to reparse, sleep
            $dots = $console->animateDotDotDot();
            echo "No unlocked parsed replays found$dots                           \r";

            $sleep->add(SLEEP_DURATION);
        }

        $db->freeResult($result2);
    }

    $db->freeResult($result);

    $sleep->execute();
}

?>