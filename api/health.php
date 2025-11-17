<?php
// ---------------------------------------------
// CORS (required for React / WooCommerce plugin)
// ---------------------------------------------
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ---------------------------------------------
// Existing health payload (kept exactly as you wrote)
// ---------------------------------------------
json_out([
    'ok'      => true,
    'service' => 'XafPay API',
    'env'     => app_env(),
    'time'    => gmdate('c'),
]);
