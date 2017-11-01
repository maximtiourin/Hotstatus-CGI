<?php

namespace Fizzik;

class HotstatusPipeline {
    //General constants
    const STATS_WEEK_RANGE = 15; //How many weeks of match data to keep alive in the database
    const REPLAY_AGE_LIMIT = 7 * self::STATS_WEEK_RANGE; //Relevant replays are those that are less than or equal to days of age
    const REPLAY_TIMEZONE = "UTC"; //Default timezone used for dating replays as well as process locks
    const REPLAY_DOWNLOAD_DIRECTORY = "replays/"; //Where the replays are downloaded relative to the replayprocess scripts
    const REPLAY_DOWNLOAD_DIRECTORY_ERROR = "replays/error/"; //Where the replays are copied to if an error occurs during replayprocess, for debugging purposes
    const REPLAY_DOWNLOAD_EXTENSION = ".StormReplay"; //Extension of the replays downloaded
    const REPLAY_DOWNLOAD_LIMIT = 72; //How many replays can be downloaded to disk at any one time
    const REPLAY_EXECUTABLE_DIRECTORY = "/bin/"; //Where the executables for processing replays are located
    const REPLAY_EXECUTABLE_ID_REPLAYPARSER = "ReplayParser.exe"; //String id of the replay parser executable, relative to exec dir
    const REPLAY_EXECUTABLE_ID_MMRCALCULATOR = "MMRCalculator.exe"; //String id of the mmr calculator executable, relative to exec dir
    const REPLAY_STATUS_QUEUED = "queued"; //status value for when a replay is queued to be downloaded
    const REPLAY_STATUS_DOWNLOADING = "downloading"; //status value for when a replay is in the process of being downloaded
    const REPLAY_STATUS_DOWNLOADED = "downloaded"; //status value for when a replay has been downloaded
    const REPLAY_STATUS_DOWNLOAD_ERROR = "download_error"; //status value for when a replay could not be downloaded due to an error
    const REPLAY_STATUS_PARSING = "parsing"; //status value for when a replay is being parsed
    const REPLAY_STATUS_PARSED = "parsed"; //status value for when a replay is done being parsed
    const REPLAY_STATUS_REPARSING = "reparsing"; //status value for a when a replay is being reparsed
    const REPLAY_STATUS_REPARSED = "reparsed"; //status value for when a replay is done being reparsed
    const REPLAY_STATUS_REPARSE_ERROR = "reparse_error"; //status value for when a replay had an unknown error during reparsing
    const REPLAY_STATUS_PARSE_MMR_ERROR = "parse_mmr_error"; //status value for when a replay had an unknown error during mmr parsing
    const REPLAY_STATUS_PARSE_REPLAY_ERROR = "parse_replay_error"; //status value for when a replay had an unknown error during mmr parsing
    const REPLAY_STATUS_PARSE_TRANSLATE_ERROR = "parse_translate_error"; //status value for when a replay had hero or map names that couldnt be translated
    const REPLAY_STATUS_MONGODB_MATCH_WRITE_ERROR = "mongodb_match_write_error"; //status value for when a repaly had an unknown mongodb bulkwrite error during match insertion
    const REPLAY_STATUS_MONGODB_MATCHDATA_WRITE_ERROR = "mongodb_matchdata_write_error"; //status value for when a repaly had an unknown mongodb bulkwrite error during match data insertion
    const REPLAY_STATUS_MYSQL_ERROR = "mysql_error"; //status value for when a replay had a generic unknown mysql error, possibly from manipulating empty result objects
    const REPLAY_STATUS_MYSQL_MATCH_WRITE_ERROR = "mysql_match_write_error"; //status value for when a replay had an unknown mysql write error during match insertion
    const REPLAY_STATUS_MYSQL_MATCHDATA_WRITE_ERROR = "mysql_matchdata_write_error"; //status value for when a repaly had an unknown mysql write error during match data insertion
    const MMR_AVERAGE_FIXED_CHUNK_SIZE = 100; //Size of the chunks of mmr that the mmr average is rounded to, for use with hero match granularity
    const FORMAT_DATETIME = "Y:m:d H:i:s"; //The format of the datatime strings
    const DATABASE_CHARSET = "utf8mb4";

