<?php

namespace Fizzik;

use Fizzik\Database\RedisDatabase;

class HotstatusCache {
    const CACHE_DEFAULT_DATABASE_INDEX = 0; //The default index of the database used for caching in redis
    const CACHE_DEFAULT_TTL = PHP_INT_MAX; //The default TTL of stored cache values, keys with a TTL are subject to the volatile-lru cache policy
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
    const CACHE_REQUEST_TYPE_DATATABLE = "DataTable_";
    const CACHE_REQUEST_TYPE_PAGEDATA = "PageData_";
    const CACHE_REQUEST_PREFIX = "Cache_Request_";

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
}