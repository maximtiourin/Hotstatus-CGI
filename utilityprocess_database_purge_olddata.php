<?php
/*
 * Utility Process Purge Old Data
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

//Mininum cutoff Date Exclusive for replays to purge
$replaymindate = HotstatusPipeline::$SEASONS["2017 Season 3"]["start"]; //Season 3 Start

//Purge old data
$db->transaction_begin();
try {
    $db->query("DELETE FROM `heroes_bans_recent_granular` WHERE `date_end` < \"$replaymindate\"");
    $db->query("DELETE FROM `heroes_matches_recent_granular` WHERE `date_end` < \"$replaymindate\"");
    $db->query("DELETE FROM `matches` WHERE `date` < \"$replaymindate\"");
    $db->query("DELETE FROM `players_matches` WHERE `date` < \"$replaymindate\"");
    $db->query("DELETE FROM `players_matches_recent_granular` WHERE `date_end` < \"$replaymindate\"");
    $db->query("DELETE FROM `players_mmr` WHERE `season` = \"2017 Season 2\"");

    $db->transaction_commit();
}
catch (\Exception $e) {
    $db->transaction_rollback();
}

$db->close();

?>