<?php

require_once("lib/BlueSkyAgent.php");

$env = @parse_ini_file('.env') or php_die("Unable to parse ini file, forgot to rename '.env.example' to '.env'?".PHP_EOL);

$app = new SocialPlatform\BlueskyAgent($env);

$keywords = [ 'téléchat', 'casimir', 'récréa2', 'albator', 'bibifoc', 'chapichapo', 'cosmocats', 'goldorak', 'papivole' ];

$search_results = $app->search($keywords);

foreach( $search_results as $keyword => $posts_to_like )
{
    echo sprintf("Keyword %s has %d likes to perform".PHP_EOL, $keyword, count($posts_to_like));
}


foreach( $search_results as $keyword => $posts_to_like )
{
    echo sprintf("Sending likes for keyword %s (%d to go)".PHP_EOL, $keyword, count($posts_to_like));
    foreach($posts_to_like as $post)
    {
        $uriParts = explode('/', $post['uri']);
        $url = sprintf("https://bsky.app/profile/%s/post/%s", $post['author']['handle'], end($uriParts) );
        echo "Liking url ... $url".PHP_EOL;
        $res = $app->likePost( $post, $keyword );
        //exit;
        //sleep(3);
    }
}

/*
$agent = new SocialPlatform\BlueskyAgent( $env["BSKY_API_APP_USER"], $env["BSKY_API_APP_TOKEN"] );

$posts_to_like = $agent->search( $keyword );

foreach($posts_to_like as $post)
{
    $uriParts = explode('/', $post['uri']);
    $url = sprintf("https://bsky.app/profile/%s/post/%s", $post['author']['handle'], end($uriParts) );
    echo "Liking url ... $url".PHP_EOL;
    $res = $agent->likePost( $post, $keyword );
    sleep(3);
}
*/





