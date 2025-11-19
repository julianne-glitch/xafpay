<?php

require_once __DIR__ . '/api/logger.php';
require_once __DIR__ . '/config.php';

log_event("INDEX_HIT", $_SERVER['REMOTE_ADDR'] ?? 'unknown');

// -----------------------------------------
// CORS (safe for all clients)
// -----------------------------------------
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// -----------------------------------------
// Unified API root response
// -----------------------------------------
json_out([
    'ok'      => true,
    'service' => 'XafPay Gateway',
    'env'     => app_env(),
    'time'    => gmdate('c')
]);
