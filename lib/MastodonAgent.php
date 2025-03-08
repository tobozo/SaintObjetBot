<?php

declare(strict_types=1);

namespace SocialPlatform;

require_once("Mastodon.php");

class MastodonAgent
{
    public $mastodon;
    //private $logger;

    private $ignoredAccounts = [];
    private $ignoredStatuses = [];
    private $favourites      = [];
    private $mentions        = [];

    public  $ignoredAccountsCount = 0;
    public  $ignoredStatusesCount = 0;
    public  $ignoredSelfCount     = 0;

    private $cache_dir        = 'cache/mastodon';
    private $search_cache_dir = 'cache/mastodon/search';
    private $ignoredStatusesFile;
    private $ignoredAccountsFile;
    private $favouritesFile;
    private $followersFile;
    private $mentionsFile;

    private $me;

    public function __construct($env)
    {
        //$this->logger   = new \LogManager\FileLogger( ENV_DIR );
        $this->mastodon = new MastodonAPI($env["MASTODON_API_TOKEN"], $env["MASTODON_API_SERVER"]);

        if( ! is_dir($this->cache_dir)) mkdir($this->cache_dir, 0777, true) or php_die('Unable to create cache dir'.PHP_EOL);
        if( ! is_dir($this->search_cache_dir)) mkdir($this->search_cache_dir, 0777, true) or php_die('Unable to create search cache dir'.PHP_EOL);

        $this->ignoredStatusesFile = $this->cache_dir.'/ignoredStatuses.json';
        $this->ignoredAccountsFile = $this->cache_dir.'/ignoredAccounts.json';
        $this->favouritesFile      = $this->cache_dir.'/favourites.json';
        $this->followersFile       = $this->cache_dir.'/followers.json';
        $this->mentionsFile        = $this->cache_dir.'/mentions.json';

        $this->me = $this->mastodon->getAccount();

    }

    public function getAccount()
    {
        return $this->me;
    }

    public function querySince( $linkRelNext, $cache_file, $what=NULL, $max_age=86400, $method="GET", $args=[], $max_results=0 )
    {
        $ret = [];
        $cached = [];

        if( file_exists($cache_file) && filemtime($cache_file)+$max_age>time() )
        {
            $cached = json_decode(file_get_contents($cache_file), true);

            if(!empty($cached))
            {
                // TODO: iterate $cached to find the highest id
                $since_id = $cached[0]['id'];
                // $linkRelNext .= '&since_id='.$since_id;
                $linkRelNext .= '&min_id='.$since_id;
            }
        }

        php_logd("Querying $linkRelNext ".PHP_EOL);

        $ret = $this->mastodon->consumeQuery( $linkRelNext, $what, $method, $args, $max_results );

        if( !empty($cached) )
        {
            foreach($cached as $item)
                if( $item['id'] != $since_id )
                    $ret[] = $item;
        }

        //echo PHP_EOL;

        file_put_contents($cache_file, json_encode($ret/*, JSON_PRETTY_PRINT*/));

        return $ret;
    }


    public function buildUrl( $args )
    {
        $url = "/api/v2/search?q=".$args['q'];
        $keys = ['type', 'offset', 'limit', 'resolve', 'max_id', 'min_id', 'following', 'account_id', 'exclude_unreviewed'];

        foreach( $keys as $key )
        {
            if( isset($args[$key]) )
                $url .= '&'.$key.'='.$args[$key];
        }
        return $url;
    }


    public function convertSecToTime($sec)
    {
        $date1 = new \DateTime("@0"); //starting seconds
        $date2 = new \DateTime("@$sec"); // ending seconds
        $interval =  date_diff($date1, $date2); //the time difference
        return $interval->format('%y Years, %m months, %d days, %h hours, %i minutes and %s seconds'); // convert into Years, Months, Days, Hours, Minutes and Seconds
    }




    public function getMentions()
    {
        $mentions_query_url = '/api/v1/notifications?types[]=mention&limit=80';
        $res = $this->querySince($mentions_query_url, $this->mentionsFile);

        $mentions = [];
        foreach($res as $idx => $item)
        {
            if( !array_key_exists('status', $item))
            {
                if( isset($item['id']) && isset($item['url']) && isset($item['visibility']) )
                    $status = $item;
                else
                {
                    echo "Can't process this (no status):";
                    print_r($item);
                    continue;
                }
                //php_die("DOH $idx");
            }
            else
            {
                $status = $item['status'];
            }
            // if( $status['visibility'] != 'public' )
            //     continue;
            $mentions[] = [
                'id'  => $status['id'],
                'url' => $status['url'],
                'visibility' => $status['visibility']
            ];
        }

        file_put_contents($this->mentionsFile, json_encode($mentions, JSON_PRETTY_PRINT));

        return $mentions;
    }


