<?php

namespace Fizzik;

use Fizzik\Database\MySqlDatabase;

class GetPageDataRankingsAction {
    public static function _TYPE() {
        return HotstatusCache::CACHE_REQUEST_TYPE_PAGEDATA;
    }

    public static function _ID() {
        return "getPageDataRankingsAction";
    }

    public static function _VERSION() {
        return 1;
    }

    public static function generateFilters() {
        HotstatusPipeline::filter_generate_season();
        HotstatusPipeline::filter_generate_date();
    }

    public static function initQueries() {
        return [
            HotstatusPipeline::FILTER_KEY_REGION => [
                HotstatusResponse::QUERY_IGNORE_AFTER_CACHE => false,
                HotstatusResponse::QUERY_ISSET => false,
                HotstatusResponse::QUERY_RAWVALUE => null,
                HotstatusResponse::QUERY_SQLVALUE => null,
                HotstatusResponse::QUERY_SQLCOLUMN => "region",
                HotstatusResponse::QUERY_COMBINATORIAL => false,
                HotstatusResponse::QUERY_TYPE => HotstatusResponse::QUERY_TYPE_INDEX
            ],
            HotstatusPipeline::FILTER_KEY_SEASON => [
                HotstatusResponse::QUERY_IGNORE_AFTER_CACHE => false,
                HotstatusResponse::QUERY_ISSET => false,
                HotstatusResponse::QUERY_RAWVALUE => null,
                HotstatusResponse::QUERY_SQLVALUE => null,
                HotstatusResponse::QUERY_SQLCOLUMN => "season",
                HotstatusResponse::QUERY_COMBINATORIAL => false,
                HotstatusResponse::QUERY_TYPE => HotstatusResponse::QUERY_TYPE_RAW
            ],
            HotstatusPipeline::FILTER_KEY_GAMETYPE => [
                HotstatusResponse::QUERY_IGNORE_AFTER_CACHE => false,
                HotstatusResponse::QUERY_ISSET => false,
                HotstatusResponse::QUERY_RAWVALUE => null,
                HotstatusResponse::QUERY_SQLVALUE => null,
                HotstatusResponse::QUERY_SQLCOLUMN => "gameType",
                HotstatusResponse::QUERY_COMBINATORIAL => false,
                HotstatusResponse::QUERY_TYPE => HotstatusResponse::QUERY_TYPE_RAW
            ],
        ];
    }

    public static function execute(&$payload, MySqlDatabase &$db, &$pagedata, $isCacheProcess = false) {
        //Extract payload
        $querySeason = $payload['querySeason'];
        $queryGameType = $payload['queryGameType'];
        $queryRegion = $payload['queryRegion'];
        $querySql = $payload['querySql'];

        if ($isCacheProcess) {
            echo "[$querySeason :: $queryGameType] Updating rankings page.\n";
        }

        //Define main vars

        //Build Response
        //Get season date range
        date_default_timezone_set(HotstatusPipeline::REPLAY_TIMEZONE);
        $seasonobj = HotstatusPipeline::$SEASONS[$querySeason];
        $date_start = $seasonobj['start'];
        $date_end = $seasonobj['end'];

        //Get gameType rank and match limits
        $gameTypeobj = HotstatusPipeline::$filter[HotstatusPipeline::FILTER_KEY_GAMETYPE][$queryGameType];
        $matchLimit = $gameTypeobj['ranking']['matchLimit'];
        $rankLimit = $gameTypeobj['ranking']['rankLimit'];

        //Prepare Statements
        $t_players = HotstatusPipeline::$table_pointers['players'];
        $t_players_mmr = HotstatusPipeline::$table_pointers['players_mmr'];
        $t_players_matches = HotstatusPipeline::$table_pointers['players_matches'];

        $numRegion = HotstatusPipeline::$filter[HotstatusPipeline::FILTER_KEY_REGION][$queryRegion]['index'];

        $db->prepare("GetTopRanks",
            "SELECT mmr.`id` AS `playerid`, `name` AS `playername`, `rating`, (SELECT COUNT(`type`) FROM `$t_players_matches` pm INNER JOIN `matches` m ON pm.`match_id` = m.`id` AND pm.`region` = m.`region` WHERE pm.`id` = mmr.`id` AND `type` = \"$queryGameType\" AND pm.`date` >= \"$date_start\" AND pm.`date` <= \"$date_end\") AS `played` FROM `$t_players_mmr` mmr INNER JOIN `$t_players` p ON mmr.`id` = p.`id` AND mmr.`region` = p.`region` WHERE (SELECT COUNT(`type`) FROM `$t_players_matches` pm INNER JOIN `matches` m ON pm.`match_id` = m.`id` AND pm.`region` = m.`region` WHERE pm.`id` = mmr.`id` AND `type` = \"$queryGameType\" AND pm.`date` >= \"$date_start\" AND pm.`date` <= \"$date_end\") >= $matchLimit AND mmr.`region` = $numRegion AND `gameType` = \"$queryGameType\" AND `season` = \"$querySeason\" ORDER BY `rating` DESC LIMIT $rankLimit");

        $ranks = [];
        $rankplace = 1;
        $res = $db->execute("GetTopRanks");
        while ($row = $db->fetchArray($res)) {
            $rank = [];

            $rank["rank"] = $rankplace++;
            $rank['player_id'] = $row['playerid'];
            $rank['player_name'] = $row['playername'];
            $rank['rating'] = $row['rating'];
            $rank['region'] = $numRegion;
            $rank['played'] = $row['played'];


            $ranks[] = $rank;
        }

        $db->freeResult($res);

        $pagedata['ranks'] = $ranks;

        //Limits
        $pagedata['limits'] = [
            "matchLimit" => $matchLimit,
            "rankLimit" => $rankLimit,
        ];

        //Last Updated
        $pagedata['last_updated'] = time();

        //Max Age
        $pagedata['max_age'] = HotstatusCache::getCacheDefaultExpirationTimeInSecondsForToday();
    }
}