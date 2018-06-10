<?php
/*
 * Replay Process Find
 * In charge of checking hotsapi for unseen replays and inserting initial entries into the 'replays' table then queueing them.
 */

namespace Fizzik;

require_once 'lib/AWS/aws-autoloader.php';
require_once 'includes/include.php';

use Aws\CloudWatch\CloudWatchClient;
use Aws\Exception\AwsException;
use Fizzik\Database\MySqlDatabase;
use Fizzik\Utility\SleepHandler;

set_time_limit(0);
date_default_timezone_set(HotstatusPipeline::REPLAY_TIMEZONE);

$db = new MysqlDatabase();
$creds = Credentials::getCredentialsForUser(Credentials::USER_REPLAYPROCESS);
HotstatusPipeline::hotstatus_mysql_connect($db, $creds);
$db->setEncoding(HotstatusPipeline::DATABASE_CHARSET);

//Aws
$awsCreds = new \Aws\Credentials\Credentials($creds[Credentials::KEY_AWS_KEY], $creds[Credentials::KEY_AWS_SECRET]);
$sdk_ireland = new \Aws\Sdk([
    'region' => $creds[Credentials::KEY_AWS_REPLAYREGION],
    'version' => 'latest',
    'credentials' => $awsCreds
]);
$cloudwatch_ireland = $sdk_ireland->createCloudWatch();

//Constants and qol
const INSTANCE_QUIET_PURGE_AGE = 1200; //20 minutes
const PROCESS_GRANULARITY = 1; //1 seconds
const E = PHP_EOL;
$out_of_replays_count = 0; //Count how many times we ran out of replays to process, once reaching limit it means the api isn't bugging out and there actually is no more replays.
$sleep = new SleepHandler();

//SETTINGS
const SYNC_DOWNLOADED_REPLAYS = FALSE;

//Prepare statements
$db->prepare("GetPipelineConfig",
    "SELECT `max_replay_date` FROM `pipeline_config` WHERE `id` = ? LIMIT 1");
$db->bind("GetPipelineConfig", "i", $r_pipeline_config_id);

$db->prepare("CountDownloadedReplaysUpToLimit",
"SELECT COUNT(`id`) AS `count` FROM `replays` WHERE `status` = " . HotstatusPipeline::REPLAY_STATUS_DOWNLOADED . " LIMIT ".HotstatusPipeline::REPLAY_DOWNLOAD_LIMIT);

$db->prepare("CountReplaysOfStatus", "SELECT COUNT(`id`) AS `count` FROM `replays` WHERE `status` = ? AND `match_date` < ?");
$db->bind("CountReplaysOfStatus", "is", $r_status, $r_date_cutoff);

$db->prepare("set_semaphore_replays_downloaded",
    "UPDATE `pipeline_semaphores` SET `value` = ? WHERE `name` = \"replays_downloaded\" LIMIT 1");
$db->bind("set_semaphore_replays_downloaded", "i", $r_replays_downloaded);

$db->prepare("get_stat_int",
    "SELECT `val_int` FROM `pipeline_analytics` WHERE `key_name` = ? LIMIT 1");
$db->bind("get_stat_int", "s", $r_key_name);

$db->prepare("set_stat_int",
    "UPDATE `pipeline_analytics` SET `val_int` = ? WHERE `key_name` = ? LIMIT 1");
$db->bind("set_stat_int", "is", $r_val_int, $r_key_name);

$db->prepare("get_var_int",
    "SELECT `val_int` FROM `pipeline_variables` WHERE `key_name` = ? LIMIT 1");
$db->bind("get_var_int", "s", $r_key_name);

$db->prepare("set_var_int",
    "UPDATE `pipeline_variables` SET `val_int` = ? WHERE `key_name` = ? LIMIT 1");
$db->bind("set_var_int", "is", $r_val_int, $r_key_name);

$db->prepare("get_var_string",
    "SELECT `val_string` FROM `pipeline_variables` WHERE `key_name` = ? LIMIT 1");
$db->bind("get_var_string", "s", $r_key_name);

$db->prepare("set_var_string",
    "UPDATE `pipeline_variables` SET `val_string` = ? WHERE `key_name` = ? LIMIT 1");
$db->bind("set_var_string", "ss", $r_val_string, $r_key_name);

$db->prepare("CountInstances",
    "SELECT COUNT(`id`) AS `count` FROM `pipeline_instances`");

$db->prepare("CountInstancesOfType",
    "SELECT COUNT(`id`) AS `count` FROM `pipeline_instances` WHERE `type` = ?");