    public function getFavourites($cache_max_age=86400)
    {
        $favourites = $this->querySince('/api/v1/favourites?limit=40', $this->favouritesFile, NULL, $cache_max_age);
        foreach( $favourites as $fav )
        {
            $this->favourites[] = $fav['id'];
            if(!in_array($fav['account']['acct'], $this->ignoredAccounts))
                $this->ignoredAccounts[] = $fav['account']['acct'];
        }
        return $this->favourites;
    }




    public function search( $arr )
    {
        if(!isset($arr['keywords']) || empty($arr['keywords']) || !is_array($arr['keywords']) )
            php_die("No keywords :(".PHP_EOL);

        foreach($arr['keywords'] as $keyword)
            if( ! preg_match('/^(?=.{2,140}$)([0-9_\p{L}]*[_ \p{L}][0-9_\p{L}]*)$/u', $keyword) )
                php_die("Invalid keyword: '$keyword'".PHP_EOL);

        if(!isset($arr['maxCount']))
            $arr['maxCount'] = 100;
        else
            $arr['maxCount'] = abs(filter_var($arr['maxCount'], FILTER_SANITIZE_NUMBER_INT));

        if(!isset($arr['maxAge']))
            $arr['maxAge'] = 86400*30*12*5; // a bit less than 5 years
        else
            $arr['maxAge'] = abs(filter_var($arr['maxAge'], FILTER_SANITIZE_NUMBER_INT));

        $statuses    = []; // array indexed by keyword, statuses
        $search_args = []; // array indexed by keyword, search args
        $keywords    = $arr['keywords'];

        $followers_query_url = '/api/v1/accounts/'.$this->me['id'].'/followers?limit=80';
        $followers = $this->querySince($followers_query_url, $this->followersFile);
        foreach($followers as $follower)
        {
            if(!in_array($follower['acct'], $this->ignoredAccounts))
                $this->ignoredAccounts[] = $follower['acct'];
        }


        $this->getFavourites();

        // $this->favourites = [];
        // $favourites = $this->querySince('/api/v1/favourites?limit=40', $this->favouritesFile);
        // foreach( $favourites as $fav )
        // {
        //     $this->favourites[] = $fav['id'];
        //     if(!in_array($fav['account']['acct'], $this->ignoredAccounts))
        //         $this->ignoredAccounts[] = $fav['account']['acct'];
        // }

        $this->mentions = $this->getMentions();

        if( file_exists($this->ignoredStatusesFile) )
        {
            $ignoredStatuses = json_decode( file_get_contents($this->ignoredStatusesFile), true) or php_die("Unable to read ignoredStatusesFile");
            $this->ignoredStatuses = array_unique(array_merge($this->ignoredStatuses, $ignoredStatuses));
        }

        if( file_exists($this->ignoredAccountsFile) )
        {
            $ignoredAccounts = json_decode( file_get_contents($this->ignoredAccountsFile), true) or php_die("Unable to read ignoredAccountsFile");
            $this->ignoredAccounts = array_unique(array_merge($this->ignoredAccounts, $ignoredAccounts));
        }


        php_logd(sprintf("Loaded entries: %d favourites, %d ignoredAccounts, %d ignoredStatuses, %d mentions".PHP_EOL."Using maxCount=%d, maxAge=%d (%s)".PHP_EOL,
            count($this->favourites),
            count($this->ignoredAccounts),
            count($this->ignoredStatuses),
            count($this->mentions),
            $arr['maxCount'],
            $arr['maxAge'],
            $this->convertSecToTime($arr['maxAge'])
        ));

        $totalStatuses = 0;
        $search_args = $arr;

        foreach($keywords as $num => $keyword)
        {
            if( $num == 0 )
            {
                //echo "No time limit for first keyword '$keyword'";
                unset($search_args['latest']);
            }
            else
                $search_args = $arr;

            $search_args['keyword'] = $keyword;

            //$keyword_results = $this->searchKeyword( $search_args );
            $keyword_results = $this->searchHashtag( $search_args );

            file_put_contents($this->search_cache_dir."/hashtag-$keyword.json", json_encode( $keyword_results, JSON_PRETTY_PRINT ) ) or php_die("Unable to cache search results".PHP_EOL);

            $statuses[$keyword]    = $keyword_results['statuses'];
            $search_args[$keyword] = $keyword_results['search_args'];

            $ids = [];
            foreach($statuses[$keyword] as $status)
            {
                if(empty($status) || !isset($status['id']))
                {
                    echo "Indigest status".PHP_EOL;
                    print_r($status);
                    php_die();
                }
                $ids[] = $status['id'];
            }

            if( count($ids)>0 )
                $search_args[$keyword]['max_id'] = min($ids); // save max id for next search

            $totalStatuses += count($statuses[$keyword]);
        }

        php_logd(sprintf("Collected %d statuses (%d accounts, %d shared statuses, %d self posts".PHP_EOL,
            $totalStatuses,
            $this->ignoredAccountsCount,
            $this->ignoredStatusesCount,
            $this->ignoredSelfCount
        ));

        return [ 'statuses' => $statuses, 'search_args' => $search_args ];
    }


