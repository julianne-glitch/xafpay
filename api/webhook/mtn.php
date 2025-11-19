<?php
// ------------------------------------------------------------
//  MTN CALLBACK RECEIVER (logs everything, always responds 200)
// ------------------------------------------------------------
require_once __DIR__ . '/logger.php';

// Log arrival
log_event("mtn.php reached", [
    'GET'  => $_GET,
    'POST' => $_POST,
    'headers' => getallheaders()
]);

// Log raw body (VERY important for MTN)
$raw = file_get_contents("php://input");
log_event("mtn.php raw_body", $raw);

// MTN requires 200 OK always
http_response_code(200);
echo "ok";
