<?php
require_once __DIR__ . '/logger.php';
log_event("PAY_START", file_get_contents("php://input"));

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_auth.php';     // FULL merchant authentication
use GuzzleHttp\Client;

$pdo   = db_connect();
$input = json_decode(file_get_contents("php://input"), true);

// -----------------------------------------
// 1️⃣ Merchant Authentication (from _auth.php)
// -----------------------------------------
$merchant = require __DIR__ . '/_auth.php';  // returns merchant row
$merchantId = $merchant['id'];
$secretKey  = $merchant['secret_key'];

// -----------------------------------------
// 2️⃣ Validate required field(s)
// -----------------------------------------
$orderId = $input['order_id'] ?? '';

if (!$orderId) {
    json_out(['error' => 'order_id required'], 400);
}

// -----------------------------------------
// 3️⃣ Fetch Session (amount + payer + carrier)
// -----------------------------------------
$stmt = $pdo->prepare("
    SELECT * FROM sessions 
    WHERE order_id = :order AND merchant_id = :m 
    LIMIT 1
");
$stmt->execute([
    'order' => $orderId,
    'm'     => $merchantId
]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$session) {
    json_out(['error' => 'Session not found'], 404);
}

// Enforce DB values
$sessionId = $session['id'];
$amount    = $session['amount'];
$phone     = $session['phone_number'];
$carrier   = $session['carrier_code'];

if ($carrier !== 'MTN') {
    json_out(['error' => 'Only MTN supported'], 400);
}

// -----------------------------------------
// 4️⃣ Idempotency Check
// -----------------------------------------
$stmt = $pdo->prepare("SELECT * FROM payments WHERE session_id = ?");
$stmt->execute([$sessionId]);
$existing = $stmt->fetch(PDO::FETCH_ASSOC);

if ($existing) {
    log_event("PAY_IDEMPOTENT_RETURN", $existing);

    json_out([
        'ok'           => true,
        'idempotent'   => true,
        'provider'     => 'MTN',
        'reference_id' => $existing['reference_id'],
        'order_id'     => $orderId,
        'amount'       => $amount,
        'currency'     => $existing['currency'],
        'status_url'   => base_url()."/api/status.php?ref=".$existing['reference_id']
    ]);
}

// -----------------------------------------
// 5️⃣ MTN Token
// -----------------------------------------
$cfg = mtn_cfg();

try {
    $client = new Client(['base_uri' => $cfg['base'], 'timeout' => 20]);

    $resp = $client->post('/collection/token/', [
        'headers' => [
            'Ocp-Apim-Subscription-Key' => $cfg['subKey'],
            'Authorization'             => 'Basic ' . base64_encode($cfg['apiUser'] . ':' . $cfg['apiKey']),
        ],
    ]);

    $token = json_decode($resp->getBody(), true)['access_token'] ?? null;

    if (!$token) throw new Exception("Token missing");

} catch (Throwable $e) {
    json_out(['error' => 'MTN token error', 'detail' => $e->getMessage()], 500);
}

// -----------------------------------------
// 6️⃣ MTN Request To Pay
// -----------------------------------------
$referenceId = uuidv4();

$body = [
    'amount'       => (string)$amount,
    'currency'     => 'XAF',
    'externalId'   => $orderId,
    'payer'        => [
        'partyIdType' => 'MSISDN',
        'partyId'     => $phone
    ],
    'payerMessage' => "XafPay Checkout",
    'payeeNote'    => "Order $orderId"
];

try {
    $client->post('/collection/v1_0/requesttopay', [
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
// 7️⃣ Insert Payment Row
// -----------------------------------------
$stmt = $pdo->prepare("
    INSERT INTO payments (
        session_id,
        merchant_id,
        order_id,
        phone_number,
        carrier,
        amount,
        currency,
        reference_id,
        status,
        callback_sent,
        created_at,
        updated_at
    ) VALUES (
        :session,
        :merchant,
        :order_id,
        :phone,
        'MTN',
        :amount,
        'XAF',
        :ref,
        'PENDING',
        FALSE,
        NOW(),
        NOW()
    )
");

$stmt->execute([
    ':session'  => $sessionId,
    ':merchant' => $merchantId,
    ':order_id' => $orderId,
    ':phone'    => $phone,
    ':amount'   => $amount,
    ':ref'      => $referenceId
]);

// -----------------------------------------
// 8️⃣ Final Response to React Checkout
// -----------------------------------------
$statusUrl = base_url()."/api/status.php?ref={$referenceId}";

json_out([
    'ok'           => true,
    'provider'     => 'MTN',
    'reference_id' => $referenceId,
    'order_id'     => $orderId,
    'amount'       => $amount,
    'currency'     => 'XAF',
    'status_url'   => $statusUrl
]);
