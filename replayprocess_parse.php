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
use Fizzik\Utility\AssocArray;

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
    "SELECT `name` FROM herodata_heroes WHERE `name` = ?");
$db->bind("DoesHeroNameExist", "s", $r_name);

$db->prepare("DoesMapNameExist",
    "SELECT `name` FROM herodata_maps WHERE `name` = ?");
$db->bind("DoesMapNameExist", "s", $r_name);

$db->prepare("GetHeroNameFromAttribute",
    "SELECT `name` FROM herodata_heroes WHERE name_attribute = ?");
$db->bind("GetHeroNameFromAttribute", "s", $r_name_attribute);

$db->prepare("GetHeroNameFromHeroNameTranslation",
    "SELECT `name` FROM herodata_heroes_translations WHERE name_translation = ?");
$db->bind("GetHeroNameFromHeroNameTranslation", "s", $r_name_translation);

$db->prepare("GetMapNameFromMapNameTranslation",
    "SELECT `name` FROM herodata_maps_translations WHERE name_translation = ?");
$db->bind("GetMapNameFromMapNameTranslation", "s", $r_name_translation);

$db->prepare("GetMMRForPlayer",
    "SELECT rating, mu, sigma FROM players_mmr WHERE id = ? AND season = ? AND gameType = ? FOR UPDATE");
$db->bind("GetMMRForPlayer", "iss", $r_player_id, $r_season, $r_gameType);

$db->prepare("InsertMatch",
    "INSERT INTO matches (id, type, map, date, match_length, version, region, winner, players, bans, team_level, mmr) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
$db->bind("InsertMatch", "isssisiissss", $r_id, $r_type, $r_map, $r_date, $r_match_length, $r_version, $r_region, $r_winner, $r_players, $r_bans, $r_team_level, $r_mmr);

$db->prepare("+=:players",
    "INSERT INTO players "
    . "(id, name, tag, region, account_level) "
    . "VALUES (?, ?, ?, ?, ?) "
    . "ON DUPLICATE KEY UPDATE "
    . "account_level = GREATEST(account_level, VALUES(account_level))");
$db->bind("+=:players",
    "isiii",
    $r_player_id, $r_name, $r_tag, $r_region, $r_account_level);

$db->prepare("+=:players_heroes",
    "INSERT INTO players_heroes "
    . "(id, hero, hero_level) "
    . "VALUES (?, ?, ?) "
    . "ON DUPLICATE KEY UPDATE "
    . "hero_level = GREATEST(hero_level, VALUES(hero_level))");
$db->bind("+=:players_heroes",
    "isi",
    $r_player_id, $r_hero, $r_hero_level);

$db->prepare("+=:players_matches",
    "INSERT INTO players_matches "
    . "(id, match_id, date) "
    . "VALUES (?, ?, ?)");
$db->bind("+=:players_matches",
    "iis",
    $r_player_id, $r_match_id, $r_date);

$db->prepare("??:players_matches_recent_granular",
    "SELECT * FROM players_matches_recent_granular WHERE id = ? AND year = ? AND week = ? AND day = ? AND hero = ? AND map = ? AND gameType = ? FOR UPDATE");
$db->bind("??:players_matches_recent_granular",
    "iiiisss", $r_player_id, $r_year, $r_week, $r_day, $r_hero, $r_map, $r_gameType);

$db->prepare("+=:players_matches_recent_granular",
    "INSERT INTO players_matches_recent_granular "
    . "(id, year, week, day, date_end, hero, gameType, map, played, won, time_played, stats_kills, stats_assists, stats_deaths, stats_siege_damage, stats_hero_damage, 
    stats_structure_damage, stats_healing, stats_damage_taken, stats_merc_camps, stats_exp_contrib, stats_best_killstreak, stats_time_spent_dead, medals, talents, 
    builds, parties) "
    . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) "
    . "ON DUPLICATE KEY UPDATE "
    . "date_end = VALUES(date_end), played = played + VALUES(played), won = won + VALUES(won), time_played = time_played + VALUES(time_played), 
    stats_kills = stats_kills + VALUES(stats_kills), stats_assists = stats_assists + VALUES(stats_assists), stats_deaths = stats_deaths + VALUES(stats_deaths), 
    stats_siege_damage = stats_siege_damage + VALUES(stats_siege_damage), stats_hero_damage = stats_hero_damage + VALUES(stats_hero_damage), 
    stats_structure_damage = stats_structure_damage + VALUES(stats_structure_damage), stats_healing = stats_healing + VALUES(stats_healing), 
    stats_damage_taken = stats_damage_taken + VALUES(stats_damage_taken), stats_merc_camps = stats_merc_camps + VALUES(stats_merc_camps), 
    stats_exp_contrib = stats_exp_contrib + VALUES(stats_exp_contrib), stats_best_killstreak = GREATEST(stats_best_killstreak, VALUES(stats_best_killstreak)), 
    stats_time_spent_dead = stats_time_spent_dead + VALUES(stats_time_spent_dead), medals = VALUES(medals), talents = VALUES(talents), builds = VALUES(builds), 
    parties = VALUES(parties)");
