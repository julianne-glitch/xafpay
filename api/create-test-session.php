<?php
require_once __DIR__ . '/../config.php';

// universal UUID generator (works anywhere)
function generate_uuid_v4() {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // version 4
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

try {
    $pdo = db_connect();

    // test data
    $id = generate_uuid_v4();
    $order_id = 'WEB-' . time();
    $amount = 2000;
    $currency = 'XAF';
    $status = 'PENDING';
    $carrier_code = 'MTN';

    // ✅ corrected insert (no 'name' column)
    $stmt = $pdo->prepare("
        INSERT INTO sessions (
            id, amount, currency, status, carrier_code, created_at, updated_at
        ) VALUES (
            :id, :amount, :currency, :status, :carrier_code, NOW(), NOW()
        )
    ");

    $stmt->execute([
        ':id' => $id,
        ':amount' => $amount,
        ':currency' => $currency,
        ':status' => $status,
        ':carrier_code' => $carrier_code
    ]);

    echo json_encode([
        'ok' => true,
        'data' => [
            'session_id' => $id,
            'order_id' => $order_id,
            'amount' => $amount,
            'currency' => $currency,
            'carrier' => $carrier_code,
        ]
    ]);
} catch (Throwable $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
