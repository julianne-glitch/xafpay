<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/logger.php';

// ------------------------------------------------------------
// CORS
// ------------------------------------------------------------
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ------------------------------------------------------------
// Read input
// ------------------------------------------------------------
$sessionId = $_GET['session_id'] ?? null;
$orderId   = $_GET['order_id'] ?? null;

if (!$sessionId && !$orderId) {
    json_out(['ok' => false, 'error' => 'Missing session_id or order_id'], 400);
}

$pdo = db_connect();

// ------------------------------------------------------------
// Fetch session
// ------------------------------------------------------------
$query = "
    SELECT 
        s.id AS session_id,
        s.order_id,
        s.amount,
        s.currency,
        s.phone_number,
        s.carrier_code,
        s.status AS session_status,
        p.id AS payment_id,
        p.status AS payment_status,
        p.reference_id,
        p.transaction_request_id,
        p.transaction_id,
        p.response_payload,
        s.created_at,
        s.updated_at
    FROM sessions s
    LEFT JOIN payments p ON p.session_id = s.id
";

if ($sessionId) {
    $query .= " WHERE s.id = :sid LIMIT 1";
    $params = ['sid' => $sessionId];
} else {
    $query .= " WHERE s.order_id = :oid LIMIT 1";
    $params = ['oid' => $orderId];
}

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    json_out(['ok' => false, 'error' => 'Session not found'], 404);
}

// ------------------------------------------------------------
// Return session & payment combined
// ------------------------------------------------------------
json_out([
    'ok'      => true,
    'session' => [
        'id'         => $row['session_id'],
        'order_id'   => $row['order_id'],
        'amount'     => $row['amount'],
        'currency'   => $row['currency'],
        'phone'      => $row['phone_number'],
        'carrier'    => $row['carrier_code'],
        'status'     => $row['session_status'],
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at']
    ],
    'payment' => [
        'id'                   => $row['payment_id'],
        'reference_id'         => $row['reference_id'],
        'status'               => $row['payment_status'],
        'transaction_request_id' => $row['transaction_request_id'],
        'transaction_id'       => $row['transaction_id'],
        'response_payload'     => json_decode($row['response_payload'] ?? "{}", true),
    ]
]);
