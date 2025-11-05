<?php
use GuzzleHttp\Client;

$cfg = mtn_cfg();
$ref = $_GET['ref'] ?? '';

if (!$ref) json_out(['error' => 'ref required'], 400);
if (!$cfg['subKey'] || !$cfg['apiUser'] || !$cfg['apiKey']) {
  json_out(['error' => 'MTN sandbox keys missing'], 500);
}

$client = new Client(['base_uri' => $cfg['base'], 'timeout' => 20]);

// token
try {
  $resp = $client->post('/collection/token/', [
    'headers' => [
      'Ocp-Apim-Subscription-Key' => $cfg['subKey'],
      'Authorization'             => 'Basic ' . base64_encode($cfg['apiUser'] . ':' . $cfg['apiKey']),
    ],
  ]);
  $token = json_decode((string)$resp->getBody(), true)['access_token'] ?? null;
  if (!$token) throw new Exception('No access_token');
} catch (Throwable $e) {
  json_out(['error' => 'MTN token error', 'detail' => $e->getMessage()], 500);
}

// GET status
try {
  $resp = $client->get("/collection/v1_0/requesttopay/{$ref}", [
    'headers' => [
      'Authorization'             => "Bearer {$token}",
      'X-Target-Environment'      => $cfg['env'],
      'Ocp-Apim-Subscription-Key' => $cfg['subKey'],
    ],
  ]);
  $data = json_decode((string)$resp->getBody(), true);
} catch (Throwable $e) {
  json_out(['error' => 'MTN status error', 'detail' => $e->getMessage()], 500);
}

json_out(['ok' => true, 'data' => $data]);
