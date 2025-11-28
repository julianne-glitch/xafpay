<?php
require_once __DIR__ . '/../config.php';

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

$raw = file_get_contents("php://input");
$input = json_decode($raw, true);

if (!$input) {
    $input = $_POST;
}

// ----------------------
//  INPUTS
// ----------------------
$amount   = floatval($input['amount'] ?? 0);
$phone    = $input['phone'] ?? '';
$carrier  = strtoupper($input['carrier'] ?? 'MTN');
$currency = $input['currency'] ?? 'XAF';

if (!$amount || !$phone) {
    echo json_encode(["ok" => false, "error" => "Missing amount or phone"]);
    exit;
}

// sanitize phone
$phone = preg_replace('/\D/', '', $phone);
$phoneE164 = (strlen($phone) == 9) ? "237$phone" : $phone;

// Create an internal order id
$orderId = "ORD" . time() . rand(1000, 9999);

// ----------------------
//  DB INIT
// ----------------------
try {
    $pdo = db_connect();

    // create session
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

    // create payment entry
    $stmt2 = $pdo->prepare("
        INSERT INTO payments (session_id, amount, carrier, status, reference_id)
        VALUES (:sid, :amount, :carrier, 'pending', :ref)
        RETURNING id
    ");
    $stmt2->execute([
        ':sid'    => $sessionId,
        ':amount' => $amount,
        ':carrier'=> $carrier,
        ':ref'    => $orderId
    ]);
    $paymentId = $stmt2->fetchColumn();

} catch (Throwable $e) {
    echo json_encode(["ok" => false, "error" => "DB Error: ".$e->getMessage()]);
    exit;
}

// ----------------------
//  CALL TRANZAK API
// ----------------------
$cfg = tranzak_cfg();
$appId = $cfg['appId'];
$apiKey = $cfg['apiKey'];
$base = $cfg['base'];

$payload = [
    "amount"             => $amount,
    "currencyCode"       => $currency,
    "description"        => "Order $orderId",
    "mobileWalletNumber" => $phoneE164,
    "mchTransactionRef"  => $orderId,
    "carrier"            => $carrier,
    "returnUrl"          => "https://pay.xafpay.com/checkout/return.php?order_id=$orderId"
];

$ch = curl_init("$base/payment/initiate");
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
curl_close($ch);

$raw = json_decode($response, true);

if (!$raw || empty($raw['success'])) {
    echo json_encode(["ok" => false, "error" => "Tranzak Error", "raw" => $raw]);
    exit;
}

$requestId = $raw['data']['requestId'] ?? null;

// ----------------------
//  UPDATE PAYMENT
// ----------------------
$pdo->prepare("
    UPDATE payments 
    SET reference_id = :rid, response_payload = :payload, updated_at = NOW()
    WHERE id = :pid
")->execute([
    ':rid'     => $requestId,
    ':payload' => json_encode($raw),
    ':pid'     => $paymentId
]);

// ----------------------
//  FINAL RESPONSE
// ----------------------
echo json_encode([
    "ok"                 => true,
    "mode"               => "DIRECT",
    "session_id"         => $sessionId,
    "payment_id"         => $paymentId,
    "order_id"           => $orderId,
    "amount"             => $amount,
    "currency"           => $currency,
    "phone"              => $phone,
    "phone_e164"         => $phoneE164,
    "carrier"            => $carrier,
    "tranzak_request_id" => $requestId,
    "tranzak_raw"        => $raw
]);
