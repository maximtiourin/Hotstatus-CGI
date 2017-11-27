<?php
/*
 * Utility Process Rank Distribution
 */

namespace Fizzik;

require_once 'includes/include.php';

use Fizzik\Database\MySqlDatabase;
use Fizzik\Utility\AssocArray;

set_time_limit(0);
date_default_timezone_set(HotstatusPipeline::REPLAY_TIMEZONE);

$db = new MysqlDatabase();
$creds = Credentials::getCredentialsForUser(Credentials::USER_REPLAYPROCESS);
HotstatusPipeline::hotstatus_mysql_connect($db, $creds);
$db->setEncoding(HotstatusPipeline::DATABASE_CHARSET);

//Constants and qol
const E = PHP_EOL;

//Prepare statements
$db->prepare("GetRatings", "SELECT `rating` FROM `players_mmr` WHERE `season` = ? ORDER BY `rating` ASC");
$db->bind("GetRatings", "s", $r_season);

$r_season = "2017 Season 3";
$r_league = "Quick Match";

$stepsize = 1;
$ratings = [];

$result = $db->execute("GetRatings");
$result_rows = $db->countResultRows($result);
if ($result_rows > 0) {
    while ($row = $db->fetchArray($result)) {
        $step = [
            HotstatusPipeline::getFixedMMRStep($row['rating'], $stepsize) => 1,
        ];

        AssocArray::aggregate($ratings, $step, $null = null, AssocArray::AGGREGATE_SUM);
    }
}
$db->freeResult($result);
$db->close();

//Output ratings step distribution
echo "players: $result_rows\n\n";
/*foreach ($ratings as $rstep => $rstepcount) {
    $stepinc = $stepsize + intval($rstep) - 1;

    echo "$rstep -> $stepinc: $rstepcount\n";
}*/

echo "\n\n";

//Build ratings normal array
$ratingsarr = [];

foreach ($ratings as $rkey => $robj) {
    $ratingsarr[] = $rkey;
}

//top function
$toppercent = function(&$arr, $ratingcount, &$ratingsassoc, $percent, $name) {
    $pct = $ratingcount * $percent;

    $count = 0;
    for ($i = count($arr) - 1; $i > 0; $i--) {
        $step = $arr[$i];

        $count += $ratingsassoc[$step];

        if ($count >= $pct) {
            return "$name >= Rating($step)\n";
        }
    }

    return "$name >= UNKNOWN\n";
};

//Top .1% (Master)
echo $toppercent($ratingsarr, $result_rows, $ratings, .001, "Master");

//Top 2.1% (Diamond)
echo $toppercent($ratingsarr, $result_rows, $ratings, .021, "Diamond");

//Top 17.1% (Platinum)
echo $toppercent($ratingsarr, $result_rows, $ratings, .171, "Platinum");

//Top 52.1% (Gold)
echo $toppercent($ratingsarr, $result_rows, $ratings, .521, "Gold");

//Top 82.1% (Silver)
echo $toppercent($ratingsarr, $result_rows, $ratings, .821, "Silver");
?>