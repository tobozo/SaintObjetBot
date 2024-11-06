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

    private $stats_file_csv;

    private $posts = [];

    private $max = 10;

    private $posts_per_day = [];


    public function __construct($env)
    {
        $this->api = new \SocialPlatform\BlueskyApi($env["BSKY_API_APP_USER"], $env["BSKY_API_APP_TOKEN"], $this->cache_dir);
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
    }


    public function fetchProfile( $author_ary )
    {
        $res = $this->api->request('GET', 'app.bsky.actor.getProfile', ['actor' => $author_ary['handle']]);
        if( !$res || isset( $res['curl_error_code'] ) || !isset($res['handle']) )
        {
            print_r($res);
            php_die("... Failed to fetch profile for ".$author_ary['handle'].PHP_EOL);
        }
        echo "Fetched profile for ".$author_ary['handle'].PHP_EOL;
        return $res;
    }



    public function hydrateAuthor( $author_ary )
    {
        $author_dir = $this->cache_profiles_dir.'/'.substr($author_ary['handle'], 0, 1);
        $author_filename = $author_dir.'/'.$author_ary['handle'].'.json';
        if(! file_exists($author_filename) || filemtime($author_filename)+($this->profile_expire_after) < time() )
        {
            if(!is_dir($author_dir))
                mkdir($author_dir, 0777, true) or php_die("Unable to create profile dir $author_dir".PHP_EOL);
            $profile = $this->fetchProfile($author_ary);
            $this->saveJSON($author_filename, $profile) or php_die("Unable to save profile file $author_filename".PHP_EOL);
        }
        else
            $profile = $this->loadJSON($author_filename);

        if(empty($profile))
            php_die("Invalid profile file $author_filename".PHP_EOL);
        return $profile;
    }


    public function getProfiles()
    {
        $cached_profiles = $this->loadJSON($this->profiles_history_json);
        $profiles = [];

        if(!empty($cached_profiles))
            echo "Loading ".count($cached_profiles)." cached profiles".PHP_EOL;

        $cached_notifs = $this->loadJSON($this->notifs_history_json);

        if(!empty($cached_notifs))
        {
            $total_notifs = 0;
            foreach($cached_notifs as $reason => $notifs)
                $total_notifs += count($notifs);
            echo "Extracting profiles from $total_notifs notifications".PHP_EOL;
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

        echo "Got ".count($profiles)." profiles".PHP_EOL;

        $this->saveJSON($this->profiles_history_json, $profiles) or php_die("Unable to save profiles to $this->profiles_history_json".PHP_EOL);

        return $profiles;
    }



    public function getPostsFromMe()
    {
        if( file_exists($this->posts_history_json) && filemtime($this->posts_history_json)+$this->posts_expire_after > time() )
        {
            return $this->loadJSON($this->posts_history_json);
        }
        $posts = $this->fetchPostsFromMe();
        $this->saveJSON($this->posts_history_json, $posts ) or php_die("Unable to save posts".PHP_EOL);
        return $posts;
    }



    public function fetchPostsFromMe()
    {
        echo "Fetching Posts 'from:me'".PHP_EOL;

        $cursor = null;
        $posts = [];

        for(;;)
        {
            $resp = $this->api->request('GET', 'app.bsky.feed.searchPosts', ['q'=>'from:me', 'sort'=>'latest', 'limit'=>100, 'cursor' => $cursor ] );

            if( !$resp || isset( $resp['curl_error_code'] ) || !isset($resp['posts']) )
            {
                print_r($resp);
                php_die("... Search failed:".PHP_EOL);
            }

            if( empty($resp['posts']) )
            {
                echo "No more results".PHP_EOL;
                break;
            }

            $added = 0;

            foreach( $resp['posts'] as $item )
            {
                $record = $item['record'];

                $post = [
                    'text'        => $record['text'],
                    'createdAt'   => $record['createdAt'],
                    'cid'         => $item['cid'],
                    'uri'         => $item['uri'],
                    'replyCount'  => $item['replyCount'],
                    'repostCount' => $item['repostCount'],
                    'likeCount'   => $item['likeCount'],
                    'quoteCount'  => $item['quoteCount'],
                ];

                $date_ary = date_parse($post['createdAt']);

                $date_path = sprintf("%04d/%02d/%02d",
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

                $this->saveJSON($post_filename, $post) or php_die("Unable to save post".PHP_EOL);

                $posts[] = $post;
                $added++;
            }

            echo sprintf("Added %d/%d items, cursor: %s", $added, count($resp['posts']), $cursor?$cursor:'initial').PHP_EOL;

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

        return $posts;

    }



    public function getNotifications()
    {
        if( file_exists($this->notifs_history_json) && filemtime($this->notifs_history_json)+$this->notifs_expire_after > time() )
        {
            return $this->loadJSON($this->notifs_history_json);
        }
        $posts = $this->fetchNotifications();
        $this->saveJSON($this->notifs_history_json, $posts) or php_die("Unable to save posts".PHP_EOL);
        return $posts;
    }



    public function fetchNotifications()
    {
        $cursor = null;
        $zero_count = ['count'=>0];

        $notifications = [
            'follow'  => [],
            'like'    => [],
            'quote'   => [],
            'reply'   => [],
            'repost'  => [],
            'mention' => []
        ];

        echo "Fetching notifications".PHP_EOL;

        for(;;)
        {
            $resp = $this->api->request('GET', 'app.bsky.notification.listNotifications', [
                'limit'  => 100,
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
                $date_ary = date_parse($notification['indexedAt']);

                $date_path = sprintf("%04d/%02d/%02d/%s",
                    $date_ary['year'],
                    $date_ary['month'],
                    $date_ary['day'],
                    $notification['reason']
                );

                $date_dir = $this->cache_notifs_dir.'/'.$date_path;

                if( !is_dir($date_dir) )
                    mkdir($date_dir, 0777, true) or php_die("Unable to create dir $date_dir".PHP_EOL);

                $notif_filename = sprintf("%s/%02dh%02dm%02ds.json",
                    $date_dir,
                    $date_ary['hour'],
                    $date_ary['minute'],
                    $date_ary['second']
                );

                $this->saveJSON($notif_filename, $notification) or php_die("Unable to save notification".PHP_EOL);

                $notifications[$notification['reason']][] = $notification;
                $added++;
            }

            echo sprintf("Added %d/%d items, cursor: %s", $added, count($resp['notifications']), $cursor?$cursor:'initial').PHP_EOL;


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

        return $notifications;
    }



    public function getRecords()
    {
        if( file_exists($this->records_history_json) && filemtime($this->records_history_json)+$this->records_expire_after > time() )
        {
            return $this->loadJSON($this->records_history_json);
        }
        $records = $this->listRecords();
        file_put_contents($this->records_history_json, json_encode($records) ) or php_die("Unable to save records".PHP_EOL);
        return $records;
    }




    private function listRecords()
    {
        $cursor = null;
        $posts = [];

        echo "Fetching records".PHP_EOL;

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

                $record_filename = sprintf("%s/record-%02dh%02dm%02ds.json",
                    $date_dir,
                    $date_ary['hour'],
                    $date_ary['minute'],
                    $date_ary['second']
                );

                $this->saveJSON($record_filename, $record) or php_die("Unable to save post".PHP_EOL);

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



    public function genCSVStats($data)
    {
        if( empty($data ) )
            php_die("genCSVStats: nothing to do".PHP_EOL);

        // sort ascending
        usort($data, fn($a, $b) => (date_parse($a['created_at']) > date_parse($b['created_at'])));
        // save stats as csv
        $fp = fopen($this->stats_file_csv, 'w');
        // populate first row with column names
        fputcsv($fp, ["created_at", "id", "object", "follows", "followers (total)", "reach", "rank", "reblogs_count", "replies_count", "favourites_count", "followers_avg", "reach_avg"] );
        foreach($data as $num => $post)
        {
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

        $plotprefix = "Grouchabot Bluesky:";

        $format = "%s -e \"outputtitle='%s'; outputfilename='%s'; filename='%s'; width=%d; height=%d;\" %s";

        $execFollowers = sprintf($format, $gnuplot, "$plotprefix Followers Growth",   "$this->cache_dir/followers.png", $this->stats_file_csv, $width, $height, "data/followers.gnuplot");
        $execReach     = sprintf($format, $gnuplot, "$plotprefix Reach vs Followers", "$this->cache_dir/reach.png",     $this->stats_file_csv, $width, $height, "data/reach.gnuplot");

        exec($execFollowers);
        exec($execReach);
    }



    private function saveJSON($path, $arr)
    {
        // TODO: check if is_writable( dirname($path) );
        return file_put_contents($path, json_encode($arr, JSON_PRETTY_PRINT));
    }


    private function loadJSON($path)
    {
        if(!file_exists($path))
            return [];

        return json_decode(file_get_contents($path), true);
    }


};

