<?php

namespace Fizzik;

require_once 'includes/include.php';
require_once 'includes/HotstatusResponse/include.php';

use Fizzik\Database\MySqlDatabase;

set_time_limit(0);
date_default_timezone_set(HotstatusPipeline::REPLAY_TIMEZONE);

$db = new MysqlDatabase();
$creds = Credentials::getCredentialsForUser(Credentials::USER_REPLAYPROCESS);
HotstatusPipeline::hotstatus_mysql_connect($db, $creds);
$db->setEncoding(HotstatusPipeline::DATABASE_CHARSET);

//Constants and qol
const MAX_RANK_SIZE = 6;
const MAX_GAMETYPE_SIZE = 4;
const E = PHP_EOL;

//Targeted generation
//$target_date_range = "Last 7 Days"; //TODO - Renable by uncommenting
$target_date_range = null;

$target_patch_range = "2.33.0 (Rework)";
//$target_patch = HotstatusPipeline::$PATCHES[HotstatusPipeline::PATCH_CURRENT];
//$target_patch_range = $target_patch['version']." (".$target_patch['type'].")";

//Prepare statements
$db->prepare("GetPipelineConfig",
    "SELECT `rankings_season` FROM `pipeline_config` WHERE `id` = ? LIMIT 1");
$db->bind("GetPipelineConfig", "i", $r_pipeline_config_id);

$db->prepare("QueueCacheRequest", "INSERT INTO `pipeline_cache_requests` (`action`, `cache_id`, `payload`, `lastused`, `status`, `priority`) VALUES (?, ?, ?, ?, ?, ?)");
$db->bind("QueueCacheRequest", "sssiii", $r_action, $r_cache_id, $r_payload, $r_lastused, $r_status, $r_priority);

//Functions
function log_generating($functionId) {
    echo "Generating $functionId...".E;
}

function log_currentgeneration($permutationCount) {
    echo "Queue Cache Request #$permutationCount...                                      \r";
}

function log_totalgenerated($permutationCount) {
    echo ($permutationCount - 1) . " Total Cache Requests Queued.          ".E.E;
}

function queueCacheRequest($functionId, $cache_id, $payload, $priority, $permutationCount = null) {
    global $db, $r_action, $r_cache_id, $r_payload, $r_lastused, $r_status, $r_priority;

    $r_action = $functionId;
    $r_cache_id = $cache_id;
    $r_payload = json_encode($payload);
    $r_lastused = time();
    $r_status = HotstatusCache::QUEUE_CACHE_STATUS_QUEUED;
    $r_priority = $priority;

    $db->execute("QueueCacheRequest");

    if ($permutationCount !== null) {
        log_currentgeneration($permutationCount);
    }
}

/*
 * Given a filter key (EX: 'gameType'), and an active map, where active map =
 * [
 *      "selection key" => ["selected" => TRUE||FALSE],
 *      ...,
 *      ...,
 * ]
 *
 * returns a filter fragment object of type
 * [
 *  "key" => '$key',
 *  "value" => '$value' || '$value1,$value2,$value3,...,$valueN'
 * ]
 *
 *
 * if the active map has a 'value' => ... relation inside of its object, then that value will be used instead of the key for activevals
 */
function generateFilterFragment($key, &$activemap) {
    $activevals = [];

    foreach ($activemap as $akey => $aobj) {
        if ($aobj['selected'] === TRUE) {
            $activevals[] = $aobj['value'];
        }
    }

    $val = "";

    if (count($activevals) > 0) {
        $val = join(",", $activevals);
    }

    return [
        "key" => $key,
        "value" => $val,
    ];
}

function generateFilterList(&$query) {
    $filterlist = [];

    foreach ($query as $qkey => &$qobj) {
        $filterlist[$qkey] = HotstatusPipeline::$filter[$qkey];
    }

    return $filterlist;
}

function generateFilterKeyArray(&$filterlist) {
    $filterKeyArray = [];

    foreach ($filterlist as $fkey => &$fobj) {
        $filterKeyArray[$fkey] = getFilterKeyArray($fobj);
    }

    return $filterKeyArray;
}

function generateFilterKeyPermutations(&$filterKeyArray, &$query) {
    $filterKeyPermutations = [];

    foreach ($filterKeyArray as $fkkey => &$fkobj) {
        if ($query[$fkkey][HotstatusResponse::QUERY_COMBINATORIAL]) {
            $filterKeyPermutations[$fkkey] = pc_array_power_set($fkobj);
        }
    }

    return $filterKeyPermutations;
}

