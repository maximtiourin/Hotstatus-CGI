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
$t_matches = HotstatusPipeline::$table_pointers['matches'];
$t_matches_mmr = HotstatusPipeline::$table_pointers['matches_mmr'];
$t_players = HotstatusPipeline::$table_pointers['players'];
$t_players_heroes = HotstatusPipeline::$table_pointers['players_heroes'];
$t_players_matches = HotstatusPipeline::$table_pointers['players_matches'];
$t_players_matches_recent_granular = HotstatusPipeline::$table_pointers['players_matches_recent_granular'];
$t_players_matches_total = HotstatusPipeline::$table_pointers['players_matches_total'];
$t_players_mmr = "rp_players_mmr_v2";
$t_players_parties = HotstatusPipeline::$table_pointers['players_parties'];

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

$db->prepare("GetMMRForPlayer",
    "SELECT season, rating, mu, sigma FROM $t_players_mmr WHERE id = ? AND region = ? AND (season = ? OR season = ?) AND gameType = ? FOR UPDATE");
$db->bind("GetMMRForPlayer", "iisss", $r_player_id, $r_region, $r_season, $r_season_previous, $r_gameType);

$db->prepare("GetMatch",
    "SELECT * FROM `$t_matches` WHERE `id` = ? LIMIT 1");
$db->bind("GetMatch", "i", $r_match_id);

$db->prepare("+=:matches_mmr",
    "INSERT INTO $t_matches_mmr "
    . "(id, players, teams) "
    . "VALUES (?, ?, ?) "
    . "ON DUPLICATE KEY UPDATE "
    . "players = ?, teams = ?");
$db->bind("+=:matches_mmr",
    "issss",
    $r_match_id, $r_players, $r_teams,

    $r_players, $r_teams);

$db->prepare("+=:players_mmr",
    "INSERT INTO $t_players_mmr "
    . "(id, region, season, gameType, rating, mu, sigma) "
    . "VALUES (?, ?, ?, ?, ?, ?, ?) "
    . "ON DUPLICATE KEY UPDATE "
    . "rating = VALUES(rating), mu = VALUES(mu), sigma = VALUES(sigma)");
$db->bind("+=:players_mmr",
    "iissidd",
    $r_player_id, $r_region, $r_season, $r_gameType, $r_rating, $r_mu, $r_sigma);

function upsertMatchMMR($matchid, &$parse, &$mmrcalc, &$old_mmrs, &$new_mmrs) {
    global $db, $r_match_id, $r_players, $r_teams;

    //Team MMR
    $teams_mmr = [
        '0' => [
            'old' => [
                'rating' => $mmrcalc['team_ratings']['initial'][0]['mmr']
            ],
            'new' => [
                'rating' => $mmrcalc['team_ratings']['final'][0]['mmr']
            ]
        ],
        '1' => [
            'old' => [
                'rating' => $mmrcalc['team_ratings']['initial'][1]['mmr']
            ],
            'new' => [
                'rating' => $mmrcalc['team_ratings']['final'][1]['mmr']
            ]
        ],
        'quality' => $mmrcalc['match_quality']
    ];

    //Player MMR && Hero Name mappings
    $players_mmr = [];
    foreach ($parse['players'] as &$player) {
        $players_mmr[$player['id']] = [
            'old' => [
                'rating' => $old_mmrs['team'.$player['team']][$player['id'].""]['rating'],
                'mu' => $old_mmrs['team'.$player['team']][$player['id'].""]['mu'],
                'sigma' => $old_mmrs['team'.$player['team']][$player['id'].""]['sigma'],
            ],
            'new' => [
                'rating' => $new_mmrs['team'.$player['team']][$player['id'].""]['rating'],
                'mu' => $new_mmrs['team'.$player['team']][$player['id'].""]['mu'],
                'sigma' => $new_mmrs['team'.$player['team']][$player['id'].""]['sigma'],
            ]
        ];
    }

    //Begin inserting match
    try {
        $r_match_id = $matchid;
        $r_players = json_encode($players_mmr);
        $r_teams = json_encode($teams_mmr);

        $db->execute("+=:matches_mmr");

        echo "Upserted Match MMR'...".E;

        $ret = true;
    }
    catch (\Exception $e) {
        $ret = false;
    }

    return $ret;
}

/*
 * Updates the all relevant player and hero tables and rows with data from the match
 * All Per Player/Hero updates are done as transactions, to prevent partial updates.
 * Returns TRUE on complete success, FALSE if any errors occurred
 */
