<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/tranzak_helpers.php';

// ----------------------
// CORS
// ----------------------
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header("Content-Type: application/json");

// ----------------------
// Read Input
// ----------------------
$raw   = file_get_contents("php://input");
$input = json_decode($raw, true) ?: $_POST;

log_event("pay.php input", $input);

$amount   = floatval($input["amount"] ?? 0);
$phone    = $input["phone"] ?? "";

if (!$amount || !$phone) {
    json_out(["ok" => false, "error" => "Missing amount or phone"], 400);
}

// ----------------------
// Phone normalization → E164
// ----------------------
$phone = preg_replace("/\D/", "", $phone); // remove non-digits

if (strlen($phone) === 9) {
    $phoneE164 = "237$phone";
} else {
    $phoneE164 = $phone;
}

log_event("pay.php phoneE164", $phoneE164);

// ----------------------
// Order ID
// ----------------------
$orderId = "ORD" . time() . rand(1000, 9999);

// ----------------------
// DB: Create session and payment
// ----------------------
try {
    $pdo = db_connect();

    $stmt = $pdo->prepare("
        INSERT INTO sessions (amount, currency, phone_number, carrier_code, order_id, status)
        VALUES (:amount, 'XAF', :phone, 'MTN', :oid, 'pending')
        RETURNING id
    ");
    $stmt->execute([
        ':amount' => $amount,
        ':phone'  => $phone,
        ':oid'    => $orderId,
    ]);
    $sessionId = $stmt->fetchColumn();

    $stmt2 = $pdo->prepare("
        INSERT INTO payments (session_id, amount, carrier, status, reference_id)
        VALUES (:sid, :amount, 'MTN', 'pending', :ref)
        RETURNING id
    ");
    $stmt2->execute([
        ':sid'    => $sessionId,
        ':amount' => $amount,
        ':ref'    => $orderId
    ]);
    $paymentId = $stmt2->fetchColumn();

} catch (Throwable $e) {
    json_out(["ok" => false, "error" => "DB Error: " . $e->getMessage()], 500);
}

// ----------------------
// Tranzak Payload (CORRECT)
// ----------------------
$returnUrl = base_url() . "/api/callback.php?order_id=$orderId";

$payload = [
    "amount"             => $amount,
    "currencyCode"       => "XAF",
    "description"        => "Order $orderId",
    "mchTransactionRef"  => $orderId,
    "mobileWalletNumber" => $phoneE164,
    "returnUrl"          => $returnUrl
];

log_event("pay.php tranzak payload", $payload);

// ----------------------
// Call Tranzak
// ----------------------
$resp = tranzak_initiate_payment($payload);

log_event("pay.php tranzak_response", $resp);

if (!$resp || empty($resp["success"])) {
    json_out([
        "ok"    => false,
        "error" => "Tranzak Error",
        "raw"   => $resp
    ], 500);
}

$requestId = $resp["data"]["requestId"] ?? null;

// Save requestId
$pdo->prepare("
    UPDATE payments
    SET transaction_request_id = :tid,
        response_payload = :payload,
        updated_at = NOW()
    WHERE id = :pid
")->execute([
    ':tid'     => $requestId,
    ':payload' => json_encode($resp),
    ':pid'     => $paymentId
]);

// ----------------------
// Final API Response
// ----------------------
json_out([
    "ok"                 => true,
    "session_id"         => $sessionId,
    "payment_id"         => $paymentId,
    "order_id"           => $orderId,
    "amount"             => $amount,
    "currency"           => "XAF",
    "phone"              => $phone,
    "phone_e164"         => $phoneE164,
    "tranzak_request_id" => $requestId,
    "tranzak_response"   => $resp
]);
