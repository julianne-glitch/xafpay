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

// ------------------------------------------------------------
// READ QUERY
// ------------------------------------------------------------
$orderId = $_GET['order_id'] ?? null;

log_event("callback.php hit", $_GET);

$woo = rtrim(wc_base_url(), "/");

// ------------------------------------------------------------
// SAFETY: missing order_id → go home
// ------------------------------------------------------------
if (!$orderId) {
    header("Location: {$woo}");
    exit;
}

// ------------------------------------------------------------
// LOOKUP STORED WOO RETURN URL
// ------------------------------------------------------------
try {
    $pdo = db_connect();

    $stmt = $pdo->prepare("
        SELECT wc_return_url
        FROM sessions
        WHERE order_id = :oid
        LIMIT 1
    ");
    $stmt->execute(['oid' => $orderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    log_event("callback.php db_error", $e->getMessage());
    header("Location: {$woo}");
    exit;
}

// ------------------------------------------------------------
// ✅ CORRECT REDIRECT (WITH ORDER KEY)
// ------------------------------------------------------------
if ($row && !empty($row['wc_return_url'])) {

    log_event("callback.php redirect_success", $row['wc_return_url']);

    header("Location: " . $row['wc_return_url']);
    exit;
}

// ------------------------------------------------------------
// FALLBACK — SHOULD NEVER HAPPEN
// ------------------------------------------------------------
log_event("callback.php missing_wc_return_url", $orderId);

header("Location: {$woo}");
exit;
