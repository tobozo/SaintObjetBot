<?php

function php_die($msg, $status=1)
{
  echo $msg;
  exit($status);
}


function get_QOTD( $csv_file )
{
  //$csv_file = "data/saint-objet-bot-2023-11-09.csv";

  $template = "Chalut ! Aujourd'hui, %s %d, c'est la %s-%s.\nBonne fête à %s les %s !";
  $dayNames = ['Mitanche', 'Lourdi', 'Pardi', 'Morquidi', 'Jourdi', 'Dendrevi', 'Sordi']; // 0 (for Sunday) through 6 (for Saturday)

  $qotd = "";
  $today = getdate();
  $calendar = getCSVData( $csv_file );

  $data = getQuoteData(time(), $calendar);
  $qotd = sprintf( $template, $dayNames[$today['wday']], $today['mday'], $data[4]=='f'?'Sainte':'Saint', ucwords( $data[2] ), $data[4]=='f'?'toutes':'tous', $data[3] );

  if( empty( $qotd ) )
  {
    php_die("Unable to generate QOTD! Malformed/incomplete CSV file?".PHP_EOL);
  }
  //echo "QOTD:\n$qotd".PHP_EOL;
  return $qotd;
}


function getCSVData( $csv_file )
{
  $handle = fopen($csv_file, "r") or php_die("Unable to open CSV file".PHP_EOL);
  $calendar = [];
  while (($data = fgetcsv($handle, 1000, ",")) !== FALSE)
  {
    $calendar[] = $data;
  }
  fclose($handle);
  return $calendar;
}


function getQuoteData($date, $ary)
{
  $date_ary = getdate($date) or php_die("Invalid date".PHP_EOL);
  // iterating isn't optimal but spares the cruftiness of a leap year algorithm
  foreach($ary as $data)
  {
    if( $data[0] == $date_ary['mon'] && $data[1] == $date_ary['mday'] )
    {
      return $data;
    }
  }
  php_die("Date not found".PHP_EOL);
}
