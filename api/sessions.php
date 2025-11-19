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
require_once __DIR__ . '/_auth.php';  // contains optional_hmac_auth()

$pdo  = db_connect();

// ------------------------------------
// 1️⃣ Authenticate merchant (optional)
// ------------------------------------
$auth = optional_hmac_auth($pdo);
$merchant = $auth['merchant'];  // may be null

// Default merchant fallback
$merchantId = $merchant['id'] ?? '185b2203-ec89-4d7d-9568-f48dd9311120';


// ------------------------------------
// 2️⃣ Read input safely
// ------------------------------------
$raw = file_get_contents("php://input");
$input = json_decode($raw, true);

if (!$input) $input = $_POST;

$orderId  = $input['order_id'] ?? '';
$amount   = $input['amount'] ?? '';
$currency = $input['currency'] ?? 'XAF';
$phone    = $input['phone'] ?? '';

if (!$orderId || !$amount || !$phone) {
    json_out(['error' => 'Missing required fields (order_id, amount, phone)'], 400);
}

$phone = preg_replace('/\D+/', '', $phone);

// ------------------------------------
// 3️⃣ Detect carrier CORRECTLY
// ------------------------------------
if (preg_match('/^(67|650|651|652|653|654|680|681|682|683|684)/', $phone)) {
    $carrier = 'MTN';
} elseif (preg_match('/^(69|690|691|692|693|694|695|696|697|698)/', $phone)) {
    $carrier = 'ORANGE';
} else {
    $carrier = 'UNKNOWN';
}

if ($carrier === 'UNKNOWN') {
    json_out(['error' => 'Unsupported phone number'], 400);
}

// ------------------------------------
// 4️⃣ Idempotency check
// ------------------------------------
$stmt = $pdo->prepare("
    SELECT * FROM sessions 
    WHERE merchant_id = :m AND order_id = :o 
    LIMIT 1
");
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
// 5️⃣ Insert new session
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
    ':merchant' => $merchantId,
    ':order_id' => $orderId,
    ':amount'   => $amount,
    ':currency' => $currency,
    ':phone'    => $phone,
    ':carrier'  => $carrier,
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
