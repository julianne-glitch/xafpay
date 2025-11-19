<?php
require_once __DIR__ . '/logger.php';
log_event("SESSION_START", file_get_contents("php://input"));

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-API-KEY, X-SIGNATURE, X-TIMESTAMP");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_auth.php'; // merchant auth + HMAC

use GuzzleHttp\Client;

$pdo  = db_connect();

// ------------------------------------
// 1️⃣ Authenticate merchant
// ------------------------------------
$merchant = require __DIR__ . "/_auth.php";

$merchantId = $merchant['id'];

// ------------------------------------
// 2️⃣ Read input
// ------------------------------------
$input = json_decode($GLOBALS['XAF_RAW_BODY'] ?? file_get_contents("php://input"), true);

$orderId   = $input['order_id'] ?? '';
$amount    = $input['amount'] ?? '';
$currency  = $input['currency'] ?? 'XAF';
$phone     = $input['phone'] ?? '';

if (!$orderId || !$amount || !$phone) {
    json_out(['error' => 'Missing required fields (order_id, amount, phone)'], 400);
}

// clean phone
$phone = preg_replace('/\D+/', '', $phone);

// ------------------------------------
// 3️⃣ Detect Carrier (MTN or Orange)
// ------------------------------------
$carrier = null;

if (preg_match('/^(67|68|650|651|652|653|654|680|681|682|683|684)/', $phone)) {
    $carrier = 'MTN';
} elseif (preg_match('/^(69|690|691|692|693|694|695|696|697|698)/', $phone)) {
    $carrier = 'ORANGE';
} else {
    $carrier = "UNKNOWN";
}

if ($carrier === "UNKNOWN") {
    json_out(['error' => 'Unsupported phone number'], 400);
}

// ------------------------------------
// 4️⃣ Check duplicate session (idempotency)
// Prevents WooCommerce from creating multiple sessions
// ------------------------------------
$stmt = $pdo->prepare("SELECT * FROM sessions WHERE merchant_id = :m AND order_id = :o LIMIT 1");
$stmt->execute(['m' => $merchantId, 'o' => $orderId]);
$existing = $stmt->fetch(PDO::FETCH_ASSOC);

if ($existing) {
    log_event("SESSION_IDEMPOTENT", $existing);
    json_out([
        'ok'         => true,
        'session_id' => $existing['id'],
        'amount'     => $existing['amount'],
        'currency'   => $existing['currency'],
        'phone'      => $existing['phone_number'],
        'carrier'    => $existing['carrier_code'],
    ]);
}

// ------------------------------------
// 5️⃣ Insert NEW session
// ------------------------------------
$stmt = $pdo->prepare("
    INSERT INTO sessions (
        merchant_id,
        order_id,
        amount,
        currency,
        phone_number,
        carrier_code,
        status,
        created_at,
        updated_at
    ) VALUES (
        :merchant,
        :order_id,
        :amount,
        :currency,
        :phone,
        :carrier,
        'INIT',
        NOW(),
        NOW()
    )
    RETURNING id
");

$stmt->execute([
    ':merchant'  => $merchantId,
    ':order_id'  => $orderId,
    ':amount'    => $amount,
    ':currency'  => $currency,
    ':phone'     => $phone,
    ':carrier'   => $carrier,
]);

$sessionId = $stmt->fetchColumn();

// ------------------------------------
// 6️⃣ Response
// ------------------------------------
json_out([
    'ok'         => true,
    'session_id' => $sessionId,
    'order_id'   => $orderId,
    'amount'     => $amount,
    'currency'   => $currency,
    'phone'      => $phone,
    'carrier'    => $carrier,
]);
