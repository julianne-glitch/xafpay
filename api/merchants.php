<?php
require_once __DIR__ . '/../config.php';

try {
    $pdo = db_connect();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $pdo->query("SELECT * FROM merchant_accounts ORDER BY created_at DESC");
        $merchants = $stmt->fetchAll();
        json_out(['status' => 'ok', 'data' => $merchants]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input['name'] || !$input['phone_number']) {
            json_out(['status' => 'error', 'message' => 'Missing required fields'], 400);
        }

        $stmt = $pdo->prepare("INSERT INTO merchant_accounts(name, phone_number, email) VALUES(:name, :phone, :email)");
        $stmt->execute([
            ':name' => $input['name'],
            ':phone' => $input['phone_number'],
            ':email' => $input['email'] ?? null
        ]);

        json_out(['status' => 'ok', 'message' => 'Merchant added']);
    }
} catch (Exception $e) {
    json_out(['status' => 'error', 'message' => $e->getMessage()], 500);
}
