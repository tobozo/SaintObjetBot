<?php

require_once("lib/BlueSky.php");
require_once("lib/Mastodon.php");

$calendar = get_calendar("data/saint-objet-bot-2023-11-09.csv");

$qotd  = get_QOTD($calendar);
$video = get_VOTD($calendar);

//print_r($video);exit;


if( empty( $qotd ) )
{
  php_die("Unable to generate QOTD! Malformed/incomplete CSV file?".PHP_EOL);
}

$env = @parse_ini_file('.env') or php_die("Unable to parse ini file, forgot to rename '.env.example' to '.env'?".PHP_EOL);

//print_r($env);exit;

if( !isset($argv[1]) ) {
  php_die("Call to script is missing 1 arg (network name)".PHP_EOL );
}

echo "QOTD:\n$qotd".PHP_EOL;



if( $argv[1]=='bluesky' ) {
  if( !isset( $env["BSKY_API_APP_USER"] ) || !isset( $env["BSKY_API_APP_TOKEN"] ) ) {
    php_die("Missing credentials for bsky, check your env file!".PHP_EOL );
  }
  $api = new SocialPlatform\BlueskyApi( $env["BSKY_API_APP_USER"], $env["BSKY_API_APP_TOKEN"] );
  $bluesky = new SocialPlatform\BlueSkyStatus( $api );

  if(!empty($video))
  {
    $qotd .= PHP_EOL.PHP_EOL.$video['text'];
  }


  $res = $bluesky->publish( $qotd, $video );

  print_r($res);

  if( isset( $res['curl_error_code'] ) )
  {
    print_r($res);
    php_die("... Failed to post to bluesky:".PHP_EOL);
  }
  echo "... Posted to bluesky".PHP_EOL;
  exit(0);
}



if( $argv[1]=='mastodon' ) {
  if( !isset( $env["MASTODON_API_TOKEN"] ) || !isset( $env["MASTODON_API_SERVER"] ) ) {
    php_die("Missing credentials for mastodon, check your env file!".PHP_EOL );
  }
  $mastodon = new SocialPlatform\MastodonAPI( $env["MASTODON_API_TOKEN"], $env["MASTODON_API_SERVER"] );
  $status_data = [
    'status'     => $qotd,    // populate message
    'visibility' => 'public', // 'private'; // Public , Unlisted, Private, and Direct (default)
    'language'   => 'fr',
  ];

  if(!empty($video))
  {
    $status_data['status'] .= PHP_EOL.PHP_EOL.$video['text'];

    $media_id = $mastodon->uploadMedia('data/segments/'.$video['segment'], $video['title']);

    if(!$media_id !== null )
    {
      $status_data['media_ids[]'] = $media_id;
    }
  }


  $res = $mastodon->postStatus( $status_data );
  if( isset( $res['curl_error_code'] ) || isset( $res['error'] ) )
  {
    print_r($res);
    php_die("... Failed to post to fediverse:".PHP_EOL);
  }
  echo "... Posted to fediverse".PHP_EOL;
  exit(0);
}
