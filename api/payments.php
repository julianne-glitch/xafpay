<?php
// ------------------------------------------------------------
// payments.php — List payments for merchant dashboard
// ------------------------------------------------------------

require_once __DIR__ . '/logger.php';
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
// Load config + optional auth
// ------------------------------------------------------------
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_auth.php';

$pdo = db_connect();

// ------------------------------------------------------------
// 1️⃣ Optional Merchant Authentication
// ------------------------------------------------------------
// If no headers → merchant = null → fallback to merchant_id=1
// If headers provided → authenticates properly
// NO MORE ERRORS
// ------------------------------------------------------------

$auth = optional_hmac_auth($pdo);
$merchant = $auth['merchant'] ?? null;

$merchantId = $merchant['id'] ?? 1;   // fallback merchant

log_event("payments.php merchant_detected", $merchantId);

// ------------------------------------------------------------
// 2️⃣ Fetch last 50 payments
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
        'ok' => true,
        'merchant_id' => $merchantId,
        'data' => $rows
    ]);

} catch (Throwable $e) {

    log_event("payments.php exception", $e->getMessage());

    json_out([
        'ok' => false,
        'error' => $e->getMessage()
    ], 500);
}
