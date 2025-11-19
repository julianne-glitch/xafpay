<?php

require_once __DIR__ . '/logger.php';
log_event("STATUS_CHECK_START", $_GET);

// -------------------------------------------------------------
// CORS
// -------------------------------------------------------------
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// -------------------------------------------------------------
// CONFIG + DB
// -------------------------------------------------------------
require_once __DIR__ . '/../config.php';
use GuzzleHttp\Client;

$cfg = mtn_cfg();
$ref = $_GET['ref'] ?? '';

if (!$ref) {
    json_out(['error' => 'ref required'], 400);
}

$pdo = db_connect();

// -------------------------------------------------------------
// 1️⃣ Fetch payment row
// -------------------------------------------------------------
$stmt = $pdo->prepare("SELECT * FROM payments WHERE reference_id = ?");
$stmt->execute([$ref]);
$payment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$payment) {
    json_out(['error' => 'Payment not found'], 404);
}

$orderId     = $payment['order_id'];
$sessionId   = $payment['session_id'];
$alreadySent = $payment['callback_sent'] ?? false;

// -------------------------------------------------------------
// 2️⃣ Get MTN Token
// -------------------------------------------------------------
try {
    $client = new Client(['base_uri' => $cfg['base'], 'timeout' => 20]);

    $resp = $client->post('/collection/token/', [
        'headers' => [
            'Ocp-Apim-Subscription-Key' => $cfg['subKey'],
            'Authorization'             => 'Basic ' . base64_encode($cfg['apiUser'] . ':' . $cfg['apiKey']),
        ],
    ]);

    $token = json_decode($resp->getBody(), true)['access_token'] ?? null;

    if (!$token) throw new Exception("Token missing");

} catch (Throwable $e) {
    json_out(['error' => 'MTN token error', 'details' => $e->getMessage()], 500);
}

// -------------------------------------------------------------
// 3️⃣ Query MTN for Status
// -------------------------------------------------------------
try {
    $resp = $client->get("/collection/v1_0/requesttopay/{$ref}", [
        'headers' => [
            'Authorization'             => "Bearer {$token}",
            'X-Target-Environment'      => $cfg['env'],
            'Ocp-Apim-Subscription-Key' => $cfg['subKey'],
        ],
    ]);

    $data = json_decode($resp->getBody(), true);
    $mtnStatus = strtoupper($data['status'] ?? 'UNKNOWN');

} catch (Throwable $e) {
    json_out(['error' => 'MTN status error', 'details' => $e->getMessage()], 500);
}

// -------------------------------------------------------------
// 4️⃣ Normalize status (store consistent format)
// -------------------------------------------------------------
$frontendStatus = match ($mtnStatus) {
    'SUCCESSFUL' => 'SUCCESS',
    'FAILED'     => 'FAILED',
    default      => 'PENDING',
};

// -------------------------------------------------------------
// 5️⃣ Update DB (normalized status only)
// -------------------------------------------------------------
$pdo->prepare("
    UPDATE payments
    SET status = :status, updated_at = NOW()
    WHERE reference_id = :ref
")->execute([
    'status' => $frontendStatus,
    'ref'    => $ref
]);

$pdo->prepare("
    UPDATE sessions
    SET status = :status, updated_at = NOW()
    WHERE id = :session
")->execute([
    'status'  => $frontendStatus,
    'session' => $sessionId
]);

// -------------------------------------------------------------
// 6️⃣ WooCommerce callback — send ONCE only
// -------------------------------------------------------------
if ($frontendStatus === 'SUCCESS' && !$alreadySent) {

    // Mark before sending to avoid double-callback race condition
    $pdo->prepare("
        UPDATE payments SET callback_sent = TRUE WHERE reference_id = ?
    ")->execute([$ref]);

    $signature = hash_hmac('sha256', $orderId . 'success', hmac_secret());

    $wcCallback = base_url()
        . "/wc-api/xafpay_callback?order_id={$orderId}&status=success&signature={$signature}";

    @file_get_contents($wcCallback);

    log_event("WC_CALLBACK_SENT", $wcCallback);
}

// -------------------------------------------------------------
// 7️⃣ Final Output
// -------------------------------------------------------------
json_out([
    'reference_id' => $ref,
    'status'       => $frontendStatus,
    'provider_raw' => $data
]);
