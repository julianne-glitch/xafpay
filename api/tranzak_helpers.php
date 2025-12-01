<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/logger.php';

/**
 * -----------------------------------------------------------
 * TOKEN CACHING (XP021)
 * -----------------------------------------------------------
 */
function tranzak_get_token(): ?string
{
    $cfg = tranzak_cfg();
    $cacheFile = "/tmp/tranzak_token.json";

    // Use cached token if valid
    if (file_exists($cacheFile)) {
        $data = json_decode(file_get_contents($cacheFile), true);
        if ($data && ($data["expires_at"] ?? 0) > time()) {
            return $data["token"];
        }
    }

    // Request new token (PROD or SANDBOX)
    $url = $cfg["base"] . "/auth/token";

    $body = [
        "appId"  => $cfg["appId"],
        "appKey" => $cfg["apiKey"]
    ];

    log_event("tranzak token request", $body);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ["Content-Type: application/json"],
        CURLOPT_POSTFIELDS     => json_encode($body)
    ]);

    $resp = curl_exec($ch);
    curl_close($ch);

    log_event("tranzak token raw", $resp);

    $json = json_decode($resp, true);

    if (!$json || empty($json["success"])) {
        log_event("tranzak token error", $json);
        return null;
    }

    $token     = $json["data"]["token"];
    $expiresIn = $json["data"]["expiresIn"];

    // Cache token
    file_put_contents($cacheFile, json_encode([
        "token"      => $token,
        "expires_at" => time() + ($expiresIn * 0.75)
    ]));

    return $token;
}

/**
 * -----------------------------------------------------------
 * CREATE DIRECT MOBILE MONEY CHARGE (XP021)
 * -----------------------------------------------------------
 */
function tranzak_xp021_initiate(array $payload)
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

    // ✔ CORRECT endpoint (same as your working curl)
    $url = $cfg["base"] . "/xp021/v1/request/create-mobile-wallet-charge";

    log_event("tranzak initiate url", $url);
    log_event("tranzak initiate payload", $payload);

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
    $err  = curl_error($ch);
    curl_close($ch);

    log_event("tranzak initiate curl_error", $err);
    log_event("tranzak initiate raw", $resp);

    return json_decode($resp, true);
}