$db->bind("CountInstancesOfType", "s", $r_instance_type);

$db->prepare("CountInstancesOfTypeState",
    "SELECT COUNT(`id`) AS `count` FROM `pipeline_instances` WHERE `type` = ? AND `state` = ?");
$db->bind("CountInstancesOfTypeState", "si", $r_instance_type, $r_instance_state);

$db->prepare("PurgeQuietInstances",
    "DELETE FROM `pipeline_instances` WHERE `lastused` < ?");
$db->bind("PurgeQuietInstances", "i", $r_instance_lastused);

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

function getVarInt($key) {
    global $db, $r_key_name;

    $val = 0;

    $r_key_name = $key;
    $var_result = $db->execute("get_var_int");
    $var_result_rows = $db->countResultRows($var_result);
    if ($var_result_rows > 0) {
        $row = $db->fetchArray($var_result);

        $val = $row['val_int'];
    }
    $db->freeResult($var_result);

    return $val;
}

function setVarInt($key, $val) {
    global $db, $r_key_name, $r_val_int;

    $r_key_name = $key;
    $r_val_int = $val;

    $db->execute("set_var_int");
}

function getVarString($key) {
    global $db, $r_key_name;

    $val = "";

    $r_key_name = $key;
    $var_result = $db->execute("get_var_string");
    $var_result_rows = $db->countResultRows($var_result);
    if ($var_result_rows > 0) {
        $row = $db->fetchArray($var_result);

        $val = $row['val_string'];
    }
    $db->freeResult($var_result);

    return $val;
}

function setVarString($key, $val) {
    global $db, $r_key_name, $r_val_string;

    $r_key_name = $key;
    $r_val_string = $val;

    $db->execute("set_var_string");
}

function trackStatDifference($getkey, $setkey, &$stat) {
    $newstat = getStatInt($getkey);
    $statdiff = $newstat - $stat;
    setStatInt($setkey, $statdiff);
    $stat = $newstat;
    return $statdiff;
}

function putCloudWatchMetric(CloudWatchClient &$cloudwatch, $namespace, $metric, $timestamp, $value, $unit, $log = false) {
    try {
        $result = $cloudwatch->putMetricData([
            "Namespace" => $namespace,
            "MetricData" => [
                [
                    "MetricName" => $metric,
                    "Timestamp" => $timestamp,
                    "Value" => $value,
                    "Unit" => $unit,
                ],
            ],
        ]);
        if ($log) var_dump($result);
    }
    catch (AwsException $e) {
        if ($log) echo $e->getMessage() . E;
    }
}

function execInBackground($cmd) {
    if (substr(php_uname(), 0, 7) == "Windows"){
        pclose(popen("start /B ". $cmd, "r"));
    }
    else {
        exec($cmd . " > /dev/null &");
    }
}

/**
 * Execute the given command by displaying console output live to the user.
 *  @param  string  cmd          :  command to be executed
 *  @return array   exit_status  :  exit status of the executed command
 *                  output       :  console output of the executed command
 */
function liveExecuteCommand($cmd) {
    while (@ ob_end_flush()); // end all output buffers if any

    $proc = popen("$cmd 2>&1 ; echo Exit status : $?", 'r');

    $live_output     = "";
    $complete_output = "";

    while (!feof($proc))
    {
        $live_output     = fread($proc, 4096);
        $complete_output = $complete_output . $live_output;
        echo "$live_output";
        @ flush();
    }

    pclose($proc);

    // get exit status
    preg_match('/[0-9]+$/', $complete_output, $matches);

    // return exit status and intended output
    return array (
        'exit_status'  => intval($matches[0]),
        'output'       => str_replace("Exit status : " . $matches[0], '', $complete_output)
    );
}

//Stats Tracking
$stat_replays_processed_total = 0;
$stat_replays_errors_total = 0;
$stat_replays_reparsed_total = 0;
$stat_replays_reparsed_errors_total = 0;
$stat_cache_requests_updated_total = 0;
$stat_cache_writes_updated_total = 0;

//Begin main script
echo '--------------------------------------'.E
    .'Replay process <<SYNC>> has started'.E
    .'--------------------------------------'.E;

