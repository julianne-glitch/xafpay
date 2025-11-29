<?php

require_once __DIR__ . '/../config.php';

/**
 * STEP 1: Get Bearer Token
 */
function tranzak_get_token(): ?string
{
    $cfg = tranzak_cfg();
    $appId = $cfg['appId'];
    $appKey = $cfg['apiKey'];

    $url = "https://sandbox.dsapi.tranzak.me/auth/token";

    $payload = [
        "appId"  => $appId,
        "appKey" => $appKey
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
        CURLOPT_POSTFIELDS => json_encode($payload)
    ]);

    $resp = curl_exec($ch);
    curl_close($ch);

    $json = json_decode($resp, true);

    if (!$json || empty($json["success"])) {
        return null;
    }

    return $json["data"]["token"] ?? null;
}

/**
 * STEP 2: Use Bearer Token + App Keys to submit mobile wallet charge
 */
function tranzak_initiate_payment(array $payload)
{
    $cfg = tranzak_cfg();
    $appId = $cfg['appId'];
    $appKey = $cfg['apiKey'];

    $token = tranzak_get_token();

    if (!$token) {
        return [
            "success"   => false,
            "errorCode" => 401,
            "errorMsg"  => "Unable to obtain Tranzak access token"
        ];
    }

    // Correct endpoint for MTN/Orange direct charges
    $url = "https://sandbox.dsapi.tranzak.me/xp021/v1/request/create-mobile-wallet-charge";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "Authorization: Bearer $token",
            "x-app-id: $appId",
            "x-app-key: $appKey"
        ],
        CURLOPT_POSTFIELDS => json_encode($payload)
    ]);

    $resp = curl_exec($ch);
    curl_close($ch);

    return json_decode($resp, true);
}
