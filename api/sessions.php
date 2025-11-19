<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_auth.php';

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-API-KEY, X-SIGNATURE, X-TIMESTAMP");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ------------------------------------
// 1️⃣ Read frontend JSON input
// ------------------------------------
$raw = file_get_contents("php://input");
$input = json_decode($raw, true);

if (!$input || !is_array($input)) {
    json_out(['ok' => false, 'error' => 'Invalid JSON'], 400);
}

$amount = $input['amount'] ?? null;
$phone  = $input['phone_number'] ?? null;
$carrier = $input['carrier_code'] ?? null;

if (!$amount || !$phone || !$carrier) {
    json_out(['ok' => false, 'error' => 'Missing required fields'], 400);
}

// Clean phone
$phone = preg_replace('/\D+/', '', $phone);

// Auto-generate order ID
$orderId = "ORD-" . time();

// DB
$pdo = db_connect();

// Default merchant for now
$merchantId = '185b2203-ec89-4d7d-9568-f48dd9311120';

// ------------------------------------
// Insert session into DB
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
    )
    VALUES (
        :merchant,
        :order_id,
        :amount,
        'XAF',
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
    ':phone'    => $phone,
    ':carrier'  => strtoupper($carrier),
]);

$sessionId = $stmt->fetchColumn();

// ------------------------------------
// Successful response
// ------------------------------------
json_out([
    'ok'         => true,
    'session_id' => $sessionId,
    'order_id'   => $orderId,
    'amount'     => $amount,
    'currency'   => 'XAF',
    'phone'      => $phone,
    'carrier'    => $carrier,
]);
