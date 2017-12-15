<?php
/*
 * Replay Process Find
 * In charge of checking hotsapi for unseen replays and inserting initial entries into the 'replays' table then queueing them.
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
const PROCESS_GRANULARITY = 1; //1 seconds
const E = PHP_EOL;
$out_of_replays_count = 0; //Count how many times we ran out of replays to process, once reaching limit it means the api isn't bugging out and there actually is no more replays.
$sleep = new SleepHandler();

//SETTINGS
const SYNC_DOWNLOADED_REPLAYS = FALSE;

//Prepare statements
$db->prepare("CountDownloadedReplaysUpToLimit",
"SELECT COUNT(`id`) AS `count` FROM `replays` WHERE `status` = " . HotstatusPipeline::REPLAY_STATUS_DOWNLOADED . " LIMIT ".HotstatusPipeline::REPLAY_DOWNLOAD_LIMIT);

$db->prepare("CountReplaysOfStatus", "SELECT COUNT(`id`) AS `count` FROM `replays` WHERE `status` = ?");
$db->bind("CountReplaysOfStatus", "i", $r_status);

$db->prepare("set_semaphore_replays_downloaded",
    "UPDATE `pipeline_semaphores` SET `value` = ? WHERE `name` = \"replays_downloaded\" LIMIT 1");
$db->bind("set_semaphore_replays_downloaded", "i", $r_replays_downloaded);

$db->prepare("get_stat_int",
    "SELECT `val_int` FROM `pipeline_analytics` WHERE `key_name` = ? LIMIT 1");
$db->bind("get_stat_int", "s", $r_key_name);

$db->prepare("set_stat_int",
    "UPDATE `pipeline_analytics` SET `val_int` = ? WHERE `key_name` = ? LIMIT 1");
$db->bind("set_stat_int", "is", $r_val_int, $r_key_name);

//Helper Functions
function log($str) {
    $datetime = new \DateTime("now");
    $datestr = $datetime->format(HotstatusPipeline::FORMAT_DATETIME);
    echo "[$datestr] $str".E;
}

function getCountResult($key) {
    global $db;

    $countResult = $db->execute($key);
    $count = $db->fetchArray($countResult)['count'];
    $db->freeResult($countResult);
    return $count;
}

function getStatInt($key) {
    global $db, $r_key_name;

    $val = 0;

    $r_key_name = $key;
    $stat_result = $db->execute("get_stat_int");
    $stat_result_rows = $db->countResultRows($stat_result);
    if ($stat_result_rows > 0) {
        $row = $db->fetchArray($stat_result);

        $val = $row['val_int'];
    }
    $db->freeResult($stat_result);

    return $val;
}

function setStatInt($key, $val) {
    global $db, $r_key_name, $r_val_int;

    $r_key_name = $key;
    $r_val_int = $val;

    $db->execute("set_stat_int");
}

function trackStatDifference($getkey, $setkey, &$stat) {
    $newstat = getStatInt($getkey);
    $statdiff = $newstat - $stat;
    setStatInt($setkey, $statdiff);
    $stat = $newstat;
    return $statdiff;
}

//Stats Tracking
$stat_replays_processed_total = 0;
$stat_replays_errors_total = 0;
$stat_cache_requests_updated_total = 0;

//Begin main script
echo '--------------------------------------'.E
    .'Replay process <<SYNC>> has started'.E
    .'--------------------------------------'.E;

//Sync
while (true) {
    //Per 10 Minutes
    if (time() % 600 == 0) {
        //Count replays_queued_total
        $r_status = 1;
        $d = getCountResult("CountReplaysOfStatus");
        setStatInt("replays_queued_total", $d);
        log("Sync: Replays Queued: $d");

        //Count replays_outofdate_total
        $r_status = 16;
        $d = getCountResult("CountReplaysOfStatus");
        setStatInt("replays_outofdate_total", $d);
        log("Sync: Replays Out-of-Date: $d");
    }

    //Per Minute
    if (time() % 60 == 0) {
        //stats_replays_processed_per_minute
        $d = trackStatDifference("replays_processed_total", "replays_processed_per_minute", $stat_replays_processed_total);
        log("Stat: Replays Processed - Per Minute: $d");

        //stats_replays_errors_per_minute
        $d = trackStatDifference("replays_errors_total", "replays_errors_per_minute", $stat_replays_errors_total);
        log("Stat: Replays Errors - Per Minute: $d");

        //stats_cache_requests_updated_per_minute
        $d = trackStatDifference("cache_requests_updated_total", "cache_requests_updated_per_minute", $stat_cache_requests_updated_total);
        log("Stat: Cache Requests Updated - Per Minute: $d");
    }

    //Per 30 Seconds
    if (time() % 30 == 0) {
        //Count Downloaded Replays Up To Limit
        if (SYNC_DOWNLOADED_REPLAYS) {
            $r_replays_downloaded = getCountResult("CountDownloadedReplaysUpToLimit");
            $db->execute("set_semaphore_replays_downloaded");
            log("Sync: Semaphore - Replays Downloaded");
        }
    }

    $sleep->add(PROCESS_GRANULARITY);

    $sleep->execute();
}

?>