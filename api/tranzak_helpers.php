<?php

require_once __DIR__ . '/../config.php';

/**
 * Get Tranzak configuration
 */
function tranzak_cfg(): array {
    return [
        "base"   => "https://sandbox.dsapi.tranzak.me",
        "appId"  => getenv("TRANZAK_APP_ID"),
        "apiKey" => getenv("TRANZAK_API_KEY")
    ];
}

/**
 * STEP 1 — Get Bearer Token from correct endpoint
 */
function tranzak_get_token(): ?string
{
    $cfg   = tranzak_cfg();
    $url   = $cfg["base"] . "/auth/token";

    $payload = [
        "appId"  => $cfg["appId"],
        "appKey" => $cfg["apiKey"]
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            "Content-Type: application/json"
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload)
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
 * STEP 2 — Create Mobile Wallet Charge
 */
function tranzak_initiate_payment(array $payload)
{
    $cfg   = tranzak_cfg();
    $token = tranzak_get_token();

    if (!$token) {
        return [
            "success"   => false,
            "errorCode" => 401,
            "errorMsg"  => "Unable to obtain Tranzak access token"
        ];
    }

    $url = $cfg["base"] . "/xp021/v1/request/create-mobile-wallet-charge";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            "Content-Type: application/json",
            "Authorization: Bearer $token",
            "x-app-id: {$cfg['appId']}"
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload)
    ]);

    $resp = curl_exec($ch);
    curl_close($ch);

    return json_decode($resp, true);
}
