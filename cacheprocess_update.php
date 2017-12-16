<?php
/*
 * Cache Process Update
 * Updates Queued Cache Requests
 */

namespace Fizzik;

require_once 'includes/include.php';
require_once 'includes/HotstatusResponse/include.php';

use Fizzik\Database\MySqlDatabase;
use Fizzik\Database\RedisDatabase;
use Fizzik\Utility\Console;
use Fizzik\Utility\SleepHandler;

set_time_limit(0);
date_default_timezone_set(HotstatusPipeline::REPLAY_TIMEZONE);

$db = new MysqlDatabase();
$creds = Credentials::getCredentialsForUser(Credentials::USER_REPLAYPROCESS);
HotstatusPipeline::hotstatus_mysql_connect($db, $creds);
$db->setEncoding(HotstatusPipeline::DATABASE_CHARSET);

//Constants and qol
const UNLOCK_UPDATE_DURATION = 900; //Seconds
const UNLOCK_DEFAULT_DURATION = 5; //Must be unlocked for atleast 5 seconds
const NORMAL_EXECUTION_SLEEP_DURATION = 1000; //microseconds (1ms = 1000)
const SLEEP_DURATION = 5; //seconds
const MINI_SLEEP_DURATION = 1; //seconds
const E = PHP_EOL;
$sleep = new SleepHandler();
$console = new Console();

//Prepare statements
$db->prepare("TouchRequest",
    "UPDATE `pipeline_cache_requests` SET `lastused` = ? WHERE `id` = ?");
$db->bind("TouchRequest", "ii", $r_timestamp, $r_id);

$db->prepare("UpdateRequestStatus",
    "UPDATE `pipeline_cache_requests` SET lastused = ?, status = ? WHERE id = ? LIMIT 1");
$db->bind("UpdateRequestStatus", "iii", $r_timestamp, $r_status, $r_id);

$db->prepare("SelectNextRequestWithStatus-Unlocked",
    "SELECT `id`, `action`, `cache_id`, `payload` FROM `pipeline_cache_requests` WHERE `lastused` <= ? AND `status` = ? ORDER BY `id` ASC LIMIT 1");
$db->bind("SelectNextRequestWithStatus-Unlocked", "ii", $r_timestamp, $r_status);

$db->prepare("SelectNextRequestWithStatusPriority-Unlocked",
    "SELECT `id`, `action`, `cache_id`, `payload` FROM `pipeline_cache_requests` WHERE `lastused` <= ? AND `status` = ? ORDER BY `priority` DESC, `id` ASC LIMIT 1");
$db->bind("SelectNextRequestWithStatus-Unlocked", "ii", $r_timestamp, $r_status);

$db->prepare("DeleteRequest",
    "DELETE FROM `pipeline_cache_requests` WHERE `id` = ? LIMIT 1");
$db->bind("DeleteRequest", "i", $r_id);

$db->prepare("stats_cache_requests_updated_total",
    "UPDATE `pipeline_analytics` SET `val_int` = `val_int` + ? WHERE `key_name` = 'cache_requests_updated_total' LIMIT 1");
$db->bind("stats_cache_requests_updated_total", "i", $r_cache_requests_updated_total);

/*
 * Map actions to functions
 */
