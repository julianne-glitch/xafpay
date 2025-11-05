<?php
use GuzzleHttp\Client;

$cfg = mtn_cfg();
$amount   = isset($_REQUEST['amount']) ? (int)$_REQUEST['amount'] : 0;
$currency = $_REQUEST['currency'] ?? $cfg['currency'];
$orderId  = $_REQUEST['order_id'] ?? ('ORD-' . time());

if ($amount <= 0) {
  json_out(['error' => 'amount must be > 0'], 400);
}
if (!$cfg['subKey'] || !$cfg['apiUser'] || !$cfg['apiKey']) {
  json_out(['error' => 'MTN sandbox keys missing'], 500);
}

$client = new Client(['base_uri' => $cfg['base'], 'timeout' => 20]);

// 1) Get access token
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

// 2) requestToPay
$referenceId = uuidv4();
$body = [
  'amount'      => (string)$amount,
  'currency'    => $currency,
  'externalId'  => $orderId,
  'payer'       => [
    'partyIdType' => 'MSISDN',
    'partyId'     => $cfg['payerMsisdn'], // sandbox payer
  ],
  'payerMessage' => $cfg['payerMsg'],
  'payeeNote'    => $cfg['payeeNote'],
];

try {
  $resp = $client->post('/collection/v1_0/requesttopay', [
    'headers' => [
      'Authorization'             => "Bearer {$token}",
      'X-Reference-Id'            => $referenceId,
      'X-Target-Environment'      => $cfg['env'],
      'Ocp-Apim-Subscription-Key' => $cfg['subKey'],
      'Content-Type'              => 'application/json',
    ],
    'json' => $body,
  ]);
} catch (Throwable $e) {
  json_out(['error' => 'MTN requestToPay error', 'detail' => $e->getMessage()], 500);
}

json_out([
  'ok'            => true,
  'provider'      => 'mtn',
  'reference_id'  => $referenceId,
  'order_id'      => $orderId,
  'amount'        => $amount,
  'currency'      => $currency,
  'status_url'    => (base_url() ?: 'http://localhost:8000') . "/api/status.php?ref={$referenceId}",
]);
