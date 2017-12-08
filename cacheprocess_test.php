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

$creds = Credentials::getCredentialsForUser(Credentials::USER_REPLAYPROCESS);

$redis = new RedisDatabase();
$redis->connect($creds[Credentials::KEY_REDIS_URI], HotstatusCache::CACHE_DEFAULT_DATABASE_INDEX);

//Constants and qol
const UNLOCK_UPDATE_DURATION = 900; //Seconds
const UNLOCK_DEFAULT_DURATION = 5; //Must be unlocked for atleast 5 seconds
const NORMAL_EXECUTION_SLEEP_DURATION = 1000; //microseconds (1ms = 1000)
const SLEEP_DURATION = 5; //seconds
const MINI_SLEEP_DURATION = 1; //seconds
const E = PHP_EOL;
$sleep = new SleepHandler();
$console = new Console();

HotstatusCache::writeCacheRequest($redis, "type123", "cfuncid456", 0, "This is my value #1", PHP_INT_MAX);
HotstatusCache::writeCacheRequest($redis, "type123", "cfuncidfdgdfg", 0, "This is my value #2", PHP_INT_MAX);
HotstatusCache::writeCacheRequest($redis, "type123", "cfuncidMAXIMTIOUR", 0, "This is my value #3", PHP_INT_MAX);
HotstatusCache::writeCacheRequest($redis, "type123", "cfuncidACKACK", 0, "This is my value #4", PHP_INT_MAX);

?>