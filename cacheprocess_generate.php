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

//Test definition
$test = [
    "generateFilterFragment" => function() {
        $test = generateFilterFragment("map", HotstatusPipeline::$filter[HotstatusPipeline::FILTER_KEY_MAP]);
        echo $test['key'] . "=" . $test['value'] .E;
    }
];

//Prepare statements


?>