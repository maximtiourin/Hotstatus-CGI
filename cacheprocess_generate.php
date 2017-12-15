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
function generateFilterFragment($key, $activemap) {
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

/*
 * Returns an array of the filter's keys, for use with permutation generation
 */
function getFilterKeyArray($filter) {
    $res = [];
    foreach ($filter as $fkey => $fobj) {
        $res[] = $fkey;
    }
    return $res;
}

function pc_array_power_set($array) {
    // initialize by adding the empty set
    $results = array(array( ));

    foreach ($array as $element)
        foreach ($results as $combination)
            array_push($results, array_merge(array($element), $combination));

    return $results;
}

function filterListSetDefaultUnselected(&$filterList) {
    foreach ($filterList as $filterType => &$filterObj) {
        foreach ($filterObj as $filterObjType => &$filterObjObj) {
            $filterObjObj['selected'] = false;
        }
    }
}

function generateHeroesStatslist() {
    GetDataTableHeroesStatsListAction::generateFilters();

    $filterList = [
        HotstatusPipeline::FILTER_KEY_GAMETYPE => HotstatusPipeline::$filter[HotstatusPipeline::FILTER_KEY_GAMETYPE],
        HotstatusPipeline::FILTER_KEY_MAP => HotstatusPipeline::$filter[HotstatusPipeline::FILTER_KEY_MAP],
        HotstatusPipeline::FILTER_KEY_RANK => HotstatusPipeline::$filter[HotstatusPipeline::FILTER_KEY_RANK],
        HotstatusPipeline::FILTER_KEY_DATE => HotstatusPipeline::$filter[HotstatusPipeline::FILTER_KEY_DATE],
    ];

    //Default all filters to unselected
    filterListSetDefaultUnselected($filterList);

    $filterKeyArray = [
        HotstatusPipeline::FILTER_KEY_GAMETYPE => getFilterKeyArray($filterList[HotstatusPipeline::FILTER_KEY_GAMETYPE]),
        HotstatusPipeline::FILTER_KEY_MAP => getFilterKeyArray($filterList[HotstatusPipeline::FILTER_KEY_MAP]),
        HotstatusPipeline::FILTER_KEY_RANK => getFilterKeyArray($filterList[HotstatusPipeline::FILTER_KEY_RANK]),
        HotstatusPipeline::FILTER_KEY_DATE => getFilterKeyArray($filterList[HotstatusPipeline::FILTER_KEY_DATE]),
    ];

    $filterKeyPermutations = [
        HotstatusPipeline::FILTER_KEY_GAMETYPE => pc_array_power_set($filterKeyArray[HotstatusPipeline::FILTER_KEY_GAMETYPE]),
        HotstatusPipeline::FILTER_KEY_MAP => pc_array_power_set($filterKeyArray[HotstatusPipeline::FILTER_KEY_MAP]),
        HotstatusPipeline::FILTER_KEY_RANK => pc_array_power_set($filterKeyArray[HotstatusPipeline::FILTER_KEY_RANK]),
    ];

    //Generate all filter permutations
}

//Test definition
$test = [
    "generateFilterFragment" => function() {
        $test = generateFilterFragment("map", HotstatusPipeline::$filter[HotstatusPipeline::FILTER_KEY_MAP]);
        echo $test['key'] . "=" . $test['value'] .E;
    },
    "generateHeroesStatslist" => function() {
        $test = generateHeroesStatslist();

        var_dump($test[HotstatusPipeline::FILTER_KEY_GAMETYPE]);
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