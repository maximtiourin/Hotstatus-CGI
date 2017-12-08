<?php
/*
 * Cache Process Update
 * Updates Queued Cache Requests
 */

namespace Fizzik;

require_once 'includes/include.php';

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
    $_TYPE = HotstatusCache::CACHE_REQUEST_TYPE_DATATABLE;
$_ID = "getDataTableHeroesStatsListAction";
$_VERSION = 0;

//EXT FILTER GENERATION
HotstatusPipeline::filter_generate_date();

//EXT CONSTS
$CONST_WINDELTA_MAX_DAYS = 30;

/*
 * Process Query Parameters
 */
$queryDateKey = $payload['queryDateKey'];

//Build WhereAnd string from collected WhereOr strings
$querySql = $payload['querySql'];

//Determine cache id from query parameters
$CACHE_ID = $cache_id;

/*
 * Begin building response
 */

//Set up main vars
$datatable = [];
$pagedata = [];
$data = [];

//Determine time range
date_default_timezone_set(HotstatusPipeline::REPLAY_TIMEZONE);
$dateobj = HotstatusPipeline::$filter[HotstatusPipeline::FILTER_KEY_DATE][$queryDateKey];
$offset_date = $dateobj['offset_date'];
$offset_amount = $dateobj['offset_amount'];
$datetime = new \DateTime($offset_date);
$recent_range = HotstatusPipeline::getMinMaxRangeForLastISODaysInclusive($offset_amount, $datetime->format(HotstatusPipeline::FORMAT_DATETIME));
$old_range = HotstatusPipeline::getMinMaxRangeForLastISODaysInclusive($offset_amount, $datetime->format(HotstatusPipeline::FORMAT_DATETIME), $offset_amount);

//Prepare statements
$db->prepare("CountHeroesMatches",
    "SELECT COALESCE(SUM(`played`), 0) AS `played`, COALESCE(SUM(`won`), 0) AS `won` FROM `heroes_matches_recent_granular` WHERE `hero` = ? ".$querySql." AND `date_end` >= ? AND `date_end` <= ?");
$db->bind("CountHeroesMatches", "sss", $r_hero, $date_range_start, $date_range_end);

$db->prepare("CountHeroesBans",
    "SELECT COALESCE(SUM(`banned`), 0) AS `banned` FROM `heroes_bans_recent_granular` WHERE `hero` = ? ".$querySql." AND `date_end` >= ? AND `date_end` <= ?");
$db->bind("CountHeroesBans", "sss", $r_hero, $date_range_start, $date_range_end);

//Iterate through heroes to collect data
$herodata = [];
$maxWinrate = 0.0;
$minWinrate = 100.0;
$totalPlayed = 0;
$totalBanned = 0;
$filter = HotstatusPipeline::$filter[HotstatusPipeline::FILTER_KEY_HERO];
foreach ($filter as $heroname => $row) {
    $r_hero = $heroname;

    echo "Querying Hero: $r_hero                                  \r";

    /*
     * Collect hero data
     */
    $dt_playrate = 0;
    $dt_banrate = 0;
    $dt_winrate = 0.0;
    $dt_windelta = 0.0;

    $old_playrate = 0;
    $old_won = 0;
    $old_winrate = 0.0;

    $won = 0;

    /*
     * Calculate match statistics for hero
     */
    //Recent Time Range
    $date_range_start = $recent_range['date_start'];
    $date_range_end = $recent_range['date_end'];

    $statsResult = $db->execute("CountHeroesMatches");
    $statsrow = $db->fetchArray($statsResult);

    $dt_playrate += $statsrow['played'];
    $won += $statsrow['won'];

    $db->freeResult($statsResult);

    //Old Time Range (Only if offset is WINDELTA_MAX_DAYS or less, otherwise don't calculate windelta)
    if ($offset_amount <= $CONST_WINDELTA_MAX_DAYS) {
        $date_range_start = $old_range['date_start'];
        $date_range_end = $old_range['date_end'];

        $statsResult = $db->execute("CountHeroesMatches");
        $statsrow = $db->fetchArray($statsResult);

        $old_playrate += $statsrow['played'];
        $old_won += $statsrow['won'];

        $db->freeResult($statsResult);
    }

    /*
     * Calculate ban statistics for hero
     */
    //Recent Time Range
    $date_range_start = $recent_range['date_start'];
    $date_range_end = $recent_range['date_end'];

    $statsResult = $db->execute("CountHeroesBans");
    $statsrow = $db->fetchArray($statsResult);

    $dt_banrate += $statsrow['banned'];

    $db->freeResult($statsResult);

    //Winrate
    if ($dt_playrate > 0) {
        $dt_winrate = round(($won / ($dt_playrate * 1.00)) * 100.0, 1);
    }

    //Old Winrate
    if ($old_playrate > 0) {
        $old_winrate = round(($old_won / ($old_playrate * 1.00)) * 100.0, 1);
    }

    //Win Delta (Only if offset is WINDELTA_MAX_DAYS or less, otherwise don't calculate windelta)
    if ($offset_amount <= $CONST_WINDELTA_MAX_DAYS) {
        $dt_windelta = $dt_winrate - $old_winrate;
    }

    //Max, mins, and totals
    if ($maxWinrate < $dt_winrate && $dt_playrate > 0) $maxWinrate = $dt_winrate;
    if ($minWinrate > $dt_winrate && $dt_playrate > 0) $minWinrate = $dt_winrate;
    $totalPlayed += $dt_playrate;
    $totalBanned += $dt_banrate;

    /*
     * Construct hero object
     */
    $hero = [];
    $hero['name'] = $heroname;
    $hero['name_sort'] = $row['name_sort'];
    $hero['role_blizzard'] = $row['role_blizzard'];
    $hero['role_specific'] = $row['role_specific'];
    $hero['image_hero'] =  $row['image_hero'];
    $hero['dt_playrate'] = $dt_playrate;
    $hero['dt_banrate'] = $dt_banrate;
    $hero['dt_winrate'] = $dt_winrate;
    $hero['dt_windelta'] = $dt_windelta;

    $herodata[] = $hero;
}

