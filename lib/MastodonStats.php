<?php
declare(strict_types=1);

namespace SocialPlatform;

require_once("Mastodon.php");



class MastodonStats
{
    private $mastodon;
    private $account;

    private $cache_dir;
    private $cache_users_dir;
    private $cache_toots_dir;

    private $toots_history_json;
    private $follows_history_json;

    private $stats_file_csv;

    private $toots = [];

    private $max = 10;

    private $rebloggers = [];
    private $toots_per_day = [];


    public function __construct($mastodon, $cache_dir)
    {
        $this->mastodon = $mastodon;
        $this->cache_dir = $cache_dir;
        $this->cache_users_dir      = $this->cache_dir.'/users';
        $this->cache_toots_dir      = $this->cache_dir.'/toots';
        $this->toots_history_json   = $this->cache_toots_dir.'/stats.json';
        $this->follows_history_json = $this->cache_users_dir.'/notifs.json';
        $this->stats_file_csv       = $this->cache_toots_dir.'/stats.csv';

        foreach([$this->cache_dir, $this->cache_users_dir, $this->cache_toots_dir] as $dir ) {
            if(!is_dir($dir)) {
                mkdir($dir) or php_die("Unable to create cache dir $dir".PHP_EOL);
            }
        }

        $this->account = $mastodon->getAccount();

        if( !is_array($this->account) )
            php_die("Bad API response".PHP_EOL);

        if( !isset($this->account['id']) )
            php_die("Account does not exist".PHP_EOL);
    }


    public function getRebloggers()
    {
        return $this->rebloggers;
    }

    public function getToots()
    {
        return $this->toots;
    }

    public function getTootsPerDay()
    {
        return $this->toots_per_day;
    }


    public function updateToots( $force = false )
    {
        if(file_exists($this->toots_history_json) && !$force )
        {
            $this->toots = $this->loadJSON($this->toots_history_json) or php_die("Invalid json content");

            $dateLast      = $this->toots[0]['created_at'];
            $dateYesterday = date("Y-m-d", time()-86400);

            $lastCacheMod = date(DATE_RFC2822, filemtime($this->toots_history_json));

            // $cache_expired = filemtime($this->toots_history_json)+(86400) < time();

            if( filemtime($this->toots_history_json)+3600 > time() || $dateLast==$dateYesterday )
            {
                echo "Loading toots from cache (last mod=".$lastCacheMod.")".PHP_EOL;
                return;
            } else {
                echo "Date expired: $dateLast != $dateYesterday (last mod=".$lastCacheMod.")".PHP_EOL;
            }
        }

        $this->toots = [];

        printf("Will scan account id %d\n", $this->account['id'] );

        $next_url = '/api/v1/accounts/'.$this->account['id'].'/statuses';

        while( $next_url != "" )
        {
            $toots = $this->mastodon->callAPI($next_url, 'GET', null);

            if( isset( $toots['curl_error'] ) || isset( $toots['error'] ) || isset($toots['json_error']) )
            {
                $this->saveJSON($this->toots_history_json.".err", $this->toots);// or php_die("Unable to save stats file".PHP_EOL);
                php_die("An API request to $next_url failed after collecting ".count($this->toots)." record(s)".PHP_EOL);
            }

            if( empty($toots) )
                break;

            foreach($toots as $toot)
            {
                if( $toot['in_reply_to_id'] !== null || $toot['in_reply_to_account_id'] !== null )
                {
                    echo 'Skipping toot id '.$toot['id'].' (is a reply)'.PHP_EOL;
                    continue;
                }

                $pattern = "/c'est la Sainte?-([^\.]+)/";
                $content = html_entity_decode(strip_tags($toot['content']), ENT_QUOTES | ENT_HTML5, 'UTF-8');

                if( !preg_match( $pattern, $content, $matches ) )
                {
                    echo("Skipping Invalid toot: ".$content.PHP_EOL );
                    continue;
                }

                if( !isset($matches[1]) )
                {
                    echo("Skipping unrelated toot: ".$content.PHP_EOL );
                    continue;
                }

                $date = current(explode("T", $toot['created_at'] ));

                $this->toots[] = [
                    'id'               => $toot['id'],
                    'created_at'       => $date,
                    'reblogs_count'    => $toot['reblogs_count'],
                    'replies_count'    => $toot['replies_count'],
                    'favourites_count' => $toot['favourites_count'],
                    'object'           => $matches[1]
                ];

                echo sprintf("[%s][RT:%2d][RE:%2d][FAV:%2d] %s".PHP_EOL, $date, $toot['reblogs_count'], $toot['replies_count'], $toot['favourites_count'], $matches[1] );
            }

            $next_url = $this->mastodon->getNextPage();
            sleep(5);
        }

        $this->saveJSON($this->toots_history_json, $this->toots) or php_die("Unable to save stats file".PHP_EOL);
    }



