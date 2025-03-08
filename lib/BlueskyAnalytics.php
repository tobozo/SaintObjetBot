<?php
declare(strict_types=1);

namespace SocialPlatform;

require_once("BlueSky.php");


class BlueskyStats
{
    private $api;
    private $account;

    private $cache_dir = 'cache/bluesky';
    private $cache_notifs_dir;
    private $cache_posts_dir;
    private $cache_profiles_dir;

    private $posts_history_json;
    private $notifs_history_json;
    private $records_history_json;
    private $profiles_history_json;

    private int $profile_expire_after = 86400*15;
    private int $posts_expire_after   = 86400;
    private int $notifs_expire_after  = 86400;
    private int $records_expire_after = 86400;

    private $stats_file_csv; // csv data store for gnuplot

    private $me; // bot's profile

    // report variables
    private $posts = [];
    private $posts_by_cid   = [];
    private $items_by_date  = [/*"created_at", "id", "object", "follows", "followers (total)", "reach", "rank", "reblogs_count", "replies_count", "favourites_count", "followers_avg"*/]; // csv data
    private $notifs_by_day  = [];
    private $notifs_by_user = [];
    private $notifs_count   = [];
    //private $posts          = [];
    private $notifications  = [];
    private $profiles       = [];
    private $followersCount = 0;
    private $reach_waterline = 0;
    private $calendar = [];



    public function __construct($env, $calendar)
    {
        $this->api = new \SocialPlatform\BlueskyApi($env["BSKY_API_APP_USER"], $env["BSKY_API_APP_TOKEN"], $this->cache_dir);

        $this->calendar = $calendar;

        $this->cache_notifs_dir      = $this->cache_dir.'/notifications';
        $this->cache_posts_dir       = $this->cache_dir.'/posts';
        $this->cache_profiles_dir    = $this->cache_dir.'/profiles';

        $this->posts_history_json    = $this->cache_posts_dir.'/posts.json';
        $this->records_history_json  = $this->cache_posts_dir.'/records.json';
        $this->notifs_history_json   = $this->cache_notifs_dir.'/notifs.json';
        $this->profiles_history_json = $this->cache_profiles_dir.'/profiles.json';

        $this->stats_file_csv        = $this->cache_dir.'/stats.csv';

        foreach([$this->cache_dir, $this->cache_notifs_dir, $this->cache_posts_dir, $this->cache_profiles_dir] as $dir ) {
            if(!is_dir($dir)) {
                mkdir($dir, 0777, true) or php_die("Unable to create cache dir $dir".PHP_EOL);
            }
        }

        if( ! $this->api->getAccountDid() )
            php_die('Unable to get account id'.PHP_EOL);

        $this->me = $this->api->fetchProfile(['handle' => $this->api->getAccountDid() ]);
        $this->me['followersCount'];// => 365
        $this->me['followsCount'];// => 1
        $this->me['postsCount'];// => 397
    }


    public function run()
    {
        $this->updateCache();

        $this->calcNotifications();
        $this->calcFollowers();
        $this->calcRank();
    }


    public function updateCache()
    {
        $this->posts         = $this->getPostsFromMe();
        $this->notifications = $this->getNotifications();
        $this->profiles      = $this->getProfiles();

        $this->updateNotifications();
        $this->updatePosts();
    }


    public function updateNotifications()
    {
        if( empty($this->notifications) )
        {
            php_die("updateNotifications(): nothing to do".PHP_EOL);
        }

        $this->notifs_count = array_keys($this->notifications);
        foreach($this->notifs_count as $idx => $key)
        {
            $this->notifs_count[$key] = count($this->notifications[$key]);
            $this->notifs_by_day[$key] = [];
            $this->notifs_by_user[$key] = [];
            unset($this->notifs_count[$idx]);
        }

        print_r([
            'posts'    => count($this->posts),
            //'records'  => count($records),
            'notifs'   => $this->notifs_count,
            'profiles' => count($this->profiles),

        ]);
    }


