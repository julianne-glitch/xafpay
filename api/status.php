<?php

require_once __DIR__ . '/logger.php';
log_event("STATUS_CHECK_START", $_GET);

// -------------------------------------------------------------
// CORS
// -------------------------------------------------------------
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
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
    json_out(['ok' => false, 'error' => 'ref required'], 400);
}

$pdo = db_connect();

// -------------------------------------------------------------
// 1️⃣ Fetch payment
// -------------------------------------------------------------
$stmt = $pdo->prepare("SELECT * FROM payments WHERE reference_id = ?");
$stmt->execute([$ref]);
$payment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$payment) {
    json_out(['ok' => false, 'error' => 'Payment not found'], 404);
}

$orderId     = $payment['order_id'];
$sessionId   = $payment['session_id'];
$alreadySent = (bool)($payment['callback_sent'] ?? false);

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

    $json  = json_decode($resp->getBody(), true);
    $token = $json['access_token'] ?? null;

    if (!$token) {
        throw new Exception("Missing access_token from MTN");
    }

} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => 'MTN token error', 'details' => $e->getMessage()], 500);
}

// -------------------------------------------------------------
// 3️⃣ Query MTN API for Transaction Status
// -------------------------------------------------------------
try {
    $resp = $client->get("/collection/v1_0/requesttopay/{$ref}", [
        'headers' => [
            'Authorization'             => "Bearer {$token}",
            'X-Target-Environment'      => $cfg['env'],
            'Ocp-Apim-Subscription-Key' => $cfg['subKey'],
        ],
    ]);

    $data      = json_decode($resp->getBody(), true);
    $mtnStatus = strtoupper($data['status'] ?? 'UNKNOWN');

} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => 'MTN status error', 'details' => $e->getMessage()], 500);
}

// -------------------------------------------------------------
// 4️⃣ Normalize MTN → Frontend status
// ------------------------------------------------------
