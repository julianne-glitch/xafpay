<?php
require_once __DIR__ . '/logger.php';
log_event("status.php started", $_GET);



header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../config.php';

$pdo = db_connect();

// -----------------------------------------------------------
// 1️⃣ Read JSON input
// -----------------------------------------------------------
$input = json_decode(file_get_contents('php://input'), true);
$username = trim($input['username'] ?? '');
$password = trim($input['password'] ?? '');

if (!$username || !$password) {
    json_out(['ok' => false, 'error' => 'Missing credentials'], 400);
}

// -----------------------------------------------------------
// 2️⃣ Fetch admin user
// -----------------------------------------------------------
$stmt = $pdo->prepare("
    SELECT id, username, password_hash, role 
    FROM admin_users 
    WHERE username = :u 
    LIMIT 1
");
$stmt->execute(['u' => $username]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || !password_verify($password, $user['password_hash'])) {
    json_out(['ok' => false, 'error' => 'Invalid credentials'], 401);
}

// -----------------------------------------------------------
// 3️⃣ Create token (simple base64 token)
// -----------------------------------------------------------
// ⚠️ NOTE: This token is NOT JWT. It is simple base64 JSON.
// You must validate it manually in _auth.php.
//
$tokenData = [
    'uid'  => $user['id'],
    'user' => $user['username'],
    'role' => $user['role'],
    'exp'  => time() + 3600
];

$token = base64_encode(json_encode($tokenData));

// -----------------------------------------------------------
// 4️⃣ Return token
// -----------------------------------------------------------
json_out([
    'ok'    => true,
    'token' => $token,
    'user'  => [
        'username' => $user['username'],
        'role'     => $user['role']
    ]
]);
