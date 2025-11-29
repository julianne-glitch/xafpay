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
    // 1️⃣ TRY PAYMENTS TABLE FIRST (source of truth)
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

        $rawStatus = strtolower(trim($payment['status'] ?? 'pending'));

        // Normalize all possible statuses to our gateway vocabulary
        $normalized = match ($rawStatus) {
            'success', 'successful', 'completed'          => 'successful',
            'failed', 'error', 'declined', 'canceled'     => 'failed',
            default                                       => 'pending',
        };

        echo json_encode([
            'ok'       => true,
            'source'   => 'payments',
            'status'   => $normalized,
            'order_id' => $orderId
        ]);
        exit;
    }

    // ---------------------------------------------------------
    // 2️⃣ FALLBACK → SESSIONS TABLE
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

        $rawStatus = strtolower(trim($session['status'] ?? 'pending'));

        // Normalize session vocab to payment vocab
        $normalized = match ($rawStatus) {
            'completed', 'success', 'successful'          => 'successful',
            'failed', 'error'                             => 'failed',
            default                                       => 'pending'
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
    // 3️⃣ ORDER DOES NOT EXIST ANYWHERE
    // ---------------------------------------------------------
    echo json_encode([
        'ok'       => false,
        'status'   => 'unknown',
        'order_id' => $orderId
    ]);
    exit;

} catch (Throwable $e) {
    echo json_encode([
        'ok'    => false,
        'error' => 'DB error: ' . $e->getMessage()
    ]);
}
