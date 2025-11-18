<?php
require_once __DIR__ . '/logger.php';
log_event("status.php started", $_GET);


header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config.php';
use GuzzleHttp\Client;

$pdo   = db_connect();
$input = json_decode(file_get_contents("php://input"), true);

// ---------------------------------------------------------
// 🔐 Validate input
// ---------------------------------------------------------
$orderId  = $input['order_id'] ?? '';
$currency = $input['currency'] ?? 'XAF';

if (!$orderId) {
    json_out(['error' => 'order_id required'], 400);
}

// ---------------------------------------------------------
// 1️⃣ Fetch Session (payer info, amount, carrier)
// ---------------------------------------------------------
$stmt = $pdo->prepare("SELECT * FROM sessions WHERE order_id = :order LIMIT 1");
$stmt->execute(['order' => $orderId]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$session) {
    json_out(['error' => 'Session not found'], 404);
}

$sessionId   = $session['id'];
$amount      = $session['amount'];         // enforce stored amount
$phone       = $session['phone_number'];
$carrier     = $session['carrier_code'];

// ---------------------------------------------------------
// 2️⃣ Currently only MTN is supported
// ---------------------------------------------------------
if ($carrier !== 'MTN') {
    json_out(['error' => 'Only MTN supported for now'], 400);
}

$cfg = mtn_cfg();

if (!$cfg['subKey'] || !$cfg['apiUser'] || !$cfg['apiKey']) {
    json_out(['error' => 'MTN configuration missing'], 500);
}

$client = new Client([
    'base_uri' => $cfg['base'],
    'timeout'  => 20
]);

// ---------------------------------------------------------
// 3️⃣ Get MTN access token
// ---------------------------------------------------------
try {
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

// ---------------------------------------------------------
// 4️⃣ MTN RequestToPay
// ---------------------------------------------------------
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

// ---------------------------------------------------------
// 5️⃣ Insert Payment Row
// ---------------------------------------------------------
try {
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
            created_at,
            updated_at
        ) VALUES (
            :session,
            :order_id,
            :phone,
            'MTN',
            :amount,
            :currency,
            :ref,
            'PENDING',
            NOW(),
            NOW()
        )
    ");

    $stmt->execute([
        ':session'  => $sessionId,
        ':order_id' => $orderId,
        ':phone'    => $phone,
        ':amount'   => $amount,
        ':currency' => $currency,
        ':ref'      => $referenceId
    ]);

} catch (Throwable $e) {
    json_out(['error' => 'DB insert error', 'detail' => $e->getMessage()], 500);
}

// ---------------------------------------------------------
// 6️⃣ Return to React
// ---------------------------------------------------------
$statusUrl = base_url() . "/api/status.php?ref={$referenceId}";

json_out([
    'ok'           => true,
    'provider'     => 'MTN',
    'reference_id' => $referenceId,
    'order_id'     => $orderId,
    'amount'       => $amount,
    'currency'     => $currency,
    'status_url'   => $statusUrl
]);
