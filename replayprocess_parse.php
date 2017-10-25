<?php
/*
 * Replay Process Parse
 * In charge of looking for downloaded replays and parsing them to insert their data into a database
 */

namespace Fizzik;

require_once 'includes/include.php';
require_once 'includes/ReplayParser.php';
require_once 'includes/MMRCalculator.php';

use Fizzik\Database\MySqlDatabase;
use Fizzik\Utility\Console;
use Fizzik\Utility\FileHandling;
use Fizzik\Utility\OS;
use Fizzik\Utility\SleepHandler;

set_time_limit(0);
date_default_timezone_set(HotstatusPipeline::REPLAY_TIMEZONE);

$db = new MysqlDatabase();
$creds = Credentials::getReplayProcessCredentials();
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
$linux = OS::getOS() == OS::OS_LINUX;

//Prepare statements
$db->prepare("UpdateReplayStatus",
    "UPDATE replays SET status = ?, lastused = ? WHERE id = ?");
$db->bind("UpdateReplayStatus", "sii", $r_status, $r_timestamp, $r_id);

$db->prepare("UpdateReplayStatusError",
    "UPDATE replays SET file = NULL, error = ?, status = ?, lastused = ? WHERE id = ?");
$db->bind("UpdateReplayStatusError", "ssii", $r_error, $r_status, $r_timestamp, $r_id);

$db->prepare("UpdateReplayParsed",
    "UPDATE replays SET match_id = ?, file = NULL, status = ?, lastused = ? WHERE id = ?");
$db->bind("UpdateReplayParsed", "isii", $r_match_id, $r_status, $r_timestamp, $r_id);

$db->prepare("UpdateReplayParsedError",
    "UPDATE replays SET match_id = ?, file = NULL, error = ?, status = ?, lastused = ? WHERE id = ?");
$db->bind("UpdateReplayParsedError", "issii", $r_match_id, $r_error, $r_status, $r_timestamp, $r_id);

$db->prepare("SelectNextReplayWithStatus-Unlocked",
    "SELECT * FROM replays WHERE status = ? AND lastused <= ? ORDER BY match_date ASC, id ASC LIMIT 1");
$db->bind("SelectNextReplayWithStatus-Unlocked", "si", $r_status, $r_timestamp);

$db->prepare("DoesHeroNameExist",
    "SELECT name FROM herodata_heroes WHERE name = ?");
$db->bind("DoesHeroNameExist", "s", $r_name);

$db->prepare("DoesMapNameExist",
    "SELECT name FROM herodata_maps WHERE name = ?");
$db->bind("DoesMapNameExist", "s", $r_name);

$db->prepare("GetHeroNameFromAttribute",
    "SELECT name FROM herodata_heroes WHERE name_attribute = ?");
$db->bind("GetHeroNameFromAttribute", "s", $r_name_attribute);

$db->prepare("GetHeroNameFromHeroNameTranslation",
    "SELECT name FROM herodata_heroes_translations WHERE name_translation = ?");
$db->bind("GetHeroNameFromHeroNameTranslation", "s", $r_name_translation);

$db->prepare("GetMapNameFromMapNameTranslation",
    "SELECT name FROM herodata_maps_translations WHERE name_translation = ?");
$db->bind("GetMapNameFromMapNameTranslation", "s", $r_name_translation);

$db->prepare("GetMMRForPlayer",
    "SELECT rating, mu, sigma FROM players_mmr WHERE id = ?, season = ?, gameType = ?");
$db->bind("GetMMRForPlayer", "idd", $r_player_id, $r_season, $r_gameType);

