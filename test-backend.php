<?php
require_once __DIR__ . '/config.php';

$report = [
    'status' => 'ok',
    'details' => []
];

try {
    $pdo = db_connect();
    $report['details']['db_connection'] = '✅ Connected successfully';

    // --- 1️⃣ Carriers ---
    $carriers = $pdo->query("SELECT id, name, code, phone_number, country, active FROM carriers")->fetchAll();
    $report['details']['carriers'] = [
        'total' => count($carriers),
        'entries' => $carriers,
        'mtn_exists' => !!array_filter($carriers, fn($c) => $c['code']=='MTN'),
        'orange_exists' => !!array_filter($carriers, fn($c) => $c['code']=='ORANGE')
    ];

    // --- 2️⃣ Sessions ---
    $sessions = $pdo->query("SELECT * FROM sessions ORDER BY created_at DESC LIMIT 5")->fetchAll();
    $report['details']['sessions'] = ['last_5' => $sessions];

    // Insert test session
    $stmt = $pdo->prepare("INSERT INTO sessions(customer_id, carrier_code, amount) VALUES(:customer_id, :carrier_code, :amount) RETURNING id");
    $stmt->execute([
        ':customer_id' => '00000000-0000-0000-0000-000000000000', // dummy UUID
        ':carrier_code' => 'MTN',
        ':amount' => 123.45
    ]);
    $report['details']['sessions']['inserted_id'] = $stmt->fetch()['id'];

    // --- 3️⃣ Transactions ---
    $transactions = $pdo->query("SELECT * FROM transactions ORDER BY created_at DESC LIMIT 5")->fetchAll();
    $report['details']['transactions'] = ['last_5' => $transactions];

    $stmt = $pdo->prepare("INSERT INTO transactions(session_id, amount, status) VALUES(:session_id, :amount, :status) RETURNING id");
    $stmt->execute([
        ':session_id' => '00000000-0000-0000-0000-000000000000', // dummy session UUID
        ':amount' => 123.45,
        ':status' => 'pending'
    ]);
    $report['details']['transactions']['inserted_id'] = $stmt->fetch()['id'];

    // --- 4️⃣ Merchants ---
    $merchants = $pdo->query("SELECT * FROM merchant_accounts ORDER BY created_at DESC LIMIT 5")->fetchAll();
    $report['details']['merchants'] = ['last_5' => $merchants];

    $stmt = $pdo->prepare("INSERT INTO merchant_accounts(name, phone_number, email) VALUES(:name, :phone, :email) RETURNING id");
    $stmt->execute([
        ':name' => 'Test Merchant',
        ':phone' => '677123456',
        ':email' => 'test@merchant.com'
    ]);
    $report['details']['merchants']['inserted_id'] = $stmt->fetch()['id'];

    header('Content-Type: application/json');
    echo json_encode($report, JSON_PRETTY_PRINT);

} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