    //Enums
    public static $ENUM_REGIONS_VALID = [false, true, true, true, false, true]; //Flags for whether or not regions at that index are currently tracked
    public static $ENUM_REGIONS = ['PTR', 'US', 'EU', 'KR', '??', 'CN']; //Regen indexes for use with converting replay data

    /*
     * Season information
     * All dates are UTC, so when looking up Blizzard's season start and end dates, add 7 hours to PST time accordingly
     */
    const SEASON_UNKNOWN = "Legacy"; //This is the season to use when no season dates are defined for a given date time
    const SEASON_NONE = "None"; //This is the value of NO previous season
    public static $SEASONS = [
        "2017 Season 3" => [
            "start" =>  "2017-09-05 07:00:00",
            "end" =>    "2017-12-12 06:59:59",
            "previous" => "2017 Season 2"
        ],
        "2017 Season 2" => [
            "start" =>  "2017-06-13 07:00:00",
            "end" =>    "2017-09-05 06:59:59",
            "previous" => self::SEASON_UNKNOWN
        ],
        self::SEASON_UNKNOWN => [
            "start" =>  "2010-01-01 07:00:00",
            "end" =>    "2017-06-13 06:59:59",
            "previous" => self::SEASON_NONE
        ]
    ];

    /*
     * Filter Informations
     * All preset data for hotstatus filters, using subsets of data such as maps, leagues, gameTypes, etc.
     */
    /*
     * Filter Maps
     * ["MapProperName"] => [
     *      ['name_sort'] => mapNameSort
     *      ['selected] => TRUE/FALSE (can be modified as needed)
     * ]
     */
    public static $filter_maps = [
        "Battlefield of Eternity" => [
            "name_sort" => "BattlefieldofEternity",
            "selected" => TRUE
        ],
        "Blackheart's Bay" => [
            "name_sort" => "BlackheartsBay",
            "selected" => TRUE
        ],
        "Braxis Holdout" => [
            "name_sort" => "BraxisHoldout",
            "selected" => TRUE
        ],
        "Cursed Hollow" => [
            "name_sort" => "CursedHollow",
            "selected" => TRUE
        ],
        "Dragon Shire" => [
            "name_sort" => "DragonShire",
            "selected" => TRUE
        ],
        "Garden of Terror" => [
            "name_sort" => "GardenofTerror",
            "selected" => TRUE
        ],
        "Hanamura" => [
            "name_sort" => "Hanamura",
            "selected" => TRUE
        ],
        "Haunted Mines" => [
            "name_sort" => "HauntedMines",
            "selected" => TRUE
        ],
        "Infernal Shrines" => [
            "name_sort" => "InfernalShrines",
            "selected" => TRUE
        ],
        "Sky Temple" => [
            "name_sort" => "SkyTemple",
            "selected" => TRUE
        ],
        "Tomb of the Spider Queen" => [
            "name_sort" => "TomboftheSpiderQueen",
            "selected" => TRUE
        ],
        "Towers of Doom" => [
            "name_sort" => "TowersofDoom",
            "selected" => TRUE
        ],
        "Volskaya Foundry" => [
            "name_sort" => "VolskayaFoundry",
            "selected" => TRUE
        ],
        "Warhead Junction" => [
            "name_sort" => "WarheadJunction",
            "selected" => TRUE
        ],
    ];
    /*
     * Filter Ranks
     * ["RankProperName"] => [
     *      "selected" => TRUE/FALSE (can be modified as needed)
     * ]
     */
    public static $filter_ranks = [
        "Bronze" => [
            "selected" => TRUE
        ],
        "Silver" => [
            "selected" => TRUE
        ],
        "Gold" => [
            "selected" => TRUE
        ],
        "Platinum" => [
            "selected" => TRUE
        ],
        "Diamond" => [
            "selected" => TRUE
        ],
        "Master" => [
            "selected" => TRUE
        ]
    ];

