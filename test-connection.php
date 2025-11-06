<?php
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

try {
    $pdo = db_connect();
    echo json_encode([
        'status' => 'ok',
        'message' => '✅ Connected to the database successfully!',
        'time' => date('c')
    ]);
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => '❌ Connection failed: ' . $e->getMessage(),
        'time' => date('c')
    ]);
}
