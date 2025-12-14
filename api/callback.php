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
// LOOKUP SESSION (to get wc_order_id + session status)
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

$wcOrderId     = $row['wc_order_id'];
$sessionStatus = strtolower($row['status']);

// ------------------------------------------------------------
// WOO BASE URL
// ------------------------------------------------------------
$woo = rtrim(wc_base_url(), "/");

// ------------------------------------------------------------
// HANDLE FAILED / CANCELLED
// ------------------------------------------------------------
if (in_array($sessionStatus, ["failed", "canceled", "cancelled", "expired"])) {

    log_event("callback.php failed_status", $sessionStatus);

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
// SUCCESS CASE → REDIRECT BACK TO WOO
//
// WooCommerce listens to its OWN webhook.
// If status is still pending here, it's fine — once webhook fires,
// WC will update to Processing automatically.
// ------------------------------------------------------------
$returnUrl = $woo . "/checkout/order-received/{$wcOrderId}/?key=" . wc_get_order_key($wcOrderId);

// log it:
log_event("callback.php redirect_success", $returnUrl);

// redirect:
header("Location: {$returnUrl}");
exit;
