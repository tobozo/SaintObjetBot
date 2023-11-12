<?php

namespace SocialPlatform;


/**
* Class for interacting with the Bluesky API/AT protocol
* Inspired by https://github.com/cjrasmussen/BlueskyApi
* Changes by @tobozo: removed the id lookup in the constructor
*
*/
class BlueskyApi
{
  private ?string $accountDid = null;
  private ?string $apiKey = null;
  private string $apiUri;

  public function __construct(?string $handle = null, ?string $app_password = null, string $api_uri = 'https://bsky.social/xrpc/')
  {
    $this->apiUri = $api_uri;

    if (($handle) && ($app_password)) {

      $args = [
        'identifier' => $handle,
        'password'   => $app_password,
      ];

      $data = $this->request('POST', 'com.atproto.server.createSession', $args);

      $this->accountDid = $data->did;

      $this->apiKey = $data->accessJwt;
    }
  }

  /**
  * Get the current account DID
  *
  * @return string
  */
  public function getAccountDid(): ?string
  {
    return $this->accountDid;
  }

  /**
  * Set the account DID for future requests
  *
  * @param string|null $account_did
  * @return void
  */
  public function setAccountDid(?string $account_did): void
  {
    $this->accountDid = $account_did;
  }

  /**
  * Set the API key for future requests
  *
  * @param string|null $api_key
  * @return void
  */
  public function setApiKey(?string $api_key): void
  {
    $this->apiKey = $api_key;
  }

  /**
  * Return whether an API key has been set
  *
  * @return bool
  */
  public function hasApiKey(): bool
  {
    return $this->apiKey !== null;
  }

  /**
  * Make a request to the Bluesky API
  *
  * @param string $type
  * @param string $request
  * @param array $args
  * @param string|null $body
  * @param string|null $content_type
  * @return mixed|object
  * @throws \JsonException
  */
  public function request(string $type, string $request, array $args = [], ?string $body = null, string $content_type = null)
  {
    $url = $this->apiUri . $request;

    if (($type === 'GET') && (count($args))) {
      $url .= '?' . http_build_query($args);
    } elseif (($type === 'POST') && (!$content_type)) {
      $content_type = 'application/json';
    }

    $headers = [];
    if ($this->apiKey) {
      $headers[] = 'Authorization: Bearer ' . $this->apiKey;
    }

    if ($content_type) {
      $headers[] = 'Content-Type: ' . $content_type;

      if (($content_type === 'application/json') && (count($args))) {
        $body = json_encode($args, JSON_THROW_ON_ERROR);
        $args = [];
      }
    }

    $c = curl_init();
    curl_setopt($c, CURLOPT_URL, $url);

    if (count($headers)) {
      curl_setopt($c, CURLOPT_HTTPHEADER, $headers);
    }

    switch ($type) {
      case 'POST':
        curl_setopt($c, CURLOPT_POST, 1);
        break;
      case 'GET':
        curl_setopt($c, CURLOPT_HTTPGET, 1);
        break;
      default:
        curl_setopt($c, CURLOPT_CUSTOMREQUEST, $type);
    }

    if ($body) {
      curl_setopt($c, CURLOPT_POSTFIELDS, $body);
    } elseif (($type !== 'GET') && (count($args))) {
      curl_setopt($c, CURLOPT_POSTFIELDS, json_encode($args, JSON_THROW_ON_ERROR));
    }

    curl_setopt($c, CURLOPT_HEADER, 0);
    curl_setopt($c, CURLOPT_VERBOSE, 0);
    curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($c, CURLOPT_SSL_VERIFYPEER, 1);
    curl_setopt($c, CURLOPT_USERAGENT, 'PHP 8/GrouchaBot of terteur 1.1');

    $data = curl_exec($c);
    curl_close($c);

    return json_decode($data, false, 512, JSON_THROW_ON_ERROR);
  }
}



/*
 *
 * Class for posting text or rich text status,
 * also generates thumb card view if available.
 * Author: @tobozo
 *
 **/

class BlueSkyStatus
{


  public $lang = "en";
  private $session = NULL;
  private $api = NULL;