    public function updatePosts()
    {
        if( empty($this->posts) )
        {
            php_die("updatePosts(): nothing to do".PHP_EOL);
        }

        foreach( $this->posts as $num => $post )
        {
            // filter out replies and manual posts
            if(!str_starts_with($post['text'], 'Chalut')) // not a SaintObjet post
            {
                unset($this->posts[$num]); // this has no effect
                continue; // not a "Chalut" post, ignore
            }

            // WARNING: csv file gets often updated, this should extract the SaintObjet from the post instead of the one from the csv
            $quote_data = getQuoteData(strtotime($post['createdAt']), $this->calendar);

            $date_ary = date_parse($post['createdAt']);
            $date_sig = sprintf("%04d-%02d-%02d", $date_ary['year'], $date_ary['month'], $date_ary['day'] );

            // overload the array with additional data
            $post['date_sig'] = $date_sig;
            $post['object']   = ucwords($quote_data[2]);
            $post['reach']    = 0;
            $post['rank']     = 0;

            $this->posts_by_cid[$post['cid']] = $post;

            $this->items_by_date[$date_sig] = [
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

    }



    public function calcNotifications()
    {
        if( empty($this->notifications) )
        {
            php_die("calcNotifications(): nothing to do".PHP_EOL);
        }

        $reasons = ['follow', 'like', 'quote', 'reply', 'repost', 'mention', 'starterpack-joined'];

        foreach($reasons as $notif_key)
        {
            if( !array_key_exists($notif_key, $this->notifications))
                continue;
            $items = $this->notifications[$notif_key];
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
                        if(!isset($this->notifs_by_day['follow'][$date_sig]))
                            $this->notifs_by_day['follow'][$date_sig] = [];
                        $this->notifs_by_day['follow'][$date_sig][] = $item['author']['handle'];
                        continue 2;
                    break;
                    case 'starterpack-joined' :
                        echo "TODO: handle '$notif_key'".PHP_EOL;
                        print_r($item);
                        // $join_date = $item['record']['createdAt'];
                        // $date_sig = sprintf("%04d-%02d-%02d", $date_ary['year'], $date_ary['month'], $date_ary['day'] );
                        // if(!isset($this->notifs_by_day['starterpack-joined'][$date_sig]))
                        //     $this->notifs_by_day['starterpack-joined'][$date_sig] = [];
                        // $this->notifs_by_day['starterpack-joined'][$date_sig][] = $item['author']['handle'];
                        continue 2;
                    break;
                    default       :
                        php_die("WTF".PHP_EOL);
                }

                if(!array_key_exists($cid, $this->posts_by_cid )) // not related to a tracked post
                    continue;

                $profile = $this->hydrateAuthor($item['author']);

                $post_date = $this->posts_by_cid[$cid]['createdAt'];
                $date_ary = date_parse($post_date);
                $date_sig = sprintf("%04d-%02d-%02d", $date_ary['year'], $date_ary['month'], $date_ary['day'] );

                if( array_key_exists('followersCount', $profile) )
                    $this->items_by_date[$date_sig]['reach'] += $profile['followersCount'];

                $this->items_by_date[$date_sig]['rank']++;

                $this->notifs_by_day[$notif_key][$date_sig][] = $item;

                if(!isset($this->notifs_by_user[$notif_key][$profile['handle']]))
                    $this->notifs_by_user[$notif_key][$profile['handle']] = 0;

                $this->notifs_by_user[$notif_key][$profile['handle']]++;
            }
        }
    }




    public function calcFollowers()
    {
        if( empty($this->items_by_date) )
        {
            php_die("calcFollowers(): nothing to do".PHP_EOL);
        }

        usort($this->items_by_date, fn($a, $b) => (date_parse($a['created_at']) > date_parse($b['created_at'])));

        $followers = 0;
        $followers_avg = 0;
        $avg_size = 0;

        $reach_total = 0;
        $reach_days  = 0;

        // print_r($this->notifs_by_day['follow']);

        foreach($this->items_by_date as $idx => $post)
        {
            $date_sig = $post['created_at'];
            $follows = 0;
            if( isset($this->notifs_by_day['follow'][$date_sig]) )
            {
                $follows = count($this->notifs_by_day['follow'][$date_sig]);
                $followers += $follows;
                //echo "$follows follows on $date_sig (total=$followers, last avg=$followers_avg) (idx $idx)".PHP_EOL;
            }

            $reach_days++;
            $reach_total += $this->items_by_date[$idx]['reach'];
            $reach_avg = $reach_total/$reach_days;
            $this->items_by_date[$idx]['reach_avg']     = $reach_avg;

            if( $followers > 0 )
            {
                $avg_size++;
                $followers_avg += $followers/$avg_size;
            }
            $this->items_by_date[$idx]['follows']       = $follows;
            $this->items_by_date[$idx]['followers']     = $followers;
            $this->items_by_date[$idx]['followers_avg'] = $followers_avg;
        }

        $this->followersCount = $this->me['followersCount'];

        if($this->followersCount != $followers )
        {
            // Bot profile reports more or less followers than measured in notifications dataset.
            // Happens when the dataset is limited to a time range and/or has been pruned.
            // Adjust every entry accordingly.
            $offset = $this->followersCount - $followers;
            foreach($this->items_by_date as $idx => $post)
            {
                $this->items_by_date[$idx]['followers'] += $offset;
                $this->items_by_date[$idx]['followers_avg'] += $offset;
            }
        }

    }




