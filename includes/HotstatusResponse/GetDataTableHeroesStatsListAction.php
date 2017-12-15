<?php

namespace Fizzik;

use Fizzik\Database\MySqlDatabase;

class GetDataTableHeroesStatsListAction {
    const WINDELTA_MAX_DAYS = 30; //Windeltas are only calculated for time ranges of 30 days or less

    public static function _TYPE() {
        return HotstatusCache::CACHE_REQUEST_TYPE_DATATABLE;
    }

    public static function _ID() {
        return "getDataTableHeroesStatsListAction";
    }

    public static function _VERSION() {
        return 1;
    }

    public static function generateFilters() {
        HotstatusPipeline::filter_generate_date();
    }

    public static function initQueries() {
        return [
            HotstatusPipeline::FILTER_KEY_GAMETYPE => [
                HotstatusResponse::QUERY_IGNORE_AFTER_CACHE => false,
                HotstatusResponse::QUERY_ISSET => false,
                HotstatusResponse::QUERY_RAWVALUE => null,
                HotstatusResponse::QUERY_SQLVALUE => null,
                HotstatusResponse::QUERY_SQLCOLUMN => "gameType",
                HotstatusResponse::QUERY_COMBINATORIAL => true,
                HotstatusResponse::QUERY_TYPE => HotstatusResponse::QUERY_TYPE_RAW
            ],
            HotstatusPipeline::FILTER_KEY_MAP => [
                HotstatusResponse::QUERY_IGNORE_AFTER_CACHE => false,
                HotstatusResponse::QUERY_ISSET => false,
                HotstatusResponse::QUERY_RAWVALUE => null,
                HotstatusResponse::QUERY_SQLVALUE => null,
                HotstatusResponse::QUERY_SQLCOLUMN => "map",
                HotstatusResponse::QUERY_COMBINATORIAL => true,
                HotstatusResponse::QUERY_TYPE => HotstatusResponse::QUERY_TYPE_RAW
            ],
            HotstatusPipeline::FILTER_KEY_RANK => [
                HotstatusResponse::QUERY_IGNORE_AFTER_CACHE => false,
                HotstatusResponse::QUERY_ISSET => false,
                HotstatusResponse::QUERY_RAWVALUE => null,
                HotstatusResponse::QUERY_SQLVALUE => null,
                HotstatusResponse::QUERY_SQLCOLUMN => "mmr_average",
                HotstatusResponse::QUERY_COMBINATORIAL => true,
                HotstatusResponse::QUERY_TYPE => HotstatusResponse::QUERY_TYPE_RANGE
            ],
            HotstatusPipeline::FILTER_KEY_DATE => [
                HotstatusResponse::QUERY_IGNORE_AFTER_CACHE => true,
                HotstatusResponse::QUERY_ISSET => false,
                HotstatusResponse::QUERY_RAWVALUE => null,
                HotstatusResponse::QUERY_SQLVALUE => null,
                HotstatusResponse::QUERY_SQLCOLUMN => "date_end",
                HotstatusResponse::QUERY_COMBINATORIAL => false,
                HotstatusResponse::QUERY_TYPE => HotstatusResponse::QUERY_TYPE_RANGE
            ],
        ];
    }

    public static function execute(&$payload, MySqlDatabase &$db, &$pagedata, $isCacheProcess = false) {
        //Extract payload
        $queryDateKey = $payload['queryDateKey'];
        $querySql = $payload['querySql'];

        //Define main vars
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

        /*$db->prepare("EstimateMatchCountForGranularity",
            "SELECT ROUND(COALESCE(SUM(`played`), 0) / 10, 0) AS 'match_count' FROM `heroes_matches_recent_granular` WHERE `gameType` = ? ".$querySql." AND `date_end` >= ? AND `date_end` <= ?");
        $db->bind("EstimateMatchCountForGranularity", "sss", $r_gameType, $date_range_start, $date_range_end);*/

        //Iterate through heroes to collect data
        $herodata = [];
        $maxWinrate = 0.0;
        $minWinrate = 100.0;
        $totalPlayed = 0;
        $totalBanned = 0;
        $filter = HotstatusPipeline::$filter[HotstatusPipeline::FILTER_KEY_HERO];
        foreach ($filter as $heroname => $row) {
            $r_hero = $heroname;

            //Cache Process Logging
            if ($isCacheProcess) {
                echo "Querying Hero: $r_hero                                               \r";
            }

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
            if ($offset_amount <= self::WINDELTA_MAX_DAYS) {
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
            if ($offset_amount <= self::WINDELTA_MAX_DAYS) {
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
    }
}