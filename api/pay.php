<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/tranzak_helpers.php';

/* ----------------------------------------------------
 * CORS
 * ---------------------------------------------------- */
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

header("Content-Type: application/json");

/* ----------------------------------------------------
 * READ INPUT
 * ---------------------------------------------------- */
$raw = file_get_contents("php://input");
$input = json_decode($raw, true);

if (!is_array($input)) {
    json_out(["ok" => false, "error" => "Invalid JSON body"], 400);
}

log_event("pay.php input", $input);

$amount      = floatval($input["amount"] ?? 0);
$currency    = strtoupper($input["currency"] ?? "XAF");
$phone       = trim($input["phone"] ?? "");
$email       = trim($input["email"] ?? "");
$carrier     = strtoupper($input["carrier"] ?? "MTN");
$wc_order_id = $input["wc_order_id"] ?? null;
$return_url  = $input["return_url"] ?? null;

/* ----------------------------------------------------
 * VALIDATION
 * ---------------------------------------------------- */
if ($amount <= 0) {
    json_out(["ok" => false, "error" => "Invalid amount"], 400);
}

if (!$phone) {
    json_out(["ok" => false, "error" => "Missing phone"], 400);
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

/* ----------------------------------------------------
 * PHONE → E164
 * ---------------------------------------------------- */
$digits = preg_replace("/\D/", "", $phone);
$phoneE164 = (strlen($digits) === 9) ? "237{$digits}" : $digits;

/* ----------------------------------------------------
 * INTERNAL XAFPAY ORDER ID
 * ---------------------------------------------------- */
$orderId = "ORD" . time() . random_int(1000, 9999);

/* ----------------------------------------------------
 * DB INSERT (MATCHES EXISTING SCHEMA)
 * ---------------------------------------------------- */
try {
    $pdo = db_connect();

    // sessions (NO wc_return_url)
    $stmt = $pdo->prepare("
        INSERT INTO sessions (
            order_id,
            wc_order_id,
            amount,
            currency,
            phone_number,
            email,
            carrier_code,
            status
        ) VALUES (
            :order_id,
            :wc_order_id,
            :amount,
            :currency,
            :phone,
            :email,
            :carrier,
            'pending'
        )
        RETURNING id
    ");

    $stmt->execute([
        ":order_id"    => $orderId,
        ":wc_order_id" => $wc_order_id,
        ":amount"      => $amount,
        ":currency"    => $currency,
        ":phone"       => $digits,
        ":email"       => $email,
        ":carrier"     => $carrier,
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
        ) VALUES (
            :session_id,
            :amount,
            :carrier,
            'pending',
            :reference_id
        )
        RETURNING id
    ");

    $stmt2->execute([
        ":session_id"   => $sessionId,
        ":amount"       => $amount,
        ":carrier"      => $carrier,
        ":reference_id" => $orderId,
    ]);

    $paymentId = $stmt2->fetchColumn();

} catch (Throwable $e) {
    log_event("pay.php DB ERROR", $e->getMessage());
    json_out(["ok" => false, "error" => "Database error"], 500);
}

/* ----------------------------------------------------
 * TRANZAK CALLBACKS
 * ---------------------------------------------------- */
$callbackUrl = base_url() . "/api/callback.php?order_id={$orderId}";
$webhookUrl  = base_url() . "/api/tranzak_webhook.php";

/* ----------------------------------------------------
 * TRANZAK PAYLOAD
 * ---------------------------------------------------- */
$payload = [
    "amount"             => $amount,
    "currencyCode"       => $currency,
    "description"        => "XafPay Payment",
    "mchTransactionRef"  => $orderId,
    "mobileWalletNumber" => $phoneE164,
    "returnUrl"          => $callbackUrl,
    "callbackUrl"        => $webhookUrl,
];

/* ----------------------------------------------------
 * CALL TRANZAK
 * ---------------------------------------------------- */
$resp = tranzak_xp021_initiate($payload);

if (!$resp || empty($resp["success"])) {
    log_event("pay.php tranzak_error", $resp);
    json_out(["ok" => false, "error" => "Tranzak initiation failed"], 500);
}

$requestId = $resp["data"]["requestId"] ?? null;

/* ----------------------------------------------------
 * SAVE TRANZAK REQUEST ID
 * ---------------------------------------------------- */
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

/* ----------------------------------------------------
 * RESPONSE
 * ---------------------------------------------------- */
json_out([
    "ok"          => true,
    "order_id"    => $orderId,
    "wc_order_id" => $wc_order_id,
    "session_id"  => $sessionId,
    "payment_id"  => $paymentId,
]);
