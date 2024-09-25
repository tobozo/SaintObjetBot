<?php
declare(strict_types=1);

namespace SocialPlatform;

require_once("BlueSky.php");


class BlueskyStats
{
    private $api;
    private $account;

    private $cache_dir = 'cache/bluesky';
    private $cache_users_dir;
    private $cache_posts_dir;

    private $posts_history_json;
    private $follows_history_json;

    private $stats_file_csv;

    private $posts = [];

    private $max = 10;

    //private $rebloggers = [];
    private $posts_per_day = [];


    public function __construct($env)
    {
        $this->api = new \SocialPlatform\BlueskyApi($env["BSKY_API_APP_USER"], $env["BSKY_API_APP_TOKEN"], $this->cache_dir);
        $this->cache_users_dir      = $this->cache_dir.'/users';
        $this->cache_posts_dir      = $this->cache_dir.'/posts';
        $this->posts_history_json   = $this->cache_posts_dir.'/stats.json';
        $this->follows_history_json = $this->cache_users_dir.'/notifs.json';
        $this->stats_file_csv       = $this->cache_posts_dir.'/stats.csv';

        foreach([$this->cache_dir, $this->cache_users_dir, $this->cache_posts_dir] as $dir ) {
            if(!is_dir($dir)) {
                mkdir($dir, 0777, true) or php_die("Unable to create cache dir $dir".PHP_EOL);
            }
        }

        if( ! $this->api->getAccountDid() )
            php_die('Unable to get account id'.PHP_EOL);
    }




    public function fetchNotifications()
    {
        $cursor = null;
        //$notifications = [];

        $zero_count = ['count'=>0];

        $notifications = [
            'follow'  => [],
            'like'    => [],
            'quote'   => [],
            'reply'   => [],
            'repost'  => [],
            'mention' => []
        ];


        $q = "from:".$this->api->getSession()['handle'];

        for(;;)
        {
            $resp = $this->api->request('GET', 'app.bsky.notification.listNotifications', [
                'limit'=>100,
                'cursor' => $cursor
            ]);

            if( empty($resp['notifications']) )
            {
                echo "No more results".PHP_EOL;
                break;
            }

            $added = 0;

            foreach( $resp['notifications'] as $notification )
            {
                //$notifications[$notification['reason']]['count']++;

                $date_ary = date_parse($notification['indexedAt']);

                $date_path = sprintf("%04d/%02d/%02d/%s",
                    $date_ary['year'],
                    $date_ary['month'],
                    $date_ary['day'],
                    $notification['reason']
                );

                $date_dir = $this->cache_users_dir.'/'.$date_path;

                if( !is_dir($date_dir) )
                    mkdir($date_dir, 0777, true) or php_die("Unable to create dir $date_dir".PHP_EOL);

                $notif_filename = sprintf("%s/%02dh%02dm%02ds.json",
                    $date_dir,
                    $date_ary['hour'],
                    $date_ary['minute'],
                    $date_ary['second']
                );

                file_put_contents($notif_filename, json_encode($notification, JSON_PRETTY_PRINT) ) or php_die("Unable to save notification".PHP_EOL);

                $notifications[$notification['reason']][] = $notification;
                $added++;
            }

            echo sprintf("Added %d/%d posts, cursor: %s, last date: %s", $added, count($resp['notifications']), $cursor?$cursor:'initial', $date_path).PHP_EOL;


            if(!isset($resp['cursor']) )
            {
                echo "No more cursor".PHP_EOL;
                break;
            }

            if( $cursor == $resp['cursor'] )
            {
                echo "No new cursor".PHP_EOL;
                break;
            }

            $cursor = $resp['cursor'];
        }

        $count = array_keys($notifications);

        foreach($count as $idx => $key)
        {
            $count[$key] = count($notifications[$key]);
            unset($count[$idx]);
        }

        print_r($count);

        return $notifications;
    }





    public function getPosts()
    {
        if( file_exists($this->posts_history_json) && filemtime($this->posts_history_json)+86400 > time() )
        {
            return json_decode(file_get_contents($this->posts_history_json), true);
        }
        $posts = $this->fetchPosts();
        file_put_contents($this->posts_history_json, json_encode($posts) ) or php_die("Unable to save posts".PHP_EOL);
        return $posts;
    }




