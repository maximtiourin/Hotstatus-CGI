<?php

namespace Fizzik;

use Fizzik\Utility\OS;

class ReplayParser {
    /*
     * Executes the ReplayParser process and returns the output as a json assoc array
     *
     * Expects the absolute directory to the script calling this function, so the script should pass __DIR__
     *
     * If the process encountered an error, the result array will only contain one field:
     * ['error'] => 'DESCRIPTION OF ERROR'
     */
    public static function ParseReplay($callingDirectory, $replayfilepath) {
        $linuxmono = (OS::getOS() == OS::OS_LINUX) ? ("mono ") : ("");

        $output = shell_exec($linuxmono . $callingDirectory . HotstatusPipeline::REPLAY_EXECUTABLE_DIRECTORY . HotstatusPipeline::REPLAY_EXECUTABLE_ID_REPLAYPARSER . " " . $replayfilepath);

        $json = json_decode($output, true);

        if ($json != null) {
            return $json;
        }
        else {
            return array('error' => 'Could not parse JSON from output');
        }
    }
}