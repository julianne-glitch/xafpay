<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/api/logger.php';

log_event("TEST_USER_HIT", $_SERVER['REMOTE_ADDR'] ?? 'unknown');

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
// Environment Visibility Control
// -------------------------------------------------------------
$debug = in_array(app_env(), ['local', 'dev', 'development'], true);

// -------------------------------------------------------------
// DB check
// -------------------------------------------------------------
try {
    $pdo = db_connect();

    $row = $pdo->query("
        SELECT current_database() AS database,
               current_user      AS db_user;
    ")->fetch(PDO::FETCH_ASSOC);

    json_out([
        'ok'       => true,
        'env'      => app_env(),
        'database' => $row['database'],
        'db_user'  => $row['db_user'],
        'debug'    => $debug ? 'Debug details enabled' : 'Debug disabled'
    ]);

} catch (Throwable $e) {

    log_event("TEST_USER_DB_ERROR", $e->getMessage());

    json_out([
        'ok'   => false,
        'env'  => app_env(),
        'error' => 'Database connection failed',
        'details' => $debug ? $e->getMessage() : 'Hidden for security'
    ], 500);
}