    private function loadKeywordFile( $keyword )
    {
        $keyword_file = $this->search_cache_dir."/$keyword.json";
        if( file_exists($keyword_file) )
        {
            $meta_content = file_get_contents($keyword_file) or php_die("Unable to read $keyword_file".PHP_EOL);
            $meta = json_decode($meta_content, true) or php_die("Unable to decode $keyword_file".PHP_EOL);
            return $meta;
        }
        return [];
    }


    private function saveKeywordFile( string $keyword, array $arr )
    {
        $keyword_file = $this->search_cache_dir."/$keyword.json";
        file_put_contents($keyword_file, json_encode($arr, JSON_PRETTY_PRINT)) or php_die("Unable to save keyword file $keyword_file".PHP_EOL);
    }




    public function searchHashtag( array $arr ) : array
    {
        foreach( ['keyword', 'maxCount', 'maxAge'] as $key )
            if( !isset($arr[$key]) ) php_die("Missing key: $key".PHP_EOL);

        $keyword   = $arr['keyword'];
        $max_count = $arr['maxCount'];
        $max_age   = $arr['maxAge'];

        php_logd("Searching #$keyword ");
        $args = [
            'q'      => urlencode($keyword),
            'type'   => 'hashtags',
            'offset' => 0,
            'limit'  => 40,
        ];

        $meta = $this->loadKeywordFile($keyword);

        if(isset($arr['latest']))
        {
            $max_age = 86400*7; // one week
            if( isset($meta['min_id']) )
                $args['min_id'] = $meta['min_id'];
            else if( !empty( $this->favourites ) )
                $args['min_id'] = max($this->favourites);
        }
        else if( isset($meta['max_id']) )
            $args['max_id'] = $meta['max_id'];

        $statuses = [];
        $oldest_age = time()-$max_age;

        $linkRelNext = "/api/v1/timelines/tag/$keyword";

        while( $linkRelNext != "" )
        {
            $toots = $this->mastodon->callAPI( $linkRelNext, 'GET', []);

            if( isset( $toots['curl_error'] ) || isset( $toots['error'] ) || isset($toots['json_error']) )
                php_die("An API request to $linkRelNext failed after collecting ".count($statuses)." record(s)".PHP_EOL);

            if( empty($toots) )
                break;

            foreach($toots as $status)
            {
                // paginate using offset
                $args['offset']++;

                if( $status['language'] != 'fr' )
                {
                    php_logd("x");
                    continue;
                }

                $status_age = strtotime($status['created_at']);

                if( $status_age < $oldest_age )
                {
                    //echo "O".PHP_EOL;
                    php_logd("\n");
                    return [ 'statuses' => $statuses, 'search_args' => $args ];;
                }

                if(count($statuses)>=$max_count)
                {
                    //echo "M".PHP_EOL;
                    php_logd("\n");
                    return [ 'statuses' => $statuses, 'search_args' => $args ];;
                }

                if( $status['account']['id'] == $this->me['id'] )
                {
                    php_logd("i");
                    $this->ignoredSelfCount++;
                    continue; // ignore self
                }

                if( in_array($status['id'], $this->favourites ) )
                {
                    php_logd("*");
                    continue;
                }

                if(in_array($status['account']['acct'], $this->ignoredAccounts ) )
                {
                    php_logd("a");
                    $this->ignoredAccountsCount++;
                    continue; // ignore posts from followers and previous ignore list
                }
                if( in_array($status['id'], $this->ignoredStatuses ) )
                {
                    php_logd("d");
                    $this->ignoredStatusesCount++;
                    continue; // ignore statuses from other keyword searches
                }

                php_logd(".");
                //echo sprintf("[%s] %s : %s".PHP_EOL, $keyword, $status['account']['acct'], html_entity_decode(strip_tags($status['content']), ENT_QUOTES | ENT_HTML5, 'UTF-8') );
                $statuses[] = $status;
                $this->ignoredStatuses[] = $status['id'];
                $this->ignoredAccounts[] = $status['account']['acct'];
            }

            $linkRelNext = $this->mastodon->getNextPage();
        }

        php_logd("\n");
        return [ 'statuses' => $statuses, 'search_args' => $args ];
    }