    /*
     * Filter Hero Levels
     * ["RangeProperName"] => [
     *      "min" => rangeStartInclusiveLevels
     *      "max" => rangeEndInclusiveLevels
     * ]
     */
    public static $filter_hero_levels = [
        "1-5" => [
            "min" => 1,
            "max" => 5
        ],
        "6-10" => [
            "min" => 6,
            "max" => 10
        ],
        "11-15" => [
            "min" => 11,
            "max" => 15
        ],
        "16+" => [
            "min" => 16,
            "max" => PHP_INT_MAX
        ],
    ];

    /*
     * Filter Matches Lengths
     * ["RangeProperName"] => [
     *      "min" => rangeStartInclusiveSeconds
     *      "max" => rangeEndInclusiveSeconds
     * ]
     */
    public static $filter_matches_lengths = [
        "0-10" => [
            "min" => 0,
            "max" => 600
        ],
        "11-15" => [
            "min" => 601,
            "max" => 900
        ],
        "16-20" => [
            "min" => 901,
            "max" => 1200
        ],
        "21-25" => [
            "min" => 1201,
            "max" => 1500
        ],
        "26-30" => [
            "min" => 1501,
            "max" => 1800
        ],
        "31+" => [
            "min" => 1801,
            "max" => PHP_INT_MAX
        ],
    ];

