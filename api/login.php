<?php

// ------------------------------------------------------------
// LOGGER
// ------------------------------------------------------------
require_once __DIR__ . '/logger.php';

// Only log username (NEVER log password)
log_event("ADMIN_LOGIN_ATTEMPT", [
    'username' => $_POST['username'] ?? null
]);

// ------------------------------------------------------------
// SAFE json_out fallback (in case parent didn't load it)
// ------------------------------------------------------------
if (!function_exists('json_out')) {
    function json_out($arr, $code = 200) {
        http_response_code($code);
        header("Content-Type: application/json");
        echo json_encode(
            $arr,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        exit;
    }
}

// ------------------------------------------------------------
// CORS
// ------------------------------------------------------------
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ------------------------------------------------------------
// CONFIG + DB
// ------------------------------------------------------------
require_once __DIR__ . '/../../config.php';

try {
    $pdo = db_connect();
} catch (Throwable $e) {
    log_event("ADMIN_DB_CONNECT_FAILED", $e->getMessage());
    json_out(['ok' => false, 'error' => 'Internal server error'], 500);
}

define('ADMIN_JWT_SECRET', hmac_secret()); // reuse global HMAC secret safely

// ------------------------------------------------------------
// 1️⃣ Read input
// ------------------------------------------------------------
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);

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
// 3️⃣ Verify password securely
// ------------------------------------------------------------
if (!password_verify($password, $user['password_hash'])) {
    log_event("ADMIN_LOGIN_FAILED", "Wrong password username=$username");
    json_out(['ok' => false, 'error' => 'Invalid credentials'], 401);
}

// ------------------------------------------------------------
// 4️⃣ Create SIGNED TOKEN (HMAC-Protected)
// ------------------------------------------------------------
$tokenData = [
    'uid'  => $user['id'],
    'user' => $user['username'],
    'role' => $user['role'],
    'exp'  => time() + 3600 // token expires in 1 hour
];

$json = json_encode(
    $tokenData,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);

$signature = hash_hmac('sha256', $json, ADMIN_JWT_SECRET);

// Format: base64(json).signature
$token = base64_encode($json) . "." . $signature;

log_event("ADMIN_LOGIN_SUCCESS", [
    'uid'  => $user['id'],
    'user' => $user['username'],
    'role' => $user['role']
]);

// ------------------------------------------------------------
// 5️⃣ Return Secure Response
// ------------------------------------------------------------
json_out([
    'ok'    => true,
    'token' => $token,
    'user'  => [
        'username' => $user['username'],
        'role'     => $user['role']
    ]
]);
