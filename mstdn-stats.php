<?php

require_once("lib/MastodonAnalytics.php");

$env = @parse_ini_file('.env') or php_die("Unable to parse ini file, forgot to rename '.env.example' to '.env'?".PHP_EOL);

if( !isset( $env["MASTODON_API_TOKEN"] ) || !isset( $env["MASTODON_API_SERVER"] ) )
{
    php_die("Missing credentials for mastodon, check your env file!".PHP_EOL );
}


php_log(PHP_EOL.PHP_EOL."************* Mastodon Analytics ********************".PHP_EOL.PHP_EOL);


$api   = new SocialPlatform\MastodonAPI( $env["MASTODON_API_TOKEN"], $env["MASTODON_API_SERVER"] );
$stats = new SocialPlatform\MastodonStats($api);

if( isset($argv[1]) && $argv[1] == "update" )
{
    $stats->updateToots();
    $stats->updateReplies();
    $stats->updateReblogs();
    $stats->updateNotifications();
}

$stats->genStats(true);
// print some stats to the console
$stats->printRebloggers( 10 );
$stats->printReach( 10 );
$stats->printEngagement( 10 );
$stats->printReblogs( 10 );
$stats->printFavourites( 10 );
$stats->printHallOfShame();
$stats->printReplies( 10 );
$stats->printRepliers( 10 );
// plot some stats using gnuplot
$stats->plotStats();
