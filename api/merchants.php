<?php

// ----------------------------------------------------------
// Logger
// ----------------------------------------------------------
require_once __DIR__ . '/logger.php';
log_event("ADMIN_MERCHANTS_ENDPOINT_HIT", $_SERVER['REQUEST_METHOD']);

// ----------------------------------------------------------
// CORS
// ----------------------------------------------------------
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PATCH, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ----------------------------------------------------------
// Config + DB + Admin Auth
// ----------------------------------------------------------
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../api/_auth_admin.php';

$pdo = db_connect();
$admin = require_admin($pdo); // ONLY admins allowed

// ----------------------------------------------------------
// GET — List merchants (NO secret key exposure)
// ----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    $stmt = $pdo->query("
        SELECT 
            id, 
            name, 
            email, 
            api_key, 
            is_active, 
            created_at
        FROM merchants
        ORDER BY created_at DESC
    ");

    $merchants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    json_out(['ok' => true, 'data' => $merchants]);
    exit;
}

// ----------------------------------------------------------
// POST — Create New Merchant
// ----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $input = json_decode(file_get_contents('php://input'), true);

    if (empty($input['name']) || empty($input['email'])) {
        json_out(['ok' => false, 'error' => 'Missing fields: name or email'], 400);
    }

    // Check duplicate
    $check = $pdo->prepare("SELECT 1 FROM merchants WHERE email = :email LIMIT 1");
    $check->execute(['email' => $input['email']]);
    if ($check->fetch()) {
        json_out(['ok' => false, 'error' => 'Merchant already exists'], 400);
    }

    // Generate keys
    $stmt = $pdo->query("
        SELECT 
            'xaf_' || encode(gen_random_bytes(6), 'hex') AS api_key,
            encode(gen_random_bytes(16), 'hex') AS secret_key
    ");

    $keys = $stmt->fetch(PDO::FETCH_ASSOC);

    // Insert merchant
    $insert = $pdo->prepare("
        INSERT INTO merchants (name, email, api_key, secret_key, is_active)
        VALUES (:name, :email, :api_key, :secret_key, TRUE)
    ");

    $insert->execute([
        'name'       => $input['name'],
        'email'      => $input['email'],
        'api_key'    => $keys['api_key'],
        'secret_key' => $keys['secret_key']
    ]);

    // Audit log
    $pdo->prepare("
        INSERT INTO admin_actions (admin_user, action, target, details)
        VALUES (:admin, 'create_merchant', :target, :details)
    ")->execute([
        'admin'  => $admin['username'],
        'target' => $input['email'],
        'details'=> 'Merchant created'
    ]);

    json_out([
        'ok'    => true,
        'message' => 'Merchant created',
        'data' => [
            'name'       => $input['name'],
            'email'      => $input['email'],
            'api_key'    => $keys['api_key'],     // shown ONCE
            'secret_key' => $keys['secret_key']   // shown ONCE
        ]
    ]);
    exit;
}

// ----------------------------------------------------------
// PATCH — Activate / Suspend merchant
// ----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {

    $input = json_decode(file_get_contents('php://input'), true);

    if (empty($input['email']) || !isset($input['is_active'])) {
        json_out(['ok' => false, 'error' => 'Missing email or is_active'], 400);
    }

    $update = $pdo->prepare("
        UPDATE merchants
        SET is_active = :active
        WHERE email = :email
    ");

    $update->execute([
        'active' => (bool)$input['is_active'],
        'email'  => $input['email']
    ]);

    // Audit log
    $pdo->prepare("
        INSERT INTO admin_actions (admin_user, action, target, details)
        VALUES (:admin, 'toggle_merchant', :target, :details)
    ")->execute([
        'admin'  => $admin['username'],
        'target' => $input['email'],
        'details'=> 'is_active=' . json_encode($input['is_active'])
    ]);

    json_out(['ok' => true, 'message' => 'Merchant status updated']);
    exit;
}

// ----------------------------------------------------------
// Fallback
// ----------------------------------------------------------
json_out(['ok' => false, 'error' => 'Unsupported method'], 405);
