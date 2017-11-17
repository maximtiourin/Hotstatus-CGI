<?php
/*
 * Replay Process Download
 * In charge of looking through cataloged replays and downloading them to a temporary location for use with processing further
 * down the pipeline. Can be made to only ever download a fixed amount of replays before waiting for them to be processed to prevent
 * storing too much at once.
 */

namespace Fizzik;

require_once 'lib/AWS/aws-autoloader.php';
require_once 'includes/include.php';
require_once 'includes/Hotsapi.php';

use Fizzik\Database\MySqlDatabase;
use Fizzik\Utility\Console;
use Fizzik\Utility\SleepHandler;
use Fizzik\Utility\FileHandling;

set_time_limit(0);
date_default_timezone_set(HotstatusPipeline::REPLAY_TIMEZONE);

$db = new MysqlDatabase();
$creds = Credentials::getCredentialsForUser(Credentials::USER_REPLAYPROCESS);
$db->connect($creds[Credentials::KEY_DB_HOSTNAME], $creds[Credentials::KEY_DB_USER], $creds[Credentials::KEY_DB_PASSWORD], $creds[Credentials::KEY_DB_DATABASE]);
$db->setEncoding(HotstatusPipeline::DATABASE_CHARSET);

//Aws
$awsCreds = new \Aws\Credentials\Credentials($creds[Credentials::KEY_AWS_KEY], $creds[Credentials::KEY_AWS_SECRET]);
$sdk = new \Aws\Sdk([
    'region' => $creds[Credentials::KEY_AWS_REPLAYREGION],
    'version' => 'latest',
    'credentials' => $awsCreds
]);
$s3 = $sdk->createS3();

//Constants and qol
const DOWNLOADLIMIT_SLEEP_DURATION = 5; //seconds
const NORMAL_EXECUTION_SLEEP_DURATION = 250000; //microseconds
const SLEEP_DURATION = 5; //seconds
const MINI_SLEEP_DURATION = 1; //seconds
const UNLOCK_DEFAULT_DURATION = 5; //Must be unlocked for atleast 5 seconds
const UNLOCK_DOWNLOADING_DURATION = 120; //Must be unlocked for atleast 2 minutes while downloading status
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

$db->prepare("UpdateReplayDownloaded",
"UPDATE replays SET file = ?, status = ?, lastused = ? WHERE id = ?");
$db->bind("UpdateReplayDownloaded", "ssii", $r_filepath, $r_status, $r_timestamp, $r_id);

$db->prepare("CountDownloadedReplays",
"SELECT COUNT(id) AS replay_count FROM replays WHERE status = '" . HotstatusPipeline::REPLAY_STATUS_DOWNLOADED . "'");

$db->prepare("SelectNextReplayWithStatus-Unlocked",
    "SELECT * FROM replays WHERE status = ? AND lastused <= ? ORDER BY match_date ASC, id ASC LIMIT 1");
$db->bind("SelectNextReplayWithStatus-Unlocked", "si", $r_status, $r_timestamp);

//Helper functions

//Begin main script
echo '--------------------------------------'.E
    .'Replay process <<DOWNLOAD>> has started'.E
    .'--------------------------------------'.E;

