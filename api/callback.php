<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/logger.php';

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header("Content-Type: text/html");

// ------------------------------------------------------------
// READ RETURN QUERY
// ------------------------------------------------------------
$orderId = $_GET['order_id'] ?? null;
$email   = $_GET['email'] ?? null;

log_event("callback.php reached", [
    "order_id" => $orderId,
    "email" => $email,
    "query" => $_GET
]);

if (!$orderId) {
    echo "Missing order_id";
    exit;
}

// ------------------------------------------------------------
// LOOKUP SESSION
// ------------------------------------------------------------
try {
    $pdo = db_connect();
    $stmt = $pdo->prepare("
        SELECT 
            id,
            wc_order_id,
            status
        FROM sessions
        WHERE order_id = :oid
        LIMIT 1
    ");
    $stmt->execute(['oid' => $orderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    log_event("callback.php db_error", $e->getMessage());
    echo "Database error";
    exit;
}

if (!$row) {
    log_event("callback.php order_not_found", $orderId);
    echo "Order not found";
    exit;
}

$wcOrderId     = $row['wc_order_id'];  // may be null
$sessionStatus = strtolower($row['status']);
$woo           = rtrim(wc_base_url(), "/");

// ------------------------------------------------------------
// FAILURE CASES
// ------------------------------------------------------------
if (in_array($sessionStatus, ["failed", "canceled", "cancelled", "expired"])) {

    log_event("callback.php failed_status", $sessionStatus);

    // safe fallback even if wc_order_id is null
    $retryUrl = "{$woo}/?pay_for_order=1&order_id={$wcOrderId}";

    echo "
    <html>
    <head>
        <title>Payment Failed – XafPay</title>
        <style>
            body {
                font-family: Arial;
                background:#fdf3f3;
                text-align:center;
                padding-top:80px;
                color:#a10000;
            }
            .card {
                background:white;
                margin:auto;
                width:90%;
                max-width:420px;
                padding:30px;
                border-radius:12px;
                box-shadow:0 4px 20px rgba(0,0,0,0.08);
            }
            .btn {
                display:inline-block;
                margin-top:20px;
                padding:12px 22px;
                background:#ff4444;
                color:white;
                text-decoration:none;
                border-radius:6px;
                font-weight:bold;
            }
        </style>
    </head>
    <body>

    <div class='card'>
        <h2>Payment Failed</h2>
        <p>Your payment could not be completed.<br>You may try again.</p>
        <a class='btn' href='{$retryUrl}'>Retry Payment</a>
    </div>

    </body>
    </html>
    ";
    exit;
}

// ------------------------------------------------------------
// SUCCESS CASE
// ------------------------------------------------------------

// If WooCommerce order ID is missing → fallback thanks screen
if (!$wcOrderId) {
    log_event("callback.php missing_wc_order_id", $orderId);

    echo "
    <html>
    <head><title>Payment Received</title></head>
    <body style='font-family:Arial; text-align:center; padding-top:80px;'>
        <h2>Payment Successful</h2>
        <p>We received your payment.<br>Return to shop.</p>
        <a href='{$woo}' style='padding:10px 20px; background:#007bff; color:white; text-decoration:none; border-radius:6px;'>Back to Shop</a>
    </body>
    </html>
    ";
    exit;
}

// ------------------------------------------------------------
// NORMAL SUCCESS REDIRECT
// ------------------------------------------------------------
$returnUrl = "{$woo}/?order_id={$wcOrderId}&email=" . urlencode($email);

log_event("callback.php redirect_success", $returnUrl);
header("Location: {$returnUrl}");
exit;

?>