    private function fetchPosts()
    {
        $cursor = null;
        $posts = [];

        $q = "from:".$this->api->getSession()['handle'];

        for(;;)
        {
            $resp = $this->api->request('GET', 'com.atproto.repo.listRecords', [
                'repo' => $this->api->getSession()['handle'],
                'collection' => 'app.bsky.feed.post',
                //'limit'=>100,
                'cursor' => $cursor
            ]);

            if( empty($resp['records']) )
            {
                echo "No more results".PHP_EOL;
                break;
            }

            $added = 0;

            foreach( $resp['records'] as $record )
            {
                $post = $record['value'];
                $post['cid'] = $record['cid'];
                $post['uri'] = $record['uri'];
                unset($post['type']);

                $date_ary = date_parse($post['createdAt']);
                $date_path = sprintf("%04d/%02d/%02d", // /,
                    $date_ary['year'],
                    $date_ary['month'],
                    $date_ary['day']
                );

                $date_dir = $this->cache_posts_dir.'/'.$date_path;

                if( !is_dir($date_dir) )
                    mkdir($date_dir, 0777, true) or php_die("Unable to create dir $date_dir".PHP_EOL);

                $post_filename = sprintf("%s/%02dh%02dm%02ds.json",
                    $date_dir,
                    $date_ary['hour'],
                    $date_ary['minute'],
                    $date_ary['second']
                );

                file_put_contents($post_filename, json_encode($post, JSON_PRETTY_PRINT) ) or php_die("Unable to save post".PHP_EOL);

                $posts[] = $post;
                $added++;
            }

            echo sprintf("Added %d/%d posts, cursor: %s, last date: %s", $added, count($resp['records']), $cursor?$cursor:'initial', $date_path).PHP_EOL;


            if(!isset($resp['cursor']) )
            {
                echo "No more cursor".PHP_EOL;
                break;
            }

            if( $cursor == $resp['cursor'] )
            {
                echo "No new cursor".PHP_EOL;
                break;
            }

            $cursor = $resp['cursor'];

            //sleep(1);
        }

        return $posts;
    }



/*

    public function updateNotifications()
    {
        // preload existing history, if any
        if(file_exists($this->follows_history_json))
        {
            // check cache freshness
            $filemtime = date("Y-m-d", filemtime($this->follows_history_json));
            $nowtime   = date("Y-m-d", time());
            if( $filemtime == $nowtime )
            {
                echo "Notifications cache still fresh".PHP_EOL;
                return;
            }
        }

        printf("Will parse all follow notifications for account id %d\n", $this->account['id'] );

        $follows_per_day = $this->loadJSON($this->follows_history_json) or [];
        ksort($follows_per_day);

        $last_added_datetime = "";

        if( count($follows_per_day)>0 )
        {
            $last_added_datetime = (array_keys($follows_per_day))[count($follows_per_day)-1];
        }

        $followers = 0;

        $next_url = '/api/v1/notifications?types[]=follow';

        while( $next_url != "" )
        {
            $notifications = $this->api->callAPI($next_url, 'GET', null);

            if( isset( $notifications['curl_error'] ) || isset( $notifications['error'] ) || isset($notifications['json_error']) )
            {
                php_die("An API request to $next_url failed after collecting ".count($follows_per_day)." record(s)".PHP_EOL);
            }

            if( empty($notifications) )
                break;

            foreach($notifications as $notification)
            {
                if( substr_count($notification['account']['acct'], '@' ) === 0 )
                {
                    $notification['account']['acct'] .= '@'.$this->api->getInstanceHost();
                }

                $date_ary = date_parse($notification['created_at']);
                $date = sprintf("%04d-%02d-%02d", $date_ary['year'], $date_ary['month'], $date_ary['day'] );

                if( isset($follows_per_day[$date]) && $last_added_datetime == $date )
                {
                    echo "Notifications are up to date".PHP_EOL;
                    goto _save;
                }

                if(!isset($follows_per_day[$date]))
                {
                    $follows_per_day[$date] = 0;
                }

                $follows_per_day[$date]++;

                echo sprintf("[%s] New Follower: @%s ".PHP_EOL, $notification['created_at'], $notification['account']['acct']);
            }

            $next_url = $this->api->getNextPage();
            sleep(5);
        }

        _save:

        ksort($follows_per_day); // older (smaller value) first

        $this->saveJSON($this->follows_history_json, $follows_per_day) or php_die("Unable to save follows per day file".PHP_EOL);
    }



    public function genStats()
    {
        $this->posts = $this->loadJSON($this->posts_history_json) or php_die("Invalid json content in ".$this->posts_history_json);

        if( empty($this->posts ) )
            php_die("genStats: nothing to do".PHP_EOL);

        //$this->calcRebloggers();
        $this->calcFollowsPerDay();
        //$this->calcRank();
        $this->genCSVStats();
    }


    public function plotStats( $width=1280, $height=748)
    {
        if(!file_exists($this->stats_file_csv))
        {
            echo "plotStats: nothing to do".PHP_EOL;
            return;
        }

        exec('which gnuplot', $out);

        if(empty($out) || empty($out[0]))
        {
            echo ("gnuplot is not installed!".PHP_EOL);
            return;
        }
        if(!file_exists($out[0])) {
            echo ("gnuplot not reachable:  ".$out[0].PHP_EOL);
            return;
        }

        $gnuplot = $out[0];

        $format = "%s -e \"filename='%s'; width=%d; height=%d;\" %s";

        $execFollowers = sprintf($format, $gnuplot, $this->stats_file_csv, 1280, 748, "data/followers.gnuplot");
        $execReach = sprintf($format, $gnuplot, $this->stats_file_csv, 1280, 748, "data/reach.gnuplot");

        exec($execFollowers);
        exec($execReach);
    }



    private function calcFollowsPerDay()
    {
        if( !file_exists($this->follows_history_json))
            php_die("Update followers first!".PHP_EOL);

        $follows_per_day = $this->loadJSON($this->follows_history_json) or php_die("Unable to read follows per day".PHP_EOL);
        $this->posts_per_day = [];

        foreach($this->posts as $post)
        {
            $count = isset( $follows_per_day[$post['created_at']] ) ? $follows_per_day[$post['created_at']] : 0;
            $post['follows'] = $count;
            $this->posts_per_day[$post['created_at']] = $post;
        }
    }



    private function genCSVStats()
    {
        if( empty($this->posts ) )
            php_die("genCSVStats: nothing to do".PHP_EOL);

        // save stats as csv
        usort($this->posts, fn($a, $b) => (date_parse($a['created_at']) > date_parse($b['created_at'])));
        $fp = fopen($this->stats_file_csv, 'w');
        fputcsv($fp, ["created_at", "id", "object", "follows", "followers (total)", "reach", "rank", "reblogs_count", "replies_count", "favourites_count", "followers_avg"] );
        $followers = 0;
        $followers_avg = 0;
        foreach($this->posts as $num => $post)
        {
            // consolidate with follows count collected from notifications history
            $post['follows'] = ( !empty($this->posts_per_day) && isset($this->posts_per_day[$post['created_at']]) && isset($this->posts_per_day[$post['created_at']]['follows']) )
            ? $this->posts_per_day[$post['created_at']]['follows']
            : 0
            ;
            $followers += $post['follows'];

            if($num>0)
            {
                $followers_avg += $followers/$num;
            }

            fputcsv($fp, [ $post['created_at'], $post['id'], $post['object'], $post['follows'], $followers, $post['reach'], $post['rank'], $post['reblogs_count'], $post['replies_count'], $post['favourites_count'], $followers_avg ] );
        }
        fclose($fp);
    }

*/

    private function saveJSON($path, $arr)
    {
        // TODO: check if is_writable( dirname($path) );
        return file_put_contents($path, json_encode($arr, JSON_PRETTY_PRINT));
    }


    private function loadJSON($path)
    {
        if(!file_exists($path))
            return false;

        return json_decode(file_get_contents($path), true);
    }


};