$db->bind("+=:players_matches_recent_granular",
    "iiiissssiiiiiiiiiiiiiiissss",
    $r_player_id, $r_year, $r_week, $r_day, $r_date_end, $r_hero, $r_gameType, $r_map, $r_played, $r_won, $r_time_played, $r_stats_kills, $r_stats_assists, $r_stats_deaths,
    $r_stats_siege_damage, $r_stats_hero_damage, $r_stats_structure_damage, $r_stats_healing, $r_stats_damage_taken, $r_stats_merc_camps, $r_stats_exp_contrib,
    $r_stats_best_killstreak, $r_stats_time_spent_dead, $r_medals, $r_talents, $r_builds, $r_parties);

$db->prepare("+=:players_matches_total",
    "INSERT INTO players_matches_total "
    . "(id, hero, gameType, map, played, won, time_played, time_played_silenced, medals) "
    . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) "
    . "ON DUPLICATE KEY UPDATE "
    . "played = played + VALUES(played), won = won + VALUES(won), time_played = time_played + VALUES(time_played), 
    time_played_silenced = time_played_silenced + VALUES(time_played_silenced), medals = VALUES(medals)");
$db->bind("+=:players_matches_total",
    "isssiiiis",
    $r_player_id, $r_hero, $r_gameType, $r_map, $r_played, $r_won, $r_time_played, $r_time_played_silenced, $r_medals);

$db->prepare("+=:players_mmr",
    "INSERT INTO players_mmr "
    . "(id, season, gameType, rating, mu, sigma) "
    . "VALUES (?, ?, ?, ?, ?, ?) "
    . "ON DUPLICATE KEY UPDATE "
    . "rating = VALUES(rating), mu = VALUES(mu), sigma = VALUES(sigma)");
$db->bind("+=:players_mmr",
    "issidd",
    $r_player_id, $r_season, $r_gameType, $r_rating, $r_mu, $r_sigma);

$db->prepare("??:heroes_matches_recent_granular",
    "SELECT * FROM heroes_matches_recent_granular WHERE hero = ? AND year = ? AND week = ? AND day = ? AND map = ? AND gameType = ? FOR UPDATE");
$db->bind("??:heroes_matches_recent_granular",
    "siiiss", $r_hero, $r_year, $r_week, $r_day, $r_map, $r_gameType);

$db->prepare("+=:heroes_matches_recent_granular",
    "INSERT INTO heroes_matches_recent_granular "
    . "(hero, year, week, day, date_end, gameType, map, played, won, banned, time_played, stats_kills, stats_assists, stats_deaths, stats_siege_damage, stats_hero_damage, 
    stats_structure_damage, stats_healing, stats_damage_taken, stats_merc_camps, stats_exp_contrib, stats_best_killstreak, stats_time_spent_dead, medals, talents, builds) "
    . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) "
    . "ON DUPLICATE KEY UPDATE "
    . "date_end = VALUES(date_end), played = played + VALUES(played), won = won + VALUES(won), banned = banned + VALUES(banned), time_played = time_played + VALUES(time_played), 
    stats_kills = stats_kills + VALUES(stats_kills), stats_assists = stats_assists + VALUES(stats_assists), stats_deaths = stats_deaths + VALUES(stats_deaths), 
    stats_siege_damage = stats_siege_damage + VALUES(stats_siege_damage), stats_hero_damage = stats_hero_damage + VALUES(stats_hero_damage), 
    stats_structure_damage = stats_structure_damage + VALUES(stats_structure_damage), stats_healing = stats_healing + VALUES(stats_healing), 
    stats_damage_taken = stats_damage_taken + VALUES(stats_damage_taken), stats_merc_camps = stats_merc_camps + VALUES(stats_merc_camps), 
    stats_exp_contrib = stats_exp_contrib + VALUES(stats_exp_contrib), stats_best_killstreak = GREATEST(stats_best_killstreak, VALUES(stats_best_killstreak)), 
    stats_time_spent_dead = stats_time_spent_dead + VALUES(stats_time_spent_dead), medals = VALUES(medals), talents = VALUES(talents), builds = VALUES(builds)");
$db->bind("+=:heroes_matches_recent_granular",
    "siiisssiiiiiiiiiiiiiiiisss",
    $r_hero, $r_year, $r_week, $r_day, $r_date_end, $r_gameType, $r_map, $r_played, $r_won, $r_banned, $r_time_played, $r_stats_kills, $r_stats_assists, $r_stats_deaths,
    $r_stats_siege_damage, $r_stats_hero_damage, $r_stats_structure_damage, $r_stats_healing, $r_stats_damage_taken, $r_stats_merc_camps, $r_stats_exp_contrib,
    $r_stats_best_killstreak, $r_stats_time_spent_dead, $r_medals, $r_talents, $r_builds);

$db->prepare("trackHeroBan",
    "INSERT INTO heroes_matches_recent_granular "
    . "(hero, year, week, day, date_end, gameType, map, banned, medals, talents, builds) "
    . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) "
    . "ON DUPLICATE KEY UPDATE "
    . "banned = banned + VALUES(banned)");
