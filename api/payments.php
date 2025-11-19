<?php
// ------------------------------------------------------------
// payments.php — List payments for merchant dashboard
// ------------------------------------------------------------

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_auth.php';

log_event("payments.php reached", [
    'GET'     => $_GET,
    'POST'    => $_POST,
    'headers' => getallheaders()
]);

$raw = file_get_contents("php://input");
log_event("payments.php raw_body", $raw);

// ------------------------------------------------------------
// CORS
// ------------------------------------------------------------
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-API-KEY, X-SIGNATURE, X-TIMESTAMP");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ------------------------------------------------------------
// Merchant Auth (optional)
// ------------------------------------------------------------
$pdo = db_connect();
$auth = optional_hmac_auth($pdo);
$merchant = $auth['merchant'] ?? null;

// Default merchant UUID (same as sessions.php)
$merchantId = $merchant['id'] ?? "185b2203-ec89-4d7d-9568-f48dd9311120";

log_event("payments.php merchant_detected", $merchantId);

// ------------------------------------------------------------
// Query last 50 payments for this merchant
// ------------------------------------------------------------
try {
   $stmt = $pdo->prepare("
    SELECT 
        p.reference_id,
        s.order_id,
        p.session_id,
        s.amount,             -- currency belongs to sessions
        s.currency,
        p.status,
        p.created_at,
        s.phone_number,
        s.carrier_code
    FROM payments p
    LEFT JOIN sessions s ON s.id = p.session_id
    WHERE p.merchant_id = :mid
    ORDER BY p.created_at DESC
    LIMIT 50
");


    $stmt->execute(['mid' => $merchantId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    log_event("payments.php result_count", count($rows));

    json_out([
        'ok'          => true,
        'merchant_id' => $merchantId,
        'data'        => $rows
    ]);

} catch (Throwable $e) {
    log_event("payments.php exception", $e->getMessage());

    json_out([
        'ok'    => false,
        'error' => $e->getMessage()
    ], 500);
}
