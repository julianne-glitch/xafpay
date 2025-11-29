<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/logger.php';

// ------------------------------------------------------------
// CORS (required for React / Netlify / external sites)
// ------------------------------------------------------------
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-API-KEY, X-SIGNATURE, X-TIMESTAMP");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header("Content-Type: application/json");

// ------------------------------------------------------------
// Read JSON body
// ------------------------------------------------------------
$raw = file_get_contents("php://input");
$input = json_decode($raw, true) ?: $_POST;

log_event("pay.php input", $input);

// ------------------------------------------------------------
// Validate input
// ------------------------------------------------------------
$amount   = floatval($input['amount'] ?? 0);
$phone    = $input['phone'] ?? '';
$carrier  = strtoupper(trim($input['carrier'] ?? 'MTN'));
$currency = "XAF";

if (!$amount || !$phone) {
    json_out(["ok" => false, "error" => "Missing amount or phone"], 400);
}

// normalize phone
$phone = preg_replace('/\D/', '', $phone);
$phoneE164 = (strlen($phone) === 9) ? "237$phone" : $phone;

if (!in_array($carrier, ["MTN", "ORANGE"])) {
    $carrier = "MTN";
}

// ------------------------------------------------------------
// Generate unique order reference
// ------------------------------------------------------------
$orderId = "ORD" . time() . rand(1000, 9999);

// ------------------------------------------------------------
// Create DB session + payment
// ------------------------------------------------------------
try {
    $pdo = db_connect();

    $stmt = $pdo->prepare("
        INSERT INTO sessions (amount, currency, phone_number, carrier_code, order_id, status)
        VALUES (:amount, :currency, :phone, :carrier, :order_id, 'pending')
        RETURNING id
    ");
    $stmt->execute([
        ':amount'   => $amount,
        ':currency' => $currency,
        ':phone'    => $phone,
        ':carrier'  => $carrier,
        ':order_id' => $orderId,
    ]);
    $sessionId = $stmt->fetchColumn();

    $stmt2 = $pdo->prepare("
        INSERT INTO payments (session_id, amount, carrier, status, reference_id)
        VALUES (:sid, :amount, :carrier, 'pending', :ref)
        RETURNING id
    ");
    $stmt2->execute([
        ':sid'     => $sessionId,
        ':amount'  => $amount,
        ':carrier' => $carrier,
        ':ref'     => $orderId
    ]);
    $paymentId = $stmt2->fetchColumn();

} catch (Throwable $e) {
    json_out(["ok" => false, "error" => "DB Error: ".$e->getMessage()], 500);
}

// ------------------------------------------------------------
// INITIATE TRANZAK PAYMENT (Correct endpoint)
// ------------------------------------------------------------
$cfg = tranzak_cfg();
$appId = $cfg['appId'];
$apiKey = $cfg['apiKey'];
$base = $cfg['base'];   // should be: https://sandbox.dsapi.tranzak.me

// Required by Tranzak
$endpoint = "$base/xp021/v1/payment/initiate";

$returnUrl = base_url() . "/api/callback.php?order_id=" . $orderId;

$payload = [
    "amount"             => $amount,
    "currencyCode"       => $currency,
    "description"        => "Order $orderId",
    "mobileWalletNumber" => $phoneE164,
    "mchTransactionRef"  => $orderId,
    "carrier"            => $carrier,
    "returnUrl"          => $returnUrl
];

log_event("pay.php payload_to_tranzak", $payload);

$ch = curl_init($endpoint);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
    "Content-Type: application/json",
    "x-api-key: $apiKey",
    "x-app-id: $appId",
    "Authorization: Bearer $apiKey"
],
    CURLOPT_POSTFIELDS     => json_encode($payload)
]);

$response = curl_exec($ch);
curl_close($ch);

$resp = json_decode($response, true);

log_event("pay.php tranzak_response", $resp);

// ------------------------------------------------------------
// Handle Tranzak response
// ------------------------------------------------------------
if (!$resp || empty($resp['success'])) {
    json_out(["ok" => false, "error" => "Tranzak Error", "raw" => $resp], 500);
}

$requestId = $resp['data']['requestId'] ?? null;

// Save request ID in DB
$pdo->prepare("
    UPDATE payments
    SET transaction_request_id = :tid, response_payload = :payload, updated_at = NOW()
    WHERE id = :pid
")->execute([
    ':tid'     => $requestId,
    ':payload' => json_encode($resp),
    ':pid'     => $paymentId
]);

// ------------------------------------------------------------
// Return result to frontend
// ------------------------------------------------------------
json_out([
    "ok"                 => true,
    "session_id"         => $sessionId,
    "payment_id"         => $paymentId,
    "order_id"           => $orderId,
    "amount"             => $amount,
    "currency"           => $currency,
    "phone"              => $phone,
    "phone_e164"         => $phoneE164,
    "carrier"            => $carrier,
    "tranzak_request_id" => $requestId,
    "tranzak_response"   => $resp
]);
