<?php

require_once("lib/BlueSkyAgent.php");

$env = @parse_ini_file('.env') or php_die("Unable to parse ini file, forgot to rename '.env.example' to '.env'?".PHP_EOL);

$calendar = getCSVData("data/saint-objet-bot-2023-11-09.csv");
$quote_data = getQuoteData(time(), $calendar);

$app = new SocialPlatform\BlueskyAgent($env);

$keywords = [ 'téléchat', 'casimir', 'récréa2', 'albator', 'bibifoc', 'chapichapo', 'cosmocats', 'goldorak', 'actarus', 'papivole', 'gluon', 'terteur', 'leguman', 'chalut', 'durallo', 'duramou', 'brossedur', 'pubpub' ];

if( preg_match('/^(?=.{2,140}$)([0-9_\p{L}]*[_ \p{L}][0-9_\p{L}]*)$/u', $quote_data[2]) )
    $keywords[] = $quote_data[2];


if(date('D') == 'Sat')
{
    $keywords[] = 'chamedi';
    $keywords[] = 'caturday';
    $keywords[] = 'catsofbluesky';
}


$search_results = $app->search(['keywords' => $keywords]);

foreach( $search_results as $keyword => $posts_to_like )
{
    echo sprintf("Keyword %s has %d likes to perform".PHP_EOL, $keyword, count($posts_to_like));
}

$app->favourite( $search_results );






