<?php
/*
 * Replay Process Find
 * In charge of checking hotsapi for unseen replays and inserting initial entries into the 'replays' table then queueing them.
 */

namespace Fizzik;

require_once 'includes/include.php';
require_once 'includes/Hotsapi.php';

use Fizzik\Database\MySqlDatabase;
use Fizzik\Utility\SleepHandler;

set_time_limit(0);
date_default_timezone_set(HotstatusPipeline::REPLAY_TIMEZONE);

$db = new MysqlDatabase();
$creds = Credentials::getCredentialsForUser(Credentials::USER_REPLAYPROCESS);
HotstatusPipeline::hotstatus_mysql_connect($db, $creds);
$db->setEncoding(HotstatusPipeline::DATABASE_CHARSET);

//Constants and qol
const OUT_OF_REPLAYS_COUNT_LIMIT = 5; //5 occurence limit
const OUT_OF_REPLAYS_COUNT_DURATION = 60; //seconds
const OUT_OF_REPLAYS_SLEEP_DURATION = 900; //seconds
const NORMAL_EXECUTION_SLEEP_DURATION = 1000; //microseconds (1ms = 1000)
const UNKNOWN_ERROR_CODE = 300; //seconds
const TOO_MANY_REQUEST_SLEEP_DURATION = 30; //seconds
const REQUEST_SPREADOUT_SLEEP_DURATION = 1; //seconds
const SLEEP_DURATION = 5; //seconds
const MINI_SLEEP_DURATION = 1; //seconds
const E = PHP_EOL;
$out_of_replays_count = 0; //Count how many times we ran out of replays to process, once reaching limit it means the api isn't bugging out and there actually is no more replays.
$sleep = new SleepHandler();

//Prepare statements
$db->prepare("GetPipelineConfig",
    "SELECT `min_replay_date` FROM `pipeline_config` WHERE `id` = ? LIMIT 1");
$db->bind("GetPipelineConfig", "i", $r_pipeline_config_id);

$db->prepare("GetPipelineVariable",
    "SELECT * FROM `pipeline_variables` WHERE `key_name` = ? LIMIT 1");
$db->bind("GetPipelineVariable", "s", $r_key_name);

$db->prepare("SetReplaysLatestHotsApiId",
    "UPDATE `pipeline_variables` SET `val_int` = ? WHERE `key_name` = \"replays_latest_hotsapi_id\"");
$db->bind("SetReplaysLatestHotsApiId", "i", $r_val_int);