function updateMMR(&$match, $seasonid, &$new_mmrs) {
    global $db, $r_player_id, $r_name, $r_tag, $r_region, $r_account_level, $r_hero, $r_hero_level, $r_match_id, $r_date,
           $r_date_end, $r_hero, $r_gameType, $r_map, $r_played, $r_won, $r_time_played,
           $r_stats_kills, $r_stats_assists, $r_stats_deaths, $r_stats_siege_damage, $r_stats_hero_damage, $r_stats_structure_damage,
           $r_stats_healing, $r_stats_damage_taken, $r_stats_merc_camps, $r_stats_exp_contrib, $r_stats_best_killstreak,
           $r_stats_time_spent_dead, $r_medals, $r_talents, $r_builds, $r_parties, $r_build, $r_build_talents, $r_party, $r_players, $r_time_played_silenced,
           $r_season, $r_rating, $r_mu, $r_sigma, $r_banned, $r_mmr_average, $r_range_match_length, $r_range_hero_level, $r_matchup_friends, $r_matchup_foes;


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
            $mmr = $new_mmrs['team'.$player['team']][$player['id'].""]; //The player's new mmr object

            /*
             * Params
             */
            $r_player_id = $player['id'];
            $r_region = $match['region'];
            $r_gameType = $match['type'];


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
    .'Replay process <<MMR REPROCESS WORKER>> has started'.E
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

                        //Begin full parse transaction
                        $db->transaction_begin();

                        echo 'Reparsing replay #' . $r_id . '...                                       ' . E;

                        /* Collect player mmrs and calculate new mmr for match season */
                        $seasonid = HotstatusPipeline::getSeasonStringForDateTime($parse['date']);
                        $seasonprevid = HotstatusPipeline::getSeasonPreviousStringForSeasonString($seasonid);
                        $matchtype = $parse['type'];

                        $team0rank = ($parse['winner'] === 0) ? (1) : (2);
                        $team1rank = ($parse['winner'] === 1) ? (1) : (2);

                        //Get old mmrs if any
                        $player_old_mmrs = [
                            "team0" => [],
                            "team1" => []
                        ];

                        try {
                            foreach ($parse['players'] as $player) {
                                $r_player_id = $player['id'];
                                $r_season = $seasonid;
                                $r_season_previous = $seasonprevid;
                                $r_gameType = $matchtype;

                                //Set default mmr structure
                                $mmr = [
                                    'rating' => "?",
                                    'mu' => "?",
                                    'sigma' => "?"
                                ];

                                //Look up old mmrs
                                $mmr_result = $db->execute("GetMMRForPlayer");
                                $mmr_result_rows = $db->countResultRows($mmr_result);
                                if ($mmr_result_rows > 0) {
                                    $seasoncurrent = FALSE;
                                    $seasonprevious = FALSE;

                                    while ($season_row = $db->fetchArray($mmr_result)) {
                                        if ($season_row['season'] === $seasonid) {
                                            $seasoncurrent = $season_row;
                                        }
                                        else if ($season_row['season'] === $seasonprevid) {
                                            $seasonprevious = $season_row;
                                        }
                                    }

                                    if ($seasoncurrent !== FALSE) {
                                        //Found current season, use it
                                        $mmr['rating'] = $seasoncurrent['rating'];
                                        $mmr['mu'] = $seasoncurrent['mu'];
                                        $mmr['sigma'] = $seasoncurrent['sigma'];
                                    }
                                    else if ($seasonprevious !== FALSE) {
                                        //Found only most recent previous season, use mu for seeding
                                        $mmr['mu'] = $seasonprevious['mu'];
                                    }
                                }

                                //Set player old mmr
                                $player_old_mmrs['team' . $player['team']][$player['id'] . ""] = $mmr;

                                $db->freeResult($mmr_result);
                            }

                            //Calculate new mmrs
                            echo 'Calculating MMR...' . E;
                            $calc = MMRCalculator::Calculate(__DIR__, $team0rank, $team1rank, $player_old_mmrs, $linux);
                        }
                        catch (\Exception $e) {
                            $calc = [];

                            $calc['error'] = $e->getMessage();
                        }

                        if (!key_exists('error', $calc)) {
                            //Collect new player mmrs
                            $player_new_mmrs = [
                                "team0" => [],
                                "team1" => []
                            ];
                            foreach ($parse['players'] as $player) {
                                $obj = $calc['players'][$player['id'] . ""];

                                $mmr = [
                                    'rating' => $obj['mmr'],
                                    'mu' => $obj['mu'],
                                    'sigma' => $obj['sigma']
                                ];

                                $player_new_mmrs['team' . $player['team']][$player['id'] . ""] = $mmr;
                            }

                            //Upsert into matches_mmr and players_mmr
                            $upsertResult = upsertMatchMMR($parse['id'], $parse, $calc, $player_old_mmrs, $player_new_mmrs);

                            if ($upsertResult === FALSE) {
                                //Rollback Transaction
                                $db->transaction_rollback();

                                //Flag replay reparse error
                                $r_status = HotstatusPipeline::REPLAY_STATUS_REPARSE_ERROR;
                                $r_timestamp = time();
                                $r_error = "Match MMR Update failed #$r_match_id";

                                $db->execute("UpdateReplayStatusError");

                                //Track stats
                                $r_replays_errors_total = 1;
                                $db->execute("stats_replays_errors_total");

                                echo 'Could not successfully reparse replay #' . $r_id . '...' . E . E;
                            }
                            else {
                                $success_playersAndHeroes = updateMMR($parse, $seasonid, $player_new_mmrs);

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
                        }
                        else {
                            //MMR Calc error
                            $db->transaction_rollback();

                            //Flag replay reparse error
                            $r_status = HotstatusPipeline::REPLAY_STATUS_REPARSE_ERROR;
                            $r_timestamp = time();
                            $r_error = "MMR Recalculation error for match #$r_match_id";

                            $db->execute("UpdateReplayStatusError");

                            //Track stats
                            $r_replays_errors_total = 1;
                            $db->execute("stats_replays_errors_total");

                            echo 'Could not successfully reparse replay #' . $r_id . '...' . E . E;
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