    public function calcRank()
    {
        if( empty($this->items_by_date) )
        {
            php_die("calcRank(): nothing to do".PHP_EOL);
        }

        usort($this->items_by_date, fn($a, $b) => ($a['rank'] < $b['rank']));
        $rank = 1;
        foreach($this->items_by_date as $num => $toot)
        {
            $this->items_by_date[$num]['rank'] = $rank;
            $rank++;
        }
    }



    public function printReach($max = 10)
    {
        if( empty($this->items_by_date) )
        {
            php_die("printReach(): nothing to do".PHP_EOL);
        }

        echo PHP_EOL."SaintObjets with most reached accounts (REACH=rebloggers' followers):".PHP_EOL;

        usort($this->items_by_date, fn($a, $b) => ($a['reach'] < $b['reach']));
        $count = 0;
        foreach($this->items_by_date as $cid => $post)
        {
            echo sprintf("[REACH:%5d] (RT:%2d, RE:%2d, FAV:%2d) [%s] %s".PHP_EOL, $post['reach'], $post['reblogs_count'], $post['replies_count'], $post['favourites_count'], $post['created_at'], $post['object'] );
            if(++$count>=$max)
            {
                $this->reach_waterline = $post['reach'];
                break;
            }
        }
    }


    public function printRank($max = 10)
    {
        if( empty($this->items_by_date) )
        {
            php_die("printRank(): nothing to do".PHP_EOL);
        }

        echo PHP_EOL."SaintObjets with most interactions (RANK=RT+RE+FAV):".PHP_EOL;

        usort($this->items_by_date, fn($a, $b) => ($a['rank'] > $b['rank']));
        $count = 0;
        foreach($this->items_by_date as $cid => $post)
        {
            echo sprintf("[RANK: %d] (RT:%2d, RE:%2d, FAV:%2d) [%s] %s".PHP_EOL, $post['rank'], $post['reblogs_count'], $post['replies_count'], $post['favourites_count'], $post['created_at'], $post['object'] );
            if(++$count>=$max)
                break;
        }
    }



    public function genCSV()
    {
        $this->genCSVStats($this->items_by_date);
        $this->plotStats();
    }


    public function getProfile()
    {
        return $this->me;
    }



    public function hydrateAuthor( $author_ary )
    {
        $author_dir = $this->cache_profiles_dir.'/'.substr($author_ary['handle'], 0, 1);
        $author_filename = $author_dir.'/'.$author_ary['handle'].'.json';
        if(! file_exists($author_filename) || filemtime($author_filename)+($this->profile_expire_after) < time() )
        {
            if(!is_dir($author_dir))
                mkdir($author_dir, 0777, true) or php_die("Unable to create profile dir $author_dir".PHP_EOL);
            $profile = $this->api->fetchProfile($author_ary);
            saveJSON($author_filename, $profile) or php_die("Unable to save profile file $author_filename".PHP_EOL);
        }
        else
            $profile = loadJSON($author_filename);

        if(empty($profile))
            php_die("Invalid profile file $author_filename".PHP_EOL);
        return $profile;
    }


    public function getProfiles()
    {
        // $cached_profiles = loadJSON($this->profiles_history_json);
        //if(!empty($cached_profiles))
        //    echo "Loading ".count($cached_profiles)." cached profiles".PHP_EOL;

        $profiles = [];
        $cached_notifs = loadJSON($this->notifs_history_json);

        if(!empty($cached_notifs))
        {
            $total_notifs = 0;
            foreach($cached_notifs as $reason => $notifs)
                $total_notifs += count($notifs);
            //echo "Extracting profiles from $total_notifs notifications".PHP_EOL;
        }

        foreach($cached_notifs as $reason => $notifs)
        {
            foreach( $notifs as $num => $notif )
            {
                $handle = $notif['author']['handle'];

                if( $handle == 'handle.invalid' )
                {
                    unset( $notifs[$num] );
                    continue;
                }
                $profiles[$handle] = $this->hydrateAuthor($notif['author']);
            }
        }

        //echo "Got ".count($profiles)." profiles".PHP_EOL;

        saveJSON($this->profiles_history_json, $profiles) or php_die("Unable to save profiles to $this->profiles_history_json".PHP_EOL);

        return $profiles;
    }



