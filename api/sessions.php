<?php
require_once __DIR__ . '/../logger.php';
log_event("status.php started", $_GET);

// -----------------------------------------
// CORS
// -----------------------------------------
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config.php';

$pdo   = db_connect();
$input = json_decode(file_get_contents("php://input"), true);

// -----------------------------------------
// Validate inputs
// -----------------------------------------
$amount       = $input['amount'] ?? 0;
$carrier_code = $input['carrier_code'] ?? "";
$phone        = $input['phone_number'] ?? "";

if ($amount <= 0) {
    json_out(['error' => 'Invalid amount'], 400);
}

if (!in_array($carrier_code, ['MTN', 'Orange'])) {
    json_out(['error' => 'Invalid carrier'], 400);
}

if (!preg_match('/^\d{9}$/', $phone)) {
    json_out(['error' => 'Invalid phone number'], 400);
}

// -----------------------------------------
// Generate session + order IDs
// -----------------------------------------
$session_id = uuidv4();
$order_id   = "ORD-" . time() . "-" . rand(1000, 9999);

// -----------------------------------------
// Insert into DB — New Standard Schema
// -----------------------------------------
try {
    $stmt = $pdo->prepare("
        INSERT INTO sessions (
            id,
            order_id,
            amount,
            currency,
            status,
            phone_number,
            carrier_code,
            merchant_id,
            customer_id,
            created_at,
            updated_at
        )
        VALUES (
            :id,
            :order_id,
            :amount,
            'XAF',
            'PENDING',
            :phone,
            :carrier,
            NULL,
            NULL,
            NOW(),
            NOW()
        )
    ");

    $stmt->execute([
        ':id'       => $session_id,
        ':order_id' => $order_id,
        ':amount'   => $amount,
        ':phone'    => $phone,
        ':carrier'  => $carrier_code,
    ]);

} catch (Throwable $e) {
    json_out([
        'error'   => 'Database error',
        'details' => $e->getMessage()
    ], 500);
}

// -----------------------------------------
// Return session data to frontend
// -----------------------------------------
json_out([
    'ok'         => true,
    'session_id' => $session_id,
    'order_id'   => $order_id,
    'amount'     => $amount,
    'currency'   => 'XAF',
    'carrier'    => $carrier_code,
    'phone'      => $phone
]);
