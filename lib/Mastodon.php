<?php
declare(strict_types=1);

namespace SocialPlatform;

require_once("common.php");


class MastodonAPI
{
  private $token;
  private $instance_url;
  private $user_agent = 'PHP 8/GrouchaBot of terteur 1.2';

  public $response_headers = [];
  public $reply;

  public function __construct($token, $instance_url)
  {
    $this->token = $token;
    $this->instance_url = $instance_url;
  }


  // TODO
  public function getFollowersCount() { return 0; }
  public function getCachedFollowers() { return []; }
  public function getFollowers() { return []; }


  public function postStatus($status)
  {
    return $this->callAPI('/api/v1/statuses', 'POST', $status);
  }

  public function uploadMedia($media, $description)
  {

    $file = new \CURLFile($media, mime_content_type($media), basename($media));
    $payload = ['file' => $file, 'description' => $description];

    //$response = $this->apiCall('/api/v2/media', $payload, true);

    $headers = [
      'Authorization: Bearer '.$this->token,
      'Content-Type: multipart/form-data',
      'Accept: application/json'
    ];

    $ch = curl_init($this->instance_url.'/api/v2/media');
    curl_setopt($ch, CURLOPT_USERAGENT, $this->user_agent);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = $response ? json_decode($response, true) : [];

    if ($httpCode === 200) {
        return $json['id'] ?? null;
    }

    if(empty($json) || !isset($json['id']) || ($httpCode!=202 && $httpCode!=206) )
    { // API call failed ?
      if(!empty($json)) print_r($json);
      else print_r($response);
      echo "API call failed ".$httpCode.PHP_EOL;
      return null;
    }

    // json has id, and httpcode is either 202 or 206, query the media api until and url is available
    do
    {
      sleep(1);
      $tmp_res = $this->callAPI("/api/v1/media/".$json['id'], 'GET', []);
    } while( !isset($tmp_res['curl_error']) && empty($tmp_res['url']) );

    if( !empty($tmp_res['url']) )
    {
      return $json['id']; // SUCCESS
    }

    print_r($tmp_res);

    echo "wtf".PHP_EOL;

    return null;
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
    curl_setopt($ch, CURLOPT_USERAGENT, $this->user_agent);
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function(\CurlHandle $ch, string $header) use (&$response_headers) {
      $len = strlen($header);
      $header = explode(':', $header, 2);
      if (count($header) < 2) // ignore invalid headers
          return $len;
      $response_headers[strtolower(trim($header[0]))] = trim($header[1]);
      return $len;
    });
    $this->reply = curl_exec($ch);
    $this->response_headers = $response_headers;

    if (!$this->reply) {
      return json_encode(['ok'=>false, 'curl_error_code' => curl_errno($ch), 'curl_error' => curl_error($ch)]);
    }
    curl_close($ch);
    return json_decode($this->reply, true, 512, JSON_THROW_ON_ERROR);
  }
}

