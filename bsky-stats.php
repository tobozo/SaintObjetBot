<?php

//define("CACHE_DIR", "cache" );

require_once("lib/BlueskyAnalytics.php");


$env = @parse_ini_file('.env') or php_die("Unable to parse ini file, forgot to rename '.env.example' to '.env'?".PHP_EOL);

if( !isset( $env["BSKY_API_APP_USER"] ) || !isset( $env["BSKY_API_APP_TOKEN"] ) )
{
    php_die("Missing credentials for mastodon, check your env file!".PHP_EOL );
}

$calendar = getCSVData("data/saint-objet-bot-2023-11-09.csv");

$stats = new SocialPlatform\BlueskyStats($env);


$posts = $stats->getPostsFromMe();
$notifications = $stats->getNotifications();
//$records = $stats->getRecords();
$profiles = $stats->getProfiles();


$notifs_count = array_keys($notifications);
$notifs_by_day = [];
$notifs_by_user = [];

foreach($notifs_count as $idx => $key)
{
    $notifs_count[$key] = count($notifications[$key]);
    $notifs_by_day[$key] = [];
    $notifs_by_user[$key] = [];
    unset($notifs_count[$idx]);
}

print_r([
    'posts'    => count($posts),
    //'records'  => count($records),
    'notifs'   => $notifs_count,
    'profiles' => count($profiles),

]);



$posts_by_cid = [];
$items_by_date = [/*"created_at", "id", "object", "follows", "followers (total)", "reach", "rank", "reblogs_count", "replies_count", "favourites_count", "followers_avg"*/]; // csv data


// populate arrays by date
foreach( $posts as $num => $post )
{
    // filter out replies and manual posts
    if(!str_starts_with($post['text'], 'Chalut')) // not a SaintObjet post
    {
        unset($posts[$num]); // not a "Chalut" post, ignore
        continue;
    }

    $quote_data = getQuoteData(strtotime($post['createdAt']), $calendar);

    $date_ary = date_parse($post['createdAt']);
    $date_sig = sprintf("%04d-%02d-%02d", $date_ary['year'], $date_ary['month'], $date_ary['day'] );

    // overload the array with additional data
    $post['date_sig'] = $date_sig;
    $post['object']   = ucwords($quote_data[2]);
    $post['reach']    = 0;
    $post['rank']     = 0;

    $posts_by_cid[$post['cid']] = $post;

    $items_by_date[$date_sig] = [
        "created_at"       => $date_sig,
        "id"               => $post['cid'],
        "object"           => ucwords($quote_data[2]),
        "follows"          => 0,
        "followers"        => 0,
        "reach"            => 0,
        "rank"             => 0,
        "reblogs_count"    => $post['repostCount'],
        "replies_count"    => $post['replyCount'],
        "favourites_count" => $post['likeCount'],
        "followers_avg"    => 0
    ]; // csv data

}

// consolidate with notification data
foreach(['follow', 'like', 'quote', 'reply', 'repost', 'mention'] as $notif_key)
{
    if( !array_key_exists($notif_key, $notifications))
        continue;
    $items = $notifications[$notif_key];
    foreach($items as $item)
    {
        switch($notif_key)
        {
            case 'like'   :
            case 'repost' :
                if( isset($item['record']['subject']['cid']) )
                    $cid = $item['record']['subject']['cid'];
                else
                    continue 2; // malformed
            break;

            case 'quote'  :
                if(isset($item['record']['embed']['record']['cid']))
                    $cid = $item['record']['embed']['record']['cid'];
                else if(isset($item['record']['embed']['record']['record']['cid']))
                    $cid = $item['record']['embed']['record']['record']['cid'];
                else
                    continue 2; // quote of a quote of a quote ?
            break;

            case 'reply'  :
            case 'mention':
                if(isset($item['record']['reply']['root']['cid']))
                    $cid = $item['record']['reply']['root']['cid'];
                else
                    continue 2; // not directly attached to a tracked post
            break;
            case 'follow' :
                $follow_date = $item['record']['createdAt'];
                $date_ary = date_parse($follow_date);
                $date_sig = sprintf("%04d-%02d-%02d", $date_ary['year'], $date_ary['month'], $date_ary['day'] );
                if(!isset($notifs_by_day['follow'][$date_sig]))
                    $notifs_by_day['follow'][$date_sig] = [];
                $notifs_by_day['follow'][$date_sig][] = $item['author']['handle'];
                continue 2;
            break;
            default       :
                php_die("WTF".PHP_EOL);
        }

        if(!array_key_exists($cid, $posts_by_cid )) // not related to a tracked post
            continue;

        $profile = $stats->hydrateAuthor($item['author']);

        $post_date = $posts_by_cid[$cid]['createdAt'];
        $date_ary = date_parse($post_date);
        $date_sig = sprintf("%04d-%02d-%02d", $date_ary['year'], $date_ary['month'], $date_ary['day'] );

        if( array_key_exists('followersCount', $profile) )
            $items_by_date[$date_sig]['reach'] += $profile['followersCount'];

        $items_by_date[$date_sig]['rank']++;

        $notifs_by_day[$notif_key][$date_sig][] = $item;

        if(!isset($notifs_by_user[$notif_key][$profile['handle']]))
            $notifs_by_user[$notif_key][$profile['handle']] = 0;

        $notifs_by_user[$notif_key][$profile['handle']]++;
    }
}


