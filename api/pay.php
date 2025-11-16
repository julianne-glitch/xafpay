<?php
// Allow requests from your React dev server
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Respond quickly to preflight checks
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(200);
  exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_auth.php';
use GuzzleHttp\Client;

$pdo = db_connect();
$merchant = require_merchant($pdo);   // ✅ Auth check

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

// ✅ Connect to DB early
$pdo = db_connect();
var_dump("Connected DB OK");


// 1️⃣ Get access token
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

// 2️⃣ requestToPay
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

// ✅ 3️⃣ Log payment into DB
try {
  $stmt = $pdo->prepare("
    INSERT INTO payments (id, session_id, carrier, amount, status, reference_id, created_at)
    VALUES (:id, NULL, 'MTN', :amount, 'PENDING', :ref, NOW())
  ");
  $stmt->execute([
    'id' => uuidv4(),
    'amount' => $amount,
    'ref' => $referenceId
  ]);
} catch (Throwable $e) {
  error_log("DB insert error: " . $e->getMessage());
}

// 4️⃣ Respond to client
json_out([
  'ok'            => true,
  'provider'      => 'mtn',
  'reference_id'  => $referenceId,
  'order_id'      => $orderId,
  'amount'        => $amount,
  'currency'      => $currency,
  'status_url'    => (base_url() ?: 'http://localhost/xafpay-backend') . "/api/status.php?ref={$referenceId}",
]);
