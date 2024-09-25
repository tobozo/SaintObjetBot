<?php

//define("CACHE_DIR", "cache" );

require_once("lib/BlueskyAnalytics.php");


$env = @parse_ini_file('.env') or php_die("Unable to parse ini file, forgot to rename '.env.example' to '.env'?".PHP_EOL);

if( !isset( $env["BSKY_API_APP_USER"] ) || !isset( $env["BSKY_API_APP_TOKEN"] ) )
{
    php_die("Missing credentials for mastodon, check your env file!".PHP_EOL );
}

$stats = new SocialPlatform\BlueskyStats($env);

$notifications = $stats->fetchNotifications();

$follows = $notifications['follow'];
$follows_by_day = [];

foreach($follows as $follow)
{
    $date_ary = date_parse($follow['record']['createdAt']);
    $date_sig = sprintf("%04d-%02d-%02d", $date_ary['year'], $date_ary['month'], $date_ary['day'] );

    if( !isset($follows_by_day[$date_sig] ) )
        $follows_by_day[$date_sig] = [];

    $follows_by_day[$date_sig][] = $follow;
}

$total_followers = 0;

foreach( $follows_by_day as $date_sig => $follows )
{
    $total_followers += count($follows);
    echo sprintf("[%s] : %2d follows (total %d)".PHP_EOL, $date_sig, count($follows), $total_followers );
}



//$stats->getPosts();
//$stats->getRepo();


if( isset($argv[1]) && $argv[1] == "update" )
{
    //$stats->updateToots();
    //$stats->updateReplies();
    //$stats->updateReblogs();
    //$stats->updateNotifications();
}

// $stats->genStats();
// // print some stats to the console
// $stats->printRebloggers( 10 );
// $stats->printReach( 10 );
// $stats->printEngagement( 10 );
// $stats->printReblogs( 10 );
// $stats->printFavourites( 10 );
// $stats->printReplies( 10 );
// $stats->printRepliers( 10 );
// // plot some stats using gnuplot
// $stats->plotStats();
