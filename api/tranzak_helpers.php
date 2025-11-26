<?php
require_once __DIR__ . '/../config.php';

/**
 * Get Tranzak auth token
 */
function tranzak_get_token() {
    $cfg = tranzak_cfg();
    $url = rtrim($cfg['base'], '/') . '/auth/token';

    $body = json_encode([
        'appId'  => $cfg['appId'],
        'appKey' => $cfg['apiKey'],
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => $body,
    ]);

    $res  = curl_exec($ch);
    $err  = curl_error($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);

    if ($err) {
        throw new Exception("Tranzak token error: $err");
    }

    $data  = json_decode($res, true);
    $token = $data['data']['token'] ?? null;

    if (!$token) {
        throw new Exception("Invalid token response: HTTP {$info['http_code']} → $res");
    }

    return $token;
}

/**
 * Create Tranzak payment request (redirect flow)
 */
function tranzak_create_payment($amount, $currency, $description, $orderId, $returnUrl) {
    $cfg   = tranzak_cfg();
    $token = tranzak_get_token();

    $url = rtrim($cfg['base'], '/') . '/xp021/v1/request/create';

    $payload = [
        'amount'            => (int)$amount,
        'currencyCode'      => $currency,          // "XAF"
        'description'       => $description,
        'mchTransactionRef' => $orderId,           // must be unique
        'returnUrl'         => $returnUrl,
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
            'X-App-ID: ' . $cfg['appId'],
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
    ]);

    $res  = curl_exec($ch);
    $err  = curl_error($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);

    if ($err) {
        throw new Exception("Tranzak create payment error: $err");
    }

    $data = json_decode($res, true);

    if (empty($data['success'])) {
        throw new Exception("Payment creation failed: HTTP {$info['http_code']} → $res");
    }

    $authUrl = $data['data']['links']['paymentAuthUrl'] ?? null;

    if (!$authUrl) {
        throw new Exception("No paymentAuthUrl in response: $res");
    }

    return [
        'paymentAuthUrl' => $authUrl,
        'raw'            => $data,
    ];
}
