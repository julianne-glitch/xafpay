<?php
require_once __DIR__ . '/../config.php';

try {
    $pdo = db_connect();
    
    // GET all carriers
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $pdo->query("SELECT id, name, code, phone_number, country, active FROM carriers ORDER BY name");
        $carriers = $stmt->fetchAll();
        json_out([
            'status' => 'ok',
            'data' => $carriers
        ]);
    }

    // POST to add a new carrier
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input['name'] || !$input['code'] || !$input['phone_number']) {
            json_out(['status' => 'error', 'message' => 'Missing required fields'], 400);
        }

        $stmt = $pdo->prepare("INSERT INTO carriers(name, code, phone_number, country, active) VALUES(:name, :code, :phone, :country, :active)");
        $stmt->execute([
            ':name' => $input['name'],
            ':code' => $input['code'],
            ':phone' => $input['phone_number'],
            ':country' => $input['country'] ?? 'Cameroon',
            ':active' => $input['active'] ?? true
        ]);

        json_out(['status' => 'ok', 'message' => 'Carrier added']);
    }
} catch (Exception $e) {
    json_out(['status' => 'error', 'message' => $e->getMessage()], 500);
}
