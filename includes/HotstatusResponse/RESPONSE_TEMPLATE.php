<?php

namespace Fizzik;

use Fizzik\Database\MySqlDatabase;

class RESPONSE_TEMPLATE {
    public static function _TYPE() {
        return "";
    }

    public static function _ID() {
        return "";
    }

    public static function _VERSION() {
        return 1;
    }

    public static function generateFilters() {

    }

    public static function execute(&$payload, MySqlDatabase &$db, &$pagedata, $isCacheProcess = false) {
        //Extract payload

        //Define main vars

        //Build Response
    }
}