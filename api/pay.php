<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/logger.php';

// ------------------------------------------------------------
// CORS (for React/JS checkout)
// ------------------------------------------------------------
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-API-KEY, X-SIGNATURE, X-TIMESTAMP");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ------------------------------------------------------------
// Read JSON input
// ------------------------------------------------------------
$raw = file_get_contents("php://input");
$input = json_decode($raw, true);

if (!$input) {
    $input = $_POST;
}

log_event("pay.php input", $input);

// ------------------------------------------------------------
// Validate inputs
// ------------------------------------------------------------
$amount   = floatval($input['amount'] ?? 0);
$phone    = $input['phone'] ?? '';
$carrier  = strtoupper(trim($input['carrier'] ?? 'MTN'));
$currency = strtoupper($input['currency'] ?? 'XAF');

if (!$amount || !$phone) {
    json_out(["ok" => false, "error" => "Missing amount or phone"], 400);
}

$phone = preg_replace('/\D/', '', $phone);
$phoneE164 = (strlen($phone) === 9) ? "237$phone" : $phone;

if (!in_array($carrier, ['MTN', 'ORANGE'])) {
    $carrier = 'MTN';
}

// ------------------------------------------------------------
// Generate order ID
// ------------------------------------------------------------
$orderId = "ORD" . time() . rand(1000, 9999);

// ------------------------------------------------------------
// DB: create session + payment
// ------------------------------------------------------------
try {
    $pdo = db_connect();

    // SESSION
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

    // PAYMENT
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
// TRANZAK PAYMENT INIT
// ------------------------------------------------------------
$cfg    = tranzak_cfg();
$appId  = $cfg['appId'];
$apiKey = $cfg['apiKey'];
$base   = $cfg['base']; // example: https://sandbox.dsapi.tranzak.me/api/v1

// Correct sandbox carrier names
$tranzakCarrier = ($carrier === 'ORANGE') ? "orange_cm" : "mtn_cm";

// Callback URL
$returnUrl = base_url() . "/api/callback.php?order_id=" . $orderId;

// ------------------------------------------------------------
// Sandbox payload (ONLY this structure works)
// ------------------------------------------------------------
$payload = [
    "mchTransactionRef"  => $orderId,
    "amount"             => $amount,
    "currencyCode"       => $currency,
    "mobileWalletNumber" => $phoneE164,
    "carrierCode"        => $tranzakCarrier,
    "description"        => "Order $orderId",
    "returnUrl"          => $returnUrl
];

log_event("pay.php payload_to_tranzak", $payload);

// ------------------------------------------------------------
// Correct Sandbox endpoint
// ------------------------------------------------------------
$url = $base . "/payment/initiate";  
// -> final: https://sandbox.dsapi.tranzak.me/api/v1/payment/initiate

// ------------------------------------------------------------
// cURL call
// ------------------------------------------------------------
$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        "Content-Type: application/json",
        "x-api-key: $apiKey",
        "x-app-id: $appId"
    ],
    CURLOPT_POSTFIELDS     => json_encode($payload)
]);

$response = curl_exec($ch);
$curlError = curl_error($ch);
$curlInfo  = curl_getinfo($ch);
curl_close($ch);

// Logging
log_event("pay.php curl_info", json_encode($curlInfo));
log_event("pay.php curl_error", json_encode($curlError));
log_event("pay.php tranzak_raw_response", $response);

$resp = json_decode($response, true);
log_event("pay.php tranzak_response_json", json_encode($resp));

// ------------------------------------------------------------
// Validate Tranzak response
// ------------------------------------------------------------
if (!$resp || empty($resp['success'])) {
    json_out([
        "ok"    => false,
        "error" => "Tranzak Error",
        "raw"   => $resp
    ], 500);
}

$requestId = $resp['data']['requestId'] ?? null;

// ------------------------------------------------------------
// Save requestId in DB
// ------------------------------------------------------------
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

// ------------------------------------------------------------
// Output to frontend
// ------------------------------------------------------------
json_out([
    "ok"                  => true,
    "session_id"          => $sessionId,
    "payment_id"          => $paymentId,
    "order_id"            => $orderId,
    "amount"              => $amount,
    "currency"            => $currency,
    "phone"               => $phone,
    "phone_e164"          => $phoneE164,
    "carrier"             => $carrier,
    "tranzak_request_id"  => $requestId,
    "tranzak_payload_raw" => $resp
]);
