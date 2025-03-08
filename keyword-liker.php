<?php


die(date('D'));

if( !isset($argv[1]) )
{
  die("Call to script is missing 1 arg (network name)".PHP_EOL );
}

require_once("lib/common.php");

$env = @parse_ini_file('.env') or php_die("Unable to parse ini file, forgot to rename '.env.example' to '.env'?".PHP_EOL);

$calendar = getCSVData("data/saint-objet-bot-2023-11-09.csv");
$quote_data = getQuoteData(time(), $calendar);

$keywords = [
  'téléchat',  'casimir',    'récréa2',   'albator',
  'bibifoc',   'chapichapo', 'cosmocats', 'goldorak',
  'actarus',   'papivole',   'gluon',     'terteur',
  'leguman',   'chalut',     'Duralo',    'durallo',
  'duramou',   'brossedur',  'pubpub',    'MicMac'
];

//if( preg_match('/^(?=.{2,140}$)([0-9_\p{L}]*[_ \p{L}][0-9_\p{L}]*)$/u', $quote_data[2]) )
    // strip accents then turn to camel case
    $keywords[] = lcfirst(preg_replace('/\s+/u', '', ucwords(strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', trim($quote_data[2]))))));;


if(date('D') == 'Sat')
{
    $keywords[] = 'chamedi';
    $keywords[] = 'caturday';
}


// php_logd("TEST1");

switch($argv[1])
{
    case 'bluesky':

      define('DEBUG_LEVEL', $env['BSKY_DEBUG_LEVEL']);

      if(date('D') == 'Sat')
      {
          $keywords[] = 'catsofbluesky';
      }

      require_once("lib/BlueSkyAgent.php");
      $app = new SocialPlatform\BlueskyAgent($env);

      $search_results = $app->search(['keywords' => $keywords]);

      $app->favourite( $search_results );

    break;

    case 'mastodon':

      define('DEBUG_LEVEL', $env['MASTODON_DEBUG_LEVEL']);

      if(date('D') == 'Sat')
      {
          $keywords[] = 'fedicats';
          $keywords[] = 'catsofpixelfed';
          $keywords[] = 'catsofmastodon';
      }
      php_logd(PHP_EOL.PHP_EOL."************* Mastodon Agent ********************".PHP_EOL.PHP_EOL);

      require_once("lib/MastodonAgent.php");
      $app = new SocialPlatform\MastodonAgent($env);

      $search_results = $app->search([
          'keywords'=> $keywords,
          'latest' => true // max age is one week, comment this out to crawl the past
      ]);

      $app->favourite( $search_results );

    break;

    default:
      die("argv[1]: Invalid network name".PHP_EOL);
}

exit(0);
