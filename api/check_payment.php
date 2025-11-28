<?php
// api/check_payment.php

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

if (!isset($_GET['order_id'])) {
    echo json_encode(['ok' => false, 'error' => 'order_id required']);
    exit;
}

$orderId = trim($_GET['order_id']);

try {
    $pdo = db_connect();

    // 1) First check payments table
    $stmt = $pdo->prepare("
        SELECT status 
        FROM payments 
        WHERE reference_id = :ref
        ORDER BY updated_at DESC
        LIMIT 1
    ");
    $stmt->execute([':ref' => $orderId]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($payment) {
        echo json_encode([
            'ok' => true,
            'source' => 'payments',
            'status' => strtolower($payment['status']),
            'order_id' => $orderId
        ]);
        exit;
    }

    // 2) Fall back to sessions table
    $stmt = $pdo->prepare("
        SELECT status
        FROM sessions
        WHERE order_id = :ref
        ORDER BY updated_at DESC
        LIMIT 1
    ");
    $stmt->execute([':ref' => $orderId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($session) {
        echo json_encode([
            'ok' => true,
            'source' => 'sessions',
            'status' => strtolower($session['status']),
            'order_id' => $orderId
        ]);
        exit;
    }

    // If nothing found
    echo json_encode([
        'ok' => false,
        'status' => 'unknown',
        'order_id' => $orderId
    ]);

} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'error' => 'DB error: ' . $e->getMessage()
    ]);
}
