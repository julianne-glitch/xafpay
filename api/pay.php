<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/tranzak_helpers.php';

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

header("Content-Type: application/json");

// ----------------------------------------------------
// READ INPUT
// ----------------------------------------------------
$raw = file_get_contents("php://input");
$input = json_decode($raw, true) ?: $_POST;

log_event("pay.php input", $input);

$amount      = floatval($input["amount"] ?? 0);
$phone       = $input["phone"] ?? "";
$email       = $input["email"] ?? "";
$carrier     = strtoupper($input["carrier"] ?? "MTN");
$wc_order_id = $input["wc_order_id"] ?? null;     // ⭐ REQUIRED FOR WOOCOMMERCE

// ----------------------------------------------------
// VALIDATE INPUT
// ----------------------------------------------------
if (!$amount || !$phone) {
    json_out(["ok" => false, "error" => "Missing amount or phone"], 400);
}

if (!$wc_order_id) {
    json_out(["ok" => false, "error" => "Missing wc_order_id"], 400);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_out(["ok" => false, "error" => "Invalid email"], 400);
}

// ----------------------------------------------------
// PHONE → E164
// ----------------------------------------------------
$phone = preg_replace("/\D/", "", $phone);
$phoneE164 = (strlen($phone) === 9) ? "237".$phone : $phone;

// ----------------------------------------------------
// INTERNAL XAFPAY ORDER ID
// ----------------------------------------------------
$orderId = "ORD" . time() . rand(1000, 9999);

// ----------------------------------------------------
// DB INSERT → SESSIONS + PAYMENTS
// ----------------------------------------------------
try {
    $pdo = db_connect();

    $stmt = $pdo->prepare("
        INSERT INTO sessions(amount, currency, phone_number, email, carrier_code, order_id, wc_order_id, status)
        VALUES (:amount, 'XAF', :phone, :email, :carrier, :oid, :wc, 'pending')
        RETURNING id
    ");
    $stmt->execute([
        ":amount"  => $amount,
        ":phone"   => $phone,
        ":email"   => $email,
        ":carrier" => $carrier,
        ":oid"     => $orderId,
        ":wc"      => $wc_order_id
    ]);
    $sessionId = $stmt->fetchColumn();

    $stmt2 = $pdo->prepare("
        INSERT INTO payments(session_id, amount, carrier, status, reference_id)
        VALUES (:sid, :amount, :carrier, 'pending', :ref)
        RETURNING id
    ");
    $stmt2->execute([
        ":sid"     => $sessionId,
        ":amount"  => $amount,
        ":carrier" => $carrier,
        ":ref"     => $orderId
    ]);
    $paymentId = $stmt2->fetchColumn();

} catch (Throwable $e) {
    json_out(["ok" => false, "error" => "DB Error: " . $e->getMessage()], 500);
}

// ----------------------------------------------------
// CALLBACK URLs
// ----------------------------------------------------
$callbackUrl = base_url() . "/api/callback.php?order_id=$orderId&email=" . urlencode($email);
$webhookUrl  = base_url() . "/api/tranzak_webhook.php";

// ----------------------------------------------------
// TRANZAK INITIATE PAYLOAD
// ----------------------------------------------------
$payload = [
    "amount"             => $amount,
    "currencyCode"       => "XAF",
    "description"        => "XafPay Payment",
    "mchTransactionRef"  => $orderId,
    "mobileWalletNumber" => $phoneE164,
    "returnUrl"          => $callbackUrl,
    "callbackUrl"        => $webhookUrl
];

// ----------------------------------------------------
// CALL TRANZAK INITIATE
// ----------------------------------------------------
$resp = tranzak_xp021_initiate($payload);

if (!$resp || empty($resp["success"])) {
    json_out(["ok" => false, "error" => "Tranzak XP021 Error", "raw" => $resp], 500);
}

$requestId = $resp["data"]["requestId"] ?? null;

// ----------------------------------------------------
// SAVE TRANZAK REQUEST ID
// ----------------------------------------------------
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

// ----------------------------------------------------
// FINAL RESPONSE TO CHECKOUT
// ----------------------------------------------------
json_out([
    "ok"                 => true,
    "session_id"         => $sessionId,
    "payment_id"         => $paymentId,
    "order_id"           => $orderId,
    "wc_order_id"        => $wc_order_id,     // ⭐ Sent back to checkout
    "amount"             => $amount,
    "currency"           => "XAF",
    "email"              => $email,
    "phone"              => $phone,
    "phone_e164"         => $phoneE164,
    "tranzak_request_id" => $requestId,
    "tranzak_response"   => $resp
]);