$db->prepare("InsertMatch",
    "INSERT INTO matches (id, type, map, date, match_length, version, region, winner, players, bans, team_level, mmr) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
$db->bind("InsertMatch", "isssisiissss", $r_id, $r_type, $r_map, $r_date, $r_match_length, $r_version, $r_region, $r_winner, $r_players, $r_bans, $r_team_level, $r_mmr);

$db->prepare("+=:players",
    "INSERT INTO players "
    . "(id, name, tag, region, account_level) "
    . "VALUES (?, ?, ?, ?, ?) "
    . "ON DUPLICATE KEY UPDATE "
    . "name = VALUES(name), tag = VALUES(tag), region = VALUES(region), account_level = GREATEST(account_level, VALUES(account_level))");
$db->bind("+=:players",
    "isiii",
    $r_player_id, $r_name, $r_tag, $r_region, $r_account_level);

//Helper functions

/*
 * Inserts match into 'matches' collection
 *
 * If operations are successful, Returns assoc array:
 * ['match'] = Updated parse data with new fields added
 * ['match_id'] = The official match id tied to the match data
 *
 * Otherwise, returns FALSE
 */
function insertMatch(&$parse, $mapMapping, $heroNameMappings, &$mmrcalc, &$old_mmrs, &$new_mmrs) {
    global $db, $r_id, $r_type, $r_map, $r_date, $r_match_length, $r_version, $r_region, $r_winner, $r_players, $r_bans, $r_team_level, $r_mmr;

    /* Update document with additional relevant data */
    //Team MMR
    $parse['mmr'] = [
        '0' => [
            'old' => [
                'rating' => $mmrcalc['team_ratings']['initial'][0]
            ],
            'new' => [
                'rating' => $mmrcalc['team_ratings']['final'][0]
            ]
        ],
        '1' => [
            'old' => [
                'rating' => $mmrcalc['team_ratings']['initial'][1]
            ],
            'new' => [
                'rating' => $mmrcalc['team_ratings']['final'][1]
            ]
        ],
        'quality' => $mmrcalc['match_quality']
    ];

    //Map mapping
    $parse['map'] = $mapMapping;

    //Player MMR && Hero Name mappings
    foreach ($parse['players'] as &$player) {
        $player['hero'] = $heroNameMappings[$player['hero']];

        $player['mmr'] = [
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
        $r_type = $parse['type'];
        $r_map = $parse['map'];
        $r_date = $parse['date'];
        $r_match_length = $parse['match_length'];
        $r_version = $parse['version'];
        $r_region = $parse['region'];
        $r_winner = $parse['winner'];
        $r_players = json_encode($parse['players']);
        $r_bans = json_encode($parse['bans']);
        $r_team_level = json_encode($parse['team_level']);
        $r_mmr = json_encode($parse['mmr']);

        $db->execute("InsertMatch");

        echo "Inserted Match #" . $r_id . " into 'matches'...".E;

        $ret = true;
    }
    catch (\Exception $e) {
        $ret = false;
    }

    if ($ret) {
        return ['match' => $parse, $r_id];
    }
    else {
        return FALSE;
    }
}

/*
 * Updates the 'players' collection with all relevant player data
 * Returns TRUE on complete success, FALSE if any errors occurred
 */
//TODO Fully implement updatePlayers
function updatePlayers(&$match, $seasonid, &$new_mmrs) {
    global $db, $r_player_id, $r_name, $r_tag, $r_region, $r_account_level;

    try {
        foreach ($match['players'] as $player) {
            //+=:players
            $r_player_id = $player['id'];
            $r_name = $player['name'];
            $r_tag = $player['tag'];
            $r_region = $match['region'];
            $r_account_level = $player['account_level'];

            $db->execute("+=:players");

            //TODO Continue implementation of +=: players_* tables, check the schema document for what tables are left
        }

        echo "Upserted ".count($match['players'])." players into various player tables...".E;

        $ret = true;
    }
    catch (\Exception $e) {
        $ret = false;
    }

    return $ret;
}

/*
 * Updates the 'heroes' collection with all relevant hero data
 * Returns TRUE on complete success, FALSE if any errors occurred
 */
//TODO fully implement updateHeroes
function updateHeroes(&$match, &$bannedHeroes) {

}



//Begin main script
echo '--------------------------------------'.E
    .'Replay process <<PARSE>> has started'.E
    .'--------------------------------------'.E;

//Look for replays to parse and handle
while (true) {
    //Check for unlocked failed replay parses
    $r_status = HotstatusPipeline::REPLAY_STATUS_PARSING;
    $r_timestamp = time() - UNLOCK_PARSING_DURATION;
    $result = $db->execute("SelectNextReplayWithStatus-Unlocked");
    $resrows = $db->countResultRows($result);
    if ($resrows > 0) {
        //Found a failed replay parse, reset it to downloaded
        $row = $db->fetchArray($result);

        echo 'Found a failed replay parse at replay #' . $row['id'] . ', resetting status to \'' . HotstatusPipeline::REPLAY_STATUS_DOWNLOADED . '\'...'.E;

        $r_id = $row['id'];
        $r_status = HotstatusPipeline::REPLAY_STATUS_DOWNLOADED;
        $r_timestamp = time();

        $db->execute("UpdateReplayStatus");
    }
    else {
        //No replay parsing has previously failed, look for an unlocked downloaded replay to parse
        $r_status = HotstatusPipeline::REPLAY_STATUS_DOWNLOADED;
        $r_timestamp = time() - UNLOCK_DEFAULT_DURATION;
        $result2 = $db->execute("SelectNextReplayWithStatus-Unlocked");
        $resrows2 = $db->countResultRows($result2);
        if ($resrows2 > 0) {
            //Found an unlocked downloaded replay, parse it
            $row = $db->fetchArray($result2);

            $r_id = $row['id'];
            $r_status = HotstatusPipeline::REPLAY_STATUS_PARSING;
            $r_timestamp = time();

            $db->execute("UpdateReplayStatus");

            echo 'Parsing replay #' . $r_id . '...                                       '.E;

            $r_filepath = $row['file'];
            $r_fingerprint = $row['fingerprint'];

            $parse = ReplayParser::ParseReplay(__DIR__, $r_filepath, $linux);

            $createReplayCopy = FALSE;

            //Check if parse was a success
            if (!key_exists('error', $parse)) {
                /* Collect player mmrs and calculate new mmr for match season */
                $seasonid = HotstatusPipeline::getSeasonStringForDateTime($parse['date']);
                $matchtype = $parse['type'];

                $team0rank = ($parse['winner'] === 0) ? (1) : (2);
                $team1rank = ($parse['winner'] === 1) ? (1) : (2);

                //Get old mmrs if any
                $player_old_mmrs = [
                    "team0" => [],
                    "team1" => []
                ];
                foreach ($parse['players'] as $player) {
                    $r_player_id = $player['id'];
                    $r_season = $seasonid;
                    $r_gameType = $matchtype;

                    $mmr_result = $db->execute("GetMMRForPlayer");
                    $mmr_result_rows = $db->countResultRows($mmr_result);
                    if ($mmr_result_rows > 0) {
                        //Found player mmr
                        $obj = $db->fetchArray($mmr_result);

                        $mmr = [
                            'rating' => $obj['rating'],
                            'mu' => $obj['mu'],
                            'sigma' => $obj['sigma']
                        ];

                        $player_old_mmrs['team'.$player['team']][$player['id'].""] = $mmr;
                    }
                    else {
                        //Did not find an mmr for player
                        $mmr = [
                            'rating' => "?",
                            'mu' => "?",
                            'sigma' => "?"
                        ];

                        $player_old_mmrs['team'.$player['team']][$player['id'].""] = $mmr;
                    }
                }

                //Calculate new mmrs
                echo 'Calculating MMR...'.E;
                $calc = MMRCalculator::Calculate(__DIR__, $team0rank, $team1rank, $player_old_mmrs, $linux);

                //Check if mmr calculation was a success
                if (!key_exists('error', $calc)) {
                    //Collect new player mmrs
                    $player_new_mmrs = [
                        "team0" => [],
                        "team1" => []
                    ];
                    foreach ($parse['players'] as $player) {
                        $obj = $calc['players'][$player['id'].""];

                        $mmr = [
                            'rating' => $obj['mmr'],
                            'mu' => $obj['mu'],
                            'sigma' => $obj['sigma']
                        ];

                        $player_new_mmrs['team'.$player['team']][$player['id'].""] = $mmr;
                    }

                    //Collect mapping of ban attributes to hero names
                    $bannedHeroes = [];
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

                    //Translation invalidation flag
                    $translationInvalidateMatch = FALSE;

                    //Collect mapping of hero names, translated if necessary
                    $heroNameMappings = [];
                    $invalidHeroNames = [];
                    foreach ($parse['players'] as $player) {
                        $r_name = $player['hero'];
                        $r_name_translation = $player['hero'];

                        $result3 = $db->execute("GetHeroNameFromHeroNameTranslation");
                        $resrows3 = $db->countResultRows($result3);
                        if ($resrows3 > 0) {
                            //This name needs to be translated
                            $row2 = $db->fetchArray($result3);
                            $r_name = $row2['name'];
                        }

                        $db->freeResult($result3);

                        $result3 = $db->execute("DoesHeroNameExist");
                        $resrows3 = $db->countResultRows($result3);
                        if ($resrows3 > 0) {
                            $row2 = $db->fetchArray($result3);

                            $heroNameMappings[$player['hero']] = $r_name;
                        }
                        else {
                            //Hero name is not valid
                            $invalidHeroNames[] = $r_name;
                            $translationInvalidateMatch = TRUE;
                        }

                        $db->freeResult($result3);
                    }

                    //Collect mapping of maps, translated if necessary
                    $mapMapping = $parse['map']; //Default Value
                    $invalidMapName = null;

                    $r_name = $parse['map'];
                    $r_name_translation = $parse['map'];

                    $result3 = $db->execute("GetMapNameFromMapNameTranslation");
                    $resrows3 = $db->countResultRows($result3);
                    if ($resrows3 > 0) {
                        //This name needs to be translated
                        $row2 = $db->fetchArray($result3);
                        $r_name = $row2['name'];
                    }

                    $db->freeResult($result3);

                    $result3 = $db->execute("DoesMapNameExist");
                    $resrows3 = $db->countResultRows($result3);
                    if ($resrows3 > 0) {
                        $row2 = $db->fetchArray($result3);

                        $mapMapping = $r_name;
                    }
                    else {
                        //Map name is not valid
                        $invalidMapName = $r_name;
                        $translationInvalidateMatch = TRUE;
                    }

                    $db->freeResult($result3);

                    if ($translationInvalidateMatch === FALSE) {
                        //No translation error, add all relevant match data to database
                        $insertResult = insertMatch($parse, $mapMapping, $heroNameMappings, $calc, $player_old_mmrs, $player_new_mmrs);

                        if ($insertResult === FALSE) {
                            //Error parsing match and inserting into 'matches', cancel parsing
                            //Copy local file into replay error directory for debugging purposes
                            $createReplayCopy = TRUE;

                            //Flag replay match status as 'mysql_match_write_error'
                            $r_id = $row['id'];
                            $r_status = HotstatusPipeline::REPLAY_STATUS_MYSQL_MATCH_WRITE_ERROR;
                            $r_timestamp = time();
                            $r_error = "Couldn't insert into 'matches'";

                            $db->execute("UpdateReplayStatusError");

                            $sleep->add(MYSQL_ERROR_SLEEP_DURATION);
                        }
                        else {
                            //No error parsing match, continue with upserting of players, heroes
                            $success_players = false;
                            $success_heroes = false;

                            //Players
                            $success_players = updatePlayers($insertResult['match'], $seasonid, $player_new_mmrs);
                            //Heroes
                            $success_heroes = updateHeroes($insertResult['match'], $bannedHeroes);

                            $hadError = !$success_players || !$success_heroes;

                            $errorstr = "";
                            if (!$success_players) $errorstr .= "players";
                            if (!$success_heroes) $errorstr .= ", heroes";

                            if (!$hadError) {
                                //Flag replay as fully parsed
                                $r_id = $row['id'];
                                $r_match_id = $insertResult['match_id'];
                                $r_status = HotstatusPipeline::REPLAY_STATUS_PARSED;
                                $r_timestamp = time();

                                $db->execute("UpdateReplayParsed");

                                echo 'Successfully parsed replay #' . $r_id . '...' . E . E;
                            }
                            else {
                                //Copy local file into replay error directory for debugging purposes
                                $createReplayCopy = TRUE;

                                //Flag replay as partially parsed with mysql_matchdata_write_error
                                $r_id = $row['id'];
                                $r_match_id = $insertResult['match_id'];
                                $r_status = HotstatusPipeline::REPLAY_STATUS_MYSQL_MATCHDATA_WRITE_ERROR;
                                $r_timestamp = time();
                                $r_error = "Semi-Parsed, Missed: " . $errorstr;

                                $db->execute("UpdateReplayParsedError");

                                echo 'Semi-Successfully parsed replay #' . $r_id . '. Mysql had trouble with portions of : ' . $errorstr . '...' . E . E;
                            }
                        }
                    }
                    else {
                        //Map or Hero names could not be translated, set error status and describe error
                        //Copy local file into replay error directory for debugging purposes
                        $createReplayCopy = TRUE;

                        //Compile translation error string
                        $tstr = "";
                        if ($invalidMapName !== NULL) $tstr .= "Map: [" . $invalidMapName . "]";

                        $invcount = count($invalidHeroNames);

                        if ($invcount > 0) {
                            $tstr .= " , Heroes: [";

                            $i = 0;
                            foreach ($invalidHeroNames as $invalidhero) {
                                $tstr .= $invalidhero;

                                if ($i < $invcount - 1) {
                                    $tstr .= ",";
                                }

                                $i++;
                            }

                            $tstr .= "]";
                        }

                        //Flag replay match status as 'parse_translate_error'
                        $r_id = $row['id'];
                        $r_status = HotstatusPipeline::REPLAY_STATUS_PARSE_TRANSLATE_ERROR;
                        $r_timestamp = time();
                        $r_error = $tstr;

                        $db->execute("UpdateReplayStatusError");

                        $sleep->add(MONGODB_ERROR_SLEEP_DURATION);
                    }
                }
                else {
                    //Copy local file into replay error directory for debugging purposes
                    $createReplayCopy = TRUE;

                    //Encountered an error parsing replay, output it, and flag replay as 'parse_mmr_error'
                    $r_id = $row['id'];
                    $r_status = HotstatusPipeline::REPLAY_STATUS_PARSE_MMR_ERROR;
                    $r_timestamp = time();
                    $r_error = $calc['error'];

                    $db->execute("UpdateReplayStatusError");

                    echo 'Failed to calculate mmr for replay #' . $r_id . ', Error : "' . $calc['error'] . '"...'.E.E;

                    $sleep->add(MINI_SLEEP_DURATION);
                }
            }
            else {
                //Copy local file into replay error directory for debugging purposes
                $createReplayCopy = TRUE;

                //Encountered an error parsing replay, output it and flag replay as 'parse_replay_error'
                $r_id = $row['id'];
                $r_status = HotstatusPipeline::REPLAY_STATUS_PARSE_REPLAY_ERROR;
                $r_timestamp = time();
                $r_error = $parse['error'];

                $db->execute("UpdateReplayStatusError");

                echo 'Failed to parse replay #' . $r_id . ', Error : "' . $parse['error'] . '"...'.E.E;

                $sleep->add(MINI_SLEEP_DURATION);
            }

            if ($createReplayCopy) {
                //Copy local file into replay error directory for debugging purposes
                if (file_exists($r_filepath)) {
                    $errordir = __DIR__ . '/' . HotstatusPipeline::REPLAY_DOWNLOAD_DIRECTORY_ERROR;

                    FileHandling::ensureDirectory($errordir);

                    $newfilepath = $errordir . $r_fingerprint . HotstatusPipeline::REPLAY_DOWNLOAD_EXTENSION;
                    FileHandling::copyFile($r_filepath, $newfilepath);
                }
            }

            //Delete local file
            if (file_exists($r_filepath)) {
                FileHandling::deleteAllFilesMatchingPattern($r_filepath);
            }
        }
        else {
            //No unlocked downloaded replays to parse, sleep
            $dots = $console->animateDotDotDot();
            echo "No unlocked downloaded replays found$dots                           \r";

            $sleep->add(SLEEP_DURATION);
        }

        $db->freeResult($result2);
    }

    $db->freeResult($result);

    $sleep->execute();
}

?>