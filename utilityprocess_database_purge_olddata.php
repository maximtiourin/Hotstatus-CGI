<?php
/*
 * Utility Process Purge Old Data
 */

namespace Fizzik;

require_once 'includes/include.php';

use Fizzik\Database\MySqlDatabase;
use Fizzik\Utility\SleepHandler;

set_time_limit(0);
date_default_timezone_set(HotstatusPipeline::REPLAY_TIMEZONE);

$db = new MysqlDatabase();
$creds = Credentials::getCredentialsForUser(Credentials::USER_REPLAYPROCESS);
HotstatusPipeline::hotstatus_mysql_connect($db, $creds);
$db->setEncoding(HotstatusPipeline::DATABASE_CHARSET);

//Constants and qol
const E = PHP_EOL;
const SLEEP_DURATION = 1;
$sleep = new SleepHandler();

//Helper Functions
function log($str) {
    $datetime = new \DateTime("now");
    $datestr = $datetime->format(HotstatusPipeline::FORMAT_DATETIME);
    echo "[$datestr] $str".E;
}

//Mininum Date Inclusive for replays to process
//$replaymindate = HotstatusPipeline::$SEASONS[HotstatusPipeline::SEASON_UNKNOWN]["end"];

$replaymindate = "2018-03-01 00:00:00";

//Purge old data
//$db->transaction_begin();

$db->prepare("delete_heroes_bans", "DELETE FROM `heroes_bans_recent_granular` WHERE `date_end` < \"$replaymindate\" ORDER BY `date_end` ASC LIMIT 1000");
$db->prepare("delete_heroes_matches", "DELETE FROM `heroes_matches_recent_granular` WHERE `date_end` < \"$replaymindate\" ORDER BY `date_end` ASC LIMIT 1000");


while (TRUE) {
    $db->execute("delete_heroes_bans");
    $db->execute("delete_heroes_matches");
    log("Purged Heroes data older than [$replaymindate].");
    //$db->query("DELETE FROM `matches` WHERE `date` < \"$replaymindate\"");
    //$db->query("DELETE FROM `players_matches` WHERE `date` < \"$replaymindate\"");
    //$db->query("DELETE FROM `players_matches_recent_granular` WHERE `date_end` < \"$replaymindate\"");
    //$db->query("DELETE FROM `players_mmr` WHERE `season` = \"2017 Season 2\"");
    $sleep->add(SLEEP_DURATION);

    $sleep->execute();
}

?>