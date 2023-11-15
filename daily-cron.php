<?php

require_once("lib/BlueSky.php");
require_once("lib/Mastodon.php");

$env = parse_ini_file('.env') or php_die("Unable to parse ini file, forgot to rename '.env.example' to '.env'?".PHP_EOL);

if( !isset($argv[1]) ) {
  php_die("Call to script is missing 1 arg (network name)".PHP_EOL );
}

if( $argv[1]=='bluesky' ) {
  if( !isset( $env["BSKY_API_APP_USER"] ) || !isset( $env["BSKY_API_APP_TOKEN"] ) ) {
    php_die("Missing credentials for bsky, check your env file!".PHP_EOL );
  }
  $bluesky = new SocialPlatform\BlueSkyStatus( $env["BSKY_API_APP_USER"], $env["BSKY_API_APP_TOKEN"] );
}

if( $argv[1]=='mastodon' ) {
  if( !isset( $env["MASTODON_API_TOKEN"] ) || !isset( $env["MASTODON_API_SERVER"] ) ) {
    php_die("Missing credentials for mastodon, check your env file!".PHP_EOL );
  }
  $mastodon = new SocialPlatform\MastodonAPI( $env["MASTODON_API_TOKEN"], $env["MASTODON_API_SERVER"] );
}

$csv_file = "data/saint-objet-bot-2023-11-09.csv";

$template = "Chalut ! Aujourd'hui, %s %d, c'est la %s-%s.\nBonne fête à %s les %s !";
$dayNames = ['Mitanche', 'Lourdi', 'Pardi', 'Morquidi', 'Jourdi', 'Dendrevi', 'Sordi']; // 0 (for Sunday) through 6 (for Saturday)

$qotd = "";
$today = getdate();

$handle = fopen($csv_file, "r") or php_die("Unable to open CSV file".PHP_EOL);
while (($data = fgetcsv($handle, 1000, ",")) !== FALSE)
{
  if( $data[0] == $today['mon'] && $data[1] == $today['mday'] )
  {
    $qotd = sprintf( $template, $dayNames[$today['wday']], $today['mday'], $data[4]=='f'?'Sainte':'Saint', ucwords( $data[2] ), $data[4]=='f'?'toutes':'tous', $data[3] );
  }
}
fclose($handle);

if( empty( $qotd ) )
{
  php_die("Unable to generate QOTD! Malformed/incomplete CSV file?".PHP_EOL);
}

echo "QOTD:\n$qotd".PHP_EOL;

if( isset( $bluesky ) )
{
  $res = $bluesky->publish( $qotd );
  if( isset( $res['curl_error_code'] ) )
  {
    echo "... Failed to post to fediverse:".PHP_EOL;
    print_r($res);
    echo PHP_EOL;
    exit(1);
  }
  echo "... Posted to bluesky".PHP_EOL;
}


if( isset( $mastodon ) )
{
  $status_data = [
    'status'     => $qotd, // populate message
    'visibility' => 'public', // 'private'; // Public , Unlisted, Private, and Direct (default)
    'language'   => 'fr',
  ];

  $res = $mastodon->postStatus( $status_data );
  if( isset( $res['curl_error_code'] ) || isset( $res['error'] ) )
  {
    echo "... Failed to post to fediverse:".PHP_EOL;
    print_r($res);
    echo PHP_EOL;
    exit(1);
  }
  echo "... Posted to fediverse".PHP_EOL;
  print_r($res);
}

