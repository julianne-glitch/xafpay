<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/logger.php';

// ------------------------------------------------------------
// CORS
// ------------------------------------------------------------
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-API-KEY, X-SIGNATURE, X-TIMESTAMP");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ------------------------------------------------------------
// RAW INPUT
// ------------------------------------------------------------
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

// LOG RAW
$logFile = '/tmp/tranzak_webhook.log';
file_put_contents($logFile, date('c') . " RAW: " . $raw . PHP_EOL, FILE_APPEND);

// XP021 → payload is under resource
$payload = $data['resource']
        ?? $data['data']
        ?? $data
        ?? null;

if (!$payload) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid payload']);
    exit;
}

// ------------------------------------------------------------
// Extract fields
// ------------------------------------------------------------

// For FAILED transactions, transactionId may be absent
$txId = $payload['transactionId']
    ?? $payload['txId']
    ?? null;

$orderRef = $payload['mchTransactionRef']
    ?? $payload['merchantTransactionRef']
    ?? null;

$statusRaw = strtolower(trim(
    $payload['transactionStatus']
    ?? $payload['paymentStatus']
    ?? $payload['status']
    ?? $payload['transaction_status']
    ?? ''
));

$amount = $payload['amount'] ?? null;
$errorCode = $payload['errorCode'] ?? null;
$errorMessage = $payload['errorMessage'] ?? null;

// TXID should NOT be required because FAILED transactions may not include it
if (!$orderRef || !$statusRaw) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing fields']);
    exit;
}

// ------------------------------------------------------------
// Normalize status
// ------------------------------------------------------------
$status = match ($statusRaw) {
    'completed', 'success', 'successful' => 'successful',
    'failed', 'error'                    => 'failed',
    'canceled', 'cancelled'              => 'canceled',
    'expired'                            => 'expired',
    default                              => 'pending',
};

file_put_contents(
    $logFile,
    date('c') . " REF:$orderRef TX:$txId STATUS:$status" . PHP_EOL,
    FILE_APPEND
);

// ------------------------------------------------------------
// DB CONNECT
// ------------------------------------------------------------
try {
    $pdo = db_connect();
} catch (Throwable $e) {
    file_put_contents($logFile, date('c') . " DB ERROR: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection error']);
    exit;
}

// ------------------------------------------------------------
// Insert into webhooks table (always)
// ------------------------------------------------------------
try {
    $stmt = $pdo->prepare("
        INSERT INTO webhooks (reference_id, payload)
        VALUES (:ref, :payload)
    ");
    $stmt->execute([
        ':ref'     => $orderRef,
        ':payload' => json_encode($payload),
    ]);
} catch (Throwable $e) {
    // continue even if webhook table insert fails
}

// ------------------------------------------------------------
// Check if payment already final (idempotency)
// ------------------------------------------------------------
$stmt = $pdo->prepare("
    SELECT status FROM payments WHERE reference_id = :ref LIMIT 1
");
$stmt->execute([':ref' => $orderRef]);
$current = $stmt->fetch();

if ($current && in_array($current['status'], ['successful', 'failed', 'canceled', 'expired'])) {
    http_response_code(200);
    echo json_encode(['ok' => true, 'idempotent' => true]);
    exit;
}

// ------------------------------------------------------------
// Ensure session exists (UPSERT)
// ------------------------------------------------------------
try {
    $stmt = $pdo->prepare("
        INSERT INTO sessions (order_id, status)
        VALUES (:ref, :st)
        ON DUPLICATE KEY UPDATE updated_at = NOW()
    ");
    $stmt->execute([':ref' => $orderRef, ':st' => $status]);
} catch (Throwable $e) {
    // continue
}

// ------------------------------------------------------------
// Update payments table
// ------------------------------------------------------------
try {
    $stmt = $pdo->prepare("
        UPDATE payments
        SET status = :st,
            response_payload = :payload,
            transaction_id = :txid,
            error_code = :errcode,
            error_message = :errmsg,
            updated_at = NOW()
        WHERE reference_id = :ref
    ");
    $stmt->execute([
        ':st'      => $status,
        ':payload' => json_encode($payload),
        ':txid'    => $txId,
        ':errcode' => $errorCode,
        ':errmsg'  => $errorMessage,
        ':ref'     => $orderRef,
    ]);
} catch (Throwable $e) {
    file_put_contents($logFile, date('c') . " PAYMENT UPDATE ERROR: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
}

// ------------------------------------------------------------
// Update session status
// ------------------------------------------------------------
try {
    $stmt = $pdo->prepare("
        UPDATE sessions
        SET status = :st, updated_at = NOW()
        WHERE order_id = :ref
    ");
    $stmt->execute([':st' => $status, ':ref' => $orderRef]);
} catch (Throwable $e) {
    // continue
}

// ------------------------------------------------------------
// Return success ALWAYS (required by Tranzak)
// ------------------------------------------------------------
http_response_code(200);
echo json_encode(['ok' => true, 'status' => $status]);
exit;

?>
