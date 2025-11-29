<?php

require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/../config.php';

log_event("callback.php reached", [
    'GET' => $_GET,
    'raw_query' => $_SERVER['QUERY_STRING'] ?? ''
]);

// ------------------------------------------------------------
// CORS (optional, but harmless)
// ------------------------------------------------------------
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ------------------------------------------------------------
// Extract the order_id sent by Tranzak
// ------------------------------------------------------------
$orderId = $_GET['order_id'] ?? null;

if (!$orderId) {
    log_event("callback.php missing_order_id", $_GET);
    echo "Missing order_id";
    exit;
}

// ------------------------------------------------------------
// Confirm the session exists
// ------------------------------------------------------------
try {
    $pdo = db_connect();
    $stmt = $pdo->prepare("
        SELECT id FROM sessions WHERE order_id = :oid LIMIT 1
    ");
    $stmt->execute(['oid' => $orderId]);
    $session = $stmt->fetch();
} catch (Throwable $e) {
    log_event("callback.php db_error", $e->getMessage());
    echo "Database error";
    exit;
}

if (!$session) {
    log_event("callback.php order_not_found", $orderId);
    echo "Order not found";
    exit;
}

// ------------------------------------------------------------
// DO NOT update the order status here.
// The webhook (tranzak_webhook.php) handles it.
// ------------------------------------------------------------

// ------------------------------------------------------------
// Redirect back to WooCommerce
// ------------------------------------------------------------
$wcBase = wc_base_url();                 // from .env → WC_BASE_URL
$returnUrl = "{$wcBase}/?order_id={$orderId}";

log_event("callback.php redirecting_to", $returnUrl);

// Tranzak requires an HTML redirect
header("Location: $returnUrl");
exit;
