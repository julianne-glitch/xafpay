<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config.php';
use GuzzleHttp\Client;

$cfg = mtn_cfg();
$ref = $_GET['ref'] ?? '';

if (!$ref) json_out(['error' => 'ref required'], 400);

$pdo = db_connect();

// ---------------------------------------------------
// 1️⃣ Fetch payment row to get order_id & session_id
// ---------------------------------------------------
$stmt = $pdo->prepare("SELECT * FROM payments WHERE reference_id = ?");
$stmt->execute([$ref]);
$payment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$payment) {
    json_out(['error' => 'Payment not found'], 404);
}

$orderId   = $payment['order_id'];
$sessionId = $payment['session_id'];

// ---------------------------------------------------
// 2️⃣ Get MTN access token
// ---------------------------------------------------
try {
    $client = new Client(['base_uri' => $cfg['base'], 'timeout' => 20]);
    $resp = $client->post('/collection/token/', [
        'headers' => [
            'Ocp-Apim-Subscription-Key' => $cfg['subKey'],
            'Authorization'             => 'Basic ' . base64_encode($cfg['apiUser'] . ':' . $cfg['apiKey']),
        ],
    ]);
    $token = json_decode($resp->getBody(), true)['access_token'] ?? null;

} catch (Throwable $e) {
    json_out(['error' => 'MTN token error', 'details' => $e->getMessage()], 500);
}

// ---------------------------------------------------
// 3️⃣ Check MTN payment status
// ---------------------------------------------------
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

// ---------------------------------------------------
// 4️⃣ Update payments table + sessions table
// ---------------------------------------------------
try {
    $pdo->prepare("
        UPDATE payments SET status = :status, updated_at = NOW()
        WHERE reference_id = :ref
    ")->execute([
        'status' => $mtnStatus,
        'ref'    => $ref
    ]);

    $pdo->prepare("
        UPDATE sessions SET status = :status, updated_at = NOW()
        WHERE id = :session
    ")->execute([
        'status'  => $mtnStatus,
        'session' => $sessionId
    ]);

} catch (Throwable $e) {
    error_log("DB update error: " . $e->getMessage());
}

// ---------------------------------------------------
// 5️⃣ Trigger WooCommerce callback IF SUCCESS
// ---------------------------------------------------
if ($mtnStatus === 'SUCCESS') {
    $wcCallback = base_url() . "/wc-api/xafpay_callback?order_id={$orderId}&status=success&signature="
        . hash_hmac('sha256', $orderId . 'success', hmac_secret());

    file_get_contents($wcCallback);
}

// ---------------------------------------------------
// 6️⃣ Return format MATCHING React expectations
// ---------------------------------------------------
json_out([
    'reference_id' => $ref,
    'status'       => $mtnStatus  // <-- React checks THIS directly
]);