  public function __construct($username, $pass)
  {
    $this->api = new BlueskyApi($username, $pass);
    if( ! $this->api->getAccountDid() ) die("Unable to get account id\n");
  }


  public function getEmbedCard( $url)
  {
    # the required fields for every embed card
    $card = [
      "uri" => $url,
      "title" => "",
      "description" => "",
    ];

    # fetch the HTML
    $resp = file_get_contents( $url ) or die("Unable to fetch $url\n");

    libxml_use_internal_errors(true); // don't spam the console with XML warnings
    $doc = new \DOMDocument();
    $doc->loadHTML($resp);
    $selector = new \DOMXPath($doc);
    $title_tags_arr = $selector->query('//meta[@property="og:title"]');
    $desc_tags_arr  = $selector->query('//meta[@property="og:description"]');
    $img_url_arr    = $selector->query('//meta[@property="og:image"]');
    // loop through all found items
    foreach($title_tags_arr as $node) {
      $title_tag = $node->getAttribute('content');
    }
    foreach($desc_tags_arr as $node) {
      $description_tag = $node->getAttribute('content');
    }
    foreach($img_url_arr as $node) {
      $img_url = $node->getAttribute('content');
    }
    # parse out the "og:title" and "og:description" HTML meta tags
    if( $title_tag ) {
      $card['title'] = $title_tag;
    }
    if( $description_tag ) {
      $card['description'] = $description_tag;
    }
    if( $img_url ) {
      if(!strstr($img_url, '://') ) {
        $img_url = $url.$img_url;
      }
      $img_path = "/tmp/".md5($url).'.image';

      if(! file_exists( $img_path ) ) {
        $blobImage = file_get_contents( $img_url ) or die("Unable to fetch og:image at url $url\n");
        file_put_contents($img_path, $blobImage ) or die("Unable to save og:image at url $url\n");
      } else {
        $blobImage = file_get_contents( $img_path ) or die("Unable to fetch og:image at path $img_path\n");
      }
      // get image mimetype
      $img_mime_type = image_type_to_mime_type(exif_imagetype($img_path));
      $response = $this->api->request('POST', 'com.atproto.repo.uploadBlob', [], $blobImage, $img_mime_type);
      if( !isset($response->blob) ) die("No blob in response\n");
      // echo "uploadBlob response for $img_mime_type: ".print_r($response, true)."\n";
      $card["thumb"] = $response->blob;
    }

    return [
        '$type' => "app.bsky.embed.external",
        'external' => $card
    ];
  }



  public function publish( $text )
  {
    //Get the URL from the text
    preg_match_all('#\bhttps?://[^\s()<>]+(?:\([\w\d]+\)|([^[:punct:]\s]|/))#', $text, $matches);

    if (!empty($matches[0][0]) ) {

      $url  = $matches[0][0];
      $text = trim(preg_replace('/#\w+\s*/', '', $text)); // remove hashtags, trim

      $args = [
        "repo"       => $this->api->getAccountDid(),
        "collection" => "app.bsky.feed.post",
        "record"     => [
          '$type'      => "app.bsky.feed.post",
          "langs"      => [$this->lang],
          "createdAt"  => date("c"),
          "text"       => $text,
          "facets"     => [[
            "index"      => [
              "byteStart"  =>  strpos($text,'https:'),
              "byteEnd"    =>  (strpos($text,'https:')+strlen($url))
            ],
            "features"   =>  [[
              "uri"        =>  $url,
              '$type'      =>  "app.bsky.richtext.facet#link"
            ]]
          ]]
        ]
      ];

      $embed = $this->getEmbedCard($url);

      if( $embed ) {
        $args['record']['embed'] = $embed;
      }

    } else {
      // We won't try to do anything clever with this

      $args = [
        "repo"       => $this->api->getAccountDid(),
        "collection" => "app.bsky.feed.post",
        "record"     => [
          '$type'      => "app.bsky.feed.post",
          "createdAt"  => date("c"), "text" => $text
        ]
      ];

    }

    // post the message
    return $this->api->request('POST', 'com.atproto.repo.createRecord', $args);
  }

}


