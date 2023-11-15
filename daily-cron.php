<?php

require_once("lib/BlueSky.php");
require_once("lib/Mastodon.php");

$env = parse_ini_file('.env') or die("Unable to parse ini file, forgot to rename '.env.example' to '.env'?\n");

if( isset($argv[1]) && $argv[1]=='bluesky' && isset( $env["BSKY_API_APP_USER"] ) && isset( $env["BSKY_API_APP_TOKEN"] ) ) {
  $BSKY_API_APP_USER   = $env["BSKY_API_APP_USER"];
  $BSKY_API_APP_TOKEN  = $env["BSKY_API_APP_TOKEN"];
  $bluesky = new SocialPlatform\BlueSkyStatus( $BSKY_API_APP_USER, $BSKY_API_APP_TOKEN );
}

if( isset($argv[1]) && $argv[1]=='mastodon' && isset( $env["MASTODON_API_TOKEN"] ) && isset( $env["MASTODON_API_SERVER"] ) ) {
  $MASTODON_API_TOKEN  = $env["MASTODON_API_TOKEN"];
  $MASTODON_API_SERVER = $env["MASTODON_API_SERVER"];
  $mastodon = new SocialPlatform\MastodonAPI( $MASTODON_API_TOKEN, $MASTODON_API_SERVER );
}

$csv_file = "data/saint-objet-bot-2023-11-09.csv";

$template = "Chalut ! Aujourd'hui, %s %d, c'est la %s-%s.\nBonne fête à %s les %s !";
$dayNames = ['Mitanche', 'Lourdi', 'Pardi', 'Morquidi', 'Jourdi', 'Dendrevi', 'Sordi']; // 0 (for Sunday) through 6 (for Saturday)

$qotd = "";
$today = getdate();

$handle = fopen($csv_file, "r") or die("Unable to open CSV file\n");
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
  die("Unable to generate QOTD! Malformed/incomplete CSV file?\n");
}

echo "QOTD:\n$qotd\n";

if( isset( $bluesky ) ) {
  $bluesky->publish( $qotd );
  echo "... Posted to bluesky\n";
}


if( isset( $mastodon ) ) {

  $status_data = [
    'status'     => $qotd, // populate message
    'visibility' => 'public', // 'private'; // Public , Unlisted, Private, and Direct (default)
    'language'   => 'fr',
  ];

  $res = $mastodon->postStatus( $status_data );
  echo "... Posted to fediverse\n";
  print_r($res);
}
