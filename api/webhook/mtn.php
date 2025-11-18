<?php
require_once __DIR__ . '/../logger.php';
log_event("status.php started", $_GET);

// For later when you register callbacks with MTN
http_response_code(200);
echo 'ok';
