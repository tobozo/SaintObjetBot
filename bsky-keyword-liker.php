<?php

require_once("lib/BlueSkyAgent.php");

$env = @parse_ini_file('.env') or php_die("Unable to parse ini file, forgot to rename '.env.example' to '.env'?".PHP_EOL);

//echo "blah".PHP_EOL;

$app = new SocialPlatform\BlueskyAgent($env);

$keywords = [ 'téléchat', 'casimir', 'récréa2', 'albator', 'bibifoc', 'chapichapo', 'cosmocats', 'goldorak', 'papivole', 'gluon', 'terteur', 'leguman', 'chalut', 'durallo', 'duramou', 'brossedur', 'pubpub' ];

$search_results = $app->search(['keywords' => $keywords]);

foreach( $search_results as $keyword => $posts_to_like )
{
    echo sprintf("Keyword %s has %d likes to perform".PHP_EOL, $keyword, count($posts_to_like));
}

$app->favourite( $search_results );