//Sync
while (true) {
    //Per 10 Minutes
    if (time() % 600 == 0) {
        //Count replays_outofdate_total
        $r_status = 16;
        $d = getCountResult("CountReplaysOfStatus");
        setStatInt("replays_outofdate_total", $d);
        //log("Sync: Replays Out-of-Date: $d");
    }

    //Per Minute
    if (time() % 60 == 0) {
        //Purge quiet instances
        $r_instance_lastused = time() - INSTANCE_QUIET_PURGE_AGE;
        $db->execute("PurgeQuietInstances");

        //Get pipeline configuration
        $r_pipeline_config_id = HotstatusPipeline::$pipeline_config[HotstatusPipeline::PIPELINE_CONFIG_DEFAULT]['id'];
        $pipeconfigresult = $db->execute("GetPipelineConfig");
        $pipeconfigresrows = $db->countResultRows($pipeconfigresult);
        if ($pipeconfigresrows > 0) {
            $pipeconfig = $db->fetchArray($pipeconfigresult);

            $replaymaxdate = $pipeconfig['max_replay_date'];

            $db->freeResult($pipeconfigresult);

            //Count replays_queued_total
            $r_status = 1;
            $r_date_cutoff = $replaymaxdate;
            $d = getCountResult("CountReplaysOfStatus");
            setStatInt("replays_queued_total", $d);
            putCloudWatchMetric($cloudwatch_ireland, "Hotstatus", "Replays Queued", time(), $d, "Count", false);
            log("Sync: Replays Queued: $d");
        }

        //Scheduling: Cache Generation
        $cache_generate_enabled = getVarInt("cache_generate_enabled");
        if ($cache_generate_enabled === 1) {
            $cache_generate = getVarInt("cache_generate");
            if ($cache_generate === 0 || $cache_generate === 1) {
                $date = new \DateTime("now");

                $yearOfWeek = intval($date->format("o"));
                $weekOfYear = intval($date->format("W"));
                $dayOfWeek = intval($date->format("N"));

                $dayBeginDate = new \DateTime();
                $dayBeginDate->setISODate($yearOfWeek, $weekOfYear, $dayOfWeek);
                $dayBeginDate->setTime(7, 0, 0);

                $dayCutoffDate = new \DateTime();
                $dayCutoffDate->setISODate($yearOfWeek, $weekOfYear, $dayOfWeek);
                $dayCutoffDate->setTime(10, 0, 0);

                if ($date > $dayBeginDate && $date < $dayCutoffDate) {
                    if ($cache_generate === 1) {
                        //Reset Flag
                        setVarInt("cache_generate", 0);
                    }
                }
                elseif ($cache_generate === 0) {
                    //Process
                    //execInBackground("php cacheprocess_generate.php");
                    $execResult = liveExecuteCommand("php cacheprocess_generate.php");

                    $now = (new \DateTime("now"))->format(HotstatusPipeline::FORMAT_DATETIME);

                    if ($execResult['exit_status'] !== 0) {
                        log("ERROR: Cache Generation Failed.");

                        setVarString("cache_generate", "ERROR: $now");
                    }
                    else {
                        setVarString("cache_generate", $now);
                    }

                    setVarInt("cache_generate", 1);
                }
            }
        }

        //stats_replays_processed_per_minute
        $d = trackStatDifference("replays_processed_total", "replays_processed_per_minute", $stat_replays_processed_total);
        log("Stat: Replays Processed - Per Minute: $d");

        //stats_replays_errors_per_minute
        $d = trackStatDifference("replays_errors_total", "replays_errors_per_minute", $stat_replays_errors_total);
        log("Stat: Replays Errors - Per Minute: $d");

        //stats_replays_reparsed_per_minute
        $d = trackStatDifference("replays_reparsed_total", "replays_reparsed_per_minute", $stat_replays_reparsed_total);
        //log("Stat: Replays Reparsed - Per Minute: $d");

        //stats_replays_reparsed_errors_per_minute
        $d = trackStatDifference("replays_reparsed_errors_total", "replays_reparsed_errors_per_minute", $stat_replays_reparsed_errors_total);
        //log("Stat: Replays Reparsed Errors - Per Minute: $d");

        //stats_cache_requests_updated_per_minute
        $d = trackStatDifference("cache_requests_updated_total", "cache_requests_updated_per_minute", $stat_cache_requests_updated_total);
        //log("Stat: Cache Requests Updated - Per Minute: $d");
    }

    //Per 30 Seconds
    //if (time() % 30 == 0) {
        //Count Downloaded Replays Up To Limit
        /*if (SYNC_DOWNLOADED_REPLAYS) {
            $r_replays_downloaded = getCountResult("CountDownloadedReplaysUpToLimit");
            $db->execute("set_semaphore_replays_downloaded");
            log("Sync: Semaphore - Replays Downloaded");
        }*/
    //}

    //Per 15 Seconds
    if (time() % 15 == 0) {
        //Count online instances
        $d = getCountResult("CountInstances");
        setStatInt("instances_online", $d);
        log("Stat: Instances Online: $d");

        //
        // REPLAY FIND
        //
        $r_instance_type = "Replay Find";

        //Count online replay find
        $d = getCountResult("CountInstancesOfType");
        setStatInt("instances_replay_find_online", $d);
        log("Stat: Instances Replay Find Online: $d");

        //Count replay find state: Safe Shutoff
        $r_instance_state = HotstatusPipeline::INSTANCE_STATE_SAFESHUTOFF;
        $d = getCountResult("CountInstancesOfTypeState");
        setStatInt("instances_replay_find_s_safeshutoff", $d);
        //log("Stat: Instances Replay Find State (safeshutoff): $d");

        //Count replay find state: No Config
        $r_instance_state = HotstatusPipeline::INSTANCE_STATE_NOCONFIG;
        $d = getCountResult("CountInstancesOfTypeState");
        setStatInt("instances_replay_find_s_noconfig", $d);
        //log("Stat: Instances Replay Find State (noconfig): $d");

        //Count replay find state: Processing
        $r_instance_state = HotstatusPipeline::INSTANCE_STATE_PROCESSING;
        $d = getCountResult("CountInstancesOfTypeState");
        setStatInt("instances_replay_find_s_processing", $d);
        //log("Stat: Instances Replay Find State (processing): $d");

        //
        // CACHE UPDATE READ
        //
        $r_instance_type = "Cache Update Read";

        $d = getCountResult("CountInstancesOfType");
        setStatInt("instances_cache_update_read_online", $d);
        log("Stat: Instances Cache Update Read Online: $d");

        $r_instance_state = HotstatusPipeline::INSTANCE_STATE_SAFESHUTOFF;
        $d = getCountResult("CountInstancesOfTypeState");
        setStatInt("instances_cache_update_read_s_safeshutoff", $d);

        $r_instance_state = HotstatusPipeline::INSTANCE_STATE_PROCESSING;
        $d = getCountResult("CountInstancesOfTypeState");
        setStatInt("instances_cache_update_read_s_processing", $d);

        //
        // CACHE UPDATE WRITE
        //
        $r_instance_type = "Cache Update Write";

        $d = getCountResult("CountInstancesOfType");
        setStatInt("instances_cache_update_write_online", $d);
        log("Stat: Instances Cache Update Write Online: $d");

        $r_instance_state = HotstatusPipeline::INSTANCE_STATE_SAFESHUTOFF;
        $d = getCountResult("CountInstancesOfTypeState");
        setStatInt("instances_cache_update_write_s_safeshutoff", $d);

        $r_instance_state = HotstatusPipeline::INSTANCE_STATE_PROCESSING;
        $d = getCountResult("CountInstancesOfTypeState");
        setStatInt("instances_cache_update_write_s_processing", $d);

        //
        // REPLAY WORKERS
        //
        $r_instance_type = "Replay Worker";

        //Count online replay workers
        $d = getCountResult("CountInstancesOfType");
        setStatInt("instances_replay_workers_online", $d);
        log("Stat: Instances Replay Workers Online: $d");

        //Count replay workers state: Safe Shutoff
        $r_instance_state = HotstatusPipeline::INSTANCE_STATE_SAFESHUTOFF;
        $d = getCountResult("CountInstancesOfTypeState");
        setStatInt("instances_replay_workers_s_safeshutoff", $d);
        //log("Stat: Instances Replay Workers State (safeshutoff): $d");

        //Count replay workers state: No Config
        $r_instance_state = HotstatusPipeline::INSTANCE_STATE_NOCONFIG;
        $d = getCountResult("CountInstancesOfTypeState");
        setStatInt("instances_replay_workers_s_noconfig", $d);
        //log("Stat: Instances Replay Workers State (noconfig): $d");

        //Count replay workers state: Processing
        $r_instance_state = HotstatusPipeline::INSTANCE_STATE_PROCESSING;
        $d = getCountResult("CountInstancesOfTypeState");
        setStatInt("instances_replay_workers_s_processing", $d);
        //log("Stat: Instances Replay Workers State (processing): $d");
    }

    $sleep->add(PROCESS_GRANULARITY);

    $sleep->execute();
}

?>