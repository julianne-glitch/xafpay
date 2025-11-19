<?php
require_once __DIR__ . '/../api/logger.php';
log_event("checkout.php accessed", $_GET);

// ----------------------------------------------
// CORS — required for React / WooCommerce
// ----------------------------------------------
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ----------------------------------------------
// Read input parameters
// ----------------------------------------------
$orderId  = $_GET['order_id'] ?? null;
$amount   = $_GET['amount'] ?? null;
$currency = $_GET['currency'] ?? 'XAF';

if (!$orderId || !$amount) {
    echo "<h2>XafPay Checkout</h2>";
    echo "<p>Missing order information.</p>";
    exit;
}

// ----------------------------------------------
// Redirect to React Checkout
// ----------------------------------------------
$reactCheckout = "https://checkout.xafpay.com/?order_id={$orderId}&amount={$amount}&currency={$currency}";

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>XafPay Secure Checkout</title>
    <meta http-equiv="refresh" content="0; url=<?= htmlspecialchars($reactCheckout) ?>">
</head>
<body>
    <p>Redirecting to secure XafPay Checkout…</p>
    <p>If not redirected, <a href="<?= htmlspecialchars($reactCheckout) ?>">click here</a>.</p>
</body>
</html>
