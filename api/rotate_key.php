<?php
// ------------------------------------------------------------
// rotate_key.php — Secure admin-only key rotation
// ------------------------------------------------------------

// 1) Logger
require_once __DIR__ . '/../logger.php';
log_event("rotate_key.php called", file_get_contents("php://input"));

// 2) CORS for admin dashboard
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 3) Config
require_once __DIR__ . '/../../config.php';
$pdo = db_connect();

// 4) Authentication — IMPORTANT
require_once __DIR__ . '/../_auth_admin.php';
$adminUser = require_admin($pdo);  // returns admin username

// 5) Parse JSON
$input = json_decode(file_get_contents('php://input'), true);
$id   = $input['merchant_id'] ?? '';
$type = $input['type'] ?? 'secret';

if (!$id) {
    json_out(['ok'=>false,'error'=>'merchant_id required'],400);
}

// 6) Generate new key
$newKey = ($type === 'secret')
    ? bin2hex(random_bytes(16))              // 32-char
    : 'xaf_' . bin2hex(random_bytes(8));     // 4 bytes → prefixed API key

$field = ($type === 'secret') ? 'secret_key' : 'api_key';

// 7) Update merchant record
$stmt = $pdo->prepare("
    UPDATE merchants
    SET {$field} = :k
    WHERE id = :id
    RETURNING merchant_name
");
$stmt->execute(['k'=>$newKey, 'id'=>$id]);
$merchant = $stmt->fetchColumn();

if (!$merchant) {
    json_out(['ok'=>false,'error'=>'merchant not found'],404);
}

// 8) Insert audit log
$pdo->prepare("
    INSERT INTO admin_actions (admin_user, action, target, details)
    VALUES (:admin,'rotate_key',:target,:details)
")->execute([
    'admin'  => $adminUser,
    'target' => $merchant,
    'details'=> $field
]);

// 9) Final response
json_out([
    'ok'       => true,
    'message'  => "$field rotated for $merchant",
    'new_key'  => $newKey
]);
