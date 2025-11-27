<?php
// api/tranzak_webhook.php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// 1) Grab raw body + headers
$raw     = file_get_contents('php://input');
$headers = function_exists('getallheaders') ? getallheaders() : [];

// 2) Basic logging so we can see what Tranzak sends
$logLine = date('c') . " | HEADERS: " . json_encode($headers) . " | BODY: " . $raw . PHP_EOL;

// Make sure this path exists and is writable, or change it
$logFile = __DIR__ . '/../tranzak_webhook.log';
file_put_contents($logFile, $logLine, FILE_APPEND);
// log into PHP error log as well
error_log('[TranzakWebhook] ' . $logLine);

// 3) Decode JSON
$data = json_decode($raw, true);
if (!$data) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

// 4) OPTIONAL: verify webhook secret if you set TRANZAK_WEBHOOK_SECRET
$cfg    = tranzak_cfg();
$secret = $cfg['webhookSecret'];   // from TRANZAK_WEBHOOK_SECRET in .env (can be empty in sandbox)

if (!empty($secret)) {
    $incomingKey =
        $headers['x-auth-key'] ??
        $headers['X-Auth-Key'] ??
        $headers['x-authkey']   ??
        null;

    if ($incomingKey !== $secret) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Invalid webhook key']);
        exit;
    }
}

// 5️⃣ Extract useful fields from Tranzak payload
$resource = $data['resource'] ?? [];

$status   = $resource['status']
         ?? $resource['transactionStatus']
         ?? null;

$orderRef = $resource['mchTransactionRef'] ?? null;      // e.g. TEST-1764251365
$txId     = $resource['transactionId']     ?? null;      // e.g. TX2511271351C8662OOP
$amount   = $resource['amount']            ?? 0;

// 6️⃣ Save full webhook payload (for audit)
try {
    $pdo = db_connect();

    $stmt = $pdo->prepare("
        INSERT INTO webhooks (reference_id, payload)
        VALUES (:ref, :payload)
    ");
    $stmt->execute([
        ':ref'     => $orderRef ?: $txId ?: 'unknown',
        ':payload' => json_encode($data),
    ]);
} catch (Throwable $e) {
    file_put_contents(
        $logFile,
        date('c') . " | DB INSERT ERROR: " . $e->getMessage() . PHP_EOL,
        FILE_APPEND
    );
}

// 7️⃣ Update payments + sessions if successful
if (in_array(strtoupper($status), ['SUCCESS', 'SUCCESSFUL', 'COMPLETED'])) {
    try {
        // Update payments table
        $stmt = $pdo->prepare("
            UPDATE payments 
            SET status = 'successful',
                reference_id = :tx,
                response_payload = :payload,
                updated_at = NOW()
            WHERE reference_id = :ref
               OR session_id IN (
                    SELECT id FROM sessions WHERE order_id = :ref
               )
        ");
        $stmt->execute([
            ':tx'      => $txId,
            ':payload' => json_encode($data),
            ':ref'     => $orderRef,
        ]);

        // Optionally also mark sessions as completed
        $stmt2 = $pdo->prepare("
            UPDATE sessions
            SET status = 'completed',
                updated_at = NOW()
            WHERE order_id = :orderRef
        ");
        $stmt2->execute([':orderRef' => $orderRef]);

        error_log("[TranzakWebhook] ✅ Payment marked successful for $orderRef ($txId)");
    } catch (Throwable $e) {
        file_put_contents(
            $logFile,
            date('c') . " | DB UPDATE ERROR: " . $e->getMessage() . PHP_EOL,
            FILE_APPEND
        );
    }
}

http_response_code(200);
echo json_encode(['ok' => true]);
