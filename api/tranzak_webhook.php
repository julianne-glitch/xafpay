<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/send_email.php';   // ⭐ EMAIL MODULE

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

// XP021 structure
$payload = $data['resource'] ?? $data['data'] ?? $data ?? null;

if (!$payload) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid payload']);
    exit;
}

// ------------------------------------------------------------
// Extract fields
// ------------------------------------------------------------
$txId     = $payload['transactionId'] ?? null;
$orderRef = $payload['mchTransactionRef']
         ?? $payload['merchantTransactionRef']
         ?? null;

$statusRaw = strtolower(trim(
    $payload['transactionStatus']
    ?? $payload['paymentStatus']
    ?? $payload['status']
    ?? ''
));

// Normalize status
$status = match ($statusRaw) {
    'success','successful','completed' => 'successful',
    'failed','error'                   => 'failed',
    'canceled','cancelled'             => 'canceled',
    'expired'                          => 'expired',
    default                             => 'pending',
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
// Log webhook payload
// ------------------------------------------------------------
try {
    $stmt = $pdo->prepare("
        INSERT INTO webhooks (reference_id, payload)
        VALUES (:ref, :payload)
    ");
    $stmt->execute([
        ':ref'     => $orderRef,
        ':payload' => json_encode($payload)
    ]);
} catch (Throwable $e) {}

// ------------------------------------------------------------
// Get customer email + amount
// ------------------------------------------------------------
$stmt = $pdo->prepare("
    SELECT email, amount, phone_number 
    FROM sessions
    WHERE order_id = :oid
    LIMIT 1
");
$stmt->execute([':oid' => $orderRef]);
$session = $stmt->fetch();

$customerEmail = $session['email'] ?? null;
$customerPhone = $session['phone_number'] ?? null;
$amount        = $session['amount'] ?? 0;

// ------------------------------------------------------------
// Update payments
// ------------------------------------------------------------
$pdo->prepare("
    UPDATE payments
    SET status = :st,
        transaction_id = :tx,
        response_payload = :payload,
        updated_at = NOW()
    WHERE reference_id = :ref
")->execute([
    ':st'      => $status,
    ':tx'      => $txId,
    ':payload' => json_encode($payload),
    ':ref'     => $orderRef
]);

// ------------------------------------------------------------
// Update session
// ------------------------------------------------------------
$pdo->prepare("
    UPDATE sessions
    SET status = :st,
        updated_at = NOW()
    WHERE order_id = :ref
")->execute([
    ':st'  => $status,
    ':ref' => $orderRef
]);

// ------------------------------------------------------------
// SEND EMAIL ONLY WHEN SUCCESSFUL
// ------------------------------------------------------------
if ($status === 'successful' && $customerEmail) {
    send_receipt_email($customerEmail, $orderRef, $amount, $customerPhone);

    file_put_contents(
        $logFile,
        date('c') . " EMAIL SENT TO $customerEmail" . PHP_EOL,
        FILE_APPEND
    );
}

// ------------------------------------------------------------
// Success response (required by Tranzak)
// ------------------------------------------------------------
http_response_code(200);
echo json_encode(['ok' => true, 'status' => $status]);
exit;

?>
