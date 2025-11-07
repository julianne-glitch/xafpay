<?php
require_once __DIR__ . '/../config.php';

try {
    $pdo = db_connect();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $pdo->query("SELECT * FROM sessions ORDER BY created_at DESC LIMIT 10");
        $sessions = $stmt->fetchAll();
        json_out(['status' => 'ok', 'data' => $sessions]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input['customer_id'] || !$input['carrier_code'] || !$input['amount']) {
            json_out(['status' => 'error', 'message' => 'Missing required fields'], 400);
        }

        $stmt = $pdo->prepare("INSERT INTO sessions(customer_id, carrier_code, amount, currency, status) VALUES(:customer_id, :carrier_code, :amount, :currency, :status)");
        $stmt->execute([
            ':customer_id' => $input['customer_id'],
            ':carrier_code' => $input['carrier_code'],
            ':amount' => $input['amount'],
            ':currency' => $input['currency'] ?? 'XAF',
            ':status' => $input['status'] ?? 'pending'
        ]);

        json_out(['status' => 'ok', 'message' => 'Session created']);
    }
} catch (Exception $e) {
    json_out(['status' => 'error', 'message' => $e->getMessage()], 500);
}
