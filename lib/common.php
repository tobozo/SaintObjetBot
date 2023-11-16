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

  //echo "QOTD:\n$qotd".PHP_EOL;
  return $qotd;

}
