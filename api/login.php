<?php
require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json');
$pdo = db_connect();

$input = json_decode(file_get_contents('php://input'), true);
$username = $input['username'] ?? '';
$password = $input['password'] ?? '';

if (!$username || !$password) {
    json_out(['ok' => false, 'error' => 'Missing credentials'], 400);
}

$stmt = $pdo->prepare("SELECT * FROM admin_users WHERE username = :u LIMIT 1");
$stmt->execute(['u' => $username]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password_hash'])) {
    json_out(['ok' => false, 'error' => 'Invalid credentials'], 401);
}

$token = base64_encode(json_encode([
    'user' => $user['username'],
    'role' => $user['role'],
    'exp'  => time() + 3600
]));

json_out(['ok' => true, 'token' => $token]);
