<?php

namespace Fizzik;

use Fizzik\Database\MySqlDatabase;
use Fizzik\Database\RedisDatabase;

class GetPageDataHeroRequestTotalStatMatrix {
    public static function _TYPE() {
        return HotstatusCache::CACHE_REQUEST_TYPE_PAGEDATA;
    }

    public static function _ID() {
        return "getPageDataHeroRequestTotalStatMatrix";
    }

    public static function _VERSION() {
        return 1;
    }

    public static function generateFilters() {

    }

    public static function specialExecute(&$mysql, $connected_mysql, $queryCache, $querySql) {
        $_TYPE = GetPageDataHeroRequestTotalStatMatrix::_TYPE();
        $_ID = GetPageDataHeroRequestTotalStatMatrix::_ID();
        $_VERSION = GetPageDataHeroRequestTotalStatMatrix::_VERSION();

        //Main vars
        $pagedata = [];

        //Determine Cache Id
        $CACHE_ID = $_ID . ((strlen($queryCache) > 0) ? (":" . md5($queryCache)) : (""));

        //Define payload
        $payload = [
            "querySql" => $querySql,
        ];

        if ($connected_mysql) {
            /** @var MySqlDatabase $db */
            $db = $mysql;

            $db->prepare("GetCachedStatMatrix",
                "SELECT `payload`, `lastused` FROM `pipeline_cache_statmatrix` WHERE `action` = ? AND `cache_id` = ?");
            $db->bind("GetCachedStatMatrix", "ss", $r_action, $r_cache_id);

            $db->prepare("UpsertCachedStatMatrix",
                "INSERT INTO `pipeline_cache_statmatrix` "
                . "(`action`, `cache_id`, `payload`, `lastused`) "
                . "VALUES (?, ?, ?, ?) "
                . "ON DUPLICATE KEY UPDATE "
                . "payload = ?, lastused = ?");
            $db->bind("UpsertCachedStatMatrix",
                "sssisi",
                $r_action, $r_cache_id, $r_payload, $r_lastused,

                $r_payload, $r_lastused);

            $r_action = $_ID;
            $r_cache_id = $CACHE_ID;

            $compute = true;

            $smresult = $db->execute("GetCachedStatMatrix");
            $smresultrows = $db->countResultRows($smresult);
            if ($smresultrows > 0) {
                $row = $db->fetchArray($smresult);

                $lastused = intval($row['lastused']);

                $expiretime = 24 /* hours */ * 3600 /* seconds */;

                if (time() - $lastused < $expiretime) {
                    $pagedata = json_decode($row['payload'], true);
                    $compute = false;
                }
            }

            if ($compute) {
                //Build Response
                self::execute($payload, $db, $pagedata);

                //Store newly computed statmatrix in mysql cache
                $r_payload = json_encode($pagedata);
                $r_lastused = time();

                $db->execute("UpsertCachedStatMatrix");
            }
        }

        return $pagedata;
    }

