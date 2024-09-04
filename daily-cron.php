<?php

require_once("lib/BlueSky.php");
require_once("lib/Mastodon.php");

$qotd = get_QOTD("data/saint-objet-bot-2023-11-09.csv");

if( empty( $qotd ) )
{
  php_die("Unable to generate QOTD! Malformed/incomplete CSV file?".PHP_EOL);
}

$env = @parse_ini_file('.env') or php_die("Unable to parse ini file, forgot to rename '.env.example' to '.env'?".PHP_EOL);

if( !isset($argv[1]) )
{
  php_die("Call to script is missing 1 arg (network name)".PHP_EOL );
}

echo "QOTD:\n$qotd".PHP_EOL;

if( $argv[1]=='bluesky' )
{
  if( !isset( $env["BSKY_API_APP_USER"] ) || !isset( $env["BSKY_API_APP_TOKEN"] ) )
  {
    php_die("Missing credentials for bsky, check your env file!".PHP_EOL );
  }
  $bluesky = new SocialPlatform\BlueSkyStatus( $env["BSKY_API_APP_USER"], $env["BSKY_API_APP_TOKEN"] );
  $res = $bluesky->publish( $qotd );
  if( isset( $res['curl_error_code'] ) )
  {
    print_r($res);
    php_die("... Failed to post to bluesky:".PHP_EOL);
  }
  echo "... Posted to bluesky".PHP_EOL;
  exit(0);
}

if( $argv[1]=='mastodon' )
{
  function mstdn_die( $msg, $res ) {
    print_r($res);
    php_die( sprintf("... Failed to post to fediverse (%s)".PHP_EOL, $msg) );
  }

  if( !isset( $env["MASTODON_API_TOKEN"] ) || !isset( $env["MASTODON_API_SERVER"] ) )
  {
    php_die("Missing credentials for mastodon, check your env file!".PHP_EOL );
  }
  $mastodon = new SocialPlatform\MastodonAPI( $env["MASTODON_API_TOKEN"], $env["MASTODON_API_SERVER"] );
  $status_data = [
    'status'     => $qotd,    // populate message
    'visibility' => 'public', // 'private'; // Public , Unlisted, Private, and Direct (default)
    'language'   => 'fr',
  ];

  // publish toot
  $res = $mastodon->postStatus( $status_data );

  // check for indexed error in $res object
  if( isset( $res['curl_error_code'] ) || isset( $res['error'] ) )
  {
    mstdn_die("curl error", $res);
  }

  // check for necessary indexes in $res
  if( !isset($res['id']) || !isset($res['created_at']) || !isset($res['url']) )
  {
    mstdn_die("network error", $res);
  }

  // fetch the published toot
  $activityPubTxt = file_get_contents($res['url'].".json") or mstdn_die("toot not created", $res);
  // convert to json
  $activityPubJson = json_decode($activityPubTxt, true) or mstdn_die("invalid toot response", $res);
  // check the toot url
  if(! isset( $activityPubJson['url'] ) ) mstdn_die("incomplete toot response", $res);
  // must match the URL with the
  if( $activityPubJson['url'] != $res['url'] ) mstdn_die("invalid toot url", $res);


  echo "... Posted to fediverse".PHP_EOL;
  exit(0);
}
