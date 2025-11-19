<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/api/logger.php';

log_event("ENV_TEST_HIT", $_SERVER['REMOTE_ADDR'] ?? 'unknown');

// -------------------------------------------------------------
// CORS
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
// NEVER expose secrets in production
$exposeEnv = in_array(app_env(), ['local', 'dev', 'development'], true);

// -------------------------------------------------------------
// Database Test
// -------------------------------------------------------------
try {
    $pdo = db_connect();
    $carriers = $pdo->query("SELECT id, name, code FROM carriers LIMIT 5")->fetchAll();

    json_out([
        'ok' => true,
        'env' => app_env(),
        'db_test' => 'Connected successfully',
        'carriers' => $carriers,
        'env_vars' => $exposeEnv ? [
            'DB_HOST' => envv('DB_HOST'),
            'DB_USER' => envv('DB_USER'),
            'DB_PASS' => '[hidden]' // mask real password
        ] : '❌ Hidden in production mode'
    ]);

} catch (Throwable $e) {

    log_event("ENV_TEST_DB_ERROR", $e->getMessage());

    json_out([
        'ok' => false,
        'env' => app_env(),
        'error' => 'Database connection failed',
        'details' => $exposeEnv ? $e->getMessage() : 'Hidden in production for security'
    ], 500);
}
