<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/api/logger.php';

log_event("DB_TEST_HIT", $_SERVER['REMOTE_ADDR'] ?? 'unknown');

// -------------------------------------------------------------
// CORS (safe for React / Admin dashboard)
// -------------------------------------------------------------
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// -------------------------------------------------------------
// DB Test
// -------------------------------------------------------------
try {
    $pdo = db_connect();

    json_out([
        'ok'      => true,
        'message' => '✅ Connected to PostgreSQL successfully!',
        'env'     => app_env(),
        'time'    => gmdate('c')
    ]);

} catch (Throwable $e) {

    log_event("DB_TEST_ERROR", $e->getMessage());

    json_out([
        'ok'      => false,
        'message' => '❌ Connection failed',
        'error'   => $e->getMessage(),
        'time'    => gmdate('c')
    ], 500);
}
