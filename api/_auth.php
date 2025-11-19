<?php
// ------------------------------------------------------
// OPTIONAL HMAC AUTH — If headers exist, verify them.
// If missing, skip authentication safely.
// NEVER return empty output (prevents network errors).
// ------------------------------------------------------

require_once __DIR__ . '/../config.php';

function get_header_value($name) {
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return $_SERVER[$key] ?? null;
}

function optional_hmac_auth($pdo)
{
    $apiKey     = get_header_value('x-api-key');
    $timestamp  = get_header_value('x-timestamp');
    $signature  = get_header_value('x-signature');

    // --------------------------------------------------
    // Case 1 — No auth headers → allow request
    // --------------------------------------------------
    if (!$apiKey || !$timestamp || !$signature) {
        return [
            'auth'  => 'none',
            'valid' => true,
            'merchant' => null
        ];
    }

    // --------------------------------------------------
    // Case 2 — Merchant lookup
    // --------------------------------------------------
    $stmt = $pdo->prepare("SELECT * FROM merchants WHERE api_key = :k LIMIT 1");
    $stmt->execute(['k' => $apiKey]);
    $merchant = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$merchant) {
        json_out(['error' => 'Invalid API key'], 401);
    }

    $raw = $GLOBALS['XAF_RAW_BODY'] ?? file_get_contents("php://input");
    $compute = hash_hmac('sha256', $raw . $timestamp, $merchant['secret_key']);

    if (!hash_equals($compute, $signature)) {
        json_out(['error' => 'Invalid signature'], 401);
    }

    return [
        'auth'     => 'hmac',
        'valid'    => true,
        'merchant' => $merchant
    ];
}
