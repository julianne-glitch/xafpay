<?php
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/../config.php';
use GuzzleHttp\Client;

log_event("PAY_START", file_get_contents("php://input"));

// --------------------------------------------------
// CORS
// --------------------------------------------------
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// --------------------------------------------------
// SAFE INPUT READER (FIX FOR JSON.parse ERROR)
// Accepts JSON, FormData, or Query params
// --------------------------------------------------
$raw = file_get_contents("php://input");
$input = json_decode($raw, true);

if (!$input || !is_array($input)) {
    $input = $_POST; // fallback
}

$orderId = $input['order_id'] ?? ($_GET['order_id'] ?? '');
if (!$orderId) {
    json_out(['error' => 'order_id required'], 400);
}

// --------------------------------------------------
// DB CONNECT
// --------------------------------------------------
$pdo = db_connect();

// --------------------------------------------------
// FETCH SESSION (no merchant auth needed)
// --------------------------------------------------
$stmt = $pdo->prepare("
    SELECT *
    FROM sessions
    WHERE order_id = :order
    LIMIT 1
");
$stmt->execute(['order' => $orderId]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$session) {
    json_out(['error' => 'Session not found'], 404);
}

$sessionId = $session['id'];
$amount    = $session['amount'];
$phone     = $session['phone_number'];
$carrier   = $session['carrier_code'];

if ($carrier !== 'MTN') {
    json_out(['error' => 'Only MTN supported currently'], 400);
}

// --------------------------------------------------
// IDEMPOTENCY CHECK
// --------------------------------------------------
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

// --------------------------------------------------
// MTN TOKEN
// --------------------------------------------------
$cfg = mtn_cfg();

try {
    $client = new Client(['base_uri' => $cfg['base'], 'timeout' => 20]);

    $resp = $client->post('/collection/token/', [
        'headers' => [
            'Ocp-Apim-Subscription-Key' => $cfg['subKey'],
            'Authorization'             => 'Basic ' . base64_encode($cfg['apiUser'] . ':' . $cfg['apiKey']),
        ],
    ]);

    $json = json_decode($resp->getBody(), true);
    $token = $json['access_token'] ?? null;

    if (!$token) {
        throw new Exception("Token missing from MTN");
    }
} catch (Throwable $e) {
    json_out([
        'error' => 'MTN token error',
        'detail' => $e->getMessage()
    ], 500);
}

// --------------------------------------------------
// MTN REQUEST TO PAY
// --------------------------------------------------
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
    json_out([
        'error' => 'MTN requestToPay error',
        'detail' => $e->getMessage()
    ], 500);
}

// --------------------------------------------------
// INSERT PAYMENT ROW
// --------------------------------------------------
$stmt = $pdo->prepare("
    INSERT INTO payments (
        session_id,
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
    ':order_id' => $orderId,
    ':phone'    => $phone,
    ':amount'   => $amount,
    ':ref'      => $referenceId
]);

// --------------------------------------------------
// FINAL RESPONSE TO FRONTEND
// --------------------------------------------------
json_out([
    'ok'           => true,
    'provider'     => 'MTN',
    'reference_id' => $referenceId,
    'order_id'     => $orderId,
    'amount'       => $amount,
    'currency'     => 'XAF',
    'status_url'   => base_url() . "/api/status.php?ref={$referenceId}"
]);
