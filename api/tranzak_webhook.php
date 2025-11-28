<?php
// api/tranzak_webhook.php

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

// Log all webhook data
$logFile = __DIR__ . '/../tranzak_webhook.log';
file_put_contents($logFile, date('c') . " | RAW: " . $raw . PHP_EOL, FILE_APPEND);

// Validate body
if (!$data || empty($data['data'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid payload']);
    exit;
}

$payload = $data['data'];

$status    = strtoupper($payload['transactionStatus'] ?? $payload['status'] ?? '');
$orderRef  = $payload['mchTransactionRef'] ?? null;
$txId      = $payload['transactionId'] ?? null;
$amount    = $payload['amount'] ?? 0;
$requestId = $payload['requestId'] ?? null;

try {
    $pdo = db_connect();

    // 1) Store webhook event in "webhooks" table
    $stmt = $pdo->prepare("
        INSERT INTO webhooks (reference_id, payload)
        VALUES (:ref, :payload)
    ");
    $stmt->execute([
        ':ref'     => $orderRef ?? 'unknown',
        ':payload' => json_encode($data),
    ]);

    // 2) Update payments table
    if (in_array($status, ['SUCCESS', 'SUCCESSFUL', 'COMPLETED'])) {

        $stmt = $pdo->prepare("
            UPDATE payments
            SET status = 'successful',
                response_payload = :payload,
                updated_at = NOW()
            WHERE reference_id = :ref
        ");
        $stmt->execute([
            ':payload' => json_encode($data),
            ':ref'     => $orderRef,
        ]);

        // 3) Update sessions table
        $stmt = $pdo->prepare("
            UPDATE sessions
            SET status = 'completed',
                updated_at = NOW()
            WHERE order_id = :ref
        ");
        $stmt->execute([':ref' => $orderRef]);

        error_log("[TranzakWebhook] SUCCESSFUL PAYMENT for $orderRef ($txId)");

    } else if (in_array($status, ['FAILED', 'ERROR'])) {

        $stmt = $pdo->prepare("
            UPDATE payments
            SET status = 'failed',
                response_payload = :payload,
                updated_at = NOW()
            WHERE reference_id = :ref
        ");
        $stmt->execute([
            ':payload' => json_encode($data),
            ':ref'     => $orderRef,
        ]);

        // mark session failed
        $stmt = $pdo->prepare("
            UPDATE sessions
            SET status = 'failed',
                updated_at = NOW()
            WHERE order_id = :ref
        ");
        $stmt->execute([':ref' => $orderRef]);

        error_log("[TranzakWebhook] FAILED PAYMENT for $orderRef");
    }

} catch (Throwable $e) {

    file_put_contents(
        $logFile,
        date('c') . " | DB ERROR: " . $e->getMessage() . PHP_EOL,
        FILE_APPEND
    );

    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB error']);
    exit;
}

// FINAL RESPONSE
http_response_code(200);
echo json_encode(['ok' => true]);