//Look for replays to download and handle
while (true) {
    $result = $db->execute("CountDownloadedReplays");
    $resrows = $db->countResultRows($result);
    $downloadCount = 0;
    if ($resrows > 0) {
        $countrow = $db->fetchArray($result);
        $downloadCount = $countrow['replay_count'];
    }
    if ($downloadCount >= HotstatusPipeline::REPLAY_DOWNLOAD_LIMIT) {
        //Reached download limit
        echo 'Reached replay download limit of ' . HotstatusPipeline::REPLAY_DOWNLOAD_LIMIT . ', waiting for downloaded replays to be processed...'.E.E;
        $sleep->add(DOWNLOADLIMIT_SLEEP_DURATION);
    }
    else {
        //Have not reached download limit yet, check for unlocked failed replay downloads
        $r_status = HotstatusPipeline::REPLAY_STATUS_DOWNLOADING;
        $r_timestamp = time() - UNLOCK_DOWNLOADING_DURATION;
        $result2 = $db->execute("SelectNextReplayWithStatus-Unlocked");
        $resrows2 = $db->countResultRows($result2);
        if ($resrows2 > 0) {
            //Found a failed replay download, reset it to queued
            $row = $db->fetchArray($result2);

            echo 'Found a failed replay download at replay #' . $row['id'] . ', resetting status to \'' . HotstatusPipeline::REPLAY_STATUS_QUEUED . '\'...'.E.E;

            $r_id = $row['id'];
            $r_status = HotstatusPipeline::REPLAY_STATUS_QUEUED;
            $r_timestamp = time();

            $db->execute("UpdateReplayStatus");
        }
        else {
            //No replay downloads previously failed, look for an unlocked queued replay to download
            $r_status = HotstatusPipeline::REPLAY_STATUS_QUEUED;
            $r_timestamp = time() - UNLOCK_DEFAULT_DURATION;
            $result3 = $db->execute("SelectNextReplayWithStatus-Unlocked");
            $resrows3 = $db->countResultRows($result3);
            if ($resrows3 > 0) {
                //Found a queued unlocked replay, softlock for downloading and download it.
                $row = $db->fetchArray($result3);

                $r_id = $row['id'];
                $r_status = HotstatusPipeline::REPLAY_STATUS_DOWNLOADING;
                $r_timestamp = time();

                $db->execute("UpdateReplayStatus");

                //Set lock id
                $replayLockId = "hotstatus_downloadReplay_$r_id";

                //Obtain lock
                $replayLocked = $db->lock($replayLockId, 0);

                if ($replayLocked) {
                    echo 'Downloading replay #' . $r_id . '...                              ' . E;

                    $r_fingerprint = $row['fingerprint'];
                    $r_url = $row['storage_id'];

                    //Ensure directory
                    FileHandling::ensureDirectory(HotstatusPipeline::REPLAY_DOWNLOAD_DIRECTORY);

                    //Determine filepath
                    $r_filepath = HotstatusPipeline::REPLAY_DOWNLOAD_DIRECTORY . $r_fingerprint . HotstatusPipeline::REPLAY_DOWNLOAD_EXTENSION;

                    //Download
                    $api = Hotsapi::DownloadS3Replay($r_url, $r_filepath, $s3);

                    if ($api['success'] == TRUE) {
                        //Replay downloaded successfully
                        echo 'Replay #' . $r_id . ' (' . round($api['bytes_downloaded'] / FileHandling::getBytesForMegabytes(1), 2) . ' MB) downloaded to "' . $r_filepath . '"' . E . E;

                        $r_status = HotstatusPipeline::REPLAY_STATUS_DOWNLOADED;
                        $r_timestamp = time();

                        $db->execute("UpdateReplayDownloaded");
                    }
                    else {
                        //Error with downloading the replay
                        echo 'Failed to download replay #' . $r_id . ', Reason: ' . $api['error'] . '...' . E . E;

                        //Set status to download_error
                        $r_id = $row['id'];
                        $r_status = HotstatusPipeline::REPLAY_STATUS_DOWNLOAD_ERROR;
                        $r_timestamp = time();
                        $r_error = $api['error'];

                        $db->execute("UpdateReplayStatusError");

                        $sleep->add(MINI_SLEEP_DURATION);
                    }

                    //Release lock
                    $db->unlock($replayLockId);
                }
                else {
                    //Could not attain lock on replay, immediately continue
                }
            }
            else {
                //No unlocked queued replays to download, sleep
                $dots = $console->animateDotDotDot();
                echo "No unlocked queued replays found$dots                           \r";

                $sleep->add(SLEEP_DURATION);
            }

            $db->freeResult($result3);
        }

        $db->freeResult($result2);
    }

    $db->freeResult($result);

    //Default sleep
    $sleep->add(NORMAL_EXECUTION_SLEEP_DURATION, true, true);

    $sleep->execute();
}

?>