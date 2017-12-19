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

        //TODO - Figure out why in the everliving fuck any queries from this function return null results or fail the response altogether...

        $db->prepare("GetTopRanks",
            "SELECT mmr.`id` AS `playerid`, `name` AS `playername`, `rating`, (SELECT COUNT(`type`) FROM `$t_players_matches` pm INNER JOIN `matches` m ON pm.`match_id` = m.`id` AND pm.`region` = m.`region` WHERE pm.`id` = mmr.`id` AND `type` = \"$queryGameType\" AND pm.`date` >= \"$date_start\" AND pm.`date` <= \"$date_end\") AS `played` FROM `$t_players_mmr` mmr INNER JOIN `$t_players` p ON mmr.`id` = p.`id` AND mmr.`region` = p.`region` WHERE (SELECT COUNT(`type`) FROM `$t_players_matches` pm INNER JOIN `matches` m ON pm.`match_id` = m.`id` AND pm.`region` = m.`region` WHERE pm.`id` = mmr.`id` AND `type` = \"$queryGameType\" AND pm.`date` >= \"$date_start\" AND pm.`date` <= \"$date_end\") >= $matchLimit $querySql ORDER BY `rating` DESC LIMIT $rankLimit");
         // QuerySql no longer has AND prepended by default

        $db->prepare("GetRatings",
            "SELECT * FROM `rp_players_mmr` WHERE `region` = 1 AND `gameType` = \"Hero League\" AND `season` = \"2017 Season 3\" LIMIT 4");

        $db->prepare("CountMatchesPlayedForPlayer",
            "SELECT COUNT(*) AS `count` FROM `rp_players_matches` pm INNER JOIN `matches` m ON pm.`match_id` = m.`id` AND pm.`region` = m.`region` WHERE pm.`id` = ? AND pm.`region` = ? AND m.`type` = '$queryGameType' AND pm.`date` >= '$date_start' AND pm.`date` <= '$date_end'");
        $db->bind("CountMatchesPlayedForPlayer", "ii", $r_player_id, $r_region);

        $db->prepare("GetPlayerName",
            "SELECT `name` FROM `rp_players` WHERE `id` = ? AND `region` = ?");
        $db->bind("GetPlayerName", "ii", $r_player_id, $r_region);

        /*$ranks = [];
        $rankplace = 1;
        $rankresult = $db->execute("GetRatings");
        $rankresultrows = $db->countResultRows($rankresult);
        if ($rankresultrows > 0) {
            while ($row = $db->fetchArray($rankresult) && $rankplace <= $rankLimit) {*/
                //$r_player_id = $row['id'];
                //$r_region = $row['region'];

                /*$matchcountresult = $db->execute("CountMatchesPlayedForPlayer");
                $count = $db->fetchArray($matchcountresult)['count'];
                $db->freeResult($matchcountresult);

                if ($count >= $matchLimit) {*/
                //$nameresult = $db->execute("GetPlayerName");
                //$playername = $db->fetchArray($nameresult)['name'];


                /*$rank = [];

                $rank["rank"] = $rankplace++;
                $rank['player_id'] = ($row['id']);
                //$rank['player_name'] = $playername;
                $rank['rating'] = ($row['rating']);
                $rank['region'] = ($row['region']);
                $rank['played'] = 999;
                //$rank['played'] = $count;

                //$db->freeResult($nameresult);

                $ranks[] = $rank;
                // }
            }
        }*/

        /*$db->freeResult($rankresult);

        $pagedata['ranks'] = $ranks;*/

        /*$ranks = [];
        $ranknum = 1;
        $res = $db->execute("GetRatings");
        while ($row = $db->fetchArray($res)) {
            $rank = [];

            $rank['rank'] = $ranknum++;
            $rank['id'] = intval($row['id']);
            $rank['region'] = intval($row['region']);
            $rank['played'] = 999;
            $rank['rating'] = 2000;

            $ranks[] = $rank;
        }
        $db->freeResult($res);
        $pagedata['ranks'] = $ranks;*/


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