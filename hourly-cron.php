<?php

if( !isset($argv[1]) )
{
  die("Call to script is missing 1 arg (network name)".PHP_EOL );
}

//require_once("lib/common.php");

$env = @parse_ini_file('.env') or php_die("Unable to parse ini file, forgot to rename '.env.example' to '.env'?".PHP_EOL);

switch($argv[1])
{

    case 'bluesky':

      define('DEBUG_LEVEL', $env['BSKY_DEBUG_LEVEL']);

      // echo PHP_EOL.PHP_EOL."************* Bluesky Hourly Agent ********************".PHP_EOL.PHP_EOL;

      require_once("lib/BlueSkyAgent.php");
      $app = new SocialPlatform\BlueskyAgent($env);
      $me = $app->api->getAccountDid();

      // get last 100 likes
      $resp = $app->api->request('GET', 'app.bsky.feed.getActorLikes', [ 'actor' => $me, 'limit' => 100, ]);
      // store cid's for the last 100 likes
      foreach($resp['feed'] as $itemnum => $item)
      {
          $ignored_cids[] = $item['post']['cid'];
      }

      // get last notifications
      $notifications = $app->api->fetchNotifications('cache/bluesky/notifications', 100, 50);

      // notification reasons to include, any item found there will be auto-liked
      $reasons = ['quote', 'reply', 'mention'];

      $posts_to_like = [];

      foreach($reasons as $notif_key)
      {
          if( !array_key_exists($notif_key, $notifications))
              continue; // api response has no notifications for this key

          foreach($notifications[$notif_key] as $item)
          {
              if( isset($item['author']) && $item['author']['did'] == $me )
                  continue; // ignore items from self

              if( in_array($item['cid'], $ignored_cids) )
                  continue; // ignore existing and/or duplicate entries

              //echo sprintf("%s needs like : %s / %s".PHP_EOL, $notif_key, $item['cid'], $item['uri']);

              // TODO: check message mood in $item['record']['text']

              $posts_to_like[] = [ 'cid' => $item['cid'], 'uri' => $item['uri'] ];
          }
      }

      foreach( $posts_to_like as $post )
      {
        $record = [
            'subject'   => [ 'uri' => $post['uri'], 'cid' => $post['cid'] ],
            'createdAt' => date("c"),
            '$type'     => 'app.bsky.feed.like'
        ];

        $args = [
            'collection' => 'app.bsky.feed.like',
            'repo'       => $app->api->getAccountDid(),
            'record'     => $record
        ];

        $res = $app->api->request('POST', 'com.atproto.repo.createRecord', $args );

        if($res && $res['validationStatus']=='valid')
            php_logln("[Bluesky] Auto-liked post ".$post['uri']);
      }

    break;

    case 'mastodon':

      //echo PHP_EOL.PHP_EOL."************* Mastodon Hourly Agent ********************".PHP_EOL.PHP_EOL;
      define('DEBUG_LEVEL', $env['MASTODON_DEBUG_LEVEL']);

      require_once("lib/MastodonAgent.php");
      $app = new SocialPlatform\MastodonAgent($env);

      php_logd("Fetching favourites".PHP_EOL);

      $me = $app->getAccount()['id'];

      $ignored_ids = $app->getFavourites(1);

      php_logd("Loaded ".count($ignored_ids)." last favs".PHP_EOL);

      $posts_to_like = [];

      php_logd("Fetching last 10 mentions".PHP_EOL);

      $mentions = $app->mastodon->callAPI( '/api/v1/notifications?types[]=mention&limit=10', 'GET', [] );

      php_logd("Processing last ".count($mentions)." mentions".PHP_EOL);

      foreach( $mentions as $mention )
      {
          if( in_array( $mention['status']['id'], $ignored_ids ))
              continue;

          if( $mention['account']['id'] == $me )
              continue;

          $posts_to_like[] = $mention['status']['id'];
      }

      if( count($posts_to_like) == 0 )
      {
        php_logd("No autolike to process".PHP_EOL);
        exit(0);
      }

      php_logd("Autoliking ".count($posts_to_like)." posts".PHP_EOL);

      foreach( $posts_to_like as $post )
      {
          $fav = $app->mastodon->callAPI( '/api/v1/statuses/'.$post.'/favourite', 'POST', []);
          if(isset($fav['favourited']) && $fav['favourited']=='true')
              php_logln("[Mastodon] Auto-liked post #$post");
      }

    break;

    default:
      php_die("argv[1]: Invalid network name".PHP_EOL);
}
