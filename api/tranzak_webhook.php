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

$logFile = '/tmp/tranzak_webhook.log';
file_put_contents($logFile, date('c') . " RAW: " . $raw . PHP_EOL, FILE_APPEND);

// XP021 payload → under "resource"
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
$txId = $payload['transactionId'] ?? null;

$orderRef = $payload['mchTransactionRef']
    ?? $payload['merchantTransactionRef']
    ?? null;

$statusRaw = strtolower(trim(
    $payload['transactionStatus']
        ?? $payload['paymentStatus']
        ?? $payload['status']
        ?? ''
));

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
// Log webhook event
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
} catch (Throwable $e) {}

// ------------------------------------------------------------
// Check if payment already finalized
// ------------------------------------------------------------
$stmt = $pdo->prepare("
    SELECT status FROM payments WHERE reference_id = :ref LIMIT 1
");
$stmt->execute([':ref' => $orderRef]);
$current = $stmt->fetch();

if ($current && in_array($current['status'], ['successful', 'failed', 'canceled', 'expired'])) {
    echo json_encode(['ok' => true, 'idempotent' => true]);
    exit;
}

// ------------------------------------------------------------
// PostgreSQL UPSERT for sessions
// ------------------------------------------------------------
try {
    $stmt = $pdo->prepare("
        INSERT INTO sessions (order_id, status)
        VALUES (:ref, :st)
        ON CONFLICT (order_id) DO UPDATE SET updated_at = NOW()
    ");
    $stmt->execute([':ref' => $orderRef, ':st' => $status]);
} catch (Throwable $e) {}

// ------------------------------------------------------------
// Update payments table (FINAL FIX – NO error_code columns)
// ------------------------------------------------------------
try {
    $stmt = $pdo->prepare("
        UPDATE payments
        SET status = :st,
            response_payload = :payload,
            transaction_id = :txid,
            updated_at = NOW()
        WHERE reference_id = :ref
    ");
    $stmt->execute([
        ':st'      => $status,
        ':payload' => json_encode($payload),
        ':txid'    => $txId,
        ':ref'     => $orderRef,
    ]);
} catch (Throwable $e) {
    file_put_contents($logFile, date('c') . " PAYMENT UPDATE ERROR: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
}

// ------------------------------------------------------------
// Update session
// ------------------------------------------------------------
try {
    $stmt = $pdo->prepare("
        UPDATE sessions
        SET status = :st, updated_at = NOW()
        WHERE order_id = :ref
    ");
    $stmt->execute([':st' => $status, ':ref' => $orderRef]);
} catch (Throwable $e) {}

// ------------------------------------------------------------
// Success response (required by Tranzak)
// ------------------------------------------------------------
http_response_code(200);
echo json_encode(['ok' => true, 'status' => $status]);
exit;

?>
