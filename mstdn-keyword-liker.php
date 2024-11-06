<?php

define("CACHE_DIR", "cache" );

require_once("lib/MastodonAgent.php");

$env = @parse_ini_file('.env') or php_die("Unable to parse ini file, forgot to rename '.env.example' to '.env'?".PHP_EOL);

if( !isset( $env["MASTODON_API_TOKEN"] ) || !isset( $env["MASTODON_API_SERVER"] ) )
{
    php_die("Missing credentials for mastodon, check your env file!".PHP_EOL );
}

$calendar = getCSVData("data/saint-objet-bot-2023-11-09.csv");
$quote_data = getQuoteData(time(), $calendar);

//print_r($quote_data);exit;

$app = new SocialPlatform\MastodonAgent($env);

$keywords = [ 'téléchat', 'casimir', 'récréa2', 'albator', 'bibifoc', 'chapichapo', 'cosmocats', 'goldorak', 'actarus', 'papivole', 'gluon', 'terteur', 'leguman', 'chalut', 'Duralo', 'durallo', 'duramou', 'brossedur', 'pubpub', 'MicMac' ];

if( preg_match('/^(?=.{2,140}$)([0-9_\p{L}]*[_\p{L}][0-9_\p{L}]*)$/u', $quote_data[2]) )
    $keywords[] = $quote_data[2];

if(date('D') == 'Sat')
{
    $keywords[] = 'chamedi';
    $keywords[] = 'caturday';
    $keywords[] = 'fedicats';
    $keywords[] = 'catsofpixelfed';
    $keywords[] = 'catsofmastodon';
}


$search_results = $app->search([
    'keywords'=> $keywords,
    'latest' => true // max age is one week, comment this out to crawl the past
]);


$app->favourite( $search_results );
