<?php
declare(strict_types=1);

namespace SocialPlatform;

require_once("common.php");
require_once("ParseLinkHeader.php");

class MastodonAPI
{
  private $token;
  private $instance_url;
  private $instance_host;
  public array $account;
  public $response_headers = [];
  public $reply;

  public function __construct($token, $instance_url)
  {
    $this->token = $token;
    $this->instance_url = $instance_url;
    $urlParts = parse_url($this->instance_url);
    $this->instance_host = $urlParts['host'];
  }


  public function getInstanceHost()
  {
    return $this->instance_host;
  }

  public function getInstanceURL()
  {
    return $this->instance_url;
  }


  public function postStatus($status)
  {
    return $this->callAPI('/api/v1/statuses', 'POST', $status);
  }

  public function uploadMedia($media)
  {
    return $this->callAPI('/api/v1/media', 'POST', $media);
  }

  public function callAPI($endpoint, $method, $data)
  {
    $headers = [
      'Authorization: Bearer '.$this->token,
      'Content-Type: multipart/form-data',
      'Accept: application/json'
    ];

    $response_headers = [];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $this->instance_url.$endpoint);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_USERAGENT, 'PHP 7/GrouchaBot of terteur 1.2');
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function(\CurlHandle $ch, string $header) use (&$response_headers) {
      $len = strlen($header);
      $header = explode(':', $header, 2);
      if (count($header) < 2) // ignore invalid headers
          return $len;
      $response_headers[strtolower(trim($header[0]))] = trim($header[1]);
      return $len;
    });
    $this->reply = curl_exec($ch);
    $response_headers['status'] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $this->response_headers = $response_headers;

    if (!$this->reply)
    {
      $ret = json_encode(['ok'=>false, 'curl_error_code' => curl_errno($ch), 'curl_error' => curl_error($ch)]);
    }
    else
    {
      try
      {
        $ret = json_decode($this->reply, true, 512, JSON_THROW_ON_ERROR);
      }
      catch( \JsonException $e )
      {
        if(empty( $this->reply ) )
        {
          $ret = [];
        } else
        {
          $errmsg = sprintf("Json decode failed with [%s] query to %s\nQuery Data: %s\nCurl Response Headers: %s\nCurl Response Body: %s".PHP_EOL, $method, $endpoint, print_r($data, true), print_r($this->reply, true), print_r($this->response_headers, true ) );
          $ret = ['ok'=>false, 'json_error' => $errmsg];
        }
      }
    }
    curl_close($ch);

    return $ret;
  }

  // retrieve account information (account id, statuses_count, etc)
  // the operation also validates the token
  // return user info
  public function getAccount(): array
  {
    $resp = $this->callAPI("/api/v1/accounts/verify_credentials", "GET", []);
    // catch curl error or API error
    if( isset( $resp['curl_error'] ) || isset( $resp['error'] ) ) {
      $err = $resp['curl_error']??$resp['error'];
      php_die( "[ERROR] API Error: ".$err.PHP_EOL );
    }

    if( empty( $resp ) ) {
      php_die( "[ERROR] Bad token permissions (empty response)".PHP_EOL);
    }

    if( !isset( $resp['id'] ) ) {
      php_die( "[ERROR] Bad API response".PHP_EOL);
    }

    //print_r($resp);

    return $resp;
  }



  // look for pagination links in API response HTTP headers,
  // and return the "next"
  public function getNextPage()
  {
    $headers = $this->response_headers;

    if( empty($headers) )
      return false;

    if( !isset( $headers['link'] ) )
    {
      //echo("No link header to follow for url $next_url, aborting".PHP_EOL.print_r($headers, 1).PHP_EOL );
      return '';
    }

    $links = ( new \TiagoHillebrandt\ParseLinkHeader( $headers['link'] ) )->toArray();

    if( !is_array($links) || !isset($links['next'] ) || empty($links['next']) || !isset($links['next']['link']) || empty($links['next']['link']) )
    {
      //echo("End of list reached".PHP_EOL);
      return '';
    }

    $next_url = $links['next']['link'];
    $next_url = str_replace($this->instance_url, "", $next_url ); // strip domain from url
    return $next_url;
  }


}