function generateCleanFilterListPartialCopy(&$filterlist) {
    $cleanfilterlist = [];

    foreach ($filterlist as $filterType => &$filterObj) {
        $cleanfilterlist[$filterType] = [];
        foreach ($filterObj as $filterObjType => &$filterObjObj) {
            if (key_exists("value", $filterObjObj)) {
                $value = $filterObjObj['value'];
            }
            else {
                $value = $filterObjType;
            }

            $cleanfilterlist[$filterType][$filterObjType] = [
                "value" => $value,
                "selected" => false,
            ];
        }
    }

    return $cleanfilterlist;
}

/*
 * Returns an array of the filter's keys, for use with permutation generation
 */
function getFilterKeyArray(&$filter) {
    $res = [];
    foreach ($filter as $fkey => $fobj) {
        $res[] = $fkey;
    }
    return $res;
}

function pc_array_power_set(&$array) {
    // initialize by adding the empty set
    $results = array(array( ));

    foreach ($array as $element)
        foreach ($results as $combination)
            array_push($results, array_merge(array($element), $combination));

    return $results;
}

//Checks to see if arrays are of same size and if arr2 contains all elements of arr1, does not account for duplicates in anyway
function areArraysEqual($arr1, $arr2) {
    if (count($arr1) !== count($arr2)) {
        return false;
    }

    for ($i = 0; $i < count($arr1); $i++) {
        if (!in_array($arr1[$i], $arr2, true)) {
            return false;
        }
    }

    return true;
}

function generate_getPageDataRankingsAction() {
    $_TYPE = GetPageDataRankingsAction::_TYPE();
    $_ID = GetPageDataRankingsAction::_ID();
    $_VERSION = GetPageDataRankingsAction::_VERSION();

    GetPageDataRankingsAction::generateFilters();

    log_generating($_ID);

    $query = GetPageDataRankingsAction::initQueries();

    //Filter List
    $filterList = generateFilterList($query);

    //Filter Key Array
    $filterKeyArray = generateFilterKeyArray($filterList);

    //Loop through all filter permutations and queue responses
    $permutationCount = 1;
    foreach ($filterKeyArray[HotstatusPipeline::FILTER_KEY_REGION] as $regionSelection) {
        foreach ($filterKeyArray[HotstatusPipeline::FILTER_KEY_SEASON] as $seasonSelection) {
            foreach ($filterKeyArray[HotstatusPipeline::FILTER_KEY_GAMETYPE] as $gameTypeSelection) {
                if (HotstatusPipeline::$filter[HotstatusPipeline::FILTER_KEY_GAMETYPE][$gameTypeSelection]['aggregate'] === FALSE) {
                    //Calculate priority (Higher number has higher priority)
                    $priority = 1000000; //Rankings should process before statslist and before hero page

                    //Copy clean filterlist (where everything is unselected)
                    $cleanfilterlist = generateCleanFilterListPartialCopy($filterList);

                    //Set selections
                    $cleanfilterlist[HotstatusPipeline::FILTER_KEY_REGION][$regionSelection]['selected'] = true;
                    $cleanfilterlist[HotstatusPipeline::FILTER_KEY_SEASON][$seasonSelection]['selected'] = true;
                    $cleanfilterlist[HotstatusPipeline::FILTER_KEY_GAMETYPE][$gameTypeSelection]['selected'] = true;

                    //Generate requestQuery with filter fragments
                    $requestQuery = new HotstatusResponse();

                    foreach ($cleanfilterlist as $cfkey => &$cfobj) {
                        $fragment = generateFilterFragment($cfkey, $cfobj);

                        $requestQuery->addQuery($fragment['key'], $fragment['value']);
                    }

                    //Process requestQuery
                    $queryCacheValues = [];
                    $querySqlValues = [];

                    //Collect WhereOr strings from all query parameters for cache key
                    foreach ($query as $qkey => &$qobj) {
                        if ($requestQuery->has($qkey)) {
                            $qobj[HotstatusResponse::QUERY_ISSET] = true;
                            $qobj[HotstatusResponse::QUERY_RAWVALUE] = $requestQuery->get($qkey);
                            $qobj[HotstatusResponse::QUERY_SQLVALUE] = HotstatusResponse::buildQuery_WhereOr_String($qkey, $qobj[HotstatusResponse::QUERY_SQLCOLUMN], $qobj[HotstatusResponse::QUERY_RAWVALUE], $qobj[HotstatusResponse::QUERY_TYPE]);
                            $queryCacheValues[] = $query[$qkey][HotstatusResponse::QUERY_RAWVALUE];
                        }
                    }

                    $querySeason = $query[HotstatusPipeline::FILTER_KEY_SEASON][HotstatusResponse::QUERY_RAWVALUE];
                    $queryGameType = $query[HotstatusPipeline::FILTER_KEY_GAMETYPE][HotstatusResponse::QUERY_RAWVALUE];
                    $queryRegion = $query[HotstatusPipeline::FILTER_KEY_REGION][HotstatusResponse::QUERY_RAWVALUE];

                    //Collect WhereOr strings from non-ignored query parameters for dynamic sql query
                    foreach ($query as $qkey => &$qobj) {
                        if (!$qobj[HotstatusResponse::QUERY_IGNORE_AFTER_CACHE] && $qobj[HotstatusResponse::QUERY_ISSET]) {
                            $querySqlValues[] = $query[$qkey][HotstatusResponse::QUERY_SQLVALUE];
                        }
                    }

                    //Build WhereAnd string from collected WhereOr strings
                    $queryCache = HotstatusResponse::buildCacheKey($queryCacheValues);
                    $querySql = HotstatusResponse::buildQuery_WhereAnd_String($querySqlValues, TRUE);

                    //Determine Cache Id
                    $CACHE_ID = "$_ID:rankings" . ((strlen($queryCache) > 0) ? (":" . md5($queryCache)) : (""));

                    //Define Payload
                    $payload = [
                        "querySeason" => $querySeason,
                        "queryGameType" => $queryGameType,
                        "queryRegion" => $queryRegion,
                        "querySql" => $querySql,
                    ];

                    //Queue Cache Request
                    queueCacheRequest($_ID, $CACHE_ID, $payload, $priority, $permutationCount);

                    $permutationCount++;
                }
            }
        }
    }

    log_totalgenerated($permutationCount);
}

