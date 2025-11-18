<?php

// ---------------------------------------------
// Safe Logger for Render
// ---------------------------------------------

function log_event($label, $data = null) {
    $logFile = '/tmp/xafpay.log';  // required on Render

    $timestamp = date('Y-m-d H:i:s');

    $line = "[$timestamp] $label";
    if ($data !== null) {
        $line .= " | " . json_encode($data, JSON_UNESCAPED_SLASHES);
    }
    $line .= PHP_EOL;

    file_put_contents($logFile, $line, FILE_APPEND);
}
