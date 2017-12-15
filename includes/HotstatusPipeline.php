<?php

namespace Fizzik;

use Fizzik\Database\MySqlDatabase;

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
    const MMR_AVERAGE_FIXED_CHUNK_SIZE = 100; //Size of the chunks of mmr that the mmr average is rounded to, for use with hero match granularity
    const FORMAT_DATETIME = "Y:m:d H:i:s"; //The format of the datatime strings
    const DATABASE_CHARSET = "utf8mb4";

    //QOL
    const UNKNOWN = "Unknown";

    //Enums
    public static $ENUM_REGIONS_VALID = [false, true, true, true, false, true]; //Flags for whether or not regions at that index are currently tracked
    public static $ENUM_REGIONS = ['PTR', 'US', 'EU', 'KR', '??', 'CN']; //Regen indexes for use with converting replay data

    /*
     * Season information
     * All dates are UTC, so when looking up Blizzard's season start and end dates, add 7 hours to PST time accordingly
     */
    const SEASON_UNKNOWN = "Legacy"; //This is the season to use when no season dates are defined for a given date time
    const SEASON_NONE = "None"; //This is the value of NO previous season
    const SEASON_OVERRIDE = "2017 Season 3"; //Season override - If override is set, then select the overrided season instead of the current. Useful for when a new season begins and there is not enough data for current season
    const SEASON_CURRENT = "2018 Season 1";
    public static $SEASONS = [
        "2018 Season 1" => [
            "start" =>  "2017-12-12 07:00:00",
            "end" =>    "2018-03-06 06:59:59",
            "previous" => "2017 Season 3"
        ],
        "2017 Season 3" => [
            "start" =>  "2017-09-05 07:00:00",
            "end" =>    "2017-12-12 06:59:59",
            "previous" => self::SEASON_UNKNOWN
        ],
        self::SEASON_UNKNOWN => [
            "start" =>  "2010-01-01 07:00:00",
            "end" =>    "2017-09-05 06:59:59",
            "previous" => self::SEASON_NONE
        ]
    ];

    /*
     * Patch information
     * Historical patches are listed here, they must be filtered for a cutoff date in order to only pull patches whose
     * entire data range meets the cutoff
     */
    const PATCH_CURRENT = "CURRENT";
    public static $PATCHES = [
        self::PATCH_CURRENT => [
            "start" => "2017-12-12 00:00:00",
            "end" => null,
            "version" => "2.29.3",
            "type" => "Hanzo",
        ],
        "2.29.2" => [
            "start" => "2017-11-29 00:00:00",
            "end" => "2017-12-11 23:59:59",
            "version" => "2.29.2",
            "type" => "Balance",
        ],
        "2.29.0" => [
            "start" => "2017-11-14 00:00:00",
            "end" => "2017-11-28 23:59:59",
            "version" => "2.29.0",
            "type" => "Alexstrasza",
        ],
        "2.28.5" => [
            "start" => "2017-11-01 00:00:00",
            "end" => "2017-11-13 23:59:59",
            "version" => "2.28.5",
            "type" => "Balance",
        ],
        "2.28.3" => [
            "start" => "2017-10-17 00:00:00",
            "end" => "2017-10-31 23:59:59",
            "version" => "2.28.3",
            "type" => "Junkrat",
        ],
        "2.28.2" => [
            "start" => "2017-10-11 00:00:00",
            "end" => "2017-10-16 23:59:59",
            "version" => "2.28.2",
            "type" => "Balance",
        ],
        "2.28.0" => [
            "start" => "2017-09-26 00:00:00",
            "end" => "2017-10-10 23:59:59",
            "version" => "2.28.0",
            "type" => "Ana",
        ],
        "2.27.5" => [
            "start" => "2017-09-20 00:00:00",
            "end" => "2017-09-25 23:59:59",
            "version" => "2.27.5",
            "type" => "Balance",
        ],
        "2.27.3" => [
            "start" => "2017-09-05 00:00:00",
            "end" => "2017-09-19 23:59:59",
            "version" => "2.27.3",
            "type" => "Kel'Thuzad",
        ],
    ];

    /*
     * Medal Processing Information
     */
    const MEDALS_KEY_OUTDATED = "outdated";
    const MEDALS_KEY_REMAPPING = "remapping";
    const MEDALS_KEY_MAPSPECIFIC = "mapspecific";
    const MEDALS_KEY_DATA = "data";

    public static $medals = [
        /*
         * Array of medals that are no longer valid, should be filtered out of all medal calculations
         */
        self::MEDALS_KEY_OUTDATED => [],
        /*
         * Assoc Array of medal id remapping that should be done for medal calculations
         */
        self::MEDALS_KEY_REMAPPING => [
            "ZeroDeaths" => "0Deaths",
            "ZeroOutnumberedDeaths" => "0OutnumberedDeaths",
            "MostAltarDamage" => "MostAltarDamageDone",
        ],
        /*
         * Assoc Array of medal ids that are map specific
         */
        self::MEDALS_KEY_MAPSPECIFIC => [
            "MostCoinsPaid",
            "MostCurseDamageDone",
            "MostDamageDoneToZerg",
            "MostDamageToMinions",
            "MostDamageToPlants",
            "MostDragonShrinesCaptured",
            "MostGemsTurnedIn",
            "MostImmortalDamage",
            "MostNukeDamageDone",
            "MostSkullsCollected",
            "MostTimeInTemple",
            "MostTimeOnPoint",
            "MostTimePushing",
        ],
        /*
         * Actual medal data
         * [This data is generated by utilityprocess_medalsdata.php]
         */
        self::MEDALS_KEY_DATA => [
            "0Deaths" => [
                "name" => "Sole Survivor",
                "desc_simple" => "No Deaths",
                "image" => "storm_ui_scorescreen_mvp_solesurvivor",
            ],
            "0OutnumberedDeaths" => [
                "name" => "Team Player",
                "desc_simple" => "No Deaths While Outnumbered",
                "image" => "storm_ui_scorescreen_mvp_teamplayer",
            ],
            "ClutchHealer" => [
                "name" => "Clutch Healer",
                "desc_simple" => "Many Heals That Saved a Dying Ally",
                "image" => "storm_ui_scorescreen_mvp_clutchhealer",
            ],
            "HatTrick" => [
                "name" => "Hat Trick",
                "desc_simple" => "First Three Kills of Match",
                "image" => "storm_ui_scorescreen_mvp_hattrick",
            ],
            "HighestKillStreak" => [
                "name" => "Dominator",
                "desc_simple" => "High Killstreak",
                "image" => "storm_ui_scorescreen_mvp_skull",
            ],
            "MostAltarDamageDone" => [
                "name" => "Cannoneer",
                "desc_simple" => "High Core Damage Done",
                "image" => "storm_ui_scorescreen_mvp_cannoneer",
            ],
            "MostCoinsPaid" => [
                "name" => "Moneybags",
                "desc_simple" => "High Coins Delivered",
                "image" => "storm_ui_scorescreen_mvp_moneybags",
            ],
            "MostCurseDamageDone" => [
                "name" => "Master of the Curse",
                "desc_simple" => "High Curse Siege Damage",
                "image" => "storm_ui_scorescreen_mvp_masterofthecurse",
            ],
            "MostDamageDoneToZerg" => [
                "name" => "Zerg Crusher",
                "desc_simple" => "High Damage Done to Zerg",
                "image" => "storm_ui_scorescreen_mvp_zergcrusher",
            ],
            "MostDamageTaken" => [
                "name" => "Bulwark",
                "desc_simple" => "High Efficient Damage Soaked",
                "image" => "storm_ui_scorescreen_mvp_bulwark",
            ],
            "MostDamageToMinions" => [
                "name" => "Guardian Slayer",
                "desc_simple" => "High Damage Done to Shrine Guardians",
                "image" => "storm_ui_scorescreen_mvp_guardianslayer",
            ],
            "MostDamageToPlants" => [
                "name" => "Garden Terror",
                "desc_simple" => "High Damage Done to Plants",
                "image" => "storm_ui_scorescreen_mvp_gardenterror",
            ],
            "MostDaredevilEscapes" => [
                "name" => "Daredevil",
                "desc_simple" => "Escaped Death Many Times in Team Fights",
                "image" => "storm_ui_scorescreen_mvp_daredevil",
            ],
            "MostDragonShrinesCaptured" => [
                "name" => "Shriner",
                "desc_simple" => "High Shrines Captured",
                "image" => "storm_ui_scorescreen_mvp_shriner",
            ],
            "MostEscapes" => [
                "name" => "Escape Artist",
                "desc_simple" => "Escaped Death Many Times",
                "image" => "storm_ui_scorescreen_mvp_escapeartist",
            ],
            "MostGemsTurnedIn" => [
                "name" => "Jeweler",
                "desc_simple" => "Many Gems Turned in",
                "image" => "storm_ui_scorescreen_mvp_jeweler",
            ],
            "MostHealing" => [
                "name" => "Main Healer",
                "desc_simple" => "High Healing Done",
                "image" => "storm_ui_scorescreen_mvp_mainhealer",
            ],
            "MostHeroDamageDone" => [
                "name" => "Painbringer",
                "desc_simple" => "High Hero Damage Done",
                "image" => "storm_ui_scorescreen_mvp_painbringer",
            ],
            "MostImmortalDamage" => [
                "name" => "Immortal Slayer",
                "desc_simple" => "High Damage Done to Immortals",
                "image" => "storm_ui_scorescreen_mvp_immortalslayer",
            ],
            "MostKills" => [
                "name" => "Finisher",
                "desc_simple" => "High Kills",
                "image" => "storm_ui_scorescreen_mvp_finisher",
            ],
            "MostMercCampsCaptured" => [
                "name" => "Headhunter",
                "desc_simple" => "High Mercenary Captures",
                "image" => "storm_ui_scorescreen_mvp_headhunter",
            ],
            "MostNukeDamageDone" => [
                "name" => "Da Bomb",
                "desc_simple" => "High Damage Done with Warheads",
                "image" => "storm_ui_scorescreen_mvp_dabomb",
            ],
            "MostProtection" => [
                "name" => "Protector",
                "desc_simple" => "High Damage Prevented",
                "image" => "storm_ui_scorescreen_mvp_protector",
            ],
            "MostRoots" => [
                "name" => "Trapper",
                "desc_simple" => "High Root Time on Enemy Heroes",
                "image" => "storm_ui_scorescreen_mvp_trapper",
            ],
            "MostSiegeDamageDone" => [
                "name" => "Siege Master",
                "desc_simple" => "High Siege Damage Done",
                "image" => "storm_ui_scorescreen_mvp_siegemaster",
            ],
            "MostSilences" => [
                "name" => "Silencer",
                "desc_simple" => "High Silence Time",
                "image" => "storm_ui_scorescreen_mvp_silencer",
            ],
            "MostSkullsCollected" => [
                "name" => "Skull Collector",
                "desc_simple" => "High Skulls Collected",
                "image" => "storm_ui_scorescreen_mvp_skullcollector",
            ],
            "MostStuns" => [
                "name" => "Stunner",
                "desc_simple" => "High Stun Time",
                "image" => "storm_ui_scorescreen_mvp_stunner",
            ],
            "MostTeamfightDamageTaken" => [
                "name" => "Guardian",
                "desc_simple" => "Soaked High Damage in Team Fights",
                "image" => "storm_ui_scorescreen_mvp_guardian",
            ],
            "MostTeamfightHealingDone" => [
                "name" => "Combat Medic",
                "desc_simple" => "Healed High Damage in Team Fights",
                "image" => "storm_ui_scorescreen_mvp_combatmedic",
            ],
            "MostTeamfightHeroDamageDone" => [
                "name" => "Scrapper",
                "desc_simple" => "High Team Fight Damage",
                "image" => "storm_ui_scorescreen_mvp_scrapper",
            ],
            "MostTimeInTemple" => [
                "name" => "Temple Master",
                "desc_simple" => "High Time Occupying Shrines",
                "image" => "storm_ui_scorescreen_mvp_templemaster",
            ],
            "MostTimeOnPoint" => [
                "name" => "Point Guard",
                "desc_simple" => "High Time on Point",
                "image" => "storm_ui_scorescreen_mvp_pointguard",
            ],
            "MostTimePushing" => [
                "name" => "Pusher",
                "desc_simple" => "High amount of time on Payload",
                "image" => "storm_ui_scorescreen_mvp_pusher",
            ],
            "MostVengeancesPerformed" => [
                "name" => "Avenger",
                "desc_simple" => "Avenged Many Deaths",
                "image" => "storm_ui_scorescreen_mvp_avenger",
            ],
            "MostXPContribution" => [
                "name" => "Experienced",
                "desc_simple" => "High XP Contributed",
                "image" => "storm_ui_scorescreen_mvp_experienced",
            ],
            "MVP" => [
                "name" => "MVP",
                "desc_simple" => "Most Valuable Player",
                "image" => "storm_ui_scorescreen_mvp_mvp",
            ],
        ]
    ];

    /*
     * Filter Informations
     * All preset data for hotstatus filters, using subsets of data such as maps, leagues, gameTypes, etc.
     */
    const FILTER_KEY_SEASON = "season";
    const FILTER_KEY_DATE = "date";
    const FILTER_KEY_GAMETYPE = "gameType";
    const FILTER_KEY_MAP = "map";
    const FILTER_KEY_RANK = "rank";
    const FILTER_KEY_HERO_LEVEL = "hero_level";
    const FILTER_KEY_MATCH_LENGTH = "match_length";
    const FILTER_KEY_HERO = "hero";
    const FILTER_KEY_REGION = "region";

    public static $filter = [
        /*
         * Season filter must be generated at runtime, so call filter_generate_season before referencing
         */
        self::FILTER_KEY_SEASON => [],
        /*
         * Date filter must be generated at runtime, so call filter_generate_date before referencing
         */
        self::FILTER_KEY_DATE => [],
        /*
         * Filter Regions
         */
        self::FILTER_KEY_REGION => [
            "US" => [
                "index" => 1,
                "selected" => TRUE
            ],
            "EU" => [
                "index" => 2,
                "selected" => FALSE
            ],
            "KR" => [
                "index" => 3,
                "selected" => FALSE
            ],
            "CN" => [
                "index" => 5,
                "selected" => FALSE
            ],
        ],
        /*
         * Filter Maps
         * ["GameTypeProperName"] => [
         *      ['selected] => TRUE/FALSE (can be modified as needed)
         * ]
         */
        self::FILTER_KEY_GAMETYPE => [
            "Hero League" => [
                "name_sort" => "HeroLeague",
                "ranking" => [
                    "matchLimit" => 100,
                    "rankLimit" => 100,
                ],
                "selected" => TRUE
            ],
            "Team League" => [
                "name_sort" => "TeamLeague",
                "ranking" => [
                    "matchLimit" => 25,
                    "rankLimit" => 25,
                ],
                "selected" => TRUE
            ],
            "Unranked Draft" => [
                "name_sort" => "UnrankedDraft",
                "ranking" => [
                    "matchLimit" => 100,
                    "rankLimit" => 100,
                ],
                "selected" => TRUE
            ],
            "Quick Match" => [
                "name_sort" => "QuickMatch",
                "ranking" => [
                    "matchLimit" => 100,
                    "rankLimit" => 100,
                ],
                "selected" => TRUE
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
         *
         * min/max are match distributions for ranks (based on average match mmr) -- useful for filtering hero statistics
         *
         * players min/max are player rank distributions (based on distributions of ranks for all players) -- useful for filtering players
         *
         * [Rank Distributions are manually filled out by data generated with utilityprocess_rankdistribution variants]
         */
        self::FILTER_KEY_RANK => [
            "Bronze" => [
                "min" => 0,
                "max" => 199,
                "players" => [
                    "min" => 0,
                    "max" => 0,
                ],
                "selected" => TRUE
            ],
            "Silver" => [
                "min" => 200,
                "max" => 399,
                "players" => [
                    "min" => 1,
                    "max" => 182,
                ],
                "selected" => TRUE
            ],
            "Gold" => [
                "min" => 400,
                "max" => 799,
                "players" => [
                    "min" => 183,
                    "max" => 580,
                ],
                "selected" => TRUE
            ],
            "Platinum" => [
                "min" => 800,
                "max" => 1199,
                "players" => [
                    "min" => 581,
                    "max" => 1301,
                ],
                "selected" => TRUE
            ],
            "Diamond" => [
                "min" => 1200,
                "max" => 1599,
                "players" => [
                    "min" => 1302,
                    "max" => 1860,
                ],
                "selected" => TRUE
            ],
            "Master" => [
                "min" => 1600,
                "max" => PHP_INT_MAX,
                "players" => [
                    "min" => 1861,
                    "max" => PHP_INT_MAX,
                ],
                "selected" => TRUE
            ]
            //BACKUP - 12/4/2017
            /*"Bronze" => [
                "min" => 0,
                "max" => 99,
                "players" => [
                    "min" => 0,
                    "max" => 0,
                ],
                "selected" => TRUE
            ],
            "Silver" => [
                "min" => 100,
                "max" => 199,
                "players" => [
                    "min" => 1,
                    "max" => 151,
                ],
                "selected" => TRUE
            ],
            "Gold" => [
                "min" => 200,
                "max" => 499,
                "players" => [
                    "min" => 152,
                    "max" => 420,
                ],
                "selected" => TRUE
            ],
            "Platinum" => [
                "min" => 500,
                "max" => 799,
                "players" => [
                    "min" => 421,
                    "max" => 1006,
                ],
                "selected" => TRUE
            ],
            "Diamond" => [
                "min" => 800,
                "max" => 1099,
                "players" => [
                    "min" => 1007,
                    "max" => 1584,
                ],
                "selected" => TRUE
            ],
            "Master" => [
                "min" => 1100,
                "max" => PHP_INT_MAX,
                "players" => [
                    "min" => 1585,
                    "max" => PHP_INT_MAX,
                ],
                "selected" => TRUE
            ]*/
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
         * This filter is generated by 'cgi/utilityprocess_filterheroes.php'
         * ["HeroProperName"] => [
         *      "image_minimap" => HeroImageMinimapNameWithoutExtension
         * ]
         */
        self::FILTER_KEY_HERO => [
            "Abathur" => [
                "name_sort" => "Abathur",
                "name_attribute" => "Abat",
                "image_hero" => "ui_targetportrait_hero_abathur",
                "image_minimap" => "storm_ui_minimapicon_heros_infestor",
                "role_blizzard" => "Specialist",
                "role_specific" => "Utility",
                "selected" => false
            ],
            "Alarak" => [
                "name_sort" => "Alarak",
                "name_attribute" => "Alar",
                "image_hero" => "ui_targetportrait_hero_alarak",
                "image_minimap" => "storm_ui_minimapicon_alarak",
                "role_blizzard" => "Assassin",
                "role_specific" => "Ambusher",
                "selected" => false
            ],
            "Alexstrasza" => [
                "name_sort" => "Alexstrasza",
                "name_attribute" => "Alex",
                "image_hero" => "ui_targetportrait_hero_alexstrasza",
                "image_minimap" => "storm_ui_minimapicon_alexstrasza",
                "role_blizzard" => "Support",
                "role_specific" => "Healer",
                "selected" => false
            ],
            "Ana" => [
                "name_sort" => "Ana",
                "name_attribute" => "HANA",
                "image_hero" => "ui_targetportrait_hero_ana",
                "image_minimap" => "storm_ui_minimapicon_ana",
                "role_blizzard" => "Support",
                "role_specific" => "Healer",
                "selected" => false
            ],
            "Anub'arak" => [
                "name_sort" => "Anubarak",
                "name_attribute" => "Anub",
                "image_hero" => "ui_targetportrait_hero_anubarak",
                "image_minimap" => "storm_ui_minimapicon_anubarak",
                "role_blizzard" => "Warrior",
                "role_specific" => "Tank",
                "selected" => false
            ],
            "Artanis" => [
                "name_sort" => "Artanis",
                "name_attribute" => "Arts",
                "image_hero" => "ui_targetportrait_hero_artanis",
                "image_minimap" => "storm_ui_minimapicon_artanis",
                "role_blizzard" => "Warrior",
                "role_specific" => "Bruiser",
                "selected" => false
            ],
            "Arthas" => [
                "name_sort" => "Arthas",
                "name_attribute" => "Arth",
                "image_hero" => "ui_targetportrait_hero_arthas",
                "image_minimap" => "storm_ui_minimapicon_arthas",
                "role_blizzard" => "Warrior",
                "role_specific" => "Bruiser",
                "selected" => false
            ],
            "Auriel" => [
                "name_sort" => "Auriel",
                "name_attribute" => "Auri",
                "image_hero" => "ui_targetportrait_hero_auriel",
                "image_minimap" => "storm_ui_minimapicon_auriel",
                "role_blizzard" => "Support",
                "role_specific" => "Healer",
                "selected" => false
            ],
            "Azmodan" => [
                "name_sort" => "Azmodan",
                "name_attribute" => "Azmo",
                "image_hero" => "ui_targetportrait_hero_azmodan",
                "image_minimap" => "storm_ui_minimapicon_heros_azmodan",
                "role_blizzard" => "Specialist",
                "role_specific" => "Siege",
                "selected" => false
            ],
            "Brightwing" => [
                "name_sort" => "Brightwing",
                "name_attribute" => "Faer",
                "image_hero" => "ui_targetportrait_hero_faeriedragon",
                "image_minimap" => "storm_ui_minimapicon_heros_faeriedragon",
                "role_blizzard" => "Support",
                "role_specific" => "Healer",
                "selected" => false
            ],
            "The Butcher" => [
                "name_sort" => "Butcher",
                "name_attribute" => "Butc",
                "image_hero" => "ui_targetportrait_hero_butcher",
                "image_minimap" => "storm_ui_minimapicon_butcher",
                "role_blizzard" => "Assassin",
                "role_specific" => "Ambusher",
                "selected" => false
            ],
            "Cassia" => [
                "name_sort" => "Cassia",
                "name_attribute" => "Amaz",
                "image_hero" => "ui_targetportrait_hero_d2amazonf",
                "image_minimap" => "storm_ui_minimapicon_d2amazonf",
                "role_blizzard" => "Assassin",
                "role_specific" => "Sustained Damage",
                "selected" => false
            ],
            "Chen" => [
                "name_sort" => "Chen",
                "name_attribute" => "Chen",
                "image_hero" => "ui_targetportrait_hero_chen",
                "image_minimap" => "storm_ui_minimapicon_heros_chen",
                "role_blizzard" => "Warrior",
                "role_specific" => "Bruiser",
                "selected" => false
            ],
            "Cho" => [
                "name_sort" => "Cho",
                "name_attribute" => "CCho",
                "image_hero" => "ui_targetportrait_hero_cho",
                "image_minimap" => "storm_ui_minimapicon_cho",
                "role_blizzard" => "Warrior",
                "role_specific" => "Tank",
                "selected" => false
            ],
            "Chromie" => [
                "name_sort" => "Chromie",
                "name_attribute" => "Chro",
                "image_hero" => "ui_targetportrait_hero_chromie",
                "image_minimap" => "storm_ui_minimapicon_chromie",
                "role_blizzard" => "Assassin",
                "role_specific" => "Burst Damage",
                "selected" => false
            ],
            "Dehaka" => [
                "name_sort" => "Dehaka",
                "name_attribute" => "Deha",
                "image_hero" => "ui_targetportrait_hero_dehaka",
                "image_minimap" => "storm_ui_minimapicon_dehaka",
                "role_blizzard" => "Warrior",
                "role_specific" => "Bruiser",
                "selected" => false
            ],
            "Diablo" => [
                "name_sort" => "Diablo",
                "name_attribute" => "Diab",
                "image_hero" => "ui_targetportrait_hero_diablo",
                "image_minimap" => "storm_ui_minimapicon_heros_diablo",
                "role_blizzard" => "Warrior",
                "role_specific" => "Tank",
                "selected" => false
            ],
            "D.Va" => [
                "name_sort" => "DVa",
                "name_attribute" => "DVA0",
                "image_hero" => "ui_targetportrait_hero_dva",
                "image_minimap" => "storm_ui_minimapicon_dva",
                "role_blizzard" => "Warrior",
                "role_specific" => "Tank",
                "selected" => false
            ],
            "E.T.C." => [
                "name_sort" => "ETC",
                "name_attribute" => "L90E",
                "image_hero" => "ui_targetportrait_hero_l90etc",
                "image_minimap" => "storm_ui_minimapicon_etc",
                "role_blizzard" => "Warrior",
                "role_specific" => "Tank",
                "selected" => false
            ],
            "Falstad" => [
                "name_sort" => "Falstad",
                "name_attribute" => "Fals",
                "image_hero" => "ui_targetportrait_hero_falstad",
                "image_minimap" => "storm_ui_minimapicon_gryphon_rider",
                "role_blizzard" => "Assassin",
                "role_specific" => "Sustained Damage",
                "selected" => false
            ],
            "Gall" => [
                "name_sort" => "Gall",
                "name_attribute" => "Gall",
                "image_hero" => "ui_targetportrait_hero_gall",
                "image_minimap" => "storm_ui_minimapicon_gall",
                "role_blizzard" => "Assassin",
                "role_specific" => "Sustained Damage",
                "selected" => false
            ],
            "Garrosh" => [
                "name_sort" => "Garrosh",
                "name_attribute" => "Garr",
                "image_hero" => "ui_targetportrait_hero_garrosh",
                "image_minimap" => "storm_ui_minimapicon_garrosh",
                "role_blizzard" => "Warrior",
                "role_specific" => "Tank",
                "selected" => false
            ],
            "Gazlowe" => [
                "name_sort" => "Gazlowe",
                "name_attribute" => "Tink",
                "image_hero" => "ui_targetportrait_hero_gazlowe",
                "image_minimap" => "storm_ui_minimapicon_heros_gazlowe",
                "role_blizzard" => "Specialist",
                "role_specific" => "Siege",
                "selected" => false
            ],
            "Genji" => [
                "name_sort" => "Genji",
                "name_attribute" => "Genj",
                "image_hero" => "ui_targetportrait_hero_genji",
                "image_minimap" => "storm_ui_minimapicon_genji",
                "role_blizzard" => "Assassin",
                "role_specific" => "Sustained Damage",
                "selected" => false
            ],
            "Greymane" => [
                "name_sort" => "Greymane",
                "name_attribute" => "Genn",
                "image_hero" => "ui_targetportrait_hero_genngreymane",
                "image_minimap" => "storm_ui_minimapicon_genngreymane",
                "role_blizzard" => "Assassin",
                "role_specific" => "Sustained Damage",
                "selected" => false
            ],
            "Gul'dan" => [
                "name_sort" => "Guldan",
                "name_attribute" => "Guld",
                "image_hero" => "ui_targetportrait_hero_guldan",
                "image_minimap" => "storm_ui_minimapicon_guldan",
                "role_blizzard" => "Assassin",
                "role_specific" => "Sustained Damage",
                "selected" => false
            ],
            "Hanzo" => [
                "name_sort" => "Hanzo",
                "name_attribute" => "Hanz",
                "image_hero" => "ui_targetportrait_hero_hanzo",
                "image_minimap" => "storm_ui_minimapicon_hanzo",
                "role_blizzard" => "Assassin",
                "role_specific" => "Sustained Damage",
                "selected" => false
            ],
            "Illidan" => [
                "name_sort" => "Illidan",
                "name_attribute" => "Illi",
                "image_hero" => "ui_targetportrait_hero_illidan",
                "image_minimap" => "storm_ui_minimapicon_illidan",
                "role_blizzard" => "Assassin",
                "role_specific" => "Sustained Damage",
                "selected" => false
            ],
            "Jaina" => [
                "name_sort" => "Jaina",
                "name_attribute" => "Jain",
                "image_hero" => "ui_targetportrait_hero_jaina",
                "image_minimap" => "storm_ui_minimapicon_heros_jaina",
                "role_blizzard" => "Assassin",
                "role_specific" => "Burst Damage",
                "selected" => false
            ],
            "Johanna" => [
                "name_sort" => "Johanna",
                "name_attribute" => "Crus",
                "image_hero" => "ui_targetportrait_hero_johanna",
                "image_minimap" => "storm_ui_minimapicon_heros_johanna",
                "role_blizzard" => "Warrior",
                "role_specific" => "Tank",
                "selected" => false
            ],
            "Junkrat" => [
                "name_sort" => "Junkrat",
                "name_attribute" => "Junk",
                "image_hero" => "ui_targetportrait_hero_junkrat",
                "image_minimap" => "storm_ui_minimapicon_junkrat",
                "role_blizzard" => "Assassin",
                "role_specific" => "Burst Damage",
                "selected" => false
            ],
            "Kael'thas" => [
                "name_sort" => "Kaelthas",
                "name_attribute" => "Kael",
                "image_hero" => "ui_targetportrait_hero_kaelthas",
                "image_minimap" => "storm_ui_minimapicon_heros_kaelthas",
                "role_blizzard" => "Assassin",
                "role_specific" => "Burst Damage",
                "selected" => false
            ],
            "Kel'Thuzad" => [
                "name_sort" => "KelThuzad",
                "name_attribute" => "KelT",
                "image_hero" => "ui_targetportrait_hero_kelthuzad",
                "image_minimap" => "storm_ui_minimapicon_kelthuzad",
                "role_blizzard" => "Assassin",
                "role_specific" => "Burst Damage",
                "selected" => false
            ],
            "Kerrigan" => [
                "name_sort" => "Kerrigan",
                "name_attribute" => "Kerr",
                "image_hero" => "ui_targetportrait_hero_kerrigan",
                "image_minimap" => "storm_ui_minimapicon_kerrigan",
                "role_blizzard" => "Assassin",
                "role_specific" => "Ambusher",
                "selected" => false
            ],
            "Kharazim" => [
                "name_sort" => "Kharazim",
                "name_attribute" => "Monk",
                "image_hero" => "ui_targetportrait_hero_monk",
                "image_minimap" => "storm_ui_minimapicon_monk",
                "role_blizzard" => "Support",
                "role_specific" => "Healer",
                "selected" => false
            ],
            "Leoric" => [
                "name_sort" => "Leoric",
                "name_attribute" => "Leor",
                "image_hero" => "ui_targetportrait_hero_leoric",
                "image_minimap" => "storm_ui_minimapicon_leoric",
                "role_blizzard" => "Warrior",
                "role_specific" => "Bruiser",
                "selected" => false
            ],
            "Li Li" => [
                "name_sort" => "LiLi",
                "name_attribute" => "LiLi",
                "image_hero" => "ui_targetportrait_hero_lili",
                "image_minimap" => "storm_ui_minimapicon_heros_lili",
                "role_blizzard" => "Support",
                "role_specific" => "Healer",
                "selected" => false
            ],
            "Li-Ming" => [
                "name_sort" => "LiMing",
                "name_attribute" => "Wiza",
                "image_hero" => "ui_targetportrait_hero_wizard",
                "image_minimap" => "storm_ui_minimapicon_wizard",
                "role_blizzard" => "Assassin",
                "role_specific" => "Burst Damage",
                "selected" => false
            ],
            "The Lost Vikings" => [
                "name_sort" => "Lost Vikings",
                "name_attribute" => "Lost",
                "image_hero" => "ui_targetportrait_hero_lostvikings",
                "image_minimap" => "storm_ui_minimapicon_heros_erik",
                "role_blizzard" => "Specialist",
                "role_specific" => "Utility",
                "selected" => false
            ],
            "Lt. Morales" => [
                "name_sort" => "LtMorales",
                "name_attribute" => "Medi",
                "image_hero" => "ui_targetportrait_hero_medic",
                "image_minimap" => "storm_ui_minimapicon_medic",
                "role_blizzard" => "Support",
                "role_specific" => "Healer",
                "selected" => false
            ],
            "Lúcio" => [
                "name_sort" => "Lucio",
                "name_attribute" => "Luci",
                "image_hero" => "ui_targetportrait_hero_lucio",
                "image_minimap" => "storm_ui_minimapicon_lucio",
                "role_blizzard" => "Support",
                "role_specific" => "Healer",
                "selected" => false
            ],
            "Lunara" => [
                "name_sort" => "Lunara",
                "name_attribute" => "Drya",
                "image_hero" => "ui_targetportrait_hero_lunara",
                "image_minimap" => "storm_ui_minimapicon_lunara",
                "role_blizzard" => "Assassin",
                "role_specific" => "Sustained Damage",
                "selected" => false
            ],
            "Malfurion" => [
                "name_sort" => "Malfurion",
                "name_attribute" => "Malf",
                "image_hero" => "ui_targetportrait_hero_malfurion",
                "image_minimap" => "storm_ui_minimapicon_heros_malfurion",
                "role_blizzard" => "Support",
                "role_specific" => "Healer",
                "selected" => false
            ],
            "Malthael" => [
                "name_sort" => "Malthael",
                "name_attribute" => "MALT",
                "image_hero" => "ui_targetportrait_hero_malthael",
                "image_minimap" => "storm_ui_minimapicon_malthael",
                "role_blizzard" => "Assassin",
                "role_specific" => "Sustained Damage",
                "selected" => false
            ],
            "Medivh" => [
                "name_sort" => "Medivh",
                "name_attribute" => "Mdvh",
                "image_hero" => "ui_targetportrait_hero_medivh",
                "image_minimap" => "storm_ui_minimapicon_medivh",
                "role_blizzard" => "Specialist",
                "role_specific" => "Support",
                "selected" => false
            ],
            "Muradin" => [
                "name_sort" => "Muradin",
                "name_attribute" => "Mura",
                "image_hero" => "ui_targetportrait_hero_muradin",
                "image_minimap" => "storm_ui_minimapicon_muradin",
                "role_blizzard" => "Warrior",
                "role_specific" => "Tank",
                "selected" => false
            ],
            "Murky" => [
                "name_sort" => "Murky",
                "name_attribute" => "Murk",
                "image_hero" => "ui_targetportrait_hero_murky",
                "image_minimap" => "storm_ui_minimapicon_heros_murky",
                "role_blizzard" => "Specialist",
                "role_specific" => "Utility",
                "selected" => false
            ],
            "Nazeebo" => [
                "name_sort" => "Nazeebo",
                "name_attribute" => "Witc",
                "image_hero" => "ui_targetportrait_hero_witchdoctor",
                "image_minimap" => "storm_ui_minimapicon_witchdoctor",
                "role_blizzard" => "Specialist",
                "role_specific" => "Sustained Damage",
                "selected" => false
            ],
            "Nova" => [
                "name_sort" => "Nova",
                "name_attribute" => "Nova",
                "image_hero" => "ui_targetportrait_hero_nova",
                "image_minimap" => "storm_ui_minimapicon_nova",
                "role_blizzard" => "Assassin",
                "role_specific" => "Ambusher",
                "selected" => false
            ],
            "Probius" => [
                "name_sort" => "Probius",
                "name_attribute" => "Prob",
                "image_hero" => "ui_targetportrait_hero_probius",
                "image_minimap" => "storm_ui_minimapicon_probius",
                "role_blizzard" => "Specialist",
                "role_specific" => "Siege",
                "selected" => false
            ],
            "Ragnaros" => [
                "name_sort" => "Ragnaros",
                "name_attribute" => "Ragn",
                "image_hero" => "ui_targetportrait_hero_ragnaros",
                "image_minimap" => "storm_ui_minimapicon_ragnaros",
                "role_blizzard" => "Assassin",
                "role_specific" => "Sustained Damage",
                "selected" => false
            ],
            "Raynor" => [
                "name_sort" => "Raynor",
                "name_attribute" => "Rayn",
                "image_hero" => "ui_targetportrait_hero_raynor",
                "image_minimap" => "storm_ui_minimapicon_raynor",
                "role_blizzard" => "Assassin",
                "role_specific" => "Sustained Damage",
                "selected" => false
            ],
            "Rehgar" => [
                "name_sort" => "Rehgar",
                "name_attribute" => "Rehg",
                "image_hero" => "ui_targetportrait_hero_rehgar",
                "image_minimap" => "storm_ui_minimapicon_rehgar",
                "role_blizzard" => "Support",
                "role_specific" => "Healer",
                "selected" => false
            ],
            "Rexxar" => [
                "name_sort" => "Rexxar",
                "name_attribute" => "Rexx",
                "image_hero" => "ui_targetportrait_hero_rexxar",
                "image_minimap" => "storm_ui_minimapicon_heros_rexxar",
                "role_blizzard" => "Warrior",
                "role_specific" => "Tank",
                "selected" => false
            ],
            "Samuro" => [
                "name_sort" => "Samuro",
                "name_attribute" => "Samu",
                "image_hero" => "ui_targetportrait_hero_samuro",
                "image_minimap" => "storm_ui_minimapicon_samuro",
                "role_blizzard" => "Assassin",
                "role_specific" => "Ambusher",
                "selected" => false
            ],
            "Sgt. Hammer" => [
                "name_sort" => "SgtHammer",
                "name_attribute" => "Sgth",
                "image_hero" => "ui_targetportrait_hero_sgthammer",
                "image_minimap" => "storm_ui_minimapicon_warfield",
                "role_blizzard" => "Specialist",
                "role_specific" => "Siege",
                "selected" => false
            ],
            "Sonya" => [
                "name_sort" => "Sonya",
                "name_attribute" => "Barb",
                "image_hero" => "ui_targetportrait_hero_barbarian",
                "image_minimap" => "storm_ui_minimapicon_heros_femalebarbarian",
                "role_blizzard" => "Warrior",
                "role_specific" => "Bruiser",
                "selected" => false
            ],
            "Stitches" => [
                "name_sort" => "Stitches",
                "name_attribute" => "Stit",
                "image_hero" => "ui_targetportrait_hero_stitches",
                "image_minimap" => "storm_ui_minimapicon_heros_stitches",
                "role_blizzard" => "Warrior",
                "role_specific" => "Tank",
                "selected" => false
            ],
            "Stukov" => [
                "name_sort" => "Stukov",
                "name_attribute" => "STUK",
                "image_hero" => "ui_targetportrait_hero_stukov",
                "image_minimap" => "storm_ui_minimapicon_stukov",
                "role_blizzard" => "Support",
                "role_specific" => "Healer",
                "selected" => false
            ],
            "Sylvanas" => [
                "name_sort" => "Sylvanas",
                "name_attribute" => "Sylv",
                "image_hero" => "ui_targetportrait_hero_sylvanas",
                "image_minimap" => "storm_ui_minimapicon_sylvanas",
                "role_blizzard" => "Specialist",
                "role_specific" => "Siege",
                "selected" => false
            ],
            "Tassadar" => [
                "name_sort" => "Tassadar",
                "name_attribute" => "Tass",
                "image_hero" => "ui_targetportrait_hero_tassadar",
                "image_minimap" => "storm_ui_minimapicon_tassadar",
                "role_blizzard" => "Support",
                "role_specific" => "Support",
                "selected" => false
            ],
            "Thrall" => [
                "name_sort" => "Thrall",
                "name_attribute" => "Thra",
                "image_hero" => "ui_targetportrait_hero_thrall",
                "image_minimap" => "storm_ui_minimapicon_thrall",
                "role_blizzard" => "Assassin",
                "role_specific" => "Sustained Damage",
                "selected" => false
            ],
            "Tracer" => [
                "name_sort" => "Tracer",
                "name_attribute" => "Tra0",
                "image_hero" => "ui_targetportrait_hero_tracer",
                "image_minimap" => "storm_ui_minimapicon_tracer",
                "role_blizzard" => "Assassin",
                "role_specific" => "Sustained Damage",
                "selected" => false
            ],
            "Tychus" => [
                "name_sort" => "Tychus",
                "name_attribute" => "Tych",
                "image_hero" => "ui_targetportrait_hero_tychus",
                "image_minimap" => "storm_ui_minimapicon_tychus",
                "role_blizzard" => "Assassin",
                "role_specific" => "Sustained Damage",
                "selected" => false
            ],
            "Tyrael" => [
                "name_sort" => "Tyrael",
                "name_attribute" => "Tyrl",
                "image_hero" => "ui_targetportrait_hero_tyrael",
                "image_minimap" => "storm_ui_minimapicon_heros_tyrael",
                "role_blizzard" => "Warrior",
                "role_specific" => "Bruiser",
                "selected" => false
            ],
            "Tyrande" => [
                "name_sort" => "Tyrande",
                "name_attribute" => "Tyrd",
                "image_hero" => "ui_targetportrait_hero_tyrande",
                "image_minimap" => "storm_ui_minimapicon_heros_tyrande",
                "role_blizzard" => "Support",
                "role_specific" => "Support",
                "selected" => false
            ],
            "Uther" => [
                "name_sort" => "Uther",
                "name_attribute" => "Uthe",
                "image_hero" => "ui_targetportrait_hero_uther",
                "image_minimap" => "storm_ui_minimapicon_uther",
                "role_blizzard" => "Support",
                "role_specific" => "Healer",
                "selected" => false
            ],
            "Valeera" => [
                "name_sort" => "Valeera",
                "name_attribute" => "VALE",
                "image_hero" => "ui_targetportrait_hero_valeera",
                "image_minimap" => "storm_ui_minimapicon_valeera",
                "role_blizzard" => "Assassin",
                "role_specific" => "Ambusher",
                "selected" => false
            ],
            "Valla" => [
                "name_sort" => "Valla",
                "name_attribute" => "Demo",
                "image_hero" => "ui_targetportrait_hero_demonhunter",
                "image_minimap" => "storm_ui_minimapicon_demonhunter",
                "role_blizzard" => "Assassin",
                "role_specific" => "Sustained Damage",
                "selected" => false
            ],
            "Varian" => [
                "name_sort" => "Varian",
                "name_attribute" => "Vari",
                "image_hero" => "ui_targetportrait_hero_varian",
                "image_minimap" => "storm_ui_minimapicon_varian",
                "role_blizzard" => "Multiclass",
                "role_specific" => "Bruiser",
                "selected" => false
            ],
            "Xul" => [
                "name_sort" => "Xul",
                "name_attribute" => "Necr",
                "image_hero" => "ui_targetportrait_hero_necromancer",
                "image_minimap" => "storm_ui_minimapicon_necromancer",
                "role_blizzard" => "Specialist",
                "role_specific" => "Siege",
                "selected" => false
            ],
            "Zagara" => [
                "name_sort" => "Zagara",
                "name_attribute" => "Zaga",
                "image_hero" => "ui_targetportrait_hero_zagara",
                "image_minimap" => "storm_ui_minimapicon_zagara",
                "role_blizzard" => "Specialist",
                "role_specific" => "Siege",
                "selected" => false
            ],
            "Zarya" => [
                "name_sort" => "Zarya",
                "name_attribute" => "Zary",
                "image_hero" => "ui_targetportrait_hero_zarya",
                "image_minimap" => "storm_ui_minimapicon_zarya",
                "role_blizzard" => "Warrior",
                "role_specific" => "Tank",
                "selected" => false
            ],
            "Zeratul" => [
                "name_sort" => "Zeratul",
                "name_attribute" => "Zera",
                "image_hero" => "ui_targetportrait_hero_zeratul",
                "image_minimap" => "storm_ui_minimapicon_zeratul",
                "role_blizzard" => "Assassin",
                "role_specific" => "Ambusher",
                "selected" => false
            ],
            "Zul'jin" => [
                "name_sort" => "Zuljin",
                "name_attribute" => "ZULJ",
                "image_hero" => "ui_targetportrait_hero_zuljin",
                "image_minimap" => "storm_ui_minimapicon_zuljin",
                "role_blizzard" => "Assassin",
                "role_specific" => "Sustained Damage",
                "selected" => false
            ],
        ],
    ];

    /*
     * Generates the dynamic values for the filter season
     * ["SeasonProperName"] => [
     *      "min" => DateTimeRangeStartInclusive
     *      "max" => DateTimeRangeEndInclusive
     *      "selected" => WhetherOrNotThisFilterOptionStartsSelected
     * ]
     */
    public static function filter_generate_season() {
        foreach (self::$SEASONS as $season => $sobj) {
            if ($season !== self::SEASON_UNKNOWN) {
                self::$filter[self::FILTER_KEY_SEASON][$season] = [
                    "min" => $sobj['start'],
                    "max" => $sobj['end'],
                    "selected" => (self::SEASON_OVERRIDE === null) ? ($season === self::SEASON_CURRENT) : ($season === self::SEASON_OVERRIDE),
                ];
            }
        }
    }

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

        $date_offset = "now";

        //Last 7 Days
        $last7days = self::getMinMaxRangeForLastISODaysInclusive(7, $date_offset);
        self::$filter[self::FILTER_KEY_DATE]['Last 7 Days'] = [
            "min" => $last7days['date_start'],
            "max" => $last7days['date_end'],
            "offset_date" => $date_offset,
            "offset_amount" => 7,
            "selected" => TRUE
        ];

        //Last 30 Days
        $last30days = self::getMinMaxRangeForLastISODaysInclusive(30, $date_offset);
        self::$filter[self::FILTER_KEY_DATE]['Last 30 Days'] = [
            "min" => $last30days['date_start'],
            "max" => $last30days['date_end'],
            "offset_date" => $date_offset,
            "offset_amount" => 30,
            "selected" => FALSE
        ];

        //Last 90 Days
        $last90days = self::getMinMaxRangeForLastISODaysInclusive(90, $date_offset);
        self::$filter[self::FILTER_KEY_DATE]['Last 90 Days'] = [
            "min" => $last90days['date_start'],
            "max" => $last90days['date_end'],
            "offset_date" => $date_offset,
            "offset_amount" => 90,
            "selected" => FALSE
        ];

        //Current Build
        $cpatch = self::$PATCHES[self::PATCH_CURRENT];
        $rangeoffset = "now";
        $rangelength = self::getLengthInISODaysForDateTimeRange($cpatch['start'], $rangeoffset);
        $range = self::getMinMaxRangeForLastISODaysInclusive($rangelength, $rangeoffset);
        self::$filter[self::FILTER_KEY_DATE][$cpatch['version']." (".$cpatch['type'].")"] = [
            "min" => $range['date_start'],
            "max" => $range['date_end'],
            "offset_date" => $rangeoffset,
            "offset_amount" => $rangelength,
            "selected" => FALSE,
        ];

        //Add non-current patches
        foreach (self::$PATCHES as $patchkey => $patch) {
            if ($patchkey !== self::PATCH_CURRENT) {
                $version = $patch['version'];
                $type = $patch['type'];

                $rangeoffset = $patch['end'];
                $rangelength = self::getLengthInISODaysForDateTimeRange($patch['start'], $rangeoffset);
                $range = self::getMinMaxRangeForLastISODaysInclusive($rangelength, $rangeoffset);

                self::$filter[self::FILTER_KEY_DATE][$version . ' ('.$type.')'] = [
                    "min" => $range['date_start'],
                    "max" => $range['date_end'],
                    "offset_date" => $rangeoffset,
                    "offset_amount" => $rangelength,
                    "selected" => FALSE
                ];
            }
        }
    }

    /*
     * Returns the hero name associated with the hero attribute, returning const UNKNOWN if no matching hero found.
     */
    public static function filter_getHeroNameFromHeroAttribute($attr) {
        foreach (self::$filter[self::FILTER_KEY_HERO] as $hname => $hero) {
            if ($hero['name_attribute'] === $attr) {
                return $hname;
            }
        }

        return self::UNKNOWN;
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
            self::HEROPAGE_TYPE_KEY_TIME_SPENT_DEAD => " in Minutes per Game",
        ],
    ];

    public static $heropage_talent_tiers = [
        "1" => "Level: 1",
        "2" => "Level: 4",
        "3" => "Level: 7",
        "4" => "Level: 10",
        "5" => "Level: 13",
        "6" => "Level: 16",
        "7" => "Level: 20"
    ];

    public static function getLengthInISODaysForDateTimeRange($datestart, $dateend) {
        date_default_timezone_set(self::REPLAY_TIMEZONE);

        $endtime = new \DateTime($dateend);
        $starttime = new \DateTime($datestart);

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

        $interval = $startiso->diff($endiso);
        return $interval->days + 1; //Have to add one default day to account for same day
    }

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

        return self::getFixedMMRStep($avg, self::MMR_AVERAGE_FIXED_CHUNK_SIZE);
    }

    /*
     * Returns the fixed mmr step for a given rating use a step size
     */
    public static function getFixedMMRStep($rating, $stepsize) {
        return intval(round($rating / $stepsize)) * $stepsize;
    }

    /*
     * Returns the proper name of the rank for a given player rating
     * Returns UNKNOWN if no rank is found
     */
    public static function getRankNameForPlayerRating($rating) {
        if (is_numeric($rating)) {
            foreach (self::$filter[self::FILTER_KEY_RANK] as $rname => $robj) {
                if ($rating >= $robj['players']['min'] && $rating <= $robj['players']['max']) return $rname;
            }
        }

        return self::UNKNOWN;
    }

    /*
     * Returns a rank tier : [V, IV, III, II, I] for the rank that the rating falls into. Unknown returns "?", Highest Rank returns "*".
     */
    public static function getRankTierForPlayerRating($rating) {
        if (is_numeric($rating)) {
            foreach (self::$filter[self::FILTER_KEY_RANK] as $rname => $robj) {
                if ($rating >= $robj['players']['min'] && $rating <= $robj['players']['max']) {
                    if ($rname === "Master") {
                        return "*";
                    }
                    else {
                        //Split min/max of rating into 5 pieces, return the first section that the rating falls into
                        $min = $robj['players']['min'] * 1.00;
                        $max = $robj['players']['max'] * 1.00;

                        $diff = $max - $min;

                        $sectionsize = $diff / 5.0;

                        $sections = ["V", "IV", "III", "II", "I"];

                        $t = 0;
                        for ($i = $min; $i <= $max; $i += $sectionsize) {
                            $newmin = $i;
                            $newmax = $i + $sectionsize;

                            if ($rating >= $newmin && $rating <= $newmax) {
                                return $sections[$t];
                            }

                            $t++;
                        }
                    }
                }
            }
        }

        return "?";
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

    /*
     * Abstracts the connection to the mysql database to allow for easy changing of connection requirements, such as ssl
     */
    public static function hotstatus_mysql_connect(MySqlDatabase &$db, $creds) {
        return $db->ssl_connect(
            $creds[Credentials::KEY_DB_HOSTNAME],
            $creds[Credentials::KEY_DB_USER],
            $creds[Credentials::KEY_DB_PASSWORD],
            $creds[Credentials::KEY_DB_DATABASE],
            $creds[Credentials::KEY_DB_SSL_CLIENTKEY],
            $creds[Credentials::KEY_DB_SSL_CLIENTCERT],
            $creds[Credentials::KEY_DB_SSL_CACERT],
            false //Google SQL ssl requires verification to be off, since server is connected to by ip, but verification uses name, and Cloud SQL gives an impossible CN name for the instance
        );
    }

    /*
     * Pipeline configuration
     */
    const PIPELINE_CONFIG_DEFAULT = "default";
    public static $pipeline_config = [
        self::PIPELINE_CONFIG_DEFAULT => [
            "id" => 1,
        ],
    ];

    const REPLAY_STORAGE_INVALID = 0; //Default value of flag, for invalid storage states
    const REPLAY_STORAGE_CATALOG = 1; //"hotsapi"; //The replay is currently stored on hotsapi s3

    const REPLAY_STATUS_INVALID = 0; //Default value of flag, for invalid replays
    const REPLAY_STATUS_QUEUED = 1; //"queued"; //status value for when a replay is queued to be downloaded
    const REPLAY_STATUS_DOWNLOADING = 2; //"downloading"; //status value for when a replay is in the process of being downloaded
    const REPLAY_STATUS_DOWNLOADED = 3; //"downloaded"; //status value for when a replay has been downloaded
    const REPLAY_STATUS_DOWNLOAD_ERROR = 4; //"download_error"; //status value for when a replay could not be downloaded due to an error
    const REPLAY_STATUS_PARSING = 5; //"parsing"; //status value for when a replay is being parsed
    const REPLAY_STATUS_PARSED = 6; //"parsed"; //status value for when a replay is done being parsed
    const REPLAY_STATUS_REPARSING = 7; //"reparsing"; //status value for a when a replay is being reparsed
    const REPLAY_STATUS_REPARSED = 8; //"reparsed"; //status value for when a replay is done being reparsed
    const REPLAY_STATUS_REPARSE_ERROR = 9; //"reparse_error"; //status value for when a replay had an unknown error during reparsing
    const REPLAY_STATUS_PARSE_MMR_ERROR = 10; //"parse_mmr_error"; //status value for when a replay had an unknown error during mmr parsing
    const REPLAY_STATUS_PARSE_REPLAY_ERROR = 11; //"parse_replay_error"; //status value for when a replay had an unknown error during mmr parsing
    const REPLAY_STATUS_PARSE_TRANSLATE_ERROR = 12; //"parse_translate_error";
    const REPLAY_STATUS_MYSQL_ERROR = 13; //"mysql_error"; //status value for when a replay had a generic unknown mysql error, possibly from manipulating empty result objects
    const REPLAY_STATUS_MYSQL_MATCH_WRITE_ERROR = 14; //"mysql_match_write_error"; //status value for when a replay had an unknown mysql write error during match insertion
    const REPLAY_STATUS_MYSQL_MATCHDATA_WRITE_ERROR = 15; //"mysql_matchdata_write_error"; //status value for when a repaly had an unknown mysql write error during match data insertion
    const REPLAY_STATUS_OUTOFDATE = 16; //"replay_out_of_date"; //Replay is older than our minimum cutoff for dataset, seperate it from queued replays to improve query speeds
    const REPLAY_STATUS_PROCESSING = 17; //"processing"; //Special container instance processing, handles downloading and parsing replays one at a time without using EFS for dataset storage
}