    public function searchKeyword( array $arr ) : array
    {
        foreach( ['keyword', 'maxCount', 'maxAge'] as $key )
            if( !isset($arr[$key]) ) php_die("Missing key: $key".PHP_EOL);

        $keyword   = $arr['keyword'];
        $max_count = $arr['maxCount'];
        $max_age   = $arr['maxAge'];

        echo PHP_EOL."Searching $keyword ";
        $args = [
            'q'      => urlencode($keyword),
            'type'   => 'statuses',
            'offset' => 0,
            'limit'  => 40,
        ];

        $meta = $this->loadKeywordFile($keyword);

        if(isset($arr['latest']))
        {
            $max_age = 86400*7; // one week

            if( isset($meta['min_id']) )
                $args['min_id'] = $meta['min_id'];
            else if( !empty( $this->favourites ) )
                $args['min_id'] = max($this->favourites);
        }
        else if( isset($meta['max_id']) )
            $args['max_id'] = $meta['max_id'];

        $statuses = [];
        $oldest_age = time()-$max_age;

        $linkRelNext = $this->buildUrl( $args ); // "/api/v2/search?q=arduino&type=statuses&limit=40";

        while( $linkRelNext != "" )
        {
            $toots = $this->mastodon->callAPI( $linkRelNext, 'GET', []);

            if( isset( $toots['curl_error'] ) || isset( $toots['error'] ) || isset($toots['json_error']) )
                php_die("An API request to $linkRelNext failed after collecting ".count($this->toots)." record(s)".PHP_EOL);

            if( empty($toots) || !isset($toots['statuses']) || count($toots['statuses'])==0 )
                break;

            foreach($toots['statuses'] as $status)
            {
                // paginate using offset
                $args['offset']++;
                $status_age = strtotime($status['created_at']);

                if( $status['language'] != 'fr' )
                {
                    echo "x";
                    continue;
                }

                if( $status_age < $oldest_age )
                {
                    echo "O".PHP_EOL;
                    return [ 'statuses' => $statuses, 'search_args' => $args ];;
                }

                if(count($statuses)>=$max_count)
                {
                    echo "M".PHP_EOL;
                    return [ 'statuses' => $statuses, 'search_args' => $args ];;
                }

                if( $status['account']['id'] == $this->me['id'] )
                {
                    echo "i";
                    $this->ignoredSelfCount++;
                    continue; // ignore self
                }

                if( in_array($status['id'], $this->favourites ) )
                {
                    echo "*";
                    continue;
                }

                if(in_array($status['account']['acct'], $this->ignoredAccounts ) )
                {
                    echo "a";
                    $this->ignoredAccountsCount++;
                    continue; // ignore posts from followers and previous ignore list
                }
                if( in_array($status['id'], $this->ignoredStatuses ) )
                {
                    echo "d";
                    $this->ignoredStatusesCount++;
                    continue; // ignore statuses from other keyword searches
                }

                echo ".";
                //echo sprintf("[%s] %s : %s".PHP_EOL, $keyword, $status['account']['acct'], html_entity_decode(strip_tags($status['content']), ENT_QUOTES | ENT_HTML5, 'UTF-8') );
                $statuses[] = $status;
                $this->ignoredStatuses[] = $status['id'];
                $this->ignoredAccounts[] = $status['account']['acct'];
            }

            $linkRelNext = $this->buildUrl( $args );
        }

        echo PHP_EOL;
        return [ 'statuses' => $statuses, 'search_args' => $args ];
    }


