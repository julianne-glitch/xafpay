<?php
require_once __DIR__ . '/../config.php';

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

echo json_encode([
    'MTN_BASE_raw' => envv('MTN_BASE'),
    'MTN_BASE_visible' => json_encode(envv('MTN_BASE')),
    'MTN_BASE_length' => strlen(envv('MTN_BASE')),
]);