//Calculate popularities
$matchesPlayed = $totalPlayed / 10; //Estimate matches played for granularity
$maxPopularity = PHP_INT_MIN;
$minPopularity = PHP_INT_MAX;
foreach ($herodata as &$rhero) {
    if ($matchesPlayed > 0) {
        $dt_popularity = round(((($rhero['dt_playrate'] + $rhero['dt_banrate']) * 1.00) / (($matchesPlayed) * 1.00)) * 100.0, 1);
    }
    else {
        $dt_popularity = 0;
    }

    //Max, mins
    if ($maxPopularity < $dt_popularity) $maxPopularity = $dt_popularity;
    if ($minPopularity > $dt_popularity) $minPopularity = $dt_popularity;

    $rhero['dt_popularity'] = $dt_popularity;
}

//Iterate through heroes to create dtrows from previously collected data
foreach ($herodata as $hero) {
    $dtrow = [];

    //Hero Portrait
    $dtrow['image_hero'] = $hero['image_hero'];

    //Hero proper name
    $dtrow['name'] = $hero['name'];

    //Hero name sort helper
    $dtrow['name_sort'] = $hero['name_sort'];

    //Hero Blizzard role
    $dtrow['role_blizzard'] = $hero['role_blizzard'];

    //Hero Specific role
    $dtrow['role_specific'] = $hero['role_specific'];

    //Playrate
    $dtrow['played'] = $hero['dt_playrate'];

    //Banrate
    if ($hero['dt_banrate'] > 0) {
        $dtrow['banned'] = $hero['dt_banrate'];
    }
    else {
        $dtrow['banned'] = '';
    }

    //Popularity
    $percentOnRange = 0;
    if ($maxPopularity - $minPopularity > 0) {
        $percentOnRange = ((($hero['dt_popularity'] - $minPopularity) * 1.00) / (($maxPopularity - $minPopularity) * 1.00)) * 100.0;
    }
    $dtrow['popularity'] = sprintf("%03.1f %%", $hero['dt_popularity']);
    $dtrow['popularity_percent'] = $percentOnRange;

    //Winrate
    if ($hero['dt_playrate'] > 0) {
        $percentOnRange = 0;
        if ($maxWinrate - $minWinrate > 0) {
            $percentOnRange = ((($hero['dt_winrate'] - $minWinrate) * 1.00) / (($maxWinrate - $minWinrate) * 1.00)) * 100.0;
        }
        $dtrow['winrate'] = sprintf("%03.1f %%", $hero['dt_winrate']);
        $dtrow['winrate_raw'] = $hero['dt_winrate'];
        $dtrow['winrate_percent'] = $percentOnRange;
        $dtrow['winrate_exists'] = true;
    }
    else {
        $dtrow['winrate'] = '';
        $dtrow['winrate_exists'] = false;
    }

    //Win Delta (This is the % change in winrate from this last granularity and the older next recent granularity)
    if ($hero['dt_windelta'] > 0 || $hero['dt_windelta'] < 0) {
        $dtrow['windelta'] = sprintf("%+-03.1f %%", $hero['dt_windelta']);
        $dtrow['windelta_raw'] = $hero['dt_windelta'];
        $dtrow['windelta_exists'] = true;
    }
    else {
        $dtrow['windelta'] = '';
        $dtrow['windelta_exists'] = false;
    }

    $data[] = $dtrow;
}

$pagedata['heroes'] = $data;

//Last Updated
$pagedata['last_updated'] = time();

//Max Age
$pagedata['max_age'] = HotstatusCache::getCacheDefaultExpirationTimeInSecondsForToday();

$datatable['data'] = $pagedata;

//Store value in cache
$redis = new RedisDatabase();
$redis->connect($creds[Credentials::KEY_REDIS_URI], HotstatusCache::CACHE_DEFAULT_DATABASE_INDEX);

$encoded = json_encode($datatable);

HotstatusCache::writeCacheRequest($redis, $_TYPE, $CACHE_ID, $_VERSION, $encoded, HotstatusCache::CACHE_DEFAULT_TTL);

$redis->cacheString("testing123", "this is a test");

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
        $queuedResult = $db->execute("SelectNextRequestWithStatus-Unlocked");
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