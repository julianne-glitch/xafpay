<?php
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json');

$file = __DIR__ . '/../../logs/security.log';
if (!file_exists($file)) json_out(['ok'=>true,'data'=>[]]);

$lines = array_slice(file($file), -100);
json_out(['ok'=>true,'data'=>$lines]);
