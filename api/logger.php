<?php

// ------------------------------------------------------------
// SECURE EVENT LOGGER (Safe for Render & Local Development)
// ------------------------------------------------------------
// Features:
//  - Always logs (never throws fatal errors)
//  - UTF-8 safe
//  - 1MB auto-rotate − prevents giant log files on Render
//  - No JSON encoding failures
// ------------------------------------------------------------

function log_event($label, $data = null) {
    $logFile = '/tmp/xafpay.log';
    $maxSize = 1024 * 1024; // 1MB rotate threshold
    $timestamp = date('Y-m-d H:i:s');

    // ------------------------------------------------------------
    // Prepare UTF-8 safe JSON encoding
    // ------------------------------------------------------------
    if ($data !== null) {
        try {
            $json = json_encode(
                $data,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        } catch (Throwable $e) {
            $json = '"[json encoding error]"';
        }
    } else {
        $json = '';
    }

    // Final log line
    $line = $json
        ? "[$timestamp] $label | $json" . PHP_EOL
        : "[$timestamp] $label" . PHP_EOL;

    // ------------------------------------------------------------
    // Ensure directory exists (Render uses /tmp, local uses real FS)
    // ------------------------------------------------------------
    $dir = dirname($logFile);

    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    // ------------------------------------------------------------
    // Auto-rotate if too large
    // ------------------------------------------------------------
    if (file_exists($logFile) && filesize($logFile) > $maxSize) {
        @rename($logFile, $logFile . '.' . time() . '.old');
    }

    // ------------------------------------------------------------
    // Write log safely (never crash)
    // ------------------------------------------------------------
    try {
        file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    } catch (Throwable $e) {
        // Absolute fallback (should never happen, but safe)
        error_log("XafPay LOGGER FAILURE: " . $e->getMessage());
    }
}
