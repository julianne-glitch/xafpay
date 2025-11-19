<?php

// ------------------------------------------------------------
// LOGGER
// ------------------------------------------------------------
require_once __DIR__ . '/logger.php';

// Only log username (never password)
log_event("ADMIN_LOGIN_ATTEMPT", [
    'username' => $_POST['username'] ?? null
]);

// ------------------------------------------------------------
// CORS
// ------------------------------------------------------------
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ------------------------------------------------------------
// CONFIG + DB
// ------------------------------------------------------------
require_once __DIR__ . '/../../config.php';
$pdo = db_connect();

define('ADMIN_JWT_SECRET', hmac_secret()); // reuse global or separate admin secret

// ------------------------------------------------------------
// 1️⃣ Read input
// ------------------------------------------------------------
$input    = json_decode(file_get_contents('php://input'), true);
$username = trim($input['username'] ?? '');
$password = trim($input['password'] ?? '');

if (!$username || !$password) {
    log_event("ADMIN_LOGIN_FAILED", "Missing credentials");
    json_out(['ok' => false, 'error' => 'Missing credentials'], 400);
}

// ------------------------------------------------------------
// 2️⃣ Fetch user record
// ------------------------------------------------------------
$stmt = $pdo->prepare("
    SELECT id, username, password_hash, role
    FROM admin_users
    WHERE username = :u
    LIMIT 1
");
$stmt->execute(['u' => $username]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    log_event("ADMIN_LOGIN_FAILED", "User not found: $username");
    json_out(['ok' => false, 'error' => 'Invalid credentials'], 401);
}

// ------------------------------------------------------------
// 3️⃣ Verify password
// ------------------------------------------------------------
if (!password_verify($password, $user['password_hash'])) {
    log_event("ADMIN_LOGIN_FAILED", "Wrong password: $username");
    json_out(['ok' => false, 'error' => 'Invalid credentials'], 401);
}

// ------------------------------------------------------------
// 4️⃣ Create SIGNED TOKEN (HMAC-Protected)
// ------------------------------------------------------------
$tokenData = [
    'uid'  => $user['id'],
    'user' => $user['username'],
    'role' => $user['role'],
    'exp'  => time() + 3600 // 1 hour
];

$json = json_encode($tokenData);

// secure signature
$signature = hash_hmac('sha256', $json, ADMIN_JWT_SECRET);

// final token structure
$token = base64_encode($json) . "." . $signature;

log_event("ADMIN_LOGIN_SUCCESS", [
    'uid'  => $user['id'],
    'user' => $user['username'],
    'role' => $user['role']
]);

// ------------------------------------------------------------
// 5️⃣ Return response
// ------------------------------------------------------------
json_out([
    'ok'    => true,
    'token' => $token,
    'user'  => [
        'username' => $user['username'],
        'role'     => $user['role']
    ]
]);
