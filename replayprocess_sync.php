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
const SYNC_REPLAYS_DOWNLOADED_INTERVAL = 30; //30 seconds
const PROCESS_GRANULARITY = 1; //1 seconds
const E = PHP_EOL;
$out_of_replays_count = 0; //Count how many times we ran out of replays to process, once reaching limit it means the api isn't bugging out and there actually is no more replays.
$sleep = new SleepHandler();

//Prepare statements
$db->prepare("CountDownloadedReplaysUpToLimit",
"SELECT COUNT(id) AS replay_count FROM replays WHERE status = '" . HotstatusPipeline::REPLAY_STATUS_DOWNLOADED . "' LIMIT ".HotstatusPipeline::REPLAY_DOWNLOAD_LIMIT);

$db->prepare("set_semaphore_replays_downloaded",
    "UPDATE `pipeline_semaphores` SET `value` = ? WHERE `name` = \"replays_downloaded\" LIMIT 1");
$db->bind("set_semaphore_replays_downloaded", "i", $r_replays_downloaded);

//Helper Functions
function log($str) {
    $datetime = new \DateTime("now");
    $datestr = $datetime->format(HotstatusPipeline::FORMAT_DATETIME);
    echo "[$datestr] str".E;
}

//Begin main script
echo '--------------------------------------'.E
    .'Replay process <<SYNC>> has started'.E
    .'--------------------------------------'.E;

//Sync
while (true) {
    if (time() % SYNC_REPLAYS_DOWNLOADED_INTERVAL == 0) {
        $countResult = $db->execute("CountDownloadedReplaysUpToLimit");
        $r_replays_downloaded = $db->fetchArray($countResult)['replay_count'];
        $db->freeResult($countResult);
        $db->execute("set_semaphore_replays_downloaded");
        log("Sync: Semaphore - Replays Downloaded");
    }

    $sleep->add(PROCESS_GRANULARITY);

    $sleep->execute();
}

?>