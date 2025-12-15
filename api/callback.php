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

$orderId = $_GET['order_id'] ?? null;
$email   = $_GET['email'] ?? null;

log_event("callback.php reached", $_GET);

if (!$orderId) {
    echo "Missing order_id";
    exit;
}

$pdo = db_connect();

$stmt = $pdo->prepare("
    SELECT wc_order_id
    FROM sessions
    WHERE order_id = :oid
    LIMIT 1
");
$stmt->execute(['oid' => $orderId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row || !$row['wc_order_id']) {
    log_event("callback.php order_not_found", $orderId);
    echo "Order not found";
    exit;
}

$wcOrderId = intval($row['wc_order_id']);
$woo       = rtrim(wc_base_url(), "/");

/**
 * ✅ ALWAYS REDIRECT
 * ❌ NEVER CHECK STATUS HERE
 */
$returnUrl = "{$woo}/checkout/order-received/{$wcOrderId}/";

log_event("callback.php redirecting", $returnUrl);

header("Location: $returnUrl", true, 302);
exit;