    public function favourite( $search_results )
    {
        $statuses    = $search_results['statuses'];
        $search_args = $search_results['search_args'];

        if( empty($statuses) || empty($search_args) )
            php_die("Nothing to do".PHP_EOL);

        if( file_exists($this->ignoredStatusesFile) ) // reload ignore status list
            $this->ignoredStatuses = json_decode( file_get_contents($this->ignoredStatusesFile), true) or php_die("Unable to read ignoredStatusesFile");

        if( file_exists($this->ignoredAccountsFile) ) // merge ignore account list
        {
            $ignoredAccounts = json_decode( file_get_contents($this->ignoredAccountsFile), true) or php_die("Unable to read ignoredAccountsFile");
            $this->ignoredAccounts = array_unique(array_merge($this->ignoredAccounts, $ignoredAccounts));
        }

        foreach($statuses as $keyword => $arr)
        {
            $ids = [];
            $meta = $this->loadKeywordFile($keyword);

            foreach($arr as $status)
            {
                if( $status['visibility'] == 'direct' ) // don't spam private messages with favs
                     continue;

                $ids[] = $status['id'];
                $fav = $this->mastodon->callAPI( '/api/v1/statuses/'.$status['id'].'/favourite', 'POST', []);

                if(!$fav || isset($fav['error']) || isset($fav['json_error']) || isset($fav['curl_error']))
                {
                    print_r($fav);
                    php_die("[FATAL] FAILED to favourite ".$status['url'].PHP_EOL);
                }

                if(isset($fav['favourited']) && $fav['favourited']=='true')
                {
                    if(!in_array($status['id'], $this->ignoredStatuses))
                    {
                        $this->ignoredStatuses[] = $status['id'];
                        file_put_contents($this->ignoredStatusesFile, json_encode($this->ignoredStatuses, JSON_PRETTY_PRINT));
                    }

                    if(!in_array($status['account']['acct'], $this->ignoredAccounts) && $status['account']['id'] != $this->me['id'] )
                    {
                        $this->ignoredAccounts[] = $status['account']['acct'];
                        file_put_contents($this->ignoredAccountsFile, json_encode($this->ignoredAccounts, JSON_PRETTY_PRINT));
                    }

                    if(isset($search_args[$keyword]['latest']))
                        $meta['min_id'] = max($ids);
                    else
                        $meta['max_id'] = min($ids);

                    if( isset($search_args[$keyword]))
                    {
                        $meta['offset'] = $search_args[$keyword]['offset'];
                        $meta['limit']  = $search_args[$keyword]['limit'];
                    }

                    $this->saveKeywordFile($keyword, $meta);

                    echo "Favourited ".$status['url'].PHP_EOL;
                }
                else
                {
                    print_r($fav);
                    echo "[WARNING] FAILED to favourite ".$status['url'].PHP_EOL;
                }
            }
        }

        file_put_contents($this->ignoredStatusesFile, json_encode($this->ignoredStatuses, JSON_PRETTY_PRINT));
        file_put_contents($this->ignoredAccountsFile, json_encode($this->ignoredAccounts, JSON_PRETTY_PRINT));

        php_logd(sprintf("Processing %d mentions".PHP_EOL, count($this->mentions) ));

        foreach($this->mentions as $status)
        {
            //$status = $mention['status'];
            if( $status['visibility'] != 'public' )
                continue;
            if( !in_array($status['id'], $this->favourites) && !in_array($status['id'], $this->ignoredStatuses) )
            {
                $fav = $this->mastodon->callAPI( '/api/v1/statuses/'.$status['id'].'/favourite', 'POST', []);

                if(!$fav || isset($fav['error']) || isset($fav['json_error']) || isset($fav['curl_error']))
                {
                    print_r($fav);
                    php_die("[FATAL] FAILED to favourite ".$status['url'].PHP_EOL);
                }

                if(isset($fav['favourited']) && $fav['favourited']=='true')
                {
                    echo "[Mastodon] Favourited Mention ".$status['url'].PHP_EOL;
                    $this->ignoredStatuses[] = $status['id'];
                }
            }
        }

        file_put_contents($this->ignoredStatusesFile, json_encode($this->ignoredStatuses, JSON_PRETTY_PRINT));

    }

};