    /*
     * Filter Heroes (Don't use database queries to populate filters to improve app performance)
     * ["HeroProperName"] => [
     *      "image_minimap" => HeroImageMinimapNameWithoutExtension
     * ]
     */
    public static $filter_heroes = [
        "Abathur" => [
            "image_minimap" => "storm_ui_minimapicon_heros_infestor"
        ],
        "Alarak" => [
            "image_minimap" => "storm_ui_minimapicon_alarak"
        ],
        "Ana" => [
            "image_minimap" => "storm_ui_minimapicon_ana"
        ],
        "Anub'arak" => [
            "image_minimap" => "storm_ui_minimapicon_anubarak"
        ],
        "Artanis" => [
            "image_minimap" => "storm_ui_minimapicon_artanis"
        ],
        "Arthas" => [
            "image_minimap" => "storm_ui_minimapicon_arthas"
        ],
        "Auriel" => [
            "image_minimap" => "storm_ui_minimapicon_auriel"
        ],
        "Azmodan" => [
            "image_minimap" => "storm_ui_minimapicon_heros_azmodan"
        ],
        "Brightwing" => [
            "image_minimap" => "storm_ui_minimapicon_heros_faeriedragon"
        ],
        "The Butcher" => [
            "image_minimap" => "storm_ui_minimapicon_butcher"
        ],
        "Cassia" => [
            "image_minimap" => "storm_ui_minimapicon_d2amazonf"
        ],
        "Chen" => [
            "image_minimap" => "storm_ui_minimapicon_heros_chen"
        ],
        "Cho" => [
            "image_minimap" => "storm_ui_minimapicon_cho"
        ],
        "Chromie" => [
            "image_minimap" => "storm_ui_minimapicon_chromie"
        ],
        "Dehaka" => [
            "image_minimap" => "storm_ui_minimapicon_dehaka"
        ],
        "Diablo" => [
            "image_minimap" => "storm_ui_minimapicon_heros_diablo"
        ],
        "D.Va" => [
            "image_minimap" => "storm_ui_minimapicon_dva"
        ],
        "E.T.C." => [
            "image_minimap" => "storm_ui_minimapicon_etc"
        ],
        "Falstad" => [
            "image_minimap" => "storm_ui_minimapicon_gryphon_rider"
        ],
        "Gall" => [
            "image_minimap" => "storm_ui_minimapicon_gall"
        ],
        "Garrosh" => [
            "image_minimap" => "storm_ui_minimapicon_garrosh"
        ],
        "Gazlowe" => [
            "image_minimap" => "storm_ui_minimapicon_heros_gazlowe"
        ],
        "Genji" => [
            "image_minimap" => "storm_ui_minimapicon_genji"
        ],
        "Greymane" => [
            "image_minimap" => "storm_ui_minimapicon_genngreymane"
        ],
        "Gul'dan" => [
            "image_minimap" => "storm_ui_minimapicon_guldan"
        ],
        "Illidan" => [
            "image_minimap" => "storm_ui_minimapicon_illidan"
        ],
        "Jaina" => [
            "image_minimap" => "storm_ui_minimapicon_heros_jaina"
        ],
        "Johanna" => [
            "image_minimap" => "storm_ui_minimapicon_heros_johanna"
        ],
        "Junkrat" => [
            "image_minimap" => "storm_ui_minimapicon_junkrat"
        ],
        "Kael'thas" => [
            "image_minimap" => "storm_ui_minimapicon_heros_kaelthas"
        ],
        "Kel'Thuzad" => [
            "image_minimap" => "storm_ui_minimapicon_kelthuzad"
        ],
        "Kerrigan" => [
            "image_minimap" => "storm_ui_minimapicon_kerrigan"
        ],
        "Kharazim" => [
            "image_minimap" => "storm_ui_minimapicon_monk"
        ],
        "Leoric" => [
            "image_minimap" => "storm_ui_minimapicon_leoric"
        ],
        "Li Li" => [
            "image_minimap" => "storm_ui_minimapicon_heros_lili"
        ],
        "Li-Ming" => [
            "image_minimap" => "storm_ui_minimapicon_wizard"
        ],
        "The Lost Vikings" => [
            "image_minimap" => "storm_ui_minimapicon_heros_erik"
        ],
        "Lt. Morales" => [
            "image_minimap" => "storm_ui_minimapicon_medic"
        ],
        "Lúcio" => [
            "image_minimap" => "storm_ui_minimapicon_lucio"
        ],
        "Lunara" => [
            "image_minimap" => "storm_ui_minimapicon_lunara"
        ],
        "Malfurion" => [
            "image_minimap" => "storm_ui_minimapicon_heros_malfurion"
        ],
        "Malthael" => [
            "image_minimap" => "storm_ui_minimapicon_malthael"
        ],
        "Medivh" => [
            "image_minimap" => "storm_ui_minimapicon_medivh"
        ],
        "Muradin" => [
            "image_minimap" => "storm_ui_minimapicon_muradin"
        ],
        "Murky" => [
            "image_minimap" => "storm_ui_minimapicon_heros_murky"
        ],
        "Nazeebo" => [
            "image_minimap" => "storm_ui_minimapicon_witchdoctor"
        ],
        "Nova" => [
            "image_minimap" => "storm_ui_minimapicon_nova"
        ],
        "Probius" => [
            "image_minimap" => "storm_ui_minimapicon_probius"
        ],
        "Ragnaros" => [
            "image_minimap" => "storm_ui_minimapicon_ragnaros"
        ],
        "Raynor" => [
            "image_minimap" => "storm_ui_minimapicon_raynor"
        ],
        "Rehgar" => [
            "image_minimap" => "storm_ui_minimapicon_rehgar"
        ],
        "Rexxar" => [
            "image_minimap" => "storm_ui_minimapicon_heros_rexxar"
        ],
        "Samuro" => [
            "image_minimap" => "storm_ui_minimapicon_samuro"
        ],
        "Sgt. Hammer" => [
            "image_minimap" => "storm_ui_minimapicon_warfield"
        ],
        "Sonya" => [
            "image_minimap" => "storm_ui_minimapicon_heros_femalebarbarian"
        ],
        "Stitches" => [
            "image_minimap" => "storm_ui_minimapicon_heros_stitches"
        ],
        "Stukov" => [
            "image_minimap" => "storm_ui_minimapicon_stukov"
        ],
        "Sylvanas" => [
            "image_minimap" => "storm_ui_minimapicon_sylvanas"
        ],
        "Tassadar" => [
            "image_minimap" => "storm_ui_minimapicon_tassadar"
        ],
        "Thrall" => [
            "image_minimap" => "storm_ui_minimapicon_thrall"
        ],
        "Tracer" => [
            "image_minimap" => "storm_ui_minimapicon_tracer"
        ],
        "Tychus" => [
            "image_minimap" => "storm_ui_minimapicon_tychus"
        ],
        "Tyrael" => [
            "image_minimap" => "storm_ui_minimapicon_heros_tyrael"
        ],
        "Tyrande" => [
            "image_minimap" => "storm_ui_minimapicon_heros_tyrande"
        ],
        "Uther" => [
            "image_minimap" => "storm_ui_minimapicon_uther"
        ],
        "Valeera" => [
            "image_minimap" => "storm_ui_minimapicon_valeera"
        ],
        "Valla" => [
            "image_minimap" => "storm_ui_minimapicon_demonhunter"
        ],
        "Varian" => [
            "image_minimap" => "storm_ui_minimapicon_varian"
        ],
        "Xul" => [
            "image_minimap" => "storm_ui_minimapicon_necromancer"
        ],
        "Zagara" => [
            "image_minimap" => "storm_ui_minimapicon_zagara"
        ],
        "Zarya" => [
            "image_minimap" => "storm_ui_minimapicon_zarya"
        ],
        "Zeratul" => [
            "image_minimap" => "storm_ui_minimapicon_zeratul"
        ],
        "Zul'jin" => [
            "image_minimap" => "storm_ui_minimapicon_zuljin"
        ],
    ];

