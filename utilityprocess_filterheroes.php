<?php
/*
 * Utility Process Filter Heroes
 * Grabs proper hero names from the database and outputs a php str descrbing an associative array for them of structure:
 * ["ProperHeroName"] => [
 *       "image_minimap" => imageNameForMinimapImage
 * ]
 */

namespace Fizzik;

require_once 'includes/include.php';

use Fizzik\Database\MySqlDatabase;

set_time_limit(0);
date_default_timezone_set(HotstatusPipeline::REPLAY_TIMEZONE);

$db = new MysqlDatabase();
$creds = Credentials::getCredentialsForUser(Credentials::USER_REPLAYPROCESS);
$db->connect($creds[Credentials::KEY_DB_HOSTNAME], $creds[Credentials::KEY_DB_USER], $creds[Credentials::KEY_DB_PASSWORD], $creds[Credentials::KEY_DB_DATABASE]);
$db->setEncoding(HotstatusPipeline::DATABASE_CHARSET);

//Constants and qol
const E = PHP_EOL;

//Prepare statements
$db->prepare("GetHeroes", "SELECT * FROM herodata_heroes ORDER BY name_sort ASC");

$result = $db->execute("GetHeroes");
$result_rows = $db->countResultRows($result);
if ($result_rows > 0) {
    while ($row = $db->fetchArray($result)) {
        echo '"'.$row['name'].'" => ['.E
            ."\t".'"name_sort" => "'.$row['name_sort'].'",'.E
            ."\t".'"name_attribute" => "'.$row['name_attribute'].'",'.E
            ."\t".'"image_hero" => "'.$row['image_hero'].'",'.E
            ."\t".'"image_minimap" => "'.$row['image_minimap'].'",'.E
            ."\t".'"role_blizzard" => "'.$row['role_blizzard'].'",'.E
            ."\t".'"role_specific" => "'.$row['role_specific'].'",'.E
            ."\t".'"selected" => false'.E
            .'],'.E;
    }
}
else {
    echo 'No heroes found...'.E.E;
}
$db->freeResult($result);
$db->close();

?>