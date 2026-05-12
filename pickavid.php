<?php

require_once("lib/BlueSky.php");
require_once("lib/Mastodon.php");

$all_videos = get_videos();

$calendar = get_calendar("data/saint-objet-bot-2023-11-09.csv");

$videos_left = $all_videos;

$days_since_last_vid = 0;

// first pass to populate obvious matches
foreach($calendar as $num => $ary)
{
  if( $num == 0 )
    continue;

  // video already set in csv, verify
  if( isset($ary[5]) )
  {
    foreach($videos_left as $vnum => $video)
    {
        if( $ary[5] == $video['segment'] )
        {
          echo "-";
          unset($videos_left[$vnum]);
          continue(2);
        }
    }
  }

  // video not set in csv, see if there's any match by object name
  foreach($videos_left as $vnum => $video)
  {
    // found a match, attach video segment
    if( $ary[2] == strtolower($video['saintobjet']) )
    {
      $calendar[$num][5] = $video['segment'];
      echo sprintf("+ %02d/%02d : %s => %s".PHP_EOL, $ary[0], $ary[1], $ary[2], $calendar[$num][5]);
      unset($videos_left[$num]);
      break;
    }
  }

}

echo PHP_EOL;

// populate leftover videos in empty slots
foreach($calendar as $num => $ary)
{
  if( $num == 0 ) // header row
    continue;

  if( count($videos_left) == 0 ) // no more videos to assign
  {
    $days_since_last_vid+= count($calendar)-($num+1);
    break;
  }


  if( isset($ary[5])) // video already assigned
  {
    $days_since_last_vid = 0;
    continue;
  }

  // assign a video every 2..2.4 days
  $since = rand(2,2.4);

  if( $days_since_last_vid>=$since )
  {
    $rand_key = array_rand( $videos_left );
    $calendar[$num][2] = "!!".$videos_left[$rand_key]['saintobjet'];
    $calendar[$num][4] = $videos_left[$rand_key]['genre'];
    $calendar[$num][5] = $videos_left[$rand_key]['segment'];
    unset($videos_left[$rand_key]);
    echo sprintf(" %02d/%02d : %s => %s".PHP_EOL, $ary[0], $ary[1], $ary[2], $calendar[$num][5]);
    $days_since_last_vid = 0;
    continue;
  }

  $days_since_last_vid++;
}

//print_r($calendar);

echo sprintf(PHP_EOL."%d entries left, %d days since last vid".PHP_EOL, count($videos_left), $days_since_last_vid);
