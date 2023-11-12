<?php

declare(strict_types=1);

namespace SocialPlatform;

class MastodonAPI
{
  private $token;
  private $instance_url;
  public $response_headers = [];
  public $reply;

  public function __construct($token, $instance_url)
  {
    $this->token = $token;
    $this->instance_url = $instance_url;
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
    curl_setopt($ch, CURLOPT_USERAGENT, 'PHP 8/GrouchaBot of terteur 1.1');
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
    return json_decode($this->reply, true);
  }
}