    /*
     * Takes a date time string, converts it to date time, returns an assoc array:
     * ['year] = ISO Year
     * ['week] = ISO Week of the Year (Weeks start on monday)
     * ['day'] = ISO Day of the Week (1 = Mon -> 7 = Sun)
     * ['date_begin] = datetime of when the day begins for the day that the datetimestr falls into
     * ['date_end] = datetime of when the day ends for the day that the datetimestr falls into
     */
    public static function getISOYearWeekDayForDateTime($datetimestr) {
        date_default_timezone_set(self::REPLAY_TIMEZONE);

        $date = new \DateTime($datetimestr);

        $yearOfWeek = intval($date->format("o"));
        $weekOfYear = intval($date->format("W"));
        $dayOfWeek = intval($date->format("N"));

        $dayBeginDate = new \DateTime();
        $dayBeginDate->setISODate($yearOfWeek, $weekOfYear, $dayOfWeek);
        $dayBeginDate->setTime(0, 0, 0);

        $dayEndDate = new \DateTime();
        $dayEndDate->setISODate($yearOfWeek, $weekOfYear, $dayOfWeek);
        $dayEndDate->setTime(23, 59, 59);

        $ret = [];
        $ret['year'] = $yearOfWeek;
        $ret['week'] = $weekOfYear;
        $ret['day'] = $dayOfWeek;
        $ret['date_begin'] = $dayBeginDate->format(self::FORMAT_DATETIME);
        $ret['date_end'] = $dayEndDate->format(self::FORMAT_DATETIME);

        return $ret;
    }

    /*
     * Returns an assoc array containing:
     * ['date_start'] = datetime of when the range begins inclusive
     * ['date_end'] = datetime of when the range ends inclusive
     * which describes the inclusive start and end date of the last $n ISO days from the given $datetime, offset by $o days inclusively
     */
    public static function getMinMaxRangeForLastISODaysInclusive($n, $datetimestring, $o = 0) {
        date_default_timezone_set(self::REPLAY_TIMEZONE);

        $i = 0;

        $endtime = new \DateTime($datetimestring);
        $starttime = new \DateTime($datetimestring);

        $end = new \DateInterval("P". ($o) ."D");
        $start = new \DateInterval("P". ($n - 1 + $o) ."D");

        $endtime->sub($end);
        $starttime->sub($start);

        $endyear = intval($endtime->format("o"));
        $endweek = intval($endtime->format("W"));
        $endday = intval($endtime->format("N"));
        $startyear = intval($starttime->format("o"));
        $startweek = intval($starttime->format("W"));
        $startday = intval($starttime->format("N"));

        $endiso = new \DateTime();
        $endiso->setISODate($endyear, $endweek, $endday);
        $endiso->setTime(23, 59, 59);
        $startiso = new \DateTime();
        $startiso->setISODate($startyear, $startweek, $startday);
        $startiso->setTime(0, 0, 0);

        $endret = $endiso->format(self::FORMAT_DATETIME);
        $startret = $startiso->format(self::FORMAT_DATETIME);

        return [
            "date_start" => $startret,
            "date_end" => $endret
        ];
    }

