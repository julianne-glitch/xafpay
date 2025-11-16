<?php
// Allow requests from your React dev server
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Respond quickly to preflight checks
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(200);
  exit;
}

require_once __DIR__ . '/../config.php';
use GuzzleHttp\Client;

$cfg = mtn_cfg();
$ref = $_GET['ref'] ?? '';

if (!$ref) json_out(['ok' => false, 'error' => 'ref required'], 400);
if (!$cfg['subKey'] || !$cfg['apiUser'] || !$cfg['apiKey']) {
    json_out(['ok' => false, 'error' => 'MTN sandbox keys missing'], 500);
}

$pdo = db_connect();
$client = new Client(['base_uri' => $cfg['base'], 'timeout' => 20]);

// 1️⃣ Get MTN access token
try {
    $resp = $client->post('/collection/token/', [
        'headers' => [
            'Ocp-Apim-Subscription-Key' => $cfg['subKey'],
            'Authorization'             => 'Basic ' . base64_encode($cfg['apiUser'] . ':' . $cfg['apiKey']),
        ],
    ]);
    $token = json_decode((string)$resp->getBody(), true)['access_token'] ?? null;
    if (!$token) throw new Exception('No access_token');
} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => 'MTN token error', 'detail' => $e->getMessage()], 500);
}

// 2️⃣ Query MTN for payment status
try {
    $resp = $client->get("/collection/v1_0/requesttopay/{$ref}", [
        'headers' => [
            'Authorization'             => "Bearer {$token}",
            'X-Target-Environment'      => $cfg['env'],
            'Ocp-Apim-Subscription-Key' => $cfg['subKey'],
        ],
    ]);
    $data = json_decode((string)$resp->getBody(), true);
    $mtnStatus = strtoupper($data['status'] ?? 'UNKNOWN');
} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => 'MTN status error', 'detail' => $e->getMessage()], 500);
}

// 3️⃣ Update local payments table
try {
    $pdo->prepare("
        UPDATE payments 
        SET status = :status, updated_at = NOW()
        WHERE reference_id = :ref
    ")->execute([
        'status' => $mtnStatus,
        'ref'    => $ref,
    ]);
} catch (Throwable $e) {
    error_log("DB update error in status.php: " . $e->getMessage());
}

// 4️⃣ Return unified response to frontend
json_out([
    'ok'   => true,
    'data' => [
        'reference_id' => $ref,
        'status'       => $mtnStatus,
        'mtn'          => $data
    ]
]);
