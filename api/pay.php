<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/tranzak_helpers.php'; // we will update this too

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

header("Content-Type: application/json");

// ------------------------------
// READ INPUT
// ------------------------------
$raw = file_get_contents("php://input");
$input = json_decode($raw, true) ?: $_POST;

log_event("pay.php input", $input);

$amount = floatval($input["amount"] ?? 0);
$phone  = $input["phone"] ?? "";
$carrier = strtoupper($input["carrier"] ?? "MTN");

if (!$amount || !$phone) {
    json_out(["ok" => false, "error" => "Missing amount or phone"], 400);
}

// ------------------------------
// PHONE NORMALIZATION (E164)
// ------------------------------
$phone = preg_replace("/\D/", "", $phone);

if (strlen($phone) === 9) {
    $phoneE164 = "237" . $phone;
} else {
    $phoneE164 = $phone;
}

log_event("pay.php phoneE164", $phoneE164);

// ------------------------------
// ORDER ID
// ------------------------------
$orderId = "ORD" . time() . rand(1000, 9999);

// ------------------------------
// DB: CREATE SESSION + PAYMENT
// ------------------------------
try {
    $pdo = db_connect();

    $stmt = $pdo->prepare("
        INSERT INTO sessions(amount, currency, phone_number, carrier_code, order_id, status)
        VALUES (:amount, 'XAF', :phone, :carrier, :oid, 'pending')
        RETURNING id
    ");
    $stmt->execute([
        ":amount" => $amount,
        ":phone"  => $phone,
        ":carrier" => $carrier,
        ":oid"    => $orderId
    ]);
    $sessionId = $stmt->fetchColumn();

    $stmt2 = $pdo->prepare("
        INSERT INTO payments(session_id, amount, carrier, status, reference_id)
        VALUES (:sid, :amount, :carrier, 'pending', :ref)
        RETURNING id
    ");
    $stmt2->execute([
        ":sid"    => $sessionId,
        ":amount" => $amount,
        ":carrier" => $carrier,
        ":ref"    => $orderId
    ]);
    $paymentId = $stmt2->fetchColumn();

} catch (Throwable $e) {
    json_out(["ok" => false, "error" => "DB Error: " . $e->getMessage()], 500);
}

// ------------------------------
// BUILD TRanzak XP021 PAYLOAD
// ------------------------------
$callbackUrl = base_url() . "/api/callback.php";

$payload = [
    "appId"          => envv("TRANZAK_APP_ID"),
    "amount"         => $amount,
    "currency"       => "XAF",
    "countryCode"    => "CM",
    "paymentChannel" => $carrier,            // MTN/ORANGE
    "customerNumber" => $phoneE164,
    "reference"      => $orderId,
    "callbackUrl"    => $callbackUrl
];

log_event("pay.php xp021 payload", $payload);

// ------------------------------
// CALL TRANZAK XP021 INITIATE
// ------------------------------
$resp = tranzak_xp021_initiate($payload);  // new helper method

log_event("pay.php xp021 response", $resp);

// ------------------------------
// VALIDATE RESPONSE
// ------------------------------
if (!$resp || empty($resp["success"])) {
    json_out([
        "ok"    => false,
        "error" => "Tranzak XP021 Error",
        "raw"   => $resp
    ], 500);
}

$requestId = $resp["data"]["requestId"] ?? null;

// ------------------------------
// SAVE TRanzak REQUEST ID
// ------------------------------
$pdo->prepare("
    UPDATE payments
    SET transaction_request_id = :tid,
        response_payload = :payload,
        updated_at = NOW()
    WHERE id = :pid
")->execute([
    ":tid"     => $requestId,
    ":payload" => json_encode($resp),
    ":pid"     => $paymentId
]);

// ------------------------------
// FINAL API RESPONSE TO FRONTEND
// ------------------------------
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

