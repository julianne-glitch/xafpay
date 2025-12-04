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

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ------------------------------------------------------------
// Get order_id + email from Tranzak returnUrl
// ------------------------------------------------------------
$orderId = $_GET['order_id'] ?? null;
$email   = $_GET['email'] ?? null;   // ⭐ NEW — passed from pay.php

if (!$orderId) {
    log_event("callback.php missing_order_id", $_GET);
    echo "Missing order_id";
    exit;
}

// ------------------------------------------------------------
// Lookup order in sessions
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
// IMPORTANT: DO NOT update payment status here.
// Webhook controls all status updates.
// ------------------------------------------------------------

// ------------------------------------------------------------
// Build WooCommerce Redirect URL
// ------------------------------------------------------------
$wcBase = wc_base_url();  // from .env

// We forward email to WooCommerce so the plugin snippet can use it.
$query = http_build_query([
    "order_id" => $orderId,
    "email"    => $email   // ⭐ NEW
]);

$returnUrl = "{$wcBase}/?{$query}";

log_event("callback.php redirecting_to", $returnUrl);

// ------------------------------------------------------------
// Redirect Customer → WooCommerce
// ------------------------------------------------------------
header("Location: $returnUrl");
exit;

