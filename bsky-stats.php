<?php

//define("CACHE_DIR", "cache" );

require_once("lib/BlueskyAnalytics.php");


$env = @parse_ini_file('.env') or php_die("Unable to parse ini file, forgot to rename '.env.example' to '.env'?".PHP_EOL);

if( !isset( $env["BSKY_API_APP_USER"] ) || !isset( $env["BSKY_API_APP_TOKEN"] ) )
{
    php_die("Missing credentials for mastodon, check your env file!".PHP_EOL );
}

php_log(PHP_EOL.PHP_EOL."************* Bluesky Analytics ********************".PHP_EOL.PHP_EOL);


$calendar = getCSVData("data/saint-objet-bot-2023-11-09.csv");

$stats = new SocialPlatform\BlueskyStats($env, $calendar);

$stats->run();

$stats->printReach();
$stats->printRank();

$stats->genCSV();


