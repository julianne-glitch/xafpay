<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/logger.php';

/**
 * -----------------------------------------------------------
 * TOKEN CACHING (avoid calling Tranzak token every request)
 * -----------------------------------------------------------
 */
function tranzak_get_token(): ?string
{
    $cfg = tranzak_cfg();
    $cacheFile = "/tmp/tranzak_token.json";

    // If cached token exists and not expired, use it
    if (file_exists($cacheFile)) {
        $data = json_decode(file_get_contents($cacheFile), true);

        if ($data && ($data["expires_at"] ?? 0) > time()) {
            return $data["token"];
        }
    }

    // Request new token
    $url = $cfg["base"] . "/xp021/v1/auth/token";

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

    $token = $json["data"]["token"];
    $expiresIn = $json["data"]["expiresIn"];

    // Cache token
    file_put_contents($cacheFile, json_encode([
        "token"      => $token,
        "expires_at" => time() + ($expiresIn * 0.75) // refresh at 75%
    ]));

    return $token;
}

/**
 * -----------------------------------------------------------
 * INITIATE MOBILE MONEY PAYMENT (XP021)
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

    $url = $cfg["base"] . "/xp021/v1/collections/initiate";

    log_event("tranzak initiate url", $url);
    log_event("tranzak initiate payload", $payload);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            "Content-Type: application/json",
            "Authorization: Bearer $token",
            "x-api-key: {$cfg['apiKey']}"
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
