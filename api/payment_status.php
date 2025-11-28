<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$orderId = $_GET['order_id'] ?? '';
if (!$orderId) {
    echo json_encode(['ok' => false, 'error' => 'Missing order_id']);
    exit;
}

$pdo = db_connect();

$stmt = $pdo->prepare("
    SELECT s.status AS session_status, p.status AS payment_status
    FROM sessions s
    LEFT JOIN payments p ON p.session_id = s.id
    WHERE s.order_id = :oid
    ORDER BY p.updated_at DESC
    LIMIT 1
");
$stmt->execute(['oid' => $orderId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

echo json_encode([
    'ok' => true,
    'order_id' => $orderId,
    'status' => $row['payment_status'] ?? $row['session_status'] ?? 'pending',
]);
