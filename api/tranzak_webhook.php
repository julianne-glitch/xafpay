<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/logger.php';

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-API-KEY, X-SIGNATURE, X-TIMESTAMP");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}


// RAW BODY
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

// Log everything
$logFile = __DIR__ . '/../tranzak_webhook.log';
file_put_contents($logFile, date('c') . " RAW: " . $raw . PHP_EOL, FILE_APPEND);


// ------------------------------------------------------------
// 1️⃣ Handle both formats: {data:{...}} or {...}
// ------------------------------------------------------------
$payload = $data['data'] ?? $data ?? null;

if (!$payload) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid payload']);
    exit;
}


// ------------------------------------------------------------
// 2️⃣ Extract required fields
// ------------------------------------------------------------
$txId      = $payload['transactionId']     ?? null;
$orderRef  = $payload['mchTransactionRef'] ?? null;
$statusRaw = strtolower($payload['transactionStatus'] ?? $payload['status'] ?? '');
$amount    = $payload['amount']            ?? null;

if (!$txId || !$orderRef || !$statusRaw) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing fields']);
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

file_put_contents(
    $logFile,
    date('c') . " REF:{$orderRef} TX:{$txId} STATUS:$status" . PHP_EOL,
    FILE_APPEND
);


// ------------------------------------------------------------
// 3️⃣ DB CONNECT
// ------------------------------------------------------------
try {
    $pdo = db_connect();
} catch (Throwable $e) {
    file_put_contents($logFile, date('c') . " DB ERROR: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB error']);
    exit;
}


// ------------------------------------------------------------
// 4️⃣ Log webhook always
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
    // Do not stop processing
}


// ------------------------------------------------------------
// 5️⃣ Check if already final (idempotency)
// ------------------------------------------------------------
$stmt = $pdo->prepare("SELECT status FROM payments WHERE reference_id = :ref LIMIT 1");
$stmt->execute([':ref' => $orderRef]);
$current = $stmt->fetch();

if ($current && in_array($current['status'], ['successful', 'failed', 'canceled', 'expired'])) {
    http_response_code(200);
    echo json_encode(['ok' => true, 'idempotent' => true]);
    exit;
}


// ------------------------------------------------------------
// 6️⃣ Update payments
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
// 7️⃣ Update session
// ------------------------------------------------------------
try {
    $stmt = $pdo->prepare("
        UPDATE sessions
        SET status = :st,
            updated_at = NOW()
        WHERE order_id = :ref
    ");
    $stmt->execute([':st' => $status, ':ref' => $orderRef]);
} catch (Throwable $e) {
    // continue
}


// ------------------------------------------------------------
// 8️⃣ Final Response
// ------------------------------------------------------------
http_response_code(200);
echo json_encode(['ok' => true, 'status' => $status]);
exit;

