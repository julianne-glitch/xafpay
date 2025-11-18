<?php
require_once __DIR__ . '/../logger.php';
log_event("status.php started", $_GET);


// ----------------------------------------------
// checkout.php — Safe redirect to React checkout
// ----------------------------------------------

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$orderId  = $_GET['order_id'] ?? null;
$amount   = $_GET['amount'] ?? null;
$currency = $_GET['currency'] ?? 'XAF';

// If required fields missing → show simple error
if (!$orderId || !$amount) {
    echo "<h2>XafPay Checkout</h2>";
    echo "<p>Missing order information.</p>";
    exit;
}

// ----------------------------------------------
// Your React checkout URL (production)
// ----------------------------------------------
$reactCheckout = "https://checkout.xafpay.com/?order_id={$orderId}&amount={$amount}&currency={$currency}";

// (Local Dev Example: http://localhost:5173)
// ----------------------------------------------

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>XafPay Secure Checkout</title>
    <meta http-equiv="refresh" content="0;url=<?=$reactCheckout?>">
</head>
<body>
    <p>Redirecting to secure XafPay Checkout...</p>
    <p>If you are not redirected automatically, <a href="<?=$reactCheckout?>">click here</a>.</p>
</body>
</html>
