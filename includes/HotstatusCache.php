<?php

namespace Fizzik;

use Fizzik\Database\RedisDatabase;

class HotstatusCache {
    const CACHE_DEFAULT_DATABASE_INDEX = 0; //The default index of the database used for caching in redis
    const CACHE_PLAYERSEARCH_DATABASE_INDEX = 1; //The index of the databse used for caching player searches
    const CACHE_RATELIMITING_DATABASE_INDEX = 2; //The index of the database used for caching and tracking rate limiting for certain actions
    const CACHE_DEFAULT_TTL = PHP_INT_MAX; //The default TTL of stored cache values, keys with a TTL are subject to the volatile-lru cache policy
    const CACHE_PLAYERSEARCH_TTL = 300; //The TTL of stored playersearch cache values.
    const CACHE_PLAYER_HIT_TTL = 3600; //TTL of player caching when valid result
    const CACHE_PLAYER_MISS_TTL = 300; //TTL of player caching when invalid result
    const CACHE_PLAYER_UPDATE_TTL = 900; //TTL of player caching on player page response
    const HTTPCACHE_DEFAULT_TIMEZONE = "GMT"; //Default timezone used for http headers
    const HTTPCACHE_DEFAULT_RECALCULATION_TIME = ["hours" => 11, "minutes" => 0, "seconds" => 0]; //What time of day to expire all http cached dynamic data, should be after bulk data processing is done.

    /*
     * Returns the next datetime that the http cache should expire using default settings, using the current time
     */
    public static function getHTTPCacheDefaultExpirationDateForToday() {
        date_default_timezone_set(self::HTTPCACHE_DEFAULT_TIMEZONE);
        $currentDate = new \DateTime("now");
        $currentDateWithExpire = new \DateTime("now");
        $nextDateWithExpire = new \DateTime("now");

        $hours = self::HTTPCACHE_DEFAULT_RECALCULATION_TIME['hours'];
        $minutes = self::HTTPCACHE_DEFAULT_RECALCULATION_TIME['minutes'];
        $seconds = self::HTTPCACHE_DEFAULT_RECALCULATION_TIME['seconds'];

        $currentDateWithExpire->setTime(0, 0, 0);
        $currentDateWithExpire->add(new \DateInterval("PT" . $hours . "H" . $minutes . "M" . $seconds . "S"));

        $expireDate = null;
        if ($currentDate->getTimestamp() < $currentDateWithExpire->getTimestamp()) {
            //Still approaching expiration time, set to currentDateWithExpire
            $expireDate = $currentDateWithExpire;
        }
        else {
            //Already passed expiration time, set to next expiration
            $nextDateWithExpire->setTime(0, 0, 0);
            $nextDateWithExpire->add(new \DateInterval("P1DT" . $hours . "H" . $minutes . "M" . $seconds . "S"));
            $expireDate = $nextDateWithExpire;
        }

        return $expireDate;
    }

    /*
     * Returns the time in seconds from which a redis cached value should expire using default settings, using the current time
     */
    public static function getCacheDefaultExpirationTimeInSecondsForToday() {
        date_default_timezone_set(self::HTTPCACHE_DEFAULT_TIMEZONE);
        $currentTimestamp = (new \DateTime("now"))->getTimestamp();
        $expireTimestamp = self::getHTTPCacheDefaultExpirationDateForToday()->getTimestamp();

        return $expireTimestamp - $currentTimestamp;
    }

    /*
     * Cache Request - Per Function Response Caching
     */
    const CACHE_REQUEST_TYPE_DATATABLE = "DataTable:";
    const CACHE_REQUEST_TYPE_PAGEDATA = "PageData:";
    const CACHE_REQUEST_PREFIX = "Cache_Request:";

    public static function writeCacheRequest(RedisDatabase $redis, $cache_request_type = "", $functionId, $functionVersion, $value, $ttl = self::CACHE_DEFAULT_TTL) {
        if ($redis !== NULL && $redis !== FALSE) {
            $key = self::buildCacheRequestKey($cache_request_type, $functionId);
            return $redis->cacheHash($key, $functionVersion, $value, $ttl);
        }

        return NULL;
    }

    public static function readCacheRequest(RedisDatabase $redis, $cache_request_type = "", $functionId, $functionVersion) {
        if ($redis !== NULL && $redis !== FALSE) {
            $key = self::buildCacheRequestKey($cache_request_type, $functionId);
            return $redis->getCachedHash($key, $functionVersion);
        }

        return NULL;
    }

    public static function buildCacheRequestKey($cache_request_type = "", $functionId) {
        return self::CACHE_REQUEST_PREFIX . $cache_request_type . $functionId;
    }

    /*
     * Activity - Per Function Activity caching
     */
    const CACHE_ACTIVITY_TYPE = "UserIP:";
    const CACHE_ACTIVITY_PREFIX = "Cache_Activity:";

    /*
     * Rate limits an activity based on activity type and activity id, with a supplied limit amount and time range in seconds.
     * Will store only the incremental value
     *
     * Return TRUE if the activity should be rate limited, FALSE if it is still under the rate limit
     */
    public static function rateLimitActivity(RedisDatabase $redis, $cache_activity_type = "", $activityId, $limitAmount, $limitTimeRangeInSeconds) {
        $client = $redis->connection();

        $key = self::buildActivityKey($cache_activity_type, $activityId);

        $amount = $client->get($key);

        if ($amount != NULL && $amount >= $limitAmount) {
            return TRUE;
        }
        else if ($amount == NULL) {
            //Set initial expiration range
            $client->multi();

            $client->incr($key);
            $client->expire($key, $limitTimeRangeInSeconds);

            $client->exec();

            return FALSE;
        }
        else {
            //Simply increment and let it continue expiring
            $client->incr($key);

            return FALSE;
        }
    }

    /*
     * Rate limits an activity based on activity type and activity id, with a supplied limit amount and time range in seconds.
     * Will store the limitValues as a list in the generated activity key in case they need to be accessed.
     * (IE: get list of IPs that performed activity within expiring time range)
     *
     * Return TRUE if the activity should be rate limited, FALSE if it is still under the rate limit
     */
    public static function rateLimitActivityList(RedisDatabase $redis, $cache_activity_type = "", $activityId, $limitValue, $limitAmount, $limitTimeRangeInSeconds) {
        $client = $redis->connection();

        $key = self::buildActivityKey($cache_activity_type, $activityId);

        $current = $client->llen($key);

        if ($current >= $limitAmount) {
            return TRUE;
        }
        else {
            if (!$client->exists($key)) {
                $client->multi();

                $client->rpush($key, $limitValue);
                $client->expire($key, $limitTimeRangeInSeconds);

                $client->exec();
            }
            else {
                $client->rpushx($key, $limitValue);
            }

            return FALSE;
        }
    }

    public static function buildActivityKey($cache_activity_type = "", $activityId) {
        return self::CACHE_ACTIVITY_PREFIX . $cache_activity_type . $activityId;
    }
}