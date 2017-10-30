<?php

namespace Fizzik;

class ReplayParser {
    /*
     * Executes the ReplayParser process and returns the output as a json assoc array
     *
     * Expects the absolute directory to the script calling this function, so the script should pass __DIR__
     *
     * If the process encountered an error, the result array will only contain one field:
     * ['error'] => 'DESCRIPTION OF ERROR'
     */
    public static function ParseReplay($callingDirectory, $replayfilepath, $isLinux = false) {
        $linuxmono = ($isLinux) ? ("mono ") : ("");

        $output = shell_exec($linuxmono . $callingDirectory . HotstatusPipeline::REPLAY_EXECUTABLE_DIRECTORY . HotstatusPipeline::REPLAY_EXECUTABLE_ID_REPLAYPARSER . " " . $replayfilepath);

        //Remove potential UTF8 BOM
        $output = self::remove_utf8_bom($output);

        $json = json_decode($output, true);

        if ($json != null) {
            return $json;
        }
        else {
            return array('error' => 'Could not parse JSON from output');
        }
    }

    private static function remove_utf8_bom($text) {
        $bom = pack('H*','EFBBBF');
        $text = preg_replace("/^$bom/", '', $text);
        return $text;
    }
}