function generate_getDataTableHeroesStatsListAction() {
    global $target_date_range, $target_patch_range, $target_rank_range;

    $_TYPE = GetDataTableHeroesStatsListAction::_TYPE();
    $_ID = GetDataTableHeroesStatsListAction::_ID();
    $_VERSION = GetDataTableHeroesStatsListAction::_VERSION();

    GetDataTableHeroesStatsListAction::generateFilters();

    log_generating($_ID);

    $query = GetDataTableHeroesStatsListAction::initQueries();

    //Filter List
    $filterList = generateFilterList($query);

    //Filter Key Array
    $filterKeyArray = generateFilterKeyArray($filterList);

    //Filter Key Permutations
    //$filterKeyPermutations = generateFilterKeyPermutations($filterKeyArray, $query);

    //Loop through all filter permutations and queue responses
    $permutationCount = 1;
    foreach ($filterKeyArray[HotstatusPipeline::FILTER_KEY_GAMETYPE] as $gameTypeSelection) {
        foreach ($filterKeyArray[HotstatusPipeline::FILTER_KEY_MAP] as $mapSelection) {
            foreach ($filterKeyArray[HotstatusPipeline::FILTER_KEY_RANK] as $rankSelection) {
                if (HotstatusPipeline::$filter[HotstatusPipeline::FILTER_KEY_RANK][$rankSelection]['selectable'] === TRUE
                    && HotstatusPipeline::$filter[HotstatusPipeline::FILTER_KEY_RANK][$rankSelection]['enableHeroes'] === TRUE) {
                    foreach ($filterKeyArray[HotstatusPipeline::FILTER_KEY_DATE] as $dateSelection) {
                        if (($target_date_range === null && $target_patch_range === null) || $target_date_range === $dateSelection || $target_patch_range === $dateSelection) {
                            //Calculate priority (Higher number has higher priority)
                            $priority = 100000; //Statslist should process before hero page

                            if ($rankSelection === "All Ranks") {
                                $priority += 900;
                            }
                            elseif ($rankSelection === "High Ranks") {
                                $priority += 500;
                            }
                            elseif ($rankSelection === "Low Ranks") {
                                $priority += 200;
                            }

                            if ($gameTypeSelection === "All Leagues") {
                                $priority += 2000;
                            }
                            elseif ($gameTypeSelection === "Draft Leagues") {
                                $priority += 1000;
                            }

                            if ($mapSelection === "All Maps") {
                                $priority += 10000;
                            }

                            $priority += $filterList[HotstatusPipeline::FILTER_KEY_DATE][$dateSelection]['generation']['priority'] + 1; //Add 1 to take precedence over equal hero page dates

                            //Copy clean filterlist (where everything is unselected)
                            $cleanfilterlist = generateCleanFilterListPartialCopy($filterList);

                            //Set selections
                            $cleanfilterlist[HotstatusPipeline::FILTER_KEY_GAMETYPE][$gameTypeSelection]['selected'] = true;
                            $cleanfilterlist[HotstatusPipeline::FILTER_KEY_MAP][$mapSelection]['selected'] = true;
                            $cleanfilterlist[HotstatusPipeline::FILTER_KEY_RANK][$rankSelection]['selected'] = true;
                            $cleanfilterlist[HotstatusPipeline::FILTER_KEY_DATE][$dateSelection]['selected'] = true;

                            //Generate requestQuery with filter fragments
                            $requestQuery = new HotstatusResponse();

                            foreach ($cleanfilterlist as $cfkey => &$cfobj) {
                                $fragment = generateFilterFragment($cfkey, $cfobj);

                                $requestQuery->addQuery($fragment['key'], $fragment['value']);
                            }

                            //Process requestQuery
                            $queryCacheValues = [];
                            $querySqlValues = [];

                            //Collect WhereOr strings from all query parameters for cache key
                            foreach ($query as $qkey => &$qobj) {
                                if ($requestQuery->has($qkey)) {
                                    $qobj[HotstatusResponse::QUERY_ISSET] = true;
                                    $qobj[HotstatusResponse::QUERY_RAWVALUE] = $requestQuery->get($qkey);
                                    $qobj[HotstatusResponse::QUERY_SQLVALUE] = HotstatusResponse::buildQuery_WhereOr_String($qkey, $qobj[HotstatusResponse::QUERY_SQLCOLUMN], $qobj[HotstatusResponse::QUERY_RAWVALUE], $qobj[HotstatusResponse::QUERY_TYPE]);
                                    $queryCacheValues[] = $query[$qkey][HotstatusResponse::QUERY_RAWVALUE];
                                }
                            }

                            $queryDateKey = $query[HotstatusPipeline::FILTER_KEY_DATE][HotstatusResponse::QUERY_RAWVALUE];

                            //Collect WhereOr strings from non-ignored query parameters for dynamic sql query
                            foreach ($query as $qkey => &$qobj) {
                                if (!$qobj[HotstatusResponse::QUERY_IGNORE_AFTER_CACHE] && $qobj[HotstatusResponse::QUERY_ISSET]) {
                                    $querySqlValues[] = $query[$qkey][HotstatusResponse::QUERY_SQLVALUE];
                                }
                            }

                            //Build WhereAnd string from collected WhereOr strings
                            $queryCache = HotstatusResponse::buildCacheKey($queryCacheValues);
                            $querySql = HotstatusResponse::buildQuery_WhereAnd_String($querySqlValues);

                            //Determine cache id from query parameters
                            $CACHE_ID = $_ID . ((strlen($queryCache) > 0) ? (":" . md5($queryCache)) : (""));

                            //Define payload
                            $payload = [
                                "queryDateKey" => $queryDateKey,
                                "querySql" => $querySql,
                            ];

                            //Queue Cache Request
                            queueCacheRequest($_ID, $CACHE_ID, $payload, $priority, $permutationCount);

                            $permutationCount++;
                        }
                    }
                }
            }
        }
    }

    log_totalgenerated($permutationCount);
}

