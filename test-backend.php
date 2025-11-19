<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/api/logger.php';

log_event("TEST_BACKEND_HIT", $_SERVER['REMOTE_ADDR'] ?? 'unknown');

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$report = [
    'ok'      => true,
    'service' => 'XafPay Backend Diagnostic',
    'env'     => app_env(),
    'time'    => gmdate('c'),
    'checks'  => []
];

try {
    $pdo = db_connect();
    $report['checks']['db_connection'] = "✅ PostgreSQL connected";

    // -------------------------------------------------------------
    // 1️⃣ CARRIERS
    // -------------------------------------------------------------
    try {
        $rows = $pdo->query("
            SELECT id, name, code, merchant_number, active 
            FROM carriers
            ORDER BY id ASC
        ")->fetchAll();

        $report['checks']['carriers'] = [
            'count' => count($rows),
            'mtn_exists'    => !!array_filter($rows, fn($c) => $c['code'] === 'MTN'),
            'orange_exists' => !!array_filter($rows, fn($c) => $c['code'] === 'ORANGE'),
            'entries' => $rows
        ];
    } catch (Throwable $e) {
        $report['checks']['carriers'] = "❌ " . $e->getMessage();
    }

    // -------------------------------------------------------------
    // 2️⃣ SESSIONS
    // -------------------------------------------------------------
    try {
        $rows = $pdo->query("
            SELECT id, order_id, amount, currency, phone_number, carrier_code, status, created_at
            FROM sessions
            ORDER BY created_at DESC
            LIMIT 10
        ")->fetchAll();

        $report['checks']['sessions'] = [
            'count_last_10' => count($rows),
            'entries' => $rows
        ];
    } catch (Throwable $e) {
        $report['checks']['sessions'] = "❌ " . $e->getMessage();
    }

    // -------------------------------------------------------------
    // 3️⃣ PAYMENTS (modern schema)
    // -------------------------------------------------------------
    try {
        $rows = $pdo->query("
            SELECT reference_id, order_id, amount, currency, status, created_at
            FROM payments
            ORDER BY created_at DESC
            LIMIT 10
        ")->fetchAll();

        $report['checks']['payments'] = [
            'count_last_10' => count($rows),
            'entries' => $rows
        ];
    } catch (Throwable $e) {
        $report['checks']['payments'] = "❌ " . $e->getMessage();
    }

    // -------------------------------------------------------------
    // 4️⃣ MERCHANTS (modern schema)
    // -------------------------------------------------------------
    try {
        $rows = $pdo->query("
            SELECT id, merchant_name, api_key, is_active, created_at
            FROM merchants
            ORDER BY created_at DESC
            LIMIT 10
        ")->fetchAll();

        $report['checks']['merchants'] = [
            'count_last_10' => count($rows),
            'entries' => $rows
        ];
    } catch (Throwable $e) {
        $report['checks']['merchants'] = "❌ " . $e->getMessage();
    }

    // -------------------------------------------------------------
    // Return JSON
    // -------------------------------------------------------------
    json_out($report);

} catch (Throwable $e) {
    json_out([
        'ok'    => false,
        'error' => $e->getMessage()
    ], 500);
}
