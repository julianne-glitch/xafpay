<?php
// ------------------------------------------------------------
// payments.php — List payments for merchant dashboard
// ------------------------------------------------------------

require_once __DIR__ . '/logger.php';

// Log entry
log_event("payments.php reached", [
    'GET'     => $_GET,
    'POST'    => $_POST,
    'headers' => getallheaders()
]);

// Log raw body (even if empty)
$raw = file_get_contents("php://input");
log_event("payments.php raw_body", $raw);

// ------------------------------------------------------------
// CORS for dashboard
// ------------------------------------------------------------
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ------------------------------------------------------------
// Load config + merchant auth
// ------------------------------------------------------------
require_once __DIR__ . '/../config.php';

// Authenticate merchant (THIS FIXES YOUR BUG)
try {
    $merchant = require __DIR__ . '/_auth.php';
} catch (Throwable $e) {
    log_event("payments.php auth_error", $e->getMessage());
    json_out(['ok' => false, 'error' => 'Unauthorized'], 401);
}

$pdo = db_connect();

// ------------------------------------------------------------
// Fetch last 50 payments
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
        'mid' => $merchant['id']
    ]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    log_event("payments.php result_count", count($rows));

    json_out([
        'ok' => true,
        'data' => $rows
    ]);

} catch (Throwable $e) {

    log_event("payments.php exception", $e->getMessage());

    json_out([
        'ok' => false,
        'error' => $e->getMessage()
    ], 500);
}