function generate_getPageDataHeroAction() {
    global $target_date_range, $target_patch_range, $target_rank_range;

    $_TYPE = GetPageDataHeroAction::_TYPE();
    $_ID = GetPageDataHeroAction::_ID();
    $_VERSION = GetPageDataHeroAction::_VERSION();

    GetPageDataHeroAction::generateFilters();

    log_generating($_ID);

    $query = GetPageDataHeroAction::initQueries();

    //Filter List
    $filterList = generateFilterList($query);

    //Filter Key Array
    $filterKeyArray = generateFilterKeyArray($filterList);

    //Filter Key Permutations
    //$filterKeyPermutations = generateFilterKeyPermutations($filterKeyArray, $query);

    //Loop through all filter permutations and queue responses
    $permutationCount = 1;
    foreach ($filterKeyArray[HotstatusPipeline::FILTER_KEY_GAMETYPE] as $gameTypeSelection) {
        foreach ($filterKeyArray[HotstatusPipeline::FILTER_KEY_MAP] as $mapSelection) {
            foreach ($filterKeyArray[HotstatusPipeline::FILTER_KEY_RANK] as $rankSelection) {
                if (HotstatusPipeline::$filter[HotstatusPipeline::FILTER_KEY_RANK][$rankSelection]['selectable'] === TRUE
                    && HotstatusPipeline::$filter[HotstatusPipeline::FILTER_KEY_RANK][$rankSelection]['enableHero'] === TRUE) {
                    foreach ($filterKeyArray[HotstatusPipeline::FILTER_KEY_DATE] as $dateSelection) {
                        if (($target_date_range === null && $target_patch_range === null) || $target_date_range === $dateSelection || $target_patch_range === $dateSelection) {
                            foreach ($filterKeyArray[HotstatusPipeline::FILTER_KEY_HERO] as $heroSelection) {
                                //Calculate priority (Higher number has higher priority)
                                $priority = 0;

                                if ($rankSelection === "All Ranks") {
                                    $priority += 900;
                                }
                                elseif ($rankSelection === "High Ranks") {
                                    $priority += 500;
                                }
                                elseif ($rankSelection === "Low Ranks") {
                                    $priority += 200;
                                }

                                if ($gameTypeSelection === "All Leagues") {
                                    $priority += 2000;
                                }
                                elseif ($gameTypeSelection === "Draft Leagues") {
                                    $priority += 1000;
                                }

                                if ($mapSelection === "All Maps") {
                                    $priority += 10000;
                                }

                                $priority += $filterList[HotstatusPipeline::FILTER_KEY_DATE][$dateSelection]['generation']['priority'];

                                //Copy clean filterlist (where everything is unselected)
                                $cleanfilterlist = generateCleanFilterListPartialCopy($filterList);

                                //Set selections
                                $cleanfilterlist[HotstatusPipeline::FILTER_KEY_GAMETYPE][$gameTypeSelection]['selected'] = true;
                                $cleanfilterlist[HotstatusPipeline::FILTER_KEY_MAP][$mapSelection]['selected'] = true;
                                $cleanfilterlist[HotstatusPipeline::FILTER_KEY_RANK][$rankSelection]['selected'] = true;
                                $cleanfilterlist[HotstatusPipeline::FILTER_KEY_DATE][$dateSelection]['selected'] = true;
                                $cleanfilterlist[HotstatusPipeline::FILTER_KEY_HERO][$heroSelection]['selected'] = true;

                                //Generate requestQuery with filter fragments
                                $requestQuery = new HotstatusResponse();

                                foreach ($cleanfilterlist as $cfkey => &$cfobj) {
                                    $fragment = generateFilterFragment($cfkey, $cfobj);

                                    $requestQuery->addQuery($fragment['key'], $fragment['value']);
                                }

                                //Process requestQuery
                                $queryCacheValues = [];
                                $querySqlValues = [];
                                $querySecondaryCacheValues = [];
                                $querySecondarySqlValues = [];

                                //Collect WhereOr strings from all query parameters for cache key
                                foreach ($query as $qkey => &$qobj) {
                                    if ($requestQuery->has($qkey)) {
                                        $qobj[HotstatusResponse::QUERY_ISSET] = true;
                                        $qobj[HotstatusResponse::QUERY_RAWVALUE] = $requestQuery->get($qkey);
                                        $qobj[HotstatusResponse::QUERY_SQLVALUE] = HotstatusResponse::buildQuery_WhereOr_String($qkey, $qobj[HotstatusResponse::QUERY_SQLCOLUMN], $qobj[HotstatusResponse::QUERY_RAWVALUE], $qobj[HotstatusResponse::QUERY_TYPE]);
                                        $queryCacheValues[] = $query[$qkey][HotstatusResponse::QUERY_RAWVALUE];

                                        if ($qkey !== HotstatusPipeline::FILTER_KEY_HERO) {
                                            $querySecondaryCacheValues[] = $query[$qkey][HotstatusResponse::QUERY_RAWVALUE];
                                        }
                                    }
                                }

                                $queryHero = $query[HotstatusPipeline::FILTER_KEY_HERO][HotstatusResponse::QUERY_RAWVALUE];

                                //Collect WhereOr strings from non-ignored query parameters for dynamic sql query
                                foreach ($query as $qkey => &$qobj) {
                                    if (!$qobj[HotstatusResponse::QUERY_IGNORE_AFTER_CACHE] && $qobj[HotstatusResponse::QUERY_ISSET]) {
                                        $querySqlValues[] = $query[$qkey][HotstatusResponse::QUERY_SQLVALUE];
                                    }
                                }

                                //Collect WhereOr strings for query parameters for dynamic sql query
                                foreach ($query as $qkey => &$qobj) {
                                    if ($qobj[HotstatusResponse::QUERY_USE_FOR_SECONDARY] && $qobj[HotstatusResponse::QUERY_ISSET]) {
                                        $querySecondarySqlValues[] = $query[$qkey][HotstatusResponse::QUERY_SQLVALUE];
                                    }
                                }

                                //Build WhereAnd string from collected WhereOr strings
                                $queryCache = HotstatusResponse::buildCacheKey($queryCacheValues);
                                $querySql = HotstatusResponse::buildQuery_WhereAnd_String($querySqlValues, false);
                                $querySecondaryCache = HotstatusResponse::buildCacheKey($querySecondaryCacheValues);
                                $querySecondarySql = HotstatusResponse::buildQuery_WhereAnd_String($querySecondarySqlValues, true);

                                //Determine Cache Id
                                $CACHE_ID = $_ID . ":" . $queryHero . ((strlen($queryCache) > 0) ? (":" . md5($queryCache)) : (""));

                                //Define Payload
                                $payload = [
                                    "queryHero" => $queryHero,
                                    "querySql" => $querySql,
                                    "querySecondaryCache" => $querySecondaryCache,
                                    "querySecondarySql" => $querySecondarySql,
                                ];

                                //Queue Cache Request
                                queueCacheRequest($_ID, $CACHE_ID, $payload, $priority, $permutationCount);

                                $permutationCount++;
                            }
                        }
                    }
                }
            }
        }
    }

    log_totalgenerated($permutationCount);
}

