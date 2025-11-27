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
//log php error
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

// 5) Extract basic fields (you’ll confirm names from the log file)
$status = $data['status']           ?? $data['txStatus']        ?? null;
$ref    = $data['mchTransactionRef']?? $data['merchantRef']     ?? null;
$amount = $data['amount']           ?? null;

// TODO (later): update your DB here once we know the exact field names
// Example sketch:
//
// if ($status === 'SUCCESS' || $status === 'SUCCESSFUL') {
//     try {
//         $pdo = db_connect();
//         $stmt = $pdo->prepare("UPDATE orders SET status = 'paid', paid_at = NOW()
//                                WHERE external_ref = :ref");
//         $stmt->execute(['ref' => $ref]);
//     } catch (Throwable $e) {
//         file_put_contents($logFile,
//             date('c') . " | DB ERROR: " . $e->getMessage() . PHP_EOL,
//             FILE_APPEND
//         );
//     }
// }

http_response_code(200);
echo json_encode(['ok' => true]);
