<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/logger.php';
log_event("status.php started", $_GET);

try {
    $pdo = db_connect();

    // ==============================
    // GET — List all merchants
    // ==============================
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $pdo->query("
            SELECT id, name, email, api_key, secret_key, is_active, created_at
            FROM merchants
            ORDER BY created_at DESC
        ");
        $merchants = $stmt->fetchAll();
        json_out(['status' => 'ok', 'data' => $merchants]);
    }

    // ==============================
    // POST — Create new merchant
    // ==============================
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);

        if (empty($input['name']) || empty($input['email'])) {
            json_out(['status' => 'error', 'message' => 'Missing required fields: name or email'], 400);
        }

        // ✅ Ensure unique email
        $check = $pdo->prepare("SELECT 1 FROM merchants WHERE email = :email LIMIT 1");
        $check->execute(['email' => $input['email']]);
        if ($check->fetch()) {
            json_out(['status' => 'error', 'message' => 'Merchant already exists with this email'], 400);
        }

        // ✅ Generate keys using pgcrypto
        $stmt = $pdo->query("SELECT 
            'xaf_' || encode(gen_random_bytes(6), 'hex') AS api_key,
            encode(gen_random_bytes(16), 'hex') AS secret_key
        ");
        $keys = $stmt->fetch();

        // ✅ Insert merchant
        $insert = $pdo->prepare("
            INSERT INTO merchants (name, email, api_key, secret_key, is_active)
            VALUES (:name, :email, :api_key, :secret_key, true)
        ");
        $insert->execute([
            'name' => $input['name'],
            'email' => $input['email'],
            'api_key' => $keys['api_key'],
            'secret_key' => $keys['secret_key']
        ]);

        json_out([
            'status' => 'ok',
            'message' => 'Merchant created successfully',
            'data' => [
                'name' => $input['name'],
                'email' => $input['email'],
                'api_key' => $keys['api_key'],
                'secret_key' => $keys['secret_key']
            ]
        ]);
    }

    // ==============================
    // PATCH — Suspend / Activate merchant
    // ==============================
    if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (empty($input['email']) || !isset($input['is_active'])) {
            json_out(['status' => 'error', 'message' => 'Missing email or is_active flag'], 400);
        }

        $stmt = $pdo->prepare("UPDATE merchants SET is_active = :active WHERE email = :email");
        $stmt->execute(['active' => (bool)$input['is_active'], 'email' => $input['email']]);

        json_out(['status' => 'ok', 'message' => 'Merchant status updated']);
    }
} catch (Throwable $e) {
    json_out(['status' => 'error', 'message' => $e->getMessage()], 500);
}
