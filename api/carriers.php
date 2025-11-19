<?php

require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/../config.php';

log_event("CARRIERS_ENDPOINT_HIT", $_GET);

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

$pdo = db_connect();

// ------------------------------------------------------------
// Fetch carriers
// ------------------------------------------------------------
try {

    // ⚠️ DO NOT EXPOSE api_user or api_key in public API responses
    $stmt = $pdo->query("
        SELECT 
            id,
            name,
            code,
            merchant_number
        FROM carriers
        ORDER BY id ASC
    ");

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    json_out([
        'ok'   => true,
        'data' => $rows
    ]);

} catch (Throwable $e) {

    log_event("CARRIERS_QUERY_ERROR", $e->getMessage());

    json_out([
        'ok'    => false,
        'error' => 'Database error: ' . $e->getMessage()
    ], 500);
}