$db->bind("trackHeroBan",
    "siiisssisss",
    $r_hero, $r_year, $r_week, $r_day, $r_date_end, $r_gameType, $r_map, $r_banned, $r_medals, $r_talents, $r_builds);

$db->prepare("ensureTalentBuild",
    "INSERT INTO heroes_builds (hero, build, talents) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE hero = hero");
$db->bind("ensureTalentBuild", "sss", $r_hero, $r_build, $r_build_talents);

$db->prepare("ensurePlayerParty",
    "INSERT INTO players_parties (id, party, players) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE id = id");
$db->bind("ensurePlayerParty", "iss", $r_player_id, $r_party, $r_players);

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
    $parse['id'] = $r_id;

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
        return ['match' => $parse, 'match_id' => $r_id];
    }
    else {
        return FALSE;
    }
}

/*
 * Updates the all relevant player and hero tables and rows with data from the match
 * All Per Player/Hero updates are done as transactions, to prevent partial updates.
 * Returns TRUE on complete success, FALSE if any errors occurred
 */
function updatePlayersAndHeroes(&$match, $seasonid, &$new_mmrs, &$bannedHeroes) {
    global $db, $r_player_id, $r_name, $r_tag, $r_region, $r_account_level, $r_hero, $r_hero_level, $r_match_id, $r_date,
           $r_year, $r_week, $r_day, $r_date_end, $r_hero, $r_gameType, $r_map, $r_played, $r_won, $r_time_played,
           $r_stats_kills, $r_stats_assists, $r_stats_deaths, $r_stats_siege_damage, $r_stats_hero_damage, $r_stats_structure_damage,
           $r_stats_healing, $r_stats_damage_taken, $r_stats_merc_camps, $r_stats_exp_contrib, $r_stats_best_killstreak,
           $r_stats_time_spent_dead, $r_medals, $r_talents, $r_builds, $r_parties, $r_build, $r_build_talents, $r_party, $r_players, $r_time_played_silenced,
           $r_season, $r_rating, $r_mu, $r_sigma, $r_banned;

    $isodate = HotstatusPipeline::getISOYearWeekDayForDateTime($match['date']);

    try {
        foreach ($match['players'] as $player) {
            //Qol
            $winInc = ($player['team'] === $match['winner']) ? (1) : (0);
            $timeSilenced = ($player['silenced'] === 1) ? ($match['match_length']) : (0);
            $mmr = $new_mmrs['team'.$player['team']][$player['id'].""]; //The player's new mmr object
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


            //Set key params
            $r_year = $isodate['year'];
            $r_week = $isodate['week'];
            $r_day = $isodate['day'];
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

            /*
             * ??:heroes_matches_recent_granular
             * +=:heroes_matches_recent_granular
             */
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
            }
            else {
                $r_medals = json_encode($g_medals);
                $r_talents = json_encode($g_talents);
                $r_builds = json_encode($g_builds);
            }
            $db->freeResult($h_res);

            //Set main params
            $r_hero = $player['hero'];
            $r_banned = 0;

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
            $r_medals = "[]";
            $r_talents = "[]";
            $r_builds = "[]";

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
                //Begin Transaction
                $db->transaction_begin();

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
                    $db->freeResult($mmr_result);
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
                            $success_playersAndHeroes = updatePlayersAndHeroes($insertResult['match'], $seasonid, $player_new_mmrs, $bannedHeroes);

                            $hadError = !$success_playersAndHeroes;

                            $errorstr = "";
                            if (!$success_playersAndHeroes) $errorstr .= "Players, Heroes";

                            if (!$hadError) {
                                //Flag replay as fully parsed
                                $r_id = $row['id'];
                                $r_match_id = $insertResult['match_id'];
                                $r_status = HotstatusPipeline::REPLAY_STATUS_PARSED;
                                $r_timestamp = time();

                                $db->execute("UpdateReplayParsed");

                                //Commit Transaction
                                $db->transaction_commit();

                                echo 'Successfully parsed replay #' . $r_id . '...' . E . E;
                            }
                            else {
                                //Rollback Transaction
                                $db->transaction_rollback();

                                //Copy local file into replay error directory for debugging purposes
                                $createReplayCopy = TRUE;

                                //Flag replay as partially parsed with mysql_matchdata_write_error
                                $r_id = $row['id'];
                                $r_status = HotstatusPipeline::REPLAY_STATUS_MYSQL_MATCHDATA_WRITE_ERROR;
                                $r_timestamp = time();
                                $r_error = "Semi-Parsed, Missed: " . $errorstr;

                                $db->execute("UpdateReplayStatusError");

                                echo 'Could not successfully parsed replay #' . $r_id . '. Mysql had trouble with portions of : ' . $errorstr . '...' . E . E;
                            }
                        }
                    }
                    else {
                        //Rollback Transaction
                        $db->transaction_rollback();

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
                    //Rollback Transaction
                    $db->transaction_rollback();

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