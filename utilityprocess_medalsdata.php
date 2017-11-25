<?php
/*
 * Utility Process Medals Data
 */

namespace Fizzik;

require_once 'includes/include.php';

use Fizzik\Database\MySqlDatabase;

set_time_limit(0);
date_default_timezone_set(HotstatusPipeline::REPLAY_TIMEZONE);

$db = new MysqlDatabase();
$creds = Credentials::getCredentialsForUser(Credentials::USER_REPLAYPROCESS);
HotstatusPipeline::hotstatus_mysql_connect($db, $creds);
$db->setEncoding(HotstatusPipeline::DATABASE_CHARSET);

//Constants and qol
const E = PHP_EOL;

//Prepare statements
$db->prepare("GetMedals", "SELECT * FROM herodata_awards ORDER BY id ASC");

$result = $db->execute("GetMedals");
$result_rows = $db->countResultRows($result);
if ($result_rows > 0) {
    while ($row = $db->fetchArray($result)) {
        echo '"'.$row['id'].'" => ['.E
            ."\t".'"name" => "'.$row['name'].'",'.E
            ."\t".'"desc_simple" => "'.$row['desc_simple'].'",'.E
            ."\t".'"image" => "'.$row['image'].'",'.E
            .'],'.E;
    }
}
else {
    echo 'No medals found...'.E.E;
}
$db->freeResult($result);
$db->close();

?>