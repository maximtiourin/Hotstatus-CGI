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
    const FILTER_KEY_DATE = "date";
    const FILTER_KEY_GAMETYPE = "gameType";
    const FILTER_KEY_MAP = "map";
    const FILTER_KEY_RANK = "rank";
    const FILTER_KEY_HERO_LEVEL = "hero_level";
    const FILTER_KEY_MATCH_LENGTH = "match_length";
    const FILTER_KEY_HERO = "hero";

    public static $filter = [
        /*
         * Date filter must be generated at runtime, so call filter_generate_date before referencing
         */
        self::FILTER_KEY_DATE => [],
        /*
         * Filter Maps
         * ["GameTypeProperName"] => [
         *      ['selected] => TRUE/FALSE (can be modified as needed)
         * ]
         */
        self::FILTER_KEY_GAMETYPE => [
            "Hero League" => [
                "name_sort" => "HeroLeague",
                "selected" => TRUE
            ],
            "Team League" => [
                "name_sort" => "TeamLeague",
                "selected" => FALSE
            ],
            "Unranked Draft" => [
                "name_sort" => "UnrankedDraft",
                "selected" => FALSE
            ],
            "Quick Match" => [
                "name_sort" => "QuickMatch",
                "selected" => FALSE
            ],
        ],
        /*
         * Filter Maps
         * ["MapProperName"] => [
         *      ['name_sort'] => mapNameSort
         *      ['selected] => TRUE/FALSE (can be modified as needed)
         * ]
         */
        self::FILTER_KEY_MAP => [
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
        ],
        /*
         * Filter Ranks
         * ["RankProperName"] => [
         *      "selected" => TRUE/FALSE (can be modified as needed)
         * ]
         * TODO Temporary Rank distributions, actual distribution must be analyzed down the line
         */
        self::FILTER_KEY_RANK => [
            "Bronze" => [
                "min" => 0,
                "max" => 799,
                "selected" => TRUE
            ],
            "Silver" => [
                "min" => 800,
                "max" => 1599,
                "selected" => TRUE
            ],
            "Gold" => [
                "min" => 1600,
                "max" => 2399,
                "selected" => TRUE
            ],
            "Platinum" => [
                "min" => 2400,
                "max" => 3199,
                "selected" => TRUE
            ],
            "Diamond" => [
                "min" => 3200,
                "max" => 3599,
                "selected" => TRUE
            ],
            "Master" => [
                "min" => 3600,
                "max" => PHP_INT_MAX,
                "selected" => TRUE
            ]
        ],
        /*
         * Filter Hero Levels
         * ["RangeProperName"] => [
         *      "min" => rangeStartInclusiveLevels
         *      "max" => rangeEndInclusiveLevels
         *      "selected" => TRUE/FALSE (can be modified as needed)
         * ]
         */
        self::FILTER_KEY_HERO_LEVEL => [
            "1-5" => [
                "min" => 1,
                "max" => 5,
                "selected" => TRUE
            ],
            "6-10" => [
                "min" => 6,
                "max" => 10,
                "selected" => TRUE
            ],
            "11-15" => [
                "min" => 11,
                "max" => 15,
                "selected" => TRUE
            ],
            "16+" => [
                "min" => 16,
                "max" => PHP_INT_MAX,
                "selected" => TRUE
            ],
        ],
        /*
         * Filter Matches Lengths
         * ["RangeProperName"] => [
         *      "min" => rangeStartInclusiveSeconds
         *      "max" => rangeEndInclusiveSeconds
         *      "selected" => TRUE/FALSE (can be modified as needed)
         * ]
         */
        self::FILTER_KEY_MATCH_LENGTH => [
            "0-10" => [
                "min" => 0,
                "max" => 600,
                "selected" => TRUE
            ],
            "11-15" => [
                "min" => 601,
                "max" => 900,
                "selected" => TRUE
            ],
            "16-20" => [
                "min" => 901,
                "max" => 1200,
                "selected" => TRUE
            ],
            "21-25" => [
                "min" => 1201,
                "max" => 1500,
                "selected" => TRUE
            ],
            "26-30" => [
                "min" => 1501,
                "max" => 1800,
                "selected" => TRUE
            ],
            "31+" => [
                "min" => 1801,
                "max" => PHP_INT_MAX,
                "selected" => TRUE
            ],
        ],
        /*
         * Filter Heroes (Don't use database queries to populate filters to improve app performance)
         * ["HeroProperName"] => [
         *      "image_minimap" => HeroImageMinimapNameWithoutExtension
         * ]
         */
        self::FILTER_KEY_HERO => [
            "Abathur" => [
                "image_minimap" => "storm_ui_minimapicon_heros_infestor",
                "selected" => false
            ],
            "Alarak" => [
                "image_minimap" => "storm_ui_minimapicon_alarak",
                "selected" => false
            ],
            "Ana" => [
                "image_minimap" => "storm_ui_minimapicon_ana",
                "selected" => false
            ],
            "Anub'arak" => [
                "image_minimap" => "storm_ui_minimapicon_anubarak",
                "selected" => false
            ],
            "Artanis" => [
                "image_minimap" => "storm_ui_minimapicon_artanis",
                "selected" => false
            ],
            "Arthas" => [
                "image_minimap" => "storm_ui_minimapicon_arthas",
                "selected" => false
            ],
            "Auriel" => [
                "image_minimap" => "storm_ui_minimapicon_auriel",
                "selected" => false
            ],
            "Azmodan" => [
                "image_minimap" => "storm_ui_minimapicon_heros_azmodan",
                "selected" => false
            ],
            "Brightwing" => [
                "image_minimap" => "storm_ui_minimapicon_heros_faeriedragon",
                "selected" => false
            ],
            "The Butcher" => [
                "image_minimap" => "storm_ui_minimapicon_butcher",
                "selected" => false
            ],
            "Cassia" => [
                "image_minimap" => "storm_ui_minimapicon_d2amazonf",
                "selected" => false
            ],
            "Chen" => [
                "image_minimap" => "storm_ui_minimapicon_heros_chen",
                "selected" => false
            ],
            "Cho" => [
                "image_minimap" => "storm_ui_minimapicon_cho",
                "selected" => false
            ],
            "Chromie" => [
                "image_minimap" => "storm_ui_minimapicon_chromie",
                "selected" => false
            ],
            "Dehaka" => [
                "image_minimap" => "storm_ui_minimapicon_dehaka",
                "selected" => false
            ],
            "Diablo" => [
                "image_minimap" => "storm_ui_minimapicon_heros_diablo",
                "selected" => false
            ],
            "D.Va" => [
                "image_minimap" => "storm_ui_minimapicon_dva",
                "selected" => false
            ],
            "E.T.C." => [
                "image_minimap" => "storm_ui_minimapicon_etc",
                "selected" => false
            ],
            "Falstad" => [
                "image_minimap" => "storm_ui_minimapicon_gryphon_rider",
                "selected" => false
            ],
            "Gall" => [
                "image_minimap" => "storm_ui_minimapicon_gall",
                "selected" => false
            ],
            "Garrosh" => [
                "image_minimap" => "storm_ui_minimapicon_garrosh",
                "selected" => false
            ],
            "Gazlowe" => [
                "image_minimap" => "storm_ui_minimapicon_heros_gazlowe",
                "selected" => false
            ],
            "Genji" => [
                "image_minimap" => "storm_ui_minimapicon_genji",
                "selected" => false
            ],
            "Greymane" => [
                "image_minimap" => "storm_ui_minimapicon_genngreymane",
                "selected" => false
            ],
            "Gul'dan" => [
                "image_minimap" => "storm_ui_minimapicon_guldan",
                "selected" => false
            ],
            "Illidan" => [
                "image_minimap" => "storm_ui_minimapicon_illidan",
                "selected" => false
            ],
            "Jaina" => [
                "image_minimap" => "storm_ui_minimapicon_heros_jaina",
                "selected" => false
            ],
            "Johanna" => [
                "image_minimap" => "storm_ui_minimapicon_heros_johanna",
                "selected" => false
            ],
            "Junkrat" => [
                "image_minimap" => "storm_ui_minimapicon_junkrat",
                "selected" => false
            ],
            "Kael'thas" => [
                "image_minimap" => "storm_ui_minimapicon_heros_kaelthas",
                "selected" => false
            ],
            "Kel'Thuzad" => [
                "image_minimap" => "storm_ui_minimapicon_kelthuzad",
                "selected" => false
            ],
            "Kerrigan" => [
                "image_minimap" => "storm_ui_minimapicon_kerrigan",
                "selected" => false
            ],
            "Kharazim" => [
                "image_minimap" => "storm_ui_minimapicon_monk",
                "selected" => false
            ],
            "Leoric" => [
                "image_minimap" => "storm_ui_minimapicon_leoric",
                "selected" => false
            ],
            "Li Li" => [
                "image_minimap" => "storm_ui_minimapicon_heros_lili",
                "selected" => false
            ],
            "Li-Ming" => [
                "image_minimap" => "storm_ui_minimapicon_wizard",
                "selected" => false
            ],
            "The Lost Vikings" => [
                "image_minimap" => "storm_ui_minimapicon_heros_erik",
                "selected" => false
            ],
            "Lt. Morales" => [
                "image_minimap" => "storm_ui_minimapicon_medic",
                "selected" => false
            ],
            "Lúcio" => [
                "image_minimap" => "storm_ui_minimapicon_lucio",
                "selected" => false
            ],
            "Lunara" => [
                "image_minimap" => "storm_ui_minimapicon_lunara",
                "selected" => false
            ],
            "Malfurion" => [
                "image_minimap" => "storm_ui_minimapicon_heros_malfurion",
                "selected" => false
            ],
            "Malthael" => [
                "image_minimap" => "storm_ui_minimapicon_malthael",
                "selected" => false
            ],
            "Medivh" => [
                "image_minimap" => "storm_ui_minimapicon_medivh",
                "selected" => false
            ],
            "Muradin" => [
                "image_minimap" => "storm_ui_minimapicon_muradin",
                "selected" => false
            ],
            "Murky" => [
                "image_minimap" => "storm_ui_minimapicon_heros_murky",
                "selected" => false
            ],
            "Nazeebo" => [
                "image_minimap" => "storm_ui_minimapicon_witchdoctor",
                "selected" => false
            ],
            "Nova" => [
                "image_minimap" => "storm_ui_minimapicon_nova",
                "selected" => false
            ],
            "Probius" => [
                "image_minimap" => "storm_ui_minimapicon_probius",
                "selected" => false
            ],
            "Ragnaros" => [
                "image_minimap" => "storm_ui_minimapicon_ragnaros",
                "selected" => false
            ],
            "Raynor" => [
                "image_minimap" => "storm_ui_minimapicon_raynor",
                "selected" => false
            ],
            "Rehgar" => [
                "image_minimap" => "storm_ui_minimapicon_rehgar",
                "selected" => false
            ],
            "Rexxar" => [
                "image_minimap" => "storm_ui_minimapicon_heros_rexxar",
                "selected" => false
            ],
            "Samuro" => [
                "image_minimap" => "storm_ui_minimapicon_samuro",
                "selected" => false
            ],
            "Sgt. Hammer" => [
                "image_minimap" => "storm_ui_minimapicon_warfield",
                "selected" => false
            ],
            "Sonya" => [
                "image_minimap" => "storm_ui_minimapicon_heros_femalebarbarian",
                "selected" => false
            ],
            "Stitches" => [
                "image_minimap" => "storm_ui_minimapicon_heros_stitches",
                "selected" => false
            ],
            "Stukov" => [
                "image_minimap" => "storm_ui_minimapicon_stukov",
                "selected" => false
            ],
            "Sylvanas" => [
                "image_minimap" => "storm_ui_minimapicon_sylvanas",
                "selected" => false
            ],
            "Tassadar" => [
                "image_minimap" => "storm_ui_minimapicon_tassadar",
                "selected" => false
            ],
            "Thrall" => [
                "image_minimap" => "storm_ui_minimapicon_thrall",
                "selected" => false
            ],
            "Tracer" => [
                "image_minimap" => "storm_ui_minimapicon_tracer",
                "selected" => false
            ],
            "Tychus" => [
                "image_minimap" => "storm_ui_minimapicon_tychus",
                "selected" => false
            ],
            "Tyrael" => [
                "image_minimap" => "storm_ui_minimapicon_heros_tyrael",
                "selected" => false
            ],
            "Tyrande" => [
                "image_minimap" => "storm_ui_minimapicon_heros_tyrande",
                "selected" => false
            ],
            "Uther" => [
                "image_minimap" => "storm_ui_minimapicon_uther",
                "selected" => false
            ],
            "Valeera" => [
                "image_minimap" => "storm_ui_minimapicon_valeera",
                "selected" => false
            ],
            "Valla" => [
                "image_minimap" => "storm_ui_minimapicon_demonhunter",
                "selected" => false
            ],
            "Varian" => [
                "image_minimap" => "storm_ui_minimapicon_varian",
                "selected" => false
            ],
            "Xul" => [
                "image_minimap" => "storm_ui_minimapicon_necromancer",
                "selected" => false
            ],
            "Zagara" => [
                "image_minimap" => "storm_ui_minimapicon_zagara",
                "selected" => false
            ],
            "Zarya" => [
                "image_minimap" => "storm_ui_minimapicon_zarya",
                "selected" => false
            ],
            "Zeratul" => [
                "image_minimap" => "storm_ui_minimapicon_zeratul",
                "selected" => false
            ],
            "Zul'jin" => [
                "image_minimap" => "storm_ui_minimapicon_zuljin",
                "selected" => false
            ],
        ],
    ];

    /*
     * Generates the dynamic values for the filter date
     * ["DateRangeProperName] => [
     *      "min" => DateTimeRangeStartInclusive
     *      "max" => DateTimeRangeEndInclusive
     *      "offset_date" => DateTimeOffsetsAreCalculatedFrom
     *      "offset_amount" => HowManyDaysToCountBackBy
     *      "selected" => WhetherOrNotThisFilterOptionStartsSelected
     * ]
     */
    public static function filter_generate_date() {
        date_default_timezone_set(self::REPLAY_TIMEZONE);

        $date_offset = "now"; //TODO for release should be "now", for testing make it the date of the youngest set of data

        $debug_offset = "2017-07-18"; //TODO DEBUG
        $debug = self::getMinMaxRangeForLastISODaysInclusive(7, $debug_offset); //TODO DEBUG
        $debug_offset2 = "2017-07-11"; //TODO DEBUG
        $debug2 = self::getMinMaxRangeForLastISODaysInclusive(7, $debug_offset2); //TODO DEBUG

        $last7days = self::getMinMaxRangeForLastISODaysInclusive(7, $date_offset);
        $last30days = self::getMinMaxRangeForLastISODaysInclusive(30, $date_offset);
        $last90days = self::getMinMaxRangeForLastISODaysInclusive(90, $date_offset);


        self::$filter[self::FILTER_KEY_DATE] = [
            "Last 7 Days" => [
                "min" => $last7days['date_start'],
                "max" => $last7days['date_end'],
                "offset_date" => $date_offset,
                "offset_amount" => 7,
                "selected" => TRUE
            ],
            "Last 30 Days" => [
                "min" => $last30days['date_start'],
                "max" => $last30days['date_end'],
                "offset_date" => $date_offset,
                "offset_amount" => 30,
                "selected" => FALSE
            ],
            "Last 90 Days" => [
                "min" => $last90days['date_start'],
                "max" => $last90days['date_end'],
                "offset_date" => $date_offset,
                "offset_amount" => 90,
                "selected" => FALSE
            ],
            //TODO DEBUG
            "2017-07-18 Last 7" => [
                "min" => $debug['date_start'],
                "max" => $debug['date_end'],
                "offset_date" => $debug_offset,
                "offset_amount" => 7,
                "selected" => FALSE
            ],
            //TODO DEBUG
            "2017-07-11 Last 7" => [
                "min" => $debug2['date_start'],
                "max" => $debug2['date_end'],
                "offset_date" => $debug_offset2,
                "offset_amount" => 7,
                "selected" => FALSE
            ],
        ];
    }

    /*
     * Hero Page Information
     * Map structure to describe what information should be shown on a hero page, and how it is shown
     */
    const HEROPAGE_KEY_AVERAGE_STATS = "average_stats";

    const HEROPAGE_TYPE_KEY_AVG_PMIN = "avg-pmin";
    const HEROPAGE_TYPE_KEY_PERCENTAGE = "percentage";
    const HEROPAGE_TYPE_KEY_KDA = "kda";
    const HEROPAGE_TYPE_KEY_RAW = "raw";
    const HEROPAGE_TYPE_KEY_TIME_SPENT_DEAD = "time-spent-dead";

    public static $heropage = [
        self::HEROPAGE_KEY_AVERAGE_STATS => [
            "winrate" => [
                "name" => "Winrate",
                "type" => self::HEROPAGE_TYPE_KEY_PERCENTAGE
            ],
            "kills" => [
                "name" => "Kills",
                "type" => self::HEROPAGE_TYPE_KEY_AVG_PMIN
            ],
            "assists" => [
                "name" => "Assists",
                "type" => self::HEROPAGE_TYPE_KEY_AVG_PMIN
            ],
            "deaths" => [
                "name" => "Deaths",
                "type" => self::HEROPAGE_TYPE_KEY_AVG_PMIN
            ],
            "kda" => [
                "name" => "K/D/A",
                "type" => self::HEROPAGE_TYPE_KEY_KDA
            ],
            "siege_damage" => [
                "name" => "Siege Damage",
                "type" => self::HEROPAGE_TYPE_KEY_AVG_PMIN
            ],
            "hero_damage" => [
                "name" => "Hero Damage",
                "type" => self::HEROPAGE_TYPE_KEY_AVG_PMIN
            ],
            "structure_damage" => [
                "name" => "Structure Damage",
                "type" => self::HEROPAGE_TYPE_KEY_AVG_PMIN
            ],
            "healing" => [
                "name" => "Healing",
                "type" => self::HEROPAGE_TYPE_KEY_AVG_PMIN
            ],
            "damage_taken" => [
                "name" => "Damage Taken",
                "type" => self::HEROPAGE_TYPE_KEY_AVG_PMIN
            ],
            "merc_camps" => [
                "name" => "Mercenary Camps",
                "type" => self::HEROPAGE_TYPE_KEY_AVG_PMIN
            ],
            "exp_contrib" => [
                "name" => "Exp Contribution",
                "type" => self::HEROPAGE_TYPE_KEY_AVG_PMIN
            ],
            "best_killstreak" => [
                "name" => "Best Killstreak",
                "type" => self::HEROPAGE_TYPE_KEY_RAW
            ],
            "time_spent_dead" => [
                "name" => "Time Spent Dead",
                "type" => self::HEROPAGE_TYPE_KEY_TIME_SPENT_DEAD
            ],
        ],
    ];

    public static $heropage_tooltips = [
        self::HEROPAGE_KEY_AVERAGE_STATS => [
            self::HEROPAGE_TYPE_KEY_AVG_PMIN => [
                "avg" => " per Game",
                "pmin" => " per Minute"
            ],
            self::HEROPAGE_TYPE_KEY_PERCENTAGE => " Percentage",
            self::HEROPAGE_TYPE_KEY_KDA => "(Kills + Assists) / Deaths",
            self::HEROPAGE_TYPE_KEY_TIME_SPENT_DEAD => " in Minutes",
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
            foreach (self::$filter[self::FILTER_KEY_MATCH_LENGTH] as $rangeName => $range) {
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
            foreach (self::$filter[self::FILTER_KEY_HERO_LEVEL] as $rangeName => $range) {
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