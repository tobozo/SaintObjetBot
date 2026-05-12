<?php

function php_die($msg, $status=1)
{
  echo $msg;
  exit($status);
}


function check_followers( $api )
{
  $followers_count = $api->getFollowersCount();

  $cached_followers = $api->getCachedFollowers();
  $followers = $api->getFollowers();

  $subscribers_in  = array_diff( $cached_followers, $followers );
  $subscribers_out = array_diff( $followers, $cached_followers );

  // TODO: save followers count
  // TODO: save subscribers_in / subscribers_out
}

function sanitizeApiHost(string $api_host): ?string
{
  $output = parse_url($api_host, PHP_URL_HOST);

  if ($output === null) {
    $api_host = 'https://' . $api_host;
    $output = parse_url($api_host, PHP_URL_HOST);
  }

  if ($output === false) {
    return null;
  }

  return $output;
}


function get_VOTD($calendar)
{
  $videos = get_videos();

  $qotd = "";
  $today = getdate();

  foreach($calendar as $num => $data)
  {
    if( $data[0] == $today['mon'] && $data[1] == $today['mday'] )
    {
      if(isset($data[5]) && !empty($data[5])) {
        $video_path = 'data/segments/'.$data[5];
        if(!file_exists($video_path)) {
          return null;
        }
        foreach($videos as $item)
        {
          if( $item['segment'] == $data['5'] )
          {
            $video = $item;
            break;
          }
        }
        if(!isset($video)) {
          return null;
        }

        // check if youtube_id is valid on youtube oembed api
        $oembed_url = sprintf("https://www.youtube.com/oembed?format=json&url=https://www.youtube.com/watch?v=%s", $video['youtube_id']);
        $oembed = file_get_contents($oembed_url);
        if(empty($oembed))
        {
          echo "youtube oEmbed query failed $oembed_url".PHP_EOL;
          return null; // oembed query failed
        }
        $oembed_json = json_decode($oembed, true);
        if(empty($oembed_json))
        {
          echo "youtube oEmbed query to $oembed_url returned invalid json : $oembed_json".PHP_EOL;
          return null; // video can't be embedded
        }

        // get video width/height
        if( exec('/usr/bin/ffprobe -v error -select_streams v -show_entries stream=width,height -of csv=p=0:s=x "'.$video_path.'"', $out) )
        {
          if(empty($out))
          {
            echo "ffprobe error at $video_path".PHP_EOL;
            return null;
          }
          list($width, $height) = explode('x', $out[0]);
        }

        return [
          'text'  => sprintf("🎬 https://www.youtube.com/watch?v=%s", $video['youtube_id']),
          'title' => sprintf("Extrait de l'épisode %d de la saison %d de Téléchat", $video['episode'], $video['season']),
          'width' => (int)$width,
          'height' => (int)$height,
          'path'  => $video_path,
        ]+$video;
      }
    }
  }

  return null;
}



function get_videos()
{
  $videos_json = file_get_contents("data/yt_arr.json") or php_die("Unable to read videos_json");
  $videos = json_decode($videos_json, true);
  return $videos;
}


function get_calendar( $csv_file )
{
  $handle = fopen($csv_file, "r") or php_die("Unable to open CSV file".PHP_EOL);
  $ret = [];
  while (($data = fgetcsv($handle, 1000, ",")) !== FALSE)
  {
    $ret[] = $data;
  }
  return $ret;
}


function get_QOTD( $calendar )
{
  $template = "Chalut ! Aujourd'hui, %s %d, c'est la %s-%s.\nBonne fête à %s les %s !";
  $dayNames = ['Mitanche', 'Lourdi', 'Pardi', 'Morquidi', 'Jourdi', 'Dendrevi', 'Sordi']; // 0 (for Sunday) through 6 (for Saturday)

  $qotd = "";
  $today = getdate();

  //$calendar = get_calendar( $csv_file );

  foreach($calendar as $num => $data)
  {
    if( $data[0] == $today['mon'] && $data[1] == $today['mday'] )
    {
      $qotd = sprintf( $template, $dayNames[$today['wday']], $today['mday'], $data[4]=='f'?'Sainte':'Saint', ucwords( $data[2] ), $data[4]=='f'?'toutes':'tous', $data[3] );
    }
  }

  if( empty( $qotd ) )
  {
    php_die("Unable to generate QOTD! Malformed/incomplete CSV file?".PHP_EOL);
  }

  //echo "QOTD:\n$qotd".PHP_EOL;
  return $qotd;
}
