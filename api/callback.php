<?php

require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/../config.php';

log_event("callback.php reached", [
    'GET' => $_GET,
    'raw_query' => $_SERVER['QUERY_STRING'] ?? ''
]);

// ------------------------------------------------------------
// CORS
// ------------------------------------------------------------
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ------------------------------------------------------------
// Extract WC parameters (Tranzak does NOT send signature/status)
// ------------------------------------------------------------
$orderId = $_GET['order_id'] ?? null;

if (!$orderId) {
    json_out(['ok' => false, 'error' => 'Missing order_id'], 400);
}

// ------------------------------------------------------------
// Verify that order/session exists (optional but safer)
// ------------------------------------------------------------
$pdo = db_connect();

$stmt = $pdo->prepare("SELECT id FROM sessions WHERE order_id = :oid LIMIT 1");
$stmt->execute(['oid' => $orderId]);
$session = $stmt->fetch();

if (!$session) {
    log_event("callback.php order_not_found", $orderId);
    json_out(['ok' => false, 'error' => 'Order not found'], 404);
}

// ------------------------------------------------------------
// Do NOT check status here (Tranzak webhook handles it)
// ------------------------------------------------------------
// callback.php simply redirects user to WC final page

$wcBase = wc_base_url();
$returnUrl = "{$wcBase}/?order_id={$orderId}";

// Log redirect
log_event("callback.php redirecting", $returnUrl);

// Redirect user to WooCommerce
header("Location: {$returnUrl}");
exit;

