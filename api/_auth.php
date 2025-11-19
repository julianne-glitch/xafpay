<?php
// ---------------------------------------------------------
// OPTIONAL HMAC AUTH — Works for public + merchant requests
// NEVER throws fatal errors, always returns usable result
// ---------------------------------------------------------

require_once __DIR__ . '/../config.php';

/**
 * Read header safely
 */
function header_value($name) {
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return $_SERVER[$key] ?? null;
}

/**
 * Optional HMAC authentication
 * If headers exist → validate
 * If missing → allow public request
 */
function optional_hmac_auth(PDO $pdo): array {

    // Read headers
    $apiKey    = header_value('x-api-key');
    $timestamp = header_value('x-timestamp');
    $signature = header_value('x-signature');

    // No headers → public mode
    if (!$apiKey || !$timestamp || !$signature) {
        return [
            'auth'     => 'none',
            'valid'    => true,
            'merchant' => null
        ];
    }

    // Lookup merchant
    $stmt = $pdo->prepare("SELECT * FROM merchants WHERE api_key = :k LIMIT 1");
    $stmt->execute(['k' => $apiKey]);
    $merchant = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$merchant) {
        json_out(['error' => 'Invalid API key'], 401);
    }

    // Read raw body exactly once
    $raw = $GLOBALS['XAF_RAW_BODY'] ?? file_get_contents("php://input");
    $GLOBALS['XAF_RAW_BODY'] = $raw;

    // Compute signature
    $expected = hash_hmac('sha256', $raw . $timestamp, $merchant['secret_key']);

    if (!hash_equals($expected, $signature)) {
        json_out(['error' => 'Invalid signature'], 401);
    }

    return [
        'auth'     => 'hmac',
        'valid'    => true,
        'merchant' => $merchant
    ];
}
