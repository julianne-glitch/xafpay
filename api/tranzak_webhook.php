<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/logger.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// RAW BODY
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

// Log incoming webhook
$logFile = __DIR__ . '/../tranzak_webhook.log';
file_put_contents($logFile, date('c') . " | RAW: " . $raw . PHP_EOL, FILE_APPEND);

// ------------------------------------------------------------
// 1️⃣ Validate JSON payload
// ------------------------------------------------------------
if (!$data || empty($data['data'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid payload']);
    exit;
}

$payload = $data['data'];

// ------------------------------------------------------------
// 2️⃣ Extract required fields
// ------------------------------------------------------------
$txId      = $payload['transactionId']        ?? null;
$orderRef  = $payload['mchTransactionRef']    ?? null;
$statusRaw = strtolower($payload['transactionStatus'] ?? $payload['status'] ?? '');
$amount    = $payload['amount']               ?? null;

// Required
if (!$txId || !$orderRef || !$statusRaw) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
    exit;
}

// Normalize status
$status = match ($statusRaw) {
    'completed', 'success', 'successful' => 'successful',
    'failed', 'error'                    => 'failed',
    'canceled', 'cancelled'              => 'canceled',
    'expired'                            => 'expired',
    default                              => 'pending',
};

// Log status
file_put_contents(
    $logFile,
    date('c') . " | STATUS: $status | REF: $orderRef | TX: $txId" . PHP_EOL,
    FILE_APPEND
);

// ------------------------------------------------------------
// 3️⃣ DB CONNECTION
// ------------------------------------------------------------
try {
    $pdo = db_connect();
} catch (Throwable $e) {
    file_put_contents($logFile, date('c') . " | DB CONNECT ERROR: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection error']);
    exit;
}

// ------------------------------------------------------------
// 4️⃣ Insert webhook into webhooks table (ALWAYS)
// ------------------------------------------------------------
try {
    $stmt = $pdo->prepare("
        INSERT INTO webhooks (reference_id, payload)
        VALUES (:ref, :payload)
    ");
    $stmt->execute([
        ':ref'     => $orderRef,
        ':payload' => json_encode($data)
    ]);
} catch (Throwable $e) {
    // still continue — webhook LOGGING should never block updates
    file_put_contents($logFile, date('c') . " | WEBHOOK LOG ERROR: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
}

// ------------------------------------------------------------
// 5️⃣ Idempotency — check if payment is already final
// ------------------------------------------------------------
$stmt = $pdo->prepare("SELECT status FROM payments WHERE reference_id = :ref LIMIT 1");
$stmt->execute([':ref' => $orderRef]);
$current = $stmt->fetch();

if ($current && in_array($current['status'], ['successful', 'failed', 'canceled', 'expired'])) {
    // Already processed
    http_response_code(200);
    echo json_encode(['ok' => true, 'idempotent' => true]);
    exit;
}

// ------------------------------------------------------------
// 6️⃣ Update payments table
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
        ':payload' => json_encode($data),
        ':txid'    => $txId,
        ':ref'     => $orderRef
    ]);
} catch (Throwable $e) {
    file_put_contents($logFile, date('c') . " | PAYMENT UPDATE ERROR: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Payment update failed']);
    exit;
}

// ------------------------------------------------------------
// 7️⃣ Update session table (use order_id)
// ------------------------------------------------------------
try {
    $stmt = $pdo->prepare("
        UPDATE sessions
        SET status = :st,
            updated_at = NOW()
        WHERE order_id = :ref
    ");
    $stmt->execute([
        ':st'  => $status,
        ':ref' => $orderRef
    ]);
} catch (Throwable $e) {
    file_put_contents($logFile, date('c') . " | SESSION UPDATE ERROR: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
}

// ------------------------------------------------------------
// 8️⃣ Success response
// ------------------------------------------------------------
http_response_code(200);
echo json_encode(['ok' => true, 'status' => $status]);
exit;