    public static function execute(&$payload, MySqlDatabase &$db, &$pagedata, $isCacheProcess = false) {
        //Extract payload
        $querySql = $payload['querySql'];

        //Build Response
        //Prepare Statement
        $db->prepare("GetTargetHeroStats",
            "SELECT `played`, `won`, `time_played`, `stats_kills`, `stats_assists`, `stats_deaths`,
                `stats_siege_damage`, `stats_hero_damage`, `stats_structure_damage`, `stats_healing`, `stats_damage_taken`, `stats_merc_camps`, `stats_exp_contrib`,
                `stats_best_killstreak`, `stats_time_spent_dead` FROM heroes_matches_recent_granular WHERE `hero` = ? $querySql");
        $db->bind("GetTargetHeroStats", "s", $r_hero);

        //Loop through all heroes to collect individual average stats
        $herostats = [];
        foreach (HotstatusPipeline::$filter[HotstatusPipeline::FILTER_KEY_HERO] as $heroname => $heroobj) {
            //Initialize aggregators
            $a_played = 0;
            $a_won = 0;
            $a_time_played = 0;
            $a_kills = 0;
            $a_assists = 0;
            $a_deaths = 0;
            $a_siege_damage = 0;
            $a_hero_damage = 0;
            $a_structure_damage = 0;
            $a_healing = 0;
            $a_damage_taken = 0;
            $a_merc_camps = 0;
            $a_exp_contrib = 0;
            $a_best_killstreak = 0;
            $a_time_spent_dead = 0;

            /*
             * Collect Stats
             */
            $r_hero = $heroname;
            $heroStatsResult = $db->execute("GetTargetHeroStats");
            while ($heroStatsRow = $db->fetchArray($heroStatsResult)) {
                $row = $heroStatsRow;

                /*
                 * Aggregate
                 */
                $a_played += $row['played'];
                $a_won += $row['won'];
                $a_time_played += $row['time_played'];
                $a_kills += $row['stats_kills'];
                $a_assists += $row['stats_assists'];
                $a_deaths += $row['stats_deaths'];
                $a_siege_damage += $row['stats_siege_damage'];
                $a_hero_damage += $row['stats_hero_damage'];
                $a_structure_damage += $row['stats_structure_damage'];
                $a_healing += $row['stats_healing'];
                $a_damage_taken += $row['stats_damage_taken'];
                $a_merc_camps += $row['stats_merc_camps'];
                $a_exp_contrib += $row['stats_exp_contrib'];
                $a_best_killstreak = max($a_best_killstreak, $row['stats_best_killstreak']);
                $a_time_spent_dead += $row['stats_time_spent_dead'];
            }
            $db->freeResult($heroStatsResult);

            /*
             * Calculate
             */
            $stats = [];

            //--Helpers
            //Average Time Played in Minutes
            $c_avg_minutesPlayed = 0;
            if ($a_played > 0) {
                $c_avg_minutesPlayed = ($a_time_played / 60.0) / ($a_played * 1.00);
            }

            //Average Kills (+ Per Minute)
            $c_avg_kills = 0;
            $c_pmin_kills = 0;
            if ($a_played > 0) {
                $c_avg_kills = $a_kills / ($a_played * 1.00);
            }
            if ($c_avg_minutesPlayed > 0) {
                $c_pmin_kills = $c_avg_kills / ($c_avg_minutesPlayed * 1.00);
            }
            $stats['kills'] = $c_pmin_kills;

            //Average Assists (+ Per Minute)
            $c_avg_assists = 0;
            $c_pmin_assists = 0;
            if ($a_played > 0) {
                $c_avg_assists = $a_assists / ($a_played * 1.00);
            }
            if ($c_avg_minutesPlayed > 0) {
                $c_pmin_assists = $c_avg_assists / ($c_avg_minutesPlayed * 1.00);
            }
            $stats['assists'] = $c_pmin_assists;

            //Average Deaths (+ Per Minute)
            $c_avg_deaths = 0;
            $c_pmin_deaths = 0;
            if ($a_played > 0) {
                $c_avg_deaths = $a_deaths / ($a_played * 1.00);
            }
            if ($c_avg_minutesPlayed > 0) {
                $c_pmin_deaths = $c_avg_deaths / ($c_avg_minutesPlayed * 1.00);
            }
            $stats['deaths'] = $c_pmin_deaths;

            //Average Siege Damage (+ Per Minute)
            $c_avg_siege_damage = 0;
            $c_pmin_siege_damage = 0;
            if ($a_played > 0) {
                $c_avg_siege_damage = $a_siege_damage / ($a_played * 1.00);
            }
            if ($c_avg_minutesPlayed > 0) {
                $c_pmin_siege_damage = $c_avg_siege_damage / ($c_avg_minutesPlayed * 1.00);
            }
            $stats['siege_damage'] = $c_pmin_siege_damage;

            //Average Hero Damage (+ Per Minute)
            $c_avg_hero_damage = 0;
            $c_pmin_hero_damage = 0;
            if ($a_played > 0) {
                $c_avg_hero_damage = $a_hero_damage / ($a_played * 1.00);
            }
            if ($c_avg_minutesPlayed > 0) {
                $c_pmin_hero_damage = $c_avg_hero_damage / ($c_avg_minutesPlayed * 1.00);
            }
            $stats['hero_damage'] = $c_pmin_hero_damage;

            //Average Structure Damage (+ Per Minute)
            $c_avg_structure_damage = 0;
            $c_pmin_structure_damage = 0;
            if ($a_played > 0) {
                $c_avg_structure_damage = $a_structure_damage / ($a_played * 1.00);
            }
            if ($c_avg_minutesPlayed > 0) {
                $c_pmin_structure_damage = $c_avg_structure_damage / ($c_avg_minutesPlayed * 1.00);
            }
            $stats['structure_damage'] = $c_pmin_structure_damage;

            //Average Healing (+ Per Minute)
            $c_avg_healing = 0;
            $c_pmin_healing = 0;
            if ($a_played > 0) {
                $c_avg_healing = $a_healing / ($a_played * 1.00);
            }
            if ($c_avg_minutesPlayed > 0) {
                $c_pmin_healing = $c_avg_healing / ($c_avg_minutesPlayed * 1.00);
            }
            $stats['healing'] = $c_pmin_healing;

            //Average Damage Taken (+ Per Minute)
            $c_avg_damage_taken = 0;
            $c_pmin_damage_taken = 0;
            if ($a_played > 0) {
                $c_avg_damage_taken = $a_damage_taken / ($a_played * 1.00);
            }
            if ($c_avg_minutesPlayed > 0) {
                $c_pmin_damage_taken = $c_avg_damage_taken / ($c_avg_minutesPlayed * 1.00);
            }
            $stats['damage_taken'] = $c_pmin_damage_taken;

            //Average Merc Camps (+ Per Minute)
            $c_avg_merc_camps = 0;
            $c_pmin_merc_camps = 0;
            if ($a_played > 0) {
                $c_avg_merc_camps = $a_merc_camps / ($a_played * 1.00);
            }
            if ($c_avg_minutesPlayed > 0) {
                $c_pmin_merc_camps = $c_avg_merc_camps / ($c_avg_minutesPlayed * 1.00);
            }
            $stats['merc_camps'] = $c_pmin_merc_camps;

            //Average Exp Contrib (+ Per Minute)
            $c_avg_exp_contrib = 0;
            $c_pmin_exp_contrib = 0;
            if ($a_played > 0) {
                $c_avg_exp_contrib = $a_exp_contrib / ($a_played * 1.00);
            }
            if ($c_avg_minutesPlayed > 0) {
                $c_pmin_exp_contrib = $c_avg_exp_contrib / ($c_avg_minutesPlayed * 1.00);
            }
            $stats['exp_contrib'] = $c_pmin_exp_contrib;

            //Average Time Spent Dead (in Minutes)
            $c_avg_time_spent_dead = 0;
            if ($a_played > 0) {
                $c_avg_time_spent_dead = ($a_time_spent_dead / ($a_played * 1.00)) / 60.0;
            }
            $stats['time_spent_dead'] = $c_avg_time_spent_dead;

            //Set pagedata stats
            $herostats[$heroname] = $stats;
        }

        //Init total average stats object
        $totalstats = [
            "kills" => [
                "min" => PHP_INT_MAX,
                "avg" => 0,
                "max" => PHP_INT_MIN,
                "count" => 0,
            ],
            "assists" => [
                "min" => PHP_INT_MAX,
                "avg" => 0,
                "max" => PHP_INT_MIN,
                "count" => 0,
            ],
            "deaths" => [
                "min" => PHP_INT_MAX,
                "avg" => 0,
                "max" => PHP_INT_MIN,
                "count" => 0,
            ],
            /*"special_tankiness" => [
                "min" => PHP_INT_MAX,
                "avg" => 0,
                "max" => PHP_INT_MIN,
                "count" => 0,
                "calc" => function(&$stats) {
                    if ($stats['deaths'] > 0) {
                        return $stats['damage_taken'] / $stats['deaths'];
                    }
                    else {
                        return $stats['damage_taken'];
                    }
                }
            ],*/
            "siege_damage" => [
                "min" => PHP_INT_MAX,
                "avg" => 0,
                "max" => PHP_INT_MIN,
                "count" => 0,
            ],
            "hero_damage" => [
                "min" => PHP_INT_MAX,
                "avg" => 0,
                "max" => PHP_INT_MIN,
                "count" => 0,
            ],
            "structure_damage" => [
                "min" => PHP_INT_MAX,
                "avg" => 0,
                "max" => PHP_INT_MIN,
                "count" => 0,
            ],
            "healing" => [
                "min" => PHP_INT_MAX,
                "avg" => 0,
                "max" => PHP_INT_MIN,
                "count" => 0,
            ],
            "damage_taken" => [
                "min" => PHP_INT_MAX,
                "avg" => 0,
                "max" => PHP_INT_MIN,
                "count" => 0,
            ],
            "merc_camps" => [
                "min" => PHP_INT_MAX,
                "avg" => 0,
                "max" => PHP_INT_MIN,
                "count" => 0,
            ],
            "exp_contrib" => [
                "min" => PHP_INT_MAX,
                "avg" => 0,
                "max" => PHP_INT_MIN,
                "count" => 0,
            ],
            "time_spent_dead" => [
                "min" => PHP_INT_MAX,
                "avg" => 0,
                "max" => PHP_INT_MIN,
                "count" => 0,
            ],
        ];

        //Loop through hero average stats to collect a single object of min, avg, max
        foreach ($herostats as $heroname => $stats) {
            //Loop through total average stats object and collect herostat
            foreach ($totalstats as $tskey => &$tstat) {
                if (key_exists($tskey, $stats)) {
                    //Standard Stat
                    $stat = $stats[$tskey];

                    $val = 0;
                    if (is_array($stat)) {
                        if (key_exists('per_minute', $stat)) {
                            $val = $stat['per_minute'];
                        }
                        else {
                            $val = $stat['average'];
                        }
                    }
                    elseif (is_numeric($stat)) {
                        $val = $stat;
                    }
                }
                else {
                    //Special composite stat
                    $val = $tstat['calc']($stats);
                }

                //Only track if valid
                if ($val > 0) {
                    $tstat['avg'] += $val;
                    $tstat['min'] = min($tstat['min'], $val);
                    $tstat['max'] = max($tstat['max'], $val);
                    $tstat['count'] += 1;
                }
            }
        }

        //Loop through total average stats to calculate true average
        foreach ($totalstats as $tskey => &$tstat) {
            if ($tstat['count'] > 0) {
                $tstat['avg'] = $tstat['avg'] / ($tstat['count'] * 1.00);
            }
        }

        $pagedata = $totalstats;
    }
}