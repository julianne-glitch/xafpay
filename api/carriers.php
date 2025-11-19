<?php
require_once __DIR__ . '/logger.php';
log_event("carriers.php accessed", $_GET);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../config.php';
$pdo = db_connect();

try {
    // Match your actual table structure
    $stmt = $pdo->query("
        SELECT 
            id,
            name,
            code,
            merchant_number,
            api_user,
            api_key
        FROM carriers
        ORDER BY id ASC
    ");

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    json_out([
        'ok' => true,
        'data' => $rows
    ]);

} catch (Throwable $e) {
    json_out([
        'ok' => false,
        'error' => $e->getMessage()
    ], 500);
}
