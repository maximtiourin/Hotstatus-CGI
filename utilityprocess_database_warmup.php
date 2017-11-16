<?php

/*
 * Runs full table queries on the hotstatus mysql database to get tables into memory and warmup right after server spins up.
 */

namespace Fizzik;

require_once 'includes/include.php';

use Fizzik\Database\MySqlDatabase;

set_time_limit(0);
date_default_timezone_set(HotstatusPipeline::REPLAY_TIMEZONE);

$db = new MysqlDatabase();
$creds = Credentials::getCredentialsForUser(Credentials::USER_REPLAYPROCESS);
$db->connect($creds[Credentials::KEY_DB_HOSTNAME], $creds[Credentials::KEY_DB_USER], $creds[Credentials::KEY_DB_PASSWORD], $creds[Credentials::KEY_DB_DATABASE]);
$db->setEncoding(HotstatusPipeline::DATABASE_CHARSET);

//Constants and qol
const E = PHP_EOL;
$e = PHP_EOL;

//Tables
$tables = [
    "herodata_abilities",
    "herodata_awards",
    "herodata_heroes",
    "herodata_heroes_translations",
    "herodata_maps",
    "herodata_maps_translations",
    "herodata_talents",
    "heroes_bans_recent_granular",
    "heroes_builds",
    "heroes_matches_recent_granular",
    "matches",
    "players",
    "players_heroes",
    "players_matches",
    "players_matches_recent_granular",
    "players_matches_total",
    "players_mmr",
    "players_parties",
    "replays"
];

//Execute Statements
foreach ($tables as $table) {
    $result = $db->query("SELECT COUNT(*) as `count` FROM `$table`");
    $row = $db->fetchArray($result);
    $count = $row['count'];

    echo "Warmed ($table): $count rows.$e";

    $db->freeResult($result);
}