$db->prepare("InsertNewReplay", "INSERT INTO replays (hotsapi_id, hotsapi_page, hotsapi_idinpage, match_date, fingerprint, storage_id, status, storage_state, lastused) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
$db->bind("InsertNewReplay", "iiisssiii", $r_id, $r_page, $r_idinpage, $r_match_date, $r_fingerprint, $r_s3url, $r_status, $r_storage_state, $r_timestamp);

$db->prepare("GetExistingReplayWithHotsApiId",
    "SELECT `id` FROM `replays` WHERE `hotsapi_id` = ? LIMIT 1");
$db->bind("GetExistingReplayWithHotsApiId", "i", $r_id);

//Helper functions

function timestamp() {
    $datetime = new \DateTime("now");
    $datestr = $datetime->format(HotstatusPipeline::FORMAT_DATETIME);
    return "[$datestr] ";
}

//Begin main script
echo '--------------------------------------'.E
    .'Replay process <<FIND>> has started'.E
    .'--------------------------------------'.E;

//Look for replays to download and handle
while (true) {
    //Get pipeline configuration
    $r_pipeline_config_id = HotstatusPipeline::$pipeline_config[HotstatusPipeline::PIPELINE_CONFIG_DEFAULT]['id'];
    $pipeconfigresult = $db->execute("GetPipelineConfig");
    $pipeconfigresrows = $db->countResultRows($pipeconfigresult);
    if ($pipeconfigresrows > 0) {
        $pipeconfig = $db->fetchArray($pipeconfigresult);

        $replaymindate = $pipeconfig['min_replay_date'];
        $datetime_min = new \DateTime($replaymindate);

        $db->freeResult($pipeconfigresult);

        //Begin Finding Replays
        $r_key_name = "replays_latest_hotsapi_id";
        $lastCatalogedReplayResult = $db->execute("GetPipelineVariable");
        $lastCatalogedReplayResultRows = $db->countResultRows($lastCatalogedReplayResult);
        if ($lastCatalogedReplayResultRows > 0) {
            $lastCatalogedRow = $db->fetchArray($lastCatalogedReplayResult);
            $lastCatalogedReplay = $lastCatalogedRow['val_int'];
            $db->freeResult($lastCatalogedReplayResult);

            //Request replays starting from lastCatalogedReplay, and process them
            echo 'Requesting replays starting from id ' . $lastCatalogedReplay . ' from hotsapi...' . E;

            $api = Hotsapi::getReplaysStartingFromHotsApiId($lastCatalogedReplay);

            if ($api['code'] == Hotsapi::HTTP_OK) {
                $replays = $api['json'];
                $replaylen = count($replays);
                if ($replaylen > 0) {
                    $out_of_replays_count = 0;
                    $outofdate_replays_count = 0;
                    $duplicates = 0;

                    $getFilteredReplays = Hotsapi::getReplaysWithValidMatchTypes($replays, $lastCatalogedReplay);

                    $maxReplayId = $getFilteredReplays['maxReplayId'];
                    $validReplays = $getFilteredReplays['replays'];

                    if (count($validReplays) > 0) {
                        $db->transaction_begin();

                        foreach ($validReplays as $replay) {
                            $r_id = $replay['id'];

                            $existingReplayResult = $db->execute("GetExistingReplayWithHotsApiId");
                            $existingReplayResultRows = $db->countResultRows($existingReplayResult);
                            if ($existingReplayResultRows <= 0) {
                                $r_page = -1;
                                $r_idinpage = -1;
                                $r_match_date = $replay['game_date'];
                                $r_fingerprint = $replay['fingerprint'];
                                $r_s3url = $replay['url'];
                                $r_status = HotstatusPipeline::REPLAY_STATUS_QUEUED;
                                $r_storage_state = HotstatusPipeline::REPLAY_STORAGE_CATALOG;
                                $r_timestamp = time();

                                // Determine outofdate status
                                //
                                // This allows us to catalog replays that are older than our dataset's min start date, without having them
                                // clog up the status=queued select queries
                                $datetime_match = new \DateTime($r_match_date);
                                if ($datetime_match <= $datetime_min) {
                                    $r_status = HotstatusPipeline::REPLAY_STATUS_OUTOFDATE;
                                    $outofdate_replays_count++;
                                }

                                $db->execute("InsertNewReplay");
                            }
                            else {
                                echo "Duplicate Replay Found (#$r_id), prevented insertion...                             \r";
                                $duplicates++;
                            }
                            $db->freeResult($existingReplayResult);
                        }

                        //Finished processing results for result array, set latest hots api id processed
                        $r_val_int = $maxReplayId;
                        $db->execute("SetReplaysLatestHotsApiId");

                        $db->transaction_commit();

                        echo 'Result Group #' . $lastCatalogedReplay . ' processed (' . count($validReplays) . ' relevant -> '. $outofdate_replays_count .' out-of-date ['. $replaylen .' total : '. $duplicates .' duplicates]).' . E . E;
                    }
                    else {
                        //No relevant replays found, set new latest hotsapi id to be the maxId encountered
                        $r_val_int = $maxReplayId;
                        $db->execute("SetReplaysLatestHotsApiId");

                        echo 'Result Group #' . $lastCatalogedReplay . ' had no more relevant replays.' . E . E;
                    }
                }
                else {
                    $out_of_replays_count++;

                    if ($out_of_replays_count >= OUT_OF_REPLAYS_COUNT_LIMIT) {
                        //No more replays to process! Long sleep
                        $out_of_replays_count = 0;

                        echo timestamp() . 'Out of replays to process! Waiting for new hotsapi replay at #' . $lastCatalogedReplay . '...' . E;
                        $sleep->add(OUT_OF_REPLAYS_SLEEP_DURATION);
                    }
                    else {
                        //Potentially no more replay pages to process, try a few more times after a minute to make sure it's not just the API bugging out.
                        echo timestamp() . 'Received empty replays result...' . E . E;
                        $sleep->add(OUT_OF_REPLAYS_COUNT_DURATION);
                    }
                }
            }
            else if ($api['code'] == Hotsapi::HTTP_RATELIMITED) {
                //Error too many requests, wait awhile before trying again
                echo timestamp() . 'Error: HTTP Code ' . $api['code'] . '. Rate limited.' . E . E;
                $sleep->add(TOO_MANY_REQUEST_SLEEP_DURATION);
            }
            else {
                echo timestamp() . 'Error: HTTP Code ' . $api['code'] . '.' . E . E;
                $sleep->add(UNKNOWN_ERROR_CODE);
            }
        }
        else {
            //Couldn't Access Pipeline variable for last cataloged replay
        }
    }
    else {
        //Couldn't access Pipeline config
    }

    $sleep->add(NORMAL_EXECUTION_SLEEP_DURATION, true, true);

    $sleep->execute();
}

?>