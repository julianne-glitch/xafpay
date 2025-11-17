<?php

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

require_once __DIR__ . '/../config.php';

$payload = json_decode(file_get_contents("php://input"), true);

if (!$payload) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Extract MTN fields
$status  = strtoupper($payload['status'] ?? '');
$orderId = $payload['externalId'] ?? '';
$ftId    = $payload['financialTransactionId'] ?? null;

if (!$orderId || !$status) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields']);
    exit;
}

$pdo = db_connect();

// ------------------------------------------------------
// 1️⃣ Update payments table
// ------------------------------------------------------
$stmt = $pdo->prepare("
    UPDATE payments
    SET status = :status, mtn_ft_id = :ftid, updated_at = NOW()
    WHERE order_id = :order_id
");
$stmt->execute([
    'status'   => $status,
    'ftid'     => $ftId,
    'order_id' => $orderId
]);

// ------------------------------------------------------
// 2️⃣ Update sessions table
// ------------------------------------------------------
$stmt = $pdo->prepare("
    UPDATE sessions
    SET status = :status, updated_at = NOW()
    WHERE order_id = :order_id
");
$stmt->execute([
    'status'   => $status,
    'order_id' => $orderId
]);

// ------------------------------------------------------
// 3️⃣ Notify WooCommerce
// ------------------------------------------------------
$secret = hmac_secret();
$signature = hash_hmac('sha256', $orderId . strtolower($status), $secret);

$wcCallback =
    base_url() .
    "/wc-api/xafpay_callback?order_id={$orderId}&status=" .
    strtolower($status) . "&signature={$signature}";

file_get_contents($wcCallback);

// ------------------------------------------------------
// 4️⃣ Return OK to MTN
// ------------------------------------------------------
echo json_encode([
    'ok'       => true,
    'received' => $payload
]);
