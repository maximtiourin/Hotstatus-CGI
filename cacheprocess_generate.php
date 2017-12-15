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
const E = PHP_EOL;

//Functions

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
 */
function generateFilterFragment($key, &$activemap) {
    $activevals = [];

    foreach ($activemap as $akey => $aobj) {
        if ($aobj['selected'] === TRUE) {
            $activevals[] = $akey;
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

function generateFilterKeyPermutations(&$filterKeyArray) {
    $filterKeyPermutations = [];

    foreach ($filterKeyArray as $fkkey => &$fkobj) {
        $filterKeyPermutations[$fkkey] => pc_array_power_set($fkobj);
    }

    return $filterKeyPermutations;
}

function generateCleanFilterListPartialCopy(&$filterlist) {
    $cleanfilterlist = [];

    foreach ($filterlist as $filterType => &$filterObj) {
        $cleanfilterlist[$filterType] = [];
        foreach ($filterObj as $filterObjType => &$filterObjObj) {
            $cleanfilterlist[$filterType][$filterObjType] = [
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

function generateHeroesStatslist() {
    GetDataTableHeroesStatsListAction::generateFilters();

    $query = GetDataTableHeroesStatsListAction::initQueries();

    //Filter List
    $filterList = generateFilterList($query);

    //Filter Key Array
    $filterKeyArray = generateFilterKeyArray($filterList);

    //Filter Key Permutations
    $filterKeyPermutations = generateFilterKeyPermutations($filterKeyArray);

    //Loop through all filter permutations and queue responses
    $permutationCount = 0;
    foreach ($filterKeyPermutations[HotstatusPipeline::FILTER_KEY_GAMETYPE] as $gameTypePermutation) {
        if (count($gameTypePermutation) > 0) {
            foreach ($filterKeyPermutations[HotstatusPipeline::FILTER_KEY_MAP] as $mapPermutation) {
                if (count($mapPermutation) > 0) {
                    foreach ($filterKeyPermutations[HotstatusPipeline::FILTER_KEY_RANK] as $rankPermutation) {
                        if (count($rankPermutation) > 0) {
                            foreach ($filterKeyArray[HotstatusPipeline::FILTER_KEY_DATE] as $dateSelection) {
                                //Copy clean filterlist (where everything is unselected)
                                $cleanfilterlist = generateCleanFilterListPartialCopy($filterList);

                                //Loop through chosen permutations and select them in a clean filter list
                                foreach ($gameTypePermutation as $gameType) {
                                    $cleanfilterlist[HotstatusPipeline::FILTER_KEY_GAMETYPE][$gameType]['selected'] = true;
                                }
                                foreach ($mapPermutation as $map) {
                                    $cleanfilterlist[HotstatusPipeline::FILTER_KEY_MAP][$map]['selected'] = true;
                                }
                                foreach ($rankPermutation as $rank) {
                                    $cleanfilterlist[HotstatusPipeline::FILTER_KEY_RANK][$rank]['selected'] = true;
                                }
                                $cleanfilterlist[HotstatusPipeline::FILTER_KEY_DATE][$dateSelection]['selected'] = true;

                                //


                                $permutationCount++;
                            }
                        }
                    }
                }
            }
        }
    }
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

//Prepare statements
$db->prepare("GetPipelineConfig",
    "SELECT `rankings_season` FROM `pipeline_config` WHERE `id` = ? LIMIT 1");
$db->bind("GetPipelineConfig", "i", $r_pipeline_config_id);

/*
 * Begin generation
 */
/*echo '--------------------------------------'.E
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

    //Heroes statslist generation

}
else {
    echo "Unable to get pipeline config...".E;
}*/

?>