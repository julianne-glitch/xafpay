<?php
require_once __DIR__ . '/../config.php';

try {
    $pdo = db_connect();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $pdo->query("SELECT * FROM transactions ORDER BY created_at DESC LIMIT 10");
        $transactions = $stmt->fetchAll();
        json_out(['status' => 'ok', 'data' => $transactions]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input['session_id'] || !$input['amount']) {
            json_out(['status' => 'error', 'message' => 'Missing required fields'], 400);
        }

        $stmt = $pdo->prepare("INSERT INTO transactions(session_id, amount, status) VALUES(:session_id, :amount, :status)");
        $stmt->execute([
            ':session_id' => $input['session_id'],
            ':amount' => $input['amount'],
            ':status' => $input['status'] ?? 'pending'
        ]);

        json_out(['status' => 'ok', 'message' => 'Transaction recorded']);
    }
} catch (Exception $e) {
    json_out(['status' => 'error', 'message' => $e->getMessage()], 500);
}
