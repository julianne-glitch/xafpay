<?php

// ------------------------------------------------------------
// Safe Logger for Admin Logs Viewer
// ------------------------------------------------------------
require_once __DIR__ . '/logger.php';

// Safe json_out fallback (in case parent forgot to load it)
if (!function_exists('json_out')) {
    function json_out($arr, $code = 200) {
        http_response_code($code);
        header("Content-Type: application/json");
        echo json_encode(
            $arr,
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }
}

log_event("ADMIN_LOG_VIEW_ATTEMPT", [
    'ip' => $_SERVER['REMOTE_ADDR'] ?? null
]);

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
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../_auth_admin.php';

$pdo = db();
$admin = require_admin($pdo);   // strict admin authentication

// ------------------------------------------------------------
// LOG FILE
// ------------------------------------------------------------
$logFile = "/tmp/xafpay.log";

if (!file_exists($logFile)) {
    json_out(['ok' => true, 'count' => 0, 'data' => []]);
}

// ------------------------------------------------------------
// Read last ~200 lines efficiently
// ------------------------------------------------------------
$fp = fopen($logFile, "r");
$bufferSize = 8192; // 8KB sliding window
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
// JSON output
// ------------------------------------------------------------
json_out([
    'ok'    => true,
    'count' => count($lines),
    'data'  => array_reverse($lines) // newest LAST for UI
]);