    public function getPostsFromMe()
    {
        if( file_exists($this->posts_history_json) && filemtime($this->posts_history_json)+$this->posts_expire_after > time() )
        {
            return loadJSON($this->posts_history_json);
        }
        $posts = $this->api->fetchPostsFromMe($this->cache_posts_dir);
        saveJSON($this->posts_history_json, $posts ) or php_die("Unable to save posts".PHP_EOL);
        return $posts;
    }



    public function getNotifications()
    {
        if( file_exists($this->notifs_history_json) && filemtime($this->notifs_history_json)+$this->notifs_expire_after > time() )
        {
            return loadJSON($this->notifs_history_json);
        }
        $posts = $this->api->fetchNotifications($this->cache_notifs_dir);
        saveJSON($this->notifs_history_json, $posts) or php_die("Unable to save posts".PHP_EOL);
        return $posts;
    }




    public function getRecords()
    {
        if( file_exists($this->records_history_json) && filemtime($this->records_history_json)+$this->records_expire_after > time() )
        {
            return loadJSON($this->records_history_json);
        }
        $records = $this->api->fetchRecords($this->cache_posts_dir);
        file_put_contents($this->records_history_json, json_encode($records) ) or php_die("Unable to save records".PHP_EOL);
        return $records;
    }



    private function genCSVStats($data)
    {
        if( empty($data ) )
            php_die("genCSVStats: nothing to do".PHP_EOL);

        $oneYearAgo = strtotime('-1 year');

        // sort ascending
        usort($data, fn($a, $b) => (date_parse($a['created_at']) > date_parse($b['created_at'])));
        // save stats as csv
        $fp = fopen($this->stats_file_csv, 'w');
        $column_titles = [
            "created_at",
            "id",
            "object",
            "follows",
            "followers (total)",
            "reach",
            "rank",
            "reblogs_count",
            "replies_count",
            "favourites_count",
            "followers_avg",
            "reach_avg"
        ];
        // populate first row with column titles
        fputcsv($fp, $column_titles );
        foreach($data as $num => $post)
        {

            if( $oneYearAgo > strtotime( $post['created_at'] ) )
                continue;

            fputcsv($fp, [
                $post['created_at'],
                $post['id'],
                $post['object'],
                $post['follows'],
                $post['followers'],
                $post['reach'],
                $post['rank'],
                $post['reblogs_count'],
                $post['replies_count'],
                $post['favourites_count'],
                $post['followers_avg'],
                $post['reach_avg']
            ] );
        }
        fclose($fp);
    }


    private function plotStats( $width=1280, $height=748)
    {
        if(!file_exists($this->stats_file_csv))
        {
            echo "plotStats: nothing to do".PHP_EOL;
            return;
        }

        exec('which gnuplot', $out);

        if(empty($out) || empty($out[0]))
        {
            echo "gnuplot is not installed!".PHP_EOL;
            return;
        }
        if(!file_exists($out[0])) {
            echo "gnuplot not reachable:  ".$out[0].PHP_EOL;
            return;
        }

        $gnuplot = $out[0];

        $dateToday = date("Y-m-d");
        $plotprefix = "Grouchabot Bluesky YTD($dateToday):";
        $reach_ytics = 1000;
        $reach_waterline= $this->reach_waterline-($this->reach_waterline%$reach_ytics);

        $format = "%s -e \"outputtitle='%s %s'; outputfilename='%s'; filename='%s'; width=%d; height=%d; reach_waterline=%d; reach_ytics=%d\" %s";

        $execFollowers = sprintf($format, $gnuplot, $plotprefix, "Followers Growth",   "$this->cache_dir/followers.png", $this->stats_file_csv, $width, $height, $reach_waterline, $reach_ytics, "data/followers.gnuplot");
        $execReach     = sprintf($format, $gnuplot, $plotprefix, "Reach vs Followers", "$this->cache_dir/reach.png",     $this->stats_file_csv, $width, $height, $reach_waterline, $reach_ytics, "data/reach.gnuplot");

        exec($execFollowers);
        exec($execReach);
    }


};