    // get all contexts and replies for a given status id
    private function getAllContexts( $toot_id )
    {
        $context_filename = $this->cache_toots_dir."/".$toot_id."_context.json";

        // TODO: set cache expiration to 24h if toot is less than 1 week old
        if( file_exists($context_filename) )
        {
            //if( filemtime($context_filename)+86400*7<time() )
            $contexts = $this->loadJSON($context_filename);

            $now = time();

            $latest = $now - (86400*7); // one week ago


            foreach( $contexts['descendants'] as $num => $context )
            {
                $epoch = strtotime($context['created_at']);

                if( $latest < $epoch )
                    $latest = $epoch;
            }

            if( $now - $latest >= 86400*7 ) // return cache if latest context is more than one week old
            {
                echo "@";
                return $contexts;
            }

        }

        $contexts = ['descendants'=>[], 'ancestors'=>[]];
        $next_url = '/api/v1/statuses/'.$toot_id.'/context';

        while( $next_url != "" )
        {
            $context = $this->mastodon->callAPI($next_url, 'GET', null);

            if( isset( $context['curl_error'] ) || isset( $context['error'] ) || isset($context['json_error']) )
            {
                php_die("An API request to $next_url failed".PHP_EOL);
            }

            if( empty($context) || !isset($context['descendants']) || empty($context['descendants']) )
                break;

            if( isset($context['ancestors']) )
                $contexts['ancestors'] = array_merge($context['ancestors'],   $contexts['ancestors']);

            $contexts['descendants'] = array_merge($context['descendants'], $contexts['descendants']);

            $next_url = $this->mastodon->getNextPage();
            sleep(5);
        }

        // recursively fetch descending replies
        foreach( $contexts['descendants'] as $num => $context )
        {
            if( substr_count($contexts['descendants'][$num]['account']['acct'], '@' ) === 0 )
            {
                $contexts['descendants'][$num]['account']['acct'] .= '@'.$this->mastodon->getInstanceHost(); // "@piaille.fr";
            }


            if( $context['replies_count'] > 0 )
            {
                // init array if necessary
                if(! isset($contexts['descendants'][$num]['replies']) )
                    $contexts['descendants'][$num]['replies'] = [];

                // go recursive and flatten
                $child_contexts = $this->getAllContexts( $context['id'] );
                foreach( $child_contexts['descendants'] as $child_context )
                {
                    if( isset( $child_context['replies'] ) )
                        $contexts['descendants'][$num]['replies'] = array_merge($child_context['replies'], $contexts['descendants'][$num]['replies']);
                    $contexts['descendants'][$num]['replies'][] = $child_context['id'];
                    $this->getAllContexts( $child_context['id'] );
                }
            }

            if( isset( $contexts['descendants'][$num]['replies'] ))
            {
                // de-duplicate if applicable
                $contexts['descendants'][$num]['replies'] = array_unique($contexts['descendants'][$num]['replies']);
            }
        }

        echo "+";
        $this->saveJSON( $context_filename, $contexts );

        return $contexts;
    }