    /*
     * Returns a size $n array or smaller containing unique objects of type :
     * {
     *      'year' => ISO_YEAR
     *      'week' => ISO_WEEK
     *      'day' => ISO_DAY
     * }
     * which describe the last $n ISO days from the given $datetime, offset by $o days inclusively.
     */
    public static function getLastISODaysInclusive($n, $datetimestring, $o = 0) {
        date_default_timezone_set(self::REPLAY_TIMEZONE);

        $i = 0;

        $datetime = new \DateTime($datetimestring);

        $isodays = [];

        while ($i < $n) {
            $interval = new \DateInterval("P". ($i + $o) ."D");

            /** @var \DateTime $datetime */
            $datetime->sub($interval);

            $year = intval($datetime->format("o"));
            $week = intval($datetime->format("W"));
            $day = intval($datetime->format("N"));

            $isodays['Y' . $year . 'W' . $week . 'D' . $day] = [
                "year" => $year,
                "week" => $week,
                "day" => $day
            ];

            $datetime->add($interval); //Add back interval for next operation

            $i++;
        }

        return $isodays;
    }

    /*
     * Returns a size $n array or smaller containing unique objects of type :
     * {
     *      'year' => ISO_YEAR
     *      'week' => ISO_WEEK
     * }
     * which describe the last $n ISO weeks from the given $datetime, offset by $o weeks inclusively.
     */
    public static function getLastISOWeeksInclusive($n, $datetimestring, $o = 0) {
        date_default_timezone_set(self::REPLAY_TIMEZONE);

        $i = 0;

        $datetime = new \DateTime($datetimestring);

        $isoweeks = [];

        while ($i < $n) {
            $interval = new \DateInterval("P". ($i + $o) ."W");

            /** @var \DateTime $datetime */
            $datetime->sub($interval);

            $week = intval($datetime->format("W"));
            $year = intval($datetime->format("o"));

            $isoweeks['Y' . $year . 'W' . $week] = [
                "year" => $year,
                "week" => $week
            ];

            $datetime->add($interval); //Add back interval for next operation

            $i++;
        }

        return $isoweeks;
    }

    /*
     * Returns the region string associated with the given region id
     */
    public static function getRegionString($regionid) {
        return self::$ENUM_REGIONS[$regionid];
    }

    /*
     * Returns the season id string that a given date time string belongs within.
     * Returns const SEASON_UNKNOWN if the datetime doesn't fall within a known season time range
     */
    public static function getSeasonStringForDateTime($datetimestring) {
        date_default_timezone_set(self::REPLAY_TIMEZONE);

        $date = new \DateTime($datetimestring);

        foreach (self::$SEASONS as $season => $times) {
            $startdate = new \DateTime($times['start']);
            $enddate = new \DateTime($times['end']);

            if ($date >= $startdate && $date <= $enddate) {
                return $season;
            }
        }

        return self::SEASON_UNKNOWN;
    }

    /*
     * Returns the previous season string for the given season str.
     * Seasons without a previous season return const SEASON_NONE
     */
    public static function getSeasonPreviousStringForSeasonString($seasonstr) {
        if (key_exists($seasonstr, self::$SEASONS)) {
            return self::$SEASONS[$seasonstr]['previous'];
        }

        return self::SEASON_NONE;
    }

    /*
     * Returns the average mmr for the match given team0 and team1 old mmrs, rounding the average
     * to the near fixed size chunk
     */
    public static function getFixedAverageMMRForMatch($team0OldRating, $team1OldRating) {
        $avg = ($team0OldRating + $team1OldRating) / 2.0;

        return intval(round($avg / self::MMR_AVERAGE_FIXED_CHUNK_SIZE)) * self::MMR_AVERAGE_FIXED_CHUNK_SIZE;
    }

    /*
     * Returns the proper name string of the range for the given match length, returns "UNKNOWN" if the match length
     * doesn't fall into the known ranges
     */
    public static function getRangeNameForMatchLength($matchlength) {
        if (is_numeric($matchlength)) {
            foreach (self::$filter_matches_lengths as $rangeName => $range) {
                if ($matchlength >= $range['min'] && $matchlength <= $range['max']) {
                    return $rangeName;
                }
            }
        }

        return "UNKNOWN";
    }