// evaluate followers count
$items = $items_by_date;
usort($items_by_date, fn($a, $b) => (date_parse($a['created_at']) > date_parse($b['created_at'])));

$followers = 0;
$followers_avg = 0;
$avg_size = 0;

$reach_total = 0;
$reach_days  = 0;

// print_r($notifs_by_day['follow']);

foreach($items_by_date as $idx => $post)
{
    $date_sig = $post['created_at'];
    $follows = 0;
    if( isset($notifs_by_day['follow'][$date_sig]) )
    {
        $follows = count($notifs_by_day['follow'][$date_sig]);
        $followers += $follows;
        //echo "$follows follows on $date_sig (total=$followers, last avg=$followers_avg) (idx $idx)".PHP_EOL;
    }

    $reach_days++;
    $reach_total += $items_by_date[$idx]['reach'];
    $reach_avg = $reach_total/$reach_days;
    $items_by_date[$idx]['reach_avg']     = $reach_avg;

    if( $followers > 0 )
    {
        $avg_size++;
        $followers_avg += $followers/$avg_size;
    }
    $items_by_date[$idx]['follows']       = $follows;
    $items_by_date[$idx]['followers']     = $followers;
    $items_by_date[$idx]['followers_avg'] = $followers_avg;
}


// compute rank
usort($items_by_date, fn($a, $b) => ($a['rank'] < $b['rank']));
$rank = 1;
foreach($items_by_date as $num => $toot)
{
    $items_by_date[$num]['rank'] = $rank;
    $rank++;
}

$stats->genCSVStats($items_by_date);
$stats->plotStats();



echo PHP_EOL."SaintObjets with most reached accounts (REACH=rebloggers' followers):".PHP_EOL;

usort($items_by_date, fn($a, $b) => ($a['reach'] < $b['reach']));
$count = 0;
foreach($items_by_date as $cid => $post)
{
    echo sprintf("[REACH:%5d] (RT:%2d, RE:%2d, FAV:%2d) [%s] %s".PHP_EOL, $post['reach'], $post['reblogs_count'], $post['replies_count'], $post['favourites_count'], $post['created_at'], $post['object'] );
    if(++$count>=10)
        break;
}



echo PHP_EOL."SaintObjets with most interactions (RANK=RT+RE+FAV):".PHP_EOL;

usort($items_by_date, fn($a, $b) => ($a['rank'] > $b['rank']));
$count = 0;
foreach($items_by_date as $cid => $post)
{
    echo sprintf("[RANK: %d] (RT:%2d, RE:%2d, FAV:%2d) [%s] %s".PHP_EOL, $post['rank'], $post['reblogs_count'], $post['replies_count'], $post['favourites_count'], $post['created_at'], $post['object'] );
    if(++$count>=10)
        break;
}


// private function calcRebloggers($posts)
// {
//     if( empty($posts ) )
//         php_die("calcRebloggers: nothing to do".PHP_EOL);
//
//     $rebloggers = [];
//     // calculate reach/rank and memoize users
//     foreach($posts as $num => $toot)
//     {
//
//         // if(isset($toot['replies']))
//         // {
//         //     foreach($toot['replies'] as $reply)
//         //     {
//         //         $toot['reblogs_count']    += $reply['reblogs_count'];
//         //         $toot['favourites_count'] += $reply['favourites_count'];
//         //     }
//         // }
//
//         $posts[$num]['reach'] = $post['replyCount'];
//         $posts[$num]['rank']  = $post['favourites_count']+$post['reblogs_count']+$post['replies_count'];
//
//         if( isset($post['rebloggers']) )
//         {
//             foreach($post['rebloggers'] as $user_id => $user)
//             {
//                 if(!isset($rebloggers[$user_id]))
//                 {
//                     $rebloggers[$user_id] = $user;
//                     $rebloggers[$user_id]['reblogs_count'] = 0;
//                 }
//                 $rebloggers[$user_id]['reblogs_count']++; // increment assiduity for user
//                 $posts[$num]['reach'] += $user['followers_count']; // sum followers for toot
//             }
//         }
//     }
//     return ['posts'=>$posts, 'rebloggers'=>$rebloggers];
// }





exit;

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
