<?php
// api/tranzak_start.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/tranzak_helpers.php';

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-API-KEY");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $pdo = db_connect();
    $input = json_decode(file_get_contents("php://input"), true);

    if (!$input) {
        throw new Exception("Invalid JSON body");
    }

    $orderId = $input['order_id'] ?? 'ORD-' . time(); // fallback if none provided
    $amount  = (int)($input['amount'] ?? 0);
    $currency = $input['currency'] ?? 'XAF';
    $merchantId = $input['merchant_id'] ?? null;

    if ($amount <= 0) {
        throw new Exception("Invalid amount");
    }

    // 1️⃣ Create a session
    $stmtSess = $pdo->prepare("
        INSERT INTO sessions (order_id, total_amount, status, created_at, updated_at)
        VALUES (:order_id, :total_amount, 'pending', NOW(), NOW())
        RETURNING id
    ");
    $stmtSess->execute([
        'order_id' => $orderId,
        'total_amount' => $amount
    ]);
    $sessionId = $stmtSess->fetchColumn();

    // 2️⃣ Create a payment linked to this session
    $stmtPay = $pdo->prepare("
        INSERT INTO payments (session_id, carrier, amount, status, created_at, updated_at)
        VALUES (:session_id, 'tranzak', :amount, 'pending', NOW(), NOW())
        RETURNING id
    ");
    $stmtPay->execute([
        'session_id' => $sessionId,
        'amount'     => $amount
    ]);
    $paymentId = $stmtPay->fetchColumn();

    // 3️⃣ Create Tranzak payment
    $returnUrl = base_url() . "/checkout/return.php?order_id=" . urlencode($orderId);
    $result = tranzak_create_payment(
        $amount,
        $currency,
        "Payment for order " . $orderId,
        $orderId,
        $returnUrl
    );

    // 4️⃣ Return paymentAuthUrl to frontend
    json_out([
        'ok' => true,
        'payment_url' => $result['paymentAuthUrl'],
        'order_id' => $orderId,
        'session_id' => $sessionId,
        'payment_id' => $paymentId
    ]);
} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => $e->getMessage()], 500);
}
