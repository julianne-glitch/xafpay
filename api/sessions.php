<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/tranzak_helpers.php'; // Tranzak API helper
require_once __DIR__ . '/_auth.php';

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-API-KEY, X-SIGNATURE, X-TIMESTAMP");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// --------------------------
// 1️⃣ Read JSON input
// --------------------------
$raw   = file_get_contents("php://input");
$input = json_decode($raw, true);

if (!$input || !is_array($input)) {
    json_out(['ok' => false, 'error' => 'Invalid JSON'], 400);
}

$amount   = (int)($input['amount'] ?? 0);
$currency = $input['currency'] ?? 'XAF';
$phone    = $input['phone_number'] ?? null;
$carrier  = $input['carrier_code'] ?? null;
$orderId  = $input['order_id'] ?? ('ORD-' . time());
$merchantId = $input['merchant_id'] ?? '185b2203-ec89-4d7d-9568-f48dd9311120';

if ($amount <= 0) {
    json_out(['ok' => false, 'error' => 'Invalid amount'], 400);
}
if (!$phone || !$carrier) {
    json_out(['ok' => false, 'error' => 'Missing phone_number or carrier_code'], 400);
}

// normalise phone to digits
$phone = preg_replace('/\D+/', '', $phone);
$carrier = strtoupper($carrier);

// --------------------------
// 2️⃣ Create DB session
// --------------------------
$pdo = db_connect();

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
        :merchant_id,
        :order_id,
        :amount,
        :currency,
        :phone_number,
        :carrier_code,
        'pending',
        NOW(),
        NOW()
    )
    RETURNING id
");

$stmt->execute([
    'merchant_id'   => $merchantId,
    'order_id'      => $orderId,
    'amount'        => $amount,
    'currency'      => $currency,
    'phone_number'  => $phone,
    'carrier_code'  => $carrier,
]);

$sessionId = $stmt->fetchColumn();

// --------------------------
// 3️⃣ Create DB payment record
// --------------------------
$stmtPay = $pdo->prepare("
    INSERT INTO payments (
        session_id,
        merchant_id,
        carrier,
        amount,
        status,
        created_at,
        updated_at
    )
    VALUES (
        :session_id,
        :merchant_id,
        :carrier,
        :amount,
        'pending',
        NOW(),
        NOW()
    )
    RETURNING id
");

$stmtPay->execute([
    'session_id'  => $sessionId,
    'merchant_id' => $merchantId,
    'carrier'     => $carrier,
    'amount'      => $amount,
]);

$paymentId = $stmtPay->fetchColumn();

// --------------------------
// 4️⃣ Call Tranzak and store reference
// --------------------------
$returnUrl = base_url() . "/checkout/return.php?order_id=" . urlencode($orderId);

try {
    $tranzak = tranzak_create_payment(
        $amount,
        $currency,
        "Order $orderId",
        $orderId,
        $returnUrl
    );

    $paymentUrl = $tranzak['paymentAuthUrl'] ?? null;
    if (!$paymentUrl) {
        throw new Exception("Missing paymentAuthUrl in Tranzak response");
    }

    // link payment row with this order ref (for debugging / reconciliation)
    $stmtUpd = $pdo->prepare("
        UPDATE payments
        SET reference_id = :ref
        WHERE id = :id
    ");
    $stmtUpd->execute([
        'ref' => $orderId,
        'id'  => $paymentId,
    ]);

    // --------------------------
    // 5️⃣ Return to frontend
    // --------------------------
    json_out([
        'ok'          => true,
        'session_id'  => $sessionId,
        'payment_id'  => $paymentId,
        'order_id'    => $orderId,
        'amount'      => $amount,
        'currency'    => $currency,
        'phone'       => $phone,
        'carrier'     => $carrier,
        'payment_url' => $paymentUrl,
    ]);

} catch (Throwable $e) {
    error_log("[sessions.php] Tranzak error: " . $e->getMessage());
    json_out([
        'ok'    => false,
        'error' => 'Tranzak API error: ' . $e->getMessage()
    ], 500);
}
