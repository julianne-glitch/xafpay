<?php
// ------------------------------------------------------------
// rotate_key.php — Admin-only merchant key rotation
// ------------------------------------------------------------

require_once __DIR__ . '/../logger.php';
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../_auth_admin.php';

log_event("ADMIN_KEY_ROTATION_ATTEMPT", [
    'admin_ip' => $_SERVER['REMOTE_ADDR'] ?? null,
    'payload'  => '[hidden for security]'
]);

// ------------------------------------------------------------
// CORS
// ------------------------------------------------------------
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ------------------------------------------------------------
// DB + Auth
// ------------------------------------------------------------
$pdo = db_connect();

// Only admin can rotate keys
$adminUser = require_admin($pdo);

// ------------------------------------------------------------
// Read and validate input
// ------------------------------------------------------------
$input = json_decode(file_get_contents('php://input'), true);

$merchantId = $input['merchant_id'] ?? null;
$type       = strtolower($input['type'] ?? 'secret');

// Validate merchant_id
if (!$merchantId || !preg_match('/^[a-f0-9\-]{6,}$/i', $merchantId)) {
    json_out(['ok' => false, 'error' => 'Valid merchant_id required'], 400);
}

// Validate rotation type
if (!in_array($type, ['secret', 'api'], true)) {
    json_out(['ok' => false, 'error' => 'type must be "secret" or "api"'], 400);
}

// ------------------------------------------------------------
// Generate new key
// ------------------------------------------------------------
if ($type === 'secret') {
    $newKey = bin2hex(random_bytes(32));   // 64-char secure secret
    $field  = 'secret_key';
} else {
    // API keys should be short and prefixed
    $newKey = 'xaf_' . bin2hex(random_bytes(12));
    $field  = 'api_key';
}

// ------------------------------------------------------------
// Update merchant securely
// ------------------------------------------------------------
$stmt = $pdo->prepare("
    UPDATE merchants
    SET {$field} = :k
    WHERE id = :id
    RETURNING merchant_name
");
$stmt->execute([
    'k'  => $newKey,
    'id' => $merchantId
]);

$merchantName = $stmt->fetchColumn();

if (!$merchantName) {
    json_out(['ok' => false, 'error' => 'Merchant not found'], 404);
}

// ------------------------------------------------------------
// Admin audit log
// ------------------------------------------------------------
$pdo->prepare("
    INSERT INTO admin_actions (admin_user, action, target, details)
    VALUES (:admin, 'rotate_key', :target, :details)
")->execute([
    'admin'  => $adminUser,
    'target' => $merchantName,
    'details'=> "Rotated {$field}"
]);

log_event("ADMIN_KEY_ROTATION_SUCCESS", [
    'admin'    => $adminUser,
    'merchant' => $merchantName,
    'rotated'  => $field
]);

// ------------------------------------------------------------
// Final Response
// ------------------------------------------------------------
json_out([
    'ok'      => true,
    'message' => "{$field} rotated for {$merchantName}",
    'new_key' => $newKey  // returned ONLY to admin
]);