    /*
     * Returns the proper name string of the range for the given hero level, returns "UNKNOWN" if the hero level
     * doesn't fall into the known ranges
     */
    public static function getRangeNameForHeroLevel($herolevel) {
        if (is_numeric($herolevel)) {
            foreach (self::$filter_hero_levels as $rangeName => $range) {
                if ($herolevel >= $range['min'] && $herolevel <= $range['max']) {
                    return $rangeName;
                }
            }
        }

        return "UNKNOWN";
    }

    /*
     * Returns an md5 hash describing a talent build, given an array of talents
     * Hash is generated by concating the talents in the order they were chosen
     */
    public static function getTalentBuildHash(&$talentarr) {
        $str = "";
        foreach ($talentarr as $talent) {
            $str .= $talent;
        }
        return md5($str);
    }

    /*
     * Returns a sorted array of player name strings attained from an array of player party relation objects
     */
    public static function getPlayerNameArrayFromPlayerPartyRelationArray(&$playerpartyarr) {
        $names = [];
        foreach ($playerpartyarr as $partyrelation) {
            $names[] = $partyrelation['name'];
        }

        sort($names);

        return $names;
    }

    /*
     * Returns the 'id' of a player based on their 'name', searching through objects of a supplied players array
     * Returns false if the player of a given name wasn't found in the supplied array
     */
    public static function getPlayerIdFromName($name, &$players) {
        foreach ($players as $player) {
            if ($player['name'] === $name) {
                return $player['id'];
            }
        }
        return false;
    }

    /*
     * Returns an array of player 'id's from a given array of player 'name's, searching the supplied players array
     */
    public static function getPlayerIdArrayFromPlayerNameArray(&$names, &$players) {
        $ids = [];

        foreach ($names as $name) {
            $ids[] = self::getPlayerIdFromName($name, $players);
        }

        return $ids;
    }

    /*
     * Returns an array of player 'id's found from relating a player 'name' from a supplied players array,
     * sorting them by an associated player 'name', which is obtained from searching a supplied player party relation object array
     */
    public static function getPlayerIdArrayFromPlayerPartyRelationArray(&$players, &$playerpartyarr) {
        $names = self::getPlayerNameArrayFromPlayerPartyRelationArray($playerpartyarr);
        return self::getPlayerIdArrayFromPlayerNameArray($names, $players);
    }

    /*
     * Returns an md5 hash describing the other party members of a player, given an array of party relation objects
     * Hash is generated by concating the player names after sorting them
     */
    public static function getPerPlayerPartyHash(&$playerpartyarr) {
        $names = self::getPlayerNameArrayFromPlayerPartyRelationArray($playerpartyarr);

        $str = "";
        foreach ($names as $name) {
            $str .= $name;
        }

        return md5($str);
    }

    /**
     * @deprecated
     * Takes a date time string, converts it to a date time, and returns an assoc array
     * that contains the following fields:
     * ['week'] = week # of the year
     * ['year'] = year #
     * ['day] = day #
     * ['date_start'] = datetime string of when the week starts
     * ['date_end'] = datetime string of when the week ends
     * @param $datetimestring
     * @return array
     */
    public static function getWeekDataOfReplay($datetimestring) {
        date_default_timezone_set(self::REPLAY_TIMEZONE);

        $replaydate = new \DateTime($datetimestring);

        $weekOfYear = intval($replaydate->format("W"));
        $yearOfWeek = intval($replaydate->format("o"));

        $weekstartdate = new \DateTime();
        $weekstartdate->setISODate($yearOfWeek, $weekOfYear);
        $weekstartdate->setTime(0, 0, 0);

        $weekenddate = new \DateTime();
        $weekenddate->setISODate($yearOfWeek, $weekOfYear, 7);
        $weekenddate->setTime(23, 59, 59);

        $ret = [];
        $ret['week'] = $weekOfYear;
        $ret['year'] = $yearOfWeek;
        $ret['date_start'] = $weekstartdate->format(self::FORMAT_DATETIME);
        $ret['date_end'] = $weekenddate->format(self::FORMAT_DATETIME);

        return $ret;
    }
}