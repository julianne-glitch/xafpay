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
$raw   = file_get_contents("php://input");
$input = json_decode($raw, true) ?: $_POST;

log_event("pay.php input", $input);

$amount      = floatval($input["amount"] ?? 0);
$phone       = trim($input["phone"] ?? "");
$email       = trim($input["email"] ?? "");
$carrier     = strtoupper($input["carrier"] ?? "MTN");
$wc_order_id = $input["wc_order_id"] ?? null;
$return_url  = $input["return_url"] ?? null;   // ⭐ CRITICAL FIX

// ----------------------------------------------------
// VALIDATION
// ----------------------------------------------------
if (!$amount || !$phone) {
    json_out(["ok" => false, "error" => "Missing amount or phone"], 400);
}

if (!$wc_order_id) {
    json_out(["ok" => false, "error" => "Missing wc_order_id"], 400);
}

if (!$return_url) {
    json_out(["ok" => false, "error" => "Missing return_url"], 400);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_out(["ok" => false, "error" => "Invalid email"], 400);
}

// ----------------------------------------------------
// PHONE → E164
// ----------------------------------------------------
$phoneDigits = preg_replace("/\D/", "", $phone);
$phoneE164   = (strlen($phoneDigits) === 9)
    ? "237" . $phoneDigits
    : $phoneDigits;

// ----------------------------------------------------
// INTERNAL XAFPAY ORDER ID
// ----------------------------------------------------
$orderId = "ORD" . time() . rand(1000, 9999);

// ----------------------------------------------------
// DB INSERT → sessions + payments
// ----------------------------------------------------
try {
    $pdo = db_connect();

    // sessions
    $stmt = $pdo->prepare("
        INSERT INTO sessions (
            amount,
            currency,
            phone_number,
            email,
            carrier_code,
            order_id,
            wc_order_id,
            return_url,
            status
        )
        VALUES (
            :amount,
            'XAF',
            :phone,
            :email,
            :carrier,
            :oid,
            :wc,
            :return_url,
            'pending'
        )
        RETURNING id
    ");
    $stmt->execute([
        ":amount"     => $amount,
        ":phone"      => $phoneDigits,
        ":email"      => $email,
        ":carrier"    => $carrier,
        ":oid"        => $orderId,
        ":wc"         => $wc_order_id,
        ":return_url" => $return_url,
    ]);

    $sessionId = $stmt->fetchColumn();

    // payments
    $stmt2 = $pdo->prepare("
        INSERT INTO payments (
            session_id,
            amount,
            carrier,
            status,
            reference_id
        )
        VALUES (
            :sid,
            :amount,
            :carrier,
            'pending',
            :ref
        )
        RETURNING id
    ");
    $stmt2->execute([
        ":sid"     => $sessionId,
        ":amount"  => $amount,
        ":carrier" => $carrier,
        ":ref"     => $orderId,
    ]);

    $paymentId = $stmt2->fetchColumn();

} catch (Throwable $e) {
    log_event("pay.php db_error", $e->getMessage());
    json_out(["ok" => false, "error" => "Database error"], 500);
}

// ----------------------------------------------------
// TRANZAK CALLBACK URLS
// ----------------------------------------------------
$callbackUrl = base_url() . "/api/callback.php"
    . "?order_id=" . urlencode($orderId)
    . "&email=" . urlencode($email);

$webhookUrl = base_url() . "/api/tranzak_webhook.php";

// ----------------------------------------------------
// TRANZAK INIT PAYLOAD
// ----------------------------------------------------
$payload = [
    "amount"             => $amount,
    "currencyCode"       => "XAF",
    "description"        => "XafPay Payment",
    "mchTransactionRef"  => $orderId,
    "mobileWalletNumber" => $phoneE164,
    "returnUrl"          => $callbackUrl,
    "callbackUrl"        => $webhookUrl,
];

// ----------------------------------------------------
// CALL TRANZAK
// ----------------------------------------------------
$resp = tranzak_xp021_initiate($payload);

if (!$resp || empty($resp["success"])) {
    log_event("pay.php tranzak_error", $resp);
    json_out(["ok" => false, "error" => "Tranzak initiation failed"], 500);
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
    ":pid"     => $paymentId,
]);

// ----------------------------------------------------
// RESPONSE TO CHECKOUT UI
// ----------------------------------------------------
json_out([
    "ok"                 => true,
    "order_id"           => $orderId,
    "wc_order_id"        => $wc_order_id,
    "session_id"         => $sessionId,
    "payment_id"         => $paymentId,
    "amount"             => $amount,
    "currency"           => "XAF",
    "email"              => $email,
    "phone"              => $phoneDigits,
    "phone_e164"         => $phoneE164,
    "tranzak_request_id" => $requestId,
]);