//Test definition
$test = [
    "generateFilterFragment" => function() {
        $test = generateFilterFragment("map", HotstatusPipeline::$filter[HotstatusPipeline::FILTER_KEY_MAP]);
        echo $test['key'] . "=" . $test['value'] .E;
    },
    "generateHeroesStatslist" => function() {
        //$test = generateHeroesStatslist();

        //var_dump($test[HotstatusPipeline::FILTER_KEY_GAMETYPE]);
    }
];

/*
 * Begin generation
 */
echo '--------------------------------------'.E
    .'Cache process <<GENERATE>> has started'.E
    .'--------------------------------------'.E;
//Get pipeline configuration
$r_pipeline_config_id = HotstatusPipeline::$pipeline_config[HotstatusPipeline::PIPELINE_CONFIG_DEFAULT]['id'];
$pipeconfigresult = $db->execute("GetPipelineConfig");
$pipeconfigresrows = $db->countResultRows($pipeconfigresult);
if ($pipeconfigresrows > 0) {
    $pipeconfig = $db->fetchArray($pipeconfigresult);

    $rankings_season = $pipeconfig['rankings_season'];

    $db->freeResult($pipeconfigresult);

    //Generation
    generate_getPageDataRankingsAction();
    generate_getDataTableHeroesStatsListAction();
    generate_getPageDataHeroAction();
}
else {
    echo "Unable to get pipeline config...".E;
}

/*
 * Notes
 */
/*

--> PERMUTATION CALCULATIONS

Heroes Statslist
--------------------
15 = gameType ***
16383 = map
63 = rank ***
12 = date ***
TOTAL *** (11340) = ~333MB

Hero Page
--------------------
15 = gameType ***
16383 = map
63 = rank
12 = date ***
75 = hero ***
TOTAL *** (13500) = ~396MB

Rankings
--------------------
4 = region ***
2 = season ***
4 = gameType ***
TOTAL *** (32) = ~1 MB

 */

?>