    public function updateReplies()
    {
        $this->toots = $this->loadJSON($this->toots_history_json) or php_die("Invalid json content");

        $total_replies = 0;

        foreach( $this->toots as $num => $toot )
        {
            $total_replies += $toot['replies_count'];
        }

        echo sprintf("Checking/updating %d replies".PHP_EOL, $total_replies );

        foreach( $this->toots as $num => $toot )
        {
            if( $toot['replies_count'] == 0 )
                continue;

            $this->toots[$num]['replies'] = [];

            $contexts = $this->getAllContexts( $toot['id'] );

            echo "> ".$toot['object'].PHP_EOL;

            foreach( $contexts['descendants'] as $context )
            {
                if( substr_count($context['account']['acct'], '@' ) === 0 )
                {
                    $context['account']['acct'] .= '@'.$this->mastodon->getInstanceHost(); // "@piaille.fr";
                }

                $message = html_entity_decode(strip_tags($context['content']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $message = str_replace("@SaintObjetBot@".$this->mastodon->getInstanceHost(), "", $message);
                $message = str_replace("@SaintObjetBot", "", $message);
                $message = trim($message);

                $content = '@'.$context['account']['acct'].': '.$message;

                // remove self from message

                echo "  - ".$content.PHP_EOL;

                $this->toots[$num]['replies'][$context['id']] = [
                    'acct'              => $context['account']['acct'],
                    'content'           => $context['content'],
                    'replies_count'     => $context['replies_count'],
                    'reblogs_count'     => $context['reblogs_count'],
                    'favourites_count'  => $context['favourites_count'],
                    'media_attachments' => $context['media_attachments']
                ];
            }
        }

        echo PHP_EOL;

        $this->saveJSON($this->toots_history_json, $this->toots) or php_die("Unable to save stats file".PHP_EOL);
    }



    public function updateReblogs()
    {
        $this->toots = $this->loadJSON($this->toots_history_json) or php_die("Invalid json content");

        echo sprintf("Checking/updating rebloggers (%d toots)".PHP_EOL, count($this->toots) );

        foreach( $this->toots as $num => $toot )
        {
            if(!isset($toot['rebloggers']))
                $toot['rebloggers'] = [];

            $delay = 5; // seconds between API calls
            $toot_time = strtotime($toot['created_at']);
            $reblogs_filename = $this->cache_toots_dir."/".$toot['id']."_reblogged_by.json";
            $cache_exists = file_exists($reblogs_filename);

            $logstatus = "";

            if($cache_exists && $toot_time+(86400*7) <= time() ) // toot is more than one week old
            {
                $load_cache = true;
                $logstatus = '@';
            } else {
                if( $cache_exists )
                {
                    if( filemtime($reblogs_filename)+(86400) > time() ) // cache is less than one day old
                    {
                        $load_cache = true;
                        $logstatus = '&';
                    } else {
                        $load_cache = (rand()%8==0); // GC probability = 12.5%
                        $logstatus = $load_cache?'C':'G';
                    }
                }
                else
                {
                    $load_cache = false;
                    $logstatus = '+';
                }
            }


            if( $load_cache )
            {
                $reblogs = $this->loadJSON($reblogs_filename);
                if(!$reblogs)
                {
                    echo "."; // empty set from cache: this toot has zero reblogs
                    continue; // no need to parse
                }
                echo $logstatus; // non empty set from cache
                $delay = 0; // no API delay needed
            }
            else
            {
                // query all rebloggers for that toot
                $reblogs = [];
                $next_url = '/api/v1/statuses/'.$toot['id'].'/reblogged_by';

                while( $next_url != "" )
                {
                    $reblogged_by = $this->mastodon->callAPI($next_url, 'GET', null);

                    if( isset( $reblogged_by['curl_error'] ) || isset( $reblogged_by['error'] ) || isset($reblogged_by['json_error']) )
                    {
                        php_die("An API request to $next_url failed after collecting ".count($reblogs)." record(s)".PHP_EOL);
                    }

                    if( empty($reblogged_by) )
                        break;

                    $reblogs = array_merge($reblogged_by, $reblogs);
                    $next_url = $this->mastodon->getNextPage();
                    sleep($delay);
                }

                // NOTE: some of those files will eventually contain an empty array, but it's fine
                $this->saveJSON($reblogs_filename, $reblogs) or php_die("Unable to save reblog json");

                echo $logstatus;

/*
                if( $cache_exists )
                    if( $do_gc )
                        echo "*"; // expired cache with GC hit
                    else
                        echo "+";
                else
                    echo "#";*/

                //echo $do_gc ? "*" : "+";
            }

            foreach($reblogs as $user)
            {
                $user_arr = [
                    'id'              => $user['id'],
                    'username'        => $user['username'],
                    'display_name'    => $user['display_name'],
                    'acct'            => $user['acct'],
                    'bot'             => $user['bot'],
                    'created_at'      => $user['created_at'],
                    'url'             => $user['url'],
                    'followers_count' => $user['followers_count'],
                    'following_count' => $user['following_count'],
                    'statuses_count'  => $user['statuses_count']
                ];
                $this->saveJSON($this->cache_users_dir.'/'.$user['id'].'.json', $user_arr) or php_die("Unable to save user id file".PHP_EOL);
                $this->toots[$num]['rebloggers'][$user['id']] = $user_arr;
            }
            sleep($delay);
        }

        echo PHP_EOL;

        $this->saveJSON($this->toots_history_json, $this->toots) or php_die("Unable to save stats file".PHP_EOL);
    }



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
            $notifications = $this->mastodon->callAPI($next_url, 'GET', null);

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
                    $notification['account']['acct'] .= '@'.$this->mastodon->getInstanceHost();
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

            $next_url = $this->mastodon->getNextPage();
            sleep(5);
        }

        _save:

        ksort($follows_per_day); // older (smaller value) first

        $this->saveJSON($this->follows_history_json, $follows_per_day) or php_die("Unable to save follows per day file".PHP_EOL);
    }



