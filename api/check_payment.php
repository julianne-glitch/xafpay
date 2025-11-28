<?php
// api/check_payment.php

require_once __DIR__ . '/../config.php';

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-API-KEY, X-SIGNATURE, X-TIMESTAMP");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$orderId = $_GET['order_id'] ?? null;

if (!$orderId) {
    echo json_encode(['ok' => false, 'error' => 'order_id required']);
    exit;
}

try {
    $pdo = db_connect();

    // ---------------------------------------------------------
    // 1️⃣ FIRST: PAYMENTS TABLE (most accurate source of truth)
    // ---------------------------------------------------------
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
        $st = strtolower($payment['status']);  // pending | successful | failed | etc.

        echo json_encode([
            'ok'       => true,
            'source'   => 'payments',
            'status'   => $st,
            'order_id' => $orderId
        ]);
        exit;
    }

    // ---------------------------------------------------------
    // 2️⃣ SECOND: SESSIONS TABLE (fallback result)
    // ---------------------------------------------------------
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
        $sessionStatus = strtolower($session['status']);

        // Normalize session vocabulary to payment vocabulary
        $normalized = match ($sessionStatus) {
            'completed', 'success', 'successful' => 'successful',
            'failed', 'error'                    => 'failed',
            default                              => 'pending'
        };

        echo json_encode([
            'ok'       => true,
            'source'   => 'sessions',
            'status'   => $normalized,
            'order_id' => $orderId
        ]);
        exit;
    }

    // ---------------------------------------------------------
    // 3️⃣ If neither table has the order
    // ---------------------------------------------------------
    echo json_encode([
        'ok'       => false,
        'status'   => 'unknown',
        'order_id' => $orderId
    ]);

} catch (Throwable $e) {

    echo json_encode([
        'ok'    => false,
        'error' => 'DB error: ' . $e->getMessage()
    ]);
}