$actionMap = [
    "getDataTableHeroesStatsListAction" => function($cache_id, $payload, MySqlDatabase &$db, $creds) {
        $_TYPE = GetDataTableHeroesStatsListAction::_TYPE();
        $_ID = GetDataTableHeroesStatsListAction::_ID();
        $_VERSION = GetDataTableHeroesStatsListAction::_VERSION();

        GetDataTableHeroesStatsListAction::generateFilters();

        $CACHE_ID = $cache_id;

        $redis = new RedisDatabase();
        $redis->connect($creds[Credentials::KEY_REDIS_URI], HotstatusCache::CACHE_DEFAULT_DATABASE_INDEX);

        /*
         * Begin building response
         */
        //Set up main vars
        $pagedata = [];
        $datatable = [];

        //Execute response
        GetDataTableHeroesStatsListAction::execute($payload, $db, $pagedata, true);

        $datatable['data'] = $pagedata;

        //Store value in cache
        $encoded = json_encode($datatable);

        HotstatusCache::writeCacheRequest($redis, $_TYPE, $CACHE_ID, $_VERSION, $encoded, HotstatusCache::CACHE_DEFAULT_TTL);

        $redis->close();
    },
    "getPageDataHeroAction" => function($cache_id, $payload, MySqlDatabase &$db, $creds) {
        $_TYPE = GetPageDataHeroAction::_TYPE();
        $_ID = GetPageDataHeroAction::_ID();
        $_VERSION = GetPageDataHeroAction::_VERSION();

        GetPageDataHeroAction::generateFilters();

        $CACHE_ID = $cache_id;

        $redis = new RedisDatabase();
        $redis->connect($creds[Credentials::KEY_REDIS_URI], HotstatusCache::CACHE_DEFAULT_DATABASE_INDEX);

        /*
         * Begin building response
         */
        //Set up main vars
        $pagedata = [];
        $datatable = [];

        //Execute response
        GetPageDataHeroAction::execute($payload, $db, true, $redis, true, $pagedata, true);

        $datatable['data'] = $pagedata;

        //Store value in cache
        $encoded = json_encode($datatable);

        HotstatusCache::writeCacheRequest($redis, $_TYPE, $CACHE_ID, $_VERSION, $encoded, HotstatusCache::CACHE_DEFAULT_TTL);

        $redis->close();
    },
    "getPageDataRankingsAction" => function($cache_id, $payload, MySqlDatabase &$db, $creds) {
        $_TYPE = GetPageDataRankingsAction::_TYPE();
        $_ID = GetPageDataRankingsAction::_ID();
        $_VERSION = GetPageDataRankingsAction::_VERSION();

        GetPageDataRankingsAction::generateFilters();

        $CACHE_ID = $cache_id;

        $redis = new RedisDatabase();
        $redis->connect($creds[Credentials::KEY_REDIS_URI], HotstatusCache::CACHE_PLAYERSEARCH_DATABASE_INDEX);

        /*
         * Begin building response
         */
        //Set up main vars
        $pagedata = [];
        $datatable = [];

        //Execute response
        GetPageDataRankingsAction::execute($payload, $db, $pagedata, true);

        $datatable['data'] = $pagedata;

        //Store value in cache
        $encoded = json_encode($datatable);

        HotstatusCache::writeCacheRequest($redis, $_TYPE, $CACHE_ID, $_VERSION, $encoded, HotstatusCache::CACHE_DEFAULT_TTL);

        $redis->close();
    },
];

//Begin main script
echo '--------------------------------------'.E
    .'Cache process <<UPDATE>> has started'.E
    .'--------------------------------------'.E;

//Look for requests to update cache with
while (true) {
    //Check for unlocked failed cache updating
    $r_timestamp = time() - UNLOCK_UPDATE_DURATION;
    $r_status = HotstatusCache::QUEUE_CACHE_STATUS_UPDATING;
    $result = $db->execute("SelectNextRequestWithStatus-Unlocked");
    $resrows = $db->countResultRows($result);
    if ($resrows > 0) {
        //Found a failed cache update process, reset it to queued
        $row = $db->fetchArray($result);

        echo 'Found a failed cache update at #' . $row['id'] . ', resetting status to \'' . HotstatusCache::QUEUE_CACHE_STATUS_QUEUED . '\'...' . E;

        $r_id = $row['id'];
        $r_timestamp = time();
        $r_status = HotstatusCache::QUEUE_CACHE_STATUS_QUEUED;

        $db->execute("UpdateRequestStatus");
    }
    else {
        //No Cache Updating previously failed, look for unlocked queued request to update
        $r_timestamp = time() - UNLOCK_DEFAULT_DURATION;
        $r_status = HotstatusCache::QUEUE_CACHE_STATUS_QUEUED;
        $queuedResult = $db->execute("SelectNextRequestWithStatusPriority-Unlocked");
        $queuedResultRows = $db->countResultRows($queuedResult);
        if ($queuedResultRows > 0) {
            //Found a queued unlocked request for update, softlock for updating and update it
            $row = $db->fetchArray($queuedResult);

            $r_id = $row['id'];
            $r_timestamp = time();
            $r_status = HotstatusCache::QUEUE_CACHE_STATUS_UPDATING;

            $db->execute("UpdateRequestStatus");

            //Set lock id
            $requestLockId = "hotstatus_updateCacheRequest_$r_id";

            //Obtain lock
            $requestLocked = $db->lock($requestLockId, 0);

            if ($requestLocked) {
                echo 'Update Cache Request #' . $r_id . '...                              ' . E;

                $action = $row['action'];
                $cache_id = $row['cache_id'];
                $payload = json_decode($row['payload'], true);

                //Execute request
                $func = $actionMap[$action];
                $func($cache_id, $payload, $db, $creds);

                //Delete request after update
                $db->execute("DeleteRequest");

                //Inc updated total
                $r_cache_requests_updated_total = 1;
                $db->execute("stats_cache_requests_updated_total");

                //Release lock
                $db->unlock($requestLockId);

                echo 'Cache Request #' . $r_id . ' Updated.                                 '.E.E;
            }
            else {
                //Could not attain lock on request, immediately continue
            }
        }
        else {
            //No unlocked queued requests to update, sleep
            $dots = $console->animateDotDotDot();
            echo "No unlocked queued requests found$dots                           \r";

            $sleep->add(SLEEP_DURATION);
        }

        $db->freeResult($queuedResult);
    }

    $db->freeResult($result);

    //Default sleep
    $sleep->add(NORMAL_EXECUTION_SLEEP_DURATION, true, true);

    $sleep->execute();
}

?>