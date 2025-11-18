<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/logger.php';
log_event("status.php started", $_GET);

// Enable errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

try {
    $pdo = db_connect();

    $stmt = $pdo->query("SELECT * FROM carriers");
    $carriers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($carriers);
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
