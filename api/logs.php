<?php
require_once __DIR__ . '/logger.php';
log_event("status.php started", $_GET);


header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../_auth.php'; // <-- IMPORTANT (admin protection)

$admin = require_admin(); 
// Now logs.php can ONLY be accessed by authenticated admin users.

$logFile = __DIR__ . '/../../logs/security.log';

// ----------------------------------------------------
// If log file does not exist, return empty list
// ----------------------------------------------------
if (!file_exists($logFile)) {
    json_out(['ok' => true, 'data' => []]);
}

// ----------------------------------------------------
// Read last 100 lines only
// ----------------------------------------------------
$lines = array_slice(@file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), -100);

// ----------------------------------------------------
// Return as JSON
// ----------------------------------------------------
json_out([
    'ok'   => true,
    'count'=> count($lines),
    'data' => $lines
]);
