<?php
// ------------------------------------------------------------
// payments.php — List payments for merchant dashboard
// ------------------------------------------------------------

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
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-API-KEY, X-SIGNATURE, X-TIMESTAMP");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ------------------------------------------------------------
// Optional Merchant Auth (safe — never breaks)
// ------------------------------------------------------------
$pdo = db_connect();
$auth = optional_hmac_auth($pdo);
$merchant = $auth['merchant'] ?? null;

// If no merchant auth → default to merchant_id = 1
$merchantId = $merchant['id'] ?? 1;

log_event("payments.php merchant_detected", $merchantId);

// ------------------------------------------------------------
// Query last 50 payments for this merchant
// ------------------------------------------------------------
try {

    $stmt = $pdo->prepare("
        SELECT 
            p.reference_id,
            p.order_id,
            p.session_id,
            p.amount,
            p.currency,
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

    $stmt->execute([
        'mid' => $merchantId
    ]);

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

