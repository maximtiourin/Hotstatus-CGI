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

//Match Average Mmr Rank Distribution
$selector = 'mmr';
$db->prepare("GetRatings", "SELECT `mmr_average`, `played` FROM `heroes_matches_recent_granular` WHERE `date_end` >= ?");
$db->bind("GetRatings", "s", $r_date);

$r_season = "2017 Season 3";
$r_date = HotstatusPipeline::$SEASONS[$r_season]["start"];

$stepsize = 100;
$ratings = [];

$result = $db->execute("GetRatings");
$result_rows = $db->countResultRows($result);
$matchCount = 0;
if ($result_rows > 0) {
    while ($row = $db->fetchArray($result)) {
        $step = [
            HotstatusPipeline::getFixedMMRStep($row['mmr_average'], $stepsize) => $row['played'],
        ];

        $matchCount += $row['played'];

        AssocArray::aggregate($ratings, $step, $null = null, AssocArray::AGGREGATE_SUM);
    }
}
$db->freeResult($result);
$db->close();

$matchCount /= 10; //Estimate match count for granular data

//Output ratings step distribution
echo "matches: $matchCount\n\n";
foreach ($ratings as $rstep => $rstepcount) {
    $stepinc = $stepsize + intval($rstep) - 1;

    $countestimate = $rstepcount / 10;

    echo "$rstep -> $stepinc: $countestimate\n";
}

echo "\n\n";

//Build ratings normal array
$ratingsarr = [];

foreach ($ratings as $rkey => $robj) {
    $ratingsarr[] = $rkey;
}

sort($ratingsarr);

//top function
$toppercent = function(&$arr, $ratingcount, &$ratingsassoc, $percent, $name) {
    $pct = $ratingcount * $percent;

    $count = 0;
    for ($i = count($arr) - 1; $i > 0; $i--) {
        $step = $arr[$i];

        $count += $ratingsassoc[$step] / 10; //Estimate count for granular data

        if ($count >= $pct) {
            return "$name >= Rating($step)\n";
        }
    }

    return "$name >= UNKNOWN\n";
};

//Top .1% (Master)
echo $toppercent($ratingsarr, $matchCount, $ratings, .001, "Master");

//Top 2.1% (Diamond)
echo $toppercent($ratingsarr, $matchCount, $ratings, .021, "Diamond");

//Top 17.1% (Platinum)
echo $toppercent($ratingsarr, $matchCount, $ratings, .171, "Platinum");

//Top 52.1% (Gold)
echo $toppercent($ratingsarr, $matchCount, $ratings, .521, "Gold");

//Top 82.1% (Silver)
echo $toppercent($ratingsarr, $matchCount, $ratings, .821, "Silver");
?>