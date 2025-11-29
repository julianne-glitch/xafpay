<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/logger.php';

/**
 * --------------------------------------------------------------
 *  TRANZAK V2 AUTH + PAYMENT HELPERS
 * --------------------------------------------------------------
 *  - Fetch OAuth access token
 *  - Initiate payment with correct Authorization header
 *  - Auto-handle token failures
 * --------------------------------------------------------------
 */


/**
 * Fetch OAuth Access Token from Tranzak
 */
function tranzak_get_token()
{
    $cfg  = tranzak_cfg();
    $base = rtrim($cfg['base'], '/');
    $url  = "$base/xp021/v1/auth/token";

    $payload = [
        "clientId"     => $cfg['appId'],
        "clientSecret" => $cfg['apiKey']
    ];

    log_event("tranzak_token_request", $payload);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ["Content-Type: application/json"],
        CURLOPT_POSTFIELDS     => json_encode($payload)
    ]);

    $resp  = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    log_event("tranzak_token_raw", $resp);

    if ($error) {
        log_event("tranzak_token_error", $error);
        return null;
    }

    $json = json_decode($resp, true);

    if (!$json || empty($json["success"]) || empty($json["data"]["accessToken"])) {
        log_event("tranzak_token_failure", $json);
        return null;
    }

    return $json["data"]["accessToken"];
}


/**
 * Initiate Payment (correct Tranzak v2 endpoint)
 */
function tranzak_initiate_payment(array $payload)
{
    $cfg  = tranzak_cfg();
    $base = rtrim($cfg['base'], '/');

    $token = tranzak_get_token();    // 🔥 Fetch OAuth token
    if (!$token) {
        return [
            "success"   => false,
            "errorCode" => 401,
            "errorMsg"  => "Unable to obtain Tranzak access token"
        ];
    }

    $url = "$base/xp021/v1/payment/initiate";

    log_event("tranzak_payment_request", $payload);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            "Content-Type: application/json",
            "Authorization: Bearer $token"   // 🔥 Correct header
        ],
        CURLOPT_POSTFIELDS => json_encode($payload)
    ]);

    $resp = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        log_event("tranzak_payment_error", $error);
        return ["success" => false, "errorMsg" => $error];
    }

    log_event("tranzak_payment_raw", $resp);

    return json_decode($resp, true);
}
