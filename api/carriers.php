<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/logger.php';

log_event("carriers.php accessed", $_GET);

// --------------------------------------------
// CORS — required for React Apps
// --------------------------------------------
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header('Content-Type: application/json');

try {
    $pdo = db_connect();

    $stmt = $pdo->query("
        SELECT id, carrier_code, carrier_name, is_active
        FROM carriers
        ORDER BY carrier_name ASC
    ");

    $carriers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    json_out([
        'ok'   => true,
        'data' => $carriers
    ]);

} catch (Throwable $e) {

    log_event("carriers.php error", $e->getMessage());

    json_out([
        'ok'      => false,
        'error'   => 'Internal server error',
        'details' => $e->getMessage()
    ], 500);
}
