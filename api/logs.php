<?php

// ------------------------------------------------------------
// LOGGER
// ------------------------------------------------------------
require_once __DIR__ . '/logger.php';
log_event("ADMIN_LOG_VIEW_ATTEMPT", ['ip' => $_SERVER['REMOTE_ADDR'] ?? null]);

// ------------------------------------------------------------
// CORS
// ------------------------------------------------------------
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ------------------------------------------------------------
// CONFIG + AUTH
// ------------------------------------------------------------
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../_auth_admin.php';

$pdo = db_connect();
$admin = require_admin($pdo);   // only admin users allowed

// ------------------------------------------------------------
// Log file location (same as logger.php)
// ------------------------------------------------------------
$logFile = "/tmp/xafpay.log";

// If file doesn't exist → empty output
if (!file_exists($logFile)) {
    json_out(['ok' => true, 'count' => 0, 'data' => []]);
}

// ------------------------------------------------------------
// Efficiently read last 200 lines
// ------------------------------------------------------------
$fp = fopen($logFile, "r");
$bufferSize = 8192;
$lines = [];
$pos = -1;

fseek($fp, 0, SEEK_END);
$fileSize = ftell($fp);

while (count($lines) < 200 && -$pos < $fileSize) {
    $pos -= $bufferSize;
    fseek($fp, $pos, SEEK_END);

    $chunk = fread($fp, $bufferSize);
    $chunkLines = explode("\n", $chunk);

    foreach (array_reverse($chunkLines) as $line) {
        if (trim($line) !== '') {
            $lines[] = $line;
        }
        if (count($lines) >= 200) break;
    }
}

fclose($fp);

// ------------------------------------------------------------
// Return JSON
// ------------------------------------------------------------
json_out([
    'ok'    => true,
    'count' => count($lines),
    'data'  => array_reverse($lines)  // newest last for UI scrolling
]);
