<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(200);
  exit;
}

require_once __DIR__ . '/../config.php';
use GuzzleHttp\Client;

$pdo = db_connect();
$input = json_decode(file_get_contents("php://input"), true);

$orderId  = $input['order_id'] ?? '';
$amount   = $input['amount'] ?? 0;
$currency = $input['currency'] ?? 'XAF';

if (!$orderId) {
  json_out(['error' => 'order_id required'], 400);
}

if ($amount <= 0) {
  json_out(['error' => 'amount required'], 400);
}

// -----------------------------------------
// 1️⃣ Fetch session from DB
// -----------------------------------------
$stmt = $pdo->prepare("SELECT * FROM sessions WHERE order_id = ?");
$stmt->execute([$orderId]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$session) {
  json_out(['error' => 'Session not found'], 404);
}

$phone   = $session['phone_number'];
$carrier = $session['carrier_code'];
$amount  = $session['amount'];   // override amount for safety

// -----------------------------------------
// 2️⃣ MTN PAYMENT ONLY FOR NOW
// -----------------------------------------
if ($carrier !== 'MTN') {
  json_out(['error' => 'Only MTN supported for now'], 400);
}

$cfg = mtn_cfg();
$client = new Client(['base_uri' => $cfg['base'], 'timeout' => 20]);

// -----------------------------------------
// 3️⃣ Get MTN token
// -----------------------------------------
try {
  $resp = $client->post('/collection/token/', [
    'headers' => [
      'Ocp-Apim-Subscription-Key' => $cfg['subKey'],
      'Authorization'             => 'Basic ' . base64_encode($cfg['apiUser'] . ':' . $cfg['apiKey']),
    ],
  ]);

  $token = json_decode($resp->getBody()->getContents(), true)['access_token'] ?? null;

  if (!$token) throw new Exception("No access_token received");

} catch (Throwable $e) {
  json_out(['error' => 'MTN token error', 'details' => $e->getMessage()], 500);
}

// -----------------------------------------
// 4️⃣ MTN RequestToPay
// -----------------------------------------
$referenceId = uuidv4();
$body = [
  'amount'      => (string)$amount,
  'currency'    => $currency,
  'externalId'  => $orderId,
  'payer'       => [
    'partyIdType' => 'MSISDN',
    'partyId'     => $phone
  ],
  'payerMessage' => "XafPay Checkout",
  'payeeNote'    => "XafPay Order $orderId"
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

// -----------------------------------------
// 5️⃣ Insert payment into DB
// -----------------------------------------
try {
  $stmt = $pdo->prepare("
    INSERT INTO payments (session_id, order_id, carrier, phone_number, amount, reference_id, status, created_at)
    VALUES (:session_id, :order_id, 'MTN', :phone, :amount, :ref, 'PENDING', NOW())
  ");

  $stmt->execute([
    ':session_id' => $session['id'],
    ':order_id'   => $orderId,
    ':phone'      => $phone,
    ':amount'     => $amount,
    ':ref'        => $referenceId
  ]);

} catch (Throwable $e) {
  error_log("DB insert error: " . $e->getMessage());
}

// -----------------------------------------
// 6️⃣ Return to frontend (React)
// -----------------------------------------
$statusUrl = base_url() . "/api/status.php?ref={$referenceId}";

json_out([
  'ok'            => true,
  'provider'      => 'MTN',
  'reference_id'  => $referenceId,
  'order_id'      => $orderId,
  'amount'        => $amount,
  'currency'      => $currency,
  'status_url'    => $statusUrl
]);
