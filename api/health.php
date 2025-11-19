<?php

require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/../config.php';   // json_out(), app_env()

log_event("HEALTH_CHECK_HIT", $_SERVER['REMOTE_ADDR'] ?? 'unknown');

// -------------------------------------------------------------
// CORS (required for React / WooCommerce / Webhooks)
// -------------------------------------------------------------
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// -------------------------------------------------------------
// HEALTH RESPONSE
// (Simple + always JSON — safe for React fetch())
// -------------------------------------------------------------
json_out([
    'ok'      => true,
    'service' => 'XafPay API',
    'env'     => app_env(),
    'version' => '1.0.0',
    'time'    => gmdate('c'),
]);
