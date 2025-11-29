<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/tranzak_helpers.php';   // required

// ------------------------------------------------------------
// CORS
// ------------------------------------------------------------
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

header("Content-Type: application/json");

// ------------------------------------------------------------
// Read input
// ------------------------------------------------------------
$raw = file_get_contents("php://input");
$input = json_decode($raw, true) ?: $_POST;

log_event("pay.php input", $input);

// Validate
$amount   = floatval($input["amount"] ?? 0);
$phone    = $input["phone"] ?? "";
$carrier  = strtoupper(trim($input["carrier"] ?? "MTN"));

if (!$amount || !$phone) {
    json_out(["ok"=>false,"error"=>"Missing amount or phone"],400);
}

// normalize phone
$phone = preg_replace('/\D/','',$phone);
$phoneE164 = strlen($phone)===9 ? "237$phone" : $phone;

// ------------------------------------------------------------
// Create order ID
// ------------------------------------------------------------
$orderId = "ORD".time().rand(1000,9999);

// ------------------------------------------------------------
// Save session + payment
// ------------------------------------------------------------
try {
    $pdo = db_connect();

    $stmt = $pdo->prepare("
        INSERT INTO sessions (amount,currency,phone_number,carrier_code,order_id,status)
        VALUES (:a,'XAF',:p,:c,:o,'pending') RETURNING id
    ");
    $stmt->execute([
        ":a"=>$amount, ":p"=>$phone, ":c"=>$carrier, ":o"=>$orderId
    ]);
    $sessionId = $stmt->fetchColumn();

    $stmt2 = $pdo->prepare("
        INSERT INTO payments (session_id,amount,carrier,status,reference_id)
        VALUES (:sid,:a,:c,'pending',:ref) RETURNING id
    ");
    $stmt2->execute([
        ":sid"=>$sessionId,":a"=>$amount,":c"=>$carrier,":ref"=>$orderId
    ]);
    $paymentId = $stmt2->fetchColumn();

} catch(Throwable $e) {
    json_out(["ok"=>false,"error"=>"DB Error: ".$e->getMessage()],500);
}

// ------------------------------------------------------------
// CALL TRANZAK LEGACY API
// ------------------------------------------------------------

// correct payload for legacy API:
$payload = [
    "amount"         => $amount,
    "currencyCode"   => "XAF",
    "description"    => "Order $orderId",
    "customerNumber" => $phoneE164,
    "paymentChannel" => $carrier,
    "countryCode"    => "CM",
    "reference"      => $orderId,
    "callbackUrl"    => base_url() . "/api/webhook.php"
];

log_event("pay.php sending_payload", $payload);

$resp = tranzak_initiate_payment($payload);

log_event("pay.php tranzak_response", $resp);

// ------------------------------------------------------------
// Handle errors
// ------------------------------------------------------------
if (!$resp || empty($resp["success"])) {
    json_out([
        "ok"=>false,
        "error"=>"Tranzak Error",
        "raw"=>$resp
    ],500);
}

$requestId = $resp["data"]["requestId"] ?? null;

// save request ID
$pdo->prepare("
    UPDATE payments
    SET transaction_request_id=:tid, response_payload=:p, updated_at=NOW()
    WHERE id=:id
")->execute([
    ":tid"=>$requestId,
    ":p"=>json_encode($resp),
    ":id"=>$paymentId
]);

// ------------------------------------------------------------
// Final response
// ------------------------------------------------------------
json_out([
    "ok"=>true,
    "order_id"=>$orderId,
    "session_id"=>$sessionId,
    "payment_id"=>$paymentId,
    "phone"=>$phoneE164,
    "carrier"=>$carrier,
    "tranzak_request_id"=>$requestId,
    "tranzak_response"=>$resp
]);
