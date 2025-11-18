<?php
require_once __DIR__ . '/logger.php';
log_this("FILE_NAME.php called", ["request" => $_REQUEST]);


// Allow requests (React dashboard / merchant panel)
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_auth.php';   // merchant auth

$pdo = db_connect();
$merchant = require_merchant($pdo);

try {

    // ====================================================
    // GET /api/payments.php
    // List latest payments (limit 50)
    // ====================================================
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {

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
            ORDER BY p.created_at DESC
            LIMIT 50
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        json_out([
            'ok' => true,
            'data' => $rows
        ]);
    }

} catch (Throwable $e) {
    json_out([
        'ok' => false,
        'error' => $e->getMessage()
    ], 500);
}
