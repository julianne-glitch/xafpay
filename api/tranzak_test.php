<?php
// api/tranzak_test.php
require_once __DIR__ . '/tranzak_helpers.php';

header('Content-Type: text/plain');
header('Access-Control-Allow-Origin: *');

try {
    $orderId  = 'TEST-' . time();
    $amount   = 1000;             // 1000 XAF
    $currency = 'XAF';
    $return   = base_url() . '/checkout/return.php?order_id=' . urlencode($orderId);

    $res = tranzak_create_payment(
        $amount,
        $currency,
        "Test payment $orderId",
        $orderId,
        $return
    );

    echo "✅ Payment created successfully!\n\n";
    echo "👉 Open this URL in your browser:\n";
    echo $res['paymentAuthUrl'] . "\n";
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
