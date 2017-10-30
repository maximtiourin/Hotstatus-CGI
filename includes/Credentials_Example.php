<?php

namespace Fizzik;

/*
 * Example credentials configuration file for things such as cgi script connection strings.
 * To create own, rename to Credentials.php and enter relevant info.
 */
class Credentials {
    const USER_REPLAYPROCESS = "replayprocess";
    const USER_HOTSTATUSWEB = "hotstatusweb";

    const MODE_PROD = "prod";
    const MODE_DEV = "dev";

    const DEFAULT_MODE = self::MODE_DEV;

    const KEY_DB_HOSTNAME = "db_host";
    const KEY_DB_USER = "db_user";
    const KEY_DB_PASSWORD = "db_password";
    const KEY_DB_DATABASE = "db_database";
    const KEY_REDIS_URI = "redis_uri";
    const KEY_AWS_KEY = "aws_key";
    const KEY_AWS_SECRET = "aws_secret";
    const KEY_AWS_REPLAYREGION = 'aws_replayregion';

    //Credentials
    private static $creds = [
        self::USER_REPLAYPROCESS => [
            self::MODE_PROD => [

            ],
            self::MODE_DEV => [
                self::KEY_DB_HOSTNAME => "",
                self::KEY_DB_USER => "",
                self::KEY_DB_PASSWORD => "",
                self::KEY_DB_DATABASE => "",
                self::KEY_REDIS_URI => "",
                self::KEY_AWS_KEY => "",
                self::KEY_AWS_SECRET => "",
                self::KEY_AWS_REPLAYREGION => "" //Ex: eu-west-1
            ]
        ],
        self::USER_HOTSTATUSWEB => [
            self::MODE_PROD => [

            ],
            self::MODE_DEV => [
                self::KEY_DB_HOSTNAME => "",
                self::KEY_DB_USER => "",
                self::KEY_DB_PASSWORD => "",
                self::KEY_DB_DATABASE => "",
                self::KEY_REDIS_URI => ""
            ]
        ]
    ];

    /*
     * Returns a credentials object for the given user and mode. Keys can be accessed using KEY constants, not all users
     * share the same keys.
     */
    public static function getCredentialsForUser($user, $mode = self::DEFAULT_MODE) {
        return self::$creds[$user][$mode];
    }
}
?>