    public function genStats()
    {
        $this->toots = $this->loadJSON($this->toots_history_json) or php_die("Invalid json content in ".$this->toots_history_json);

        if( empty($this->toots ) )
            php_die("genStats: nothing to do".PHP_EOL);

        $this->calcRebloggers();
        $this->calcFollowsPerDay();
        $this->calcRank();
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


    public function printRebloggers( $max = 10 )
    {
        if( empty($this->rebloggers ) )
        {
            echo "printRebloggers: nothing to do".PHP_EOL;
            return;
        }

        echo PHP_EOL."Most Assiduous users (by reblog count):".PHP_EOL;
        usort($this->rebloggers, fn($a, $b) => ($a['reblogs_count'] < $b['reblogs_count']));
        foreach($this->rebloggers as $num => $user)
        {
            if( substr_count($user['acct'], '@' ) === 0 )
            {
                $user['acct'] .= '@'.$this->mastodon->getInstanceHost();
            }

            echo sprintf("[TOTAL REBLOGS:%3d] Followers:%5d, Posts:%5d - @%s".PHP_EOL, $user['reblogs_count'], $user['followers_count'], $user['statuses_count'], $user['acct'] );
            if($num+1>=$max)
                break;
        }
    }


    public function printReach( $max = 10 )
    {
        if( empty($this->toots ) )
        {
            echo "printReach: nothing to do".PHP_EOL;
            return;
        }

        echo PHP_EOL."SaintObjets with most reached accounts (REACH=rebloggers' followers):".PHP_EOL;
        usort($this->toots, fn($a, $b) => ($a['reach'] < $b['reach']));
        foreach($this->toots as $num => $toot)
        {
            echo sprintf("[REACH:%5d] (RT:%2d, RE:%2d, FAV:%2d) [%s] %s".PHP_EOL, $toot['reach'], $toot['reblogs_count'], $toot['replies_count'], $toot['favourites_count'], $toot['created_at'], $toot['object'] );
            if($num+1>=$max)
                break;
        }
    }

    public function printEngagement( $max = 10 )
    {
        if( empty($this->toots ) )
        {
            echo "printEngagement: nothing to do".PHP_EOL;
            return;
        }

        echo PHP_EOL."SaintObjets with most interactions (RANK=RT+RE+FAV):".PHP_EOL;
        usort($this->toots, fn($a, $b) => ($a['rank'] > $b['rank']));
        foreach($this->toots as $num => $toot)
        {
            echo sprintf("[RANK: %d] (RT:%2d, RE:%2d, FAV:%2d) [%s] %s".PHP_EOL, $toot['rank'], $toot['reblogs_count'], $toot['replies_count'], $toot['favourites_count'], $toot['created_at'], $toot['object'] );
            if($num+1>=$max)
                break;
        }
    }


    public function printReblogs( $max = 10 )
    {
        if( empty($this->toots ) )
        {
            echo "printReblogs: nothing to do".PHP_EOL;
            return;
        }

        echo PHP_EOL."SaintObjets with most reblogs:".PHP_EOL;
        usort($this->toots, fn($a, $b) => ($a['reblogs_count'] < $b['reblogs_count']));
        foreach($this->toots as $num => $toot)
        {
            echo sprintf("[REBLOGS:%2d] (RE:%2d, FAV:%2d) [%s] %s".PHP_EOL, $toot['reblogs_count'], $toot['replies_count'], $toot['favourites_count'], $toot['created_at'], $toot['object'] );
            if($num+1>=$max)
                break;
        }

    }


    public function printFavourites( $max = 10 )
    {
        if( empty($this->toots ) )
        {
            echo "printFavourites: nothing to do".PHP_EOL;
            return;
        }

        echo PHP_EOL."SaintObjets with most favourites:".PHP_EOL;
        usort($this->toots, fn($a, $b) => ($a['favourites_count'] < $b['favourites_count']));
        foreach($this->toots as $num => $toot)
        {
            echo sprintf("[FAV:%2d] (RT:%2d, RE:%2d) [%s] %s".PHP_EOL, $toot['favourites_count'], $toot['reblogs_count'], $toot['replies_count'], $toot['created_at'], $toot['object'] );
            if($num+1>=$max)
                break;
        }
    }


    public function printRepliers( $max = 10 )
    {
        if( empty($this->toots ) )
        {
            echo "printRepliers: nothing to do".PHP_EOL;
            return;
        }

        $users = [];

        foreach($this->toots as $num => $toot)
        {
            if( isset($toot['replies']) && count($toot['replies'])>0 )
            {
                foreach($toot['replies'] as $reply)
                {
                    if(!isset($users[$reply['acct']]))
                        $users[$reply['acct']] = 0;

                    $users[$reply['acct']]++;
                }
            }
        }

        arsort($users, SORT_NUMERIC );
        $num = 1;

        echo PHP_EOL."Users with most replies:".PHP_EOL;
        foreach($users as $user => $hits)
        {
            echo sprintf("[TOTAL REPLIES:%3d] %s".PHP_EOL, $hits, $user );
            if($num++>=$max)
               break;
        }
    }


    public function repliesComp($a,$b)
    {
        if(isset($a['replies']))
        {
            foreach($a['replies'] as $reply)
            {
                $a['reblogs_count']    += $reply['reblogs_count'];
                $a['favourites_count'] += $reply['favourites_count'];
            }
        }

        if(isset($b['replies']))
        {
            foreach($b['replies'] as $reply)
            {
                $b['reblogs_count']    += $reply['reblogs_count'];
                $b['favourites_count'] += $reply['favourites_count'];
            }
        }


        return (
              ($a['replies_count']*1000)+$a['reblogs_count']+$a['favourites_count']
            < ($b['replies_count']*1000)+$b['reblogs_count']+$b['favourites_count'])
        ;
    }


    public function printReplies( $max = 10 )
    {
        if( empty($this->toots ) )
        {
            echo "printReplies: nothing to do".PHP_EOL;
            return;
        }

        echo PHP_EOL."SaintObjets with most replies:".PHP_EOL;
        usort($this->toots, [MastodonStats::class, "repliesComp"]);
        foreach($this->toots as $num => $toot)
        {
            echo sprintf("[REPLIES:%2d] (RT:%2d, FAV:%2d) [%s] %s".PHP_EOL, $toot['replies_count'], $toot['reblogs_count'], $toot['favourites_count'], $toot['created_at'], $toot['object'] );

            foreach($toot['replies'] as $reply)
            {
                $message = html_entity_decode(strip_tags($reply['content']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $message = str_replace("@SaintObjetBot@".$this->mastodon->getInstanceHost(), "", $message);
                $message = str_replace("@SaintObjetBot", "", $message);
                $message = trim($message);

                $content = '@'.$reply['acct'].': '.$message;
                echo "    ".$content.PHP_EOL;
            }


            if($num+1>=$max)
                break;
        }
    }




    private function calcRebloggers()
    {
        if( empty($this->toots ) )
            php_die("calcRebloggers: nothing to do".PHP_EOL);

        $this->rebloggers = [];
        // calculate reach/rank and memoize users
        foreach($this->toots as $num => $toot)
        {

            if(isset($toot['replies']))
            {
                foreach($toot['replies'] as $reply)
                {
                    $toot['reblogs_count']    += $reply['reblogs_count'];
                    $toot['favourites_count'] += $reply['favourites_count'];
                }
            }

            $this->toots[$num]['reach'] = 0;
            $this->toots[$num]['rank']  = $toot['favourites_count']+$toot['reblogs_count']+$toot['replies_count'];

            if( isset($toot['rebloggers']) )
            {
                foreach($toot['rebloggers'] as $user_id => $user)
                {
                    if(!isset($this->rebloggers[$user_id]))
                    {
                        $this->rebloggers[$user_id] = $user;
                        $this->rebloggers[$user_id]['reblogs_count'] = 0;
                    }
                    $this->rebloggers[$user_id]['reblogs_count']++; // increment assiduity for user
                    $this->toots[$num]['reach'] += $user['followers_count']; // sum followers for toot
                }
            }
        }
    }


    private function calcFollowsPerDay()
    {
        if( !file_exists($this->follows_history_json))
            php_die("Update followers first!".PHP_EOL);

        $follows_per_day = $this->loadJSON($this->follows_history_json) or php_die("Unable to read follows per day".PHP_EOL);
        $this->toots_per_day = [];

        foreach($this->toots as $toot)
        {
            $count = isset( $follows_per_day[$toot['created_at']] ) ? $follows_per_day[$toot['created_at']] : 0;
            $toot['follows'] = $count;
            $this->toots_per_day[$toot['created_at']] = $toot;
        }
    }

    private function calcRank()
    {
        if( empty($this->toots ) )
            php_die("calcRank: nothing to do".PHP_EOL);

        usort($this->toots, fn($a, $b) => ($a['rank'] < $b['rank']));
        $rank = 1;
        foreach($this->toots as $num => $toot)
        {
            $this->toots[$num]['rank'] = $rank;
            $rank++;
        }
    }


    private function genCSVStats()
    {
        if( empty($this->toots ) )
            php_die("genCSVStats: nothing to do".PHP_EOL);

        // save stats as csv
        usort($this->toots, fn($a, $b) => (date_parse($a['created_at']) > date_parse($b['created_at'])));
        $fp = fopen($this->stats_file_csv, 'w');
        fputcsv($fp, ["created_at", "id", "object", "follows", "followers (total)", "reach", "rank", "reblogs_count", "replies_count", "favourites_count", "followers_avg"] );
        $followers = 0;
        $followers_avg = 0;
        foreach($this->toots as $num => $toot)
        {
            // consolidate with follows count collected from notifications history
            $toot['follows'] = ( !empty($this->toots_per_day) && isset($this->toots_per_day[$toot['created_at']]) && isset($this->toots_per_day[$toot['created_at']]['follows']) )
                ? $this->toots_per_day[$toot['created_at']]['follows']
                : 0
            ;
            $followers += $toot['follows'];

            if($num>0)
            {
                $followers_avg += $followers/$num;
            }

            fputcsv($fp, [ $toot['created_at'], $toot['id'], $toot['object'], $toot['follows'], $followers, $toot['reach'], $toot['rank'], $toot['reblogs_count'], $toot['replies_count'], $toot['favourites_count'], $followers_avg ] );
        }
        fclose($fp);
    }



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

