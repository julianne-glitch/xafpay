<?php
// ============================================================================
// XAFPay OPTIONAL HMAC AUTH
// Supports: public requests + merchant-signed requests
// SAFE: never fatal, always returns usable JSON response
// ============================================================================

require_once __DIR__ . '/../config.php';

// ---------------------------------------------------------------------------
// 0) Safe json_out FALLBACK (in case parent did not load response.php)
// ---------------------------------------------------------------------------
if (!function_exists('json_out')) {
    function json_out($arr, $code = 200) {
        http_response_code($code);
        header("Content-Type: application/json");
        echo json_encode($arr, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/**
 * Safe header reader
 */
function header_value(string $name): ?string {
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return $_SERVER[$key] ?? null;
}

/**
 * OPTIONAL HMAC Authentication
 *
 * Modes:
 *  - No headers → public request (valid = true, merchant = null)
 *  - With headers → validate HMAC & timestamp
 *
 * Returns:
 *  [
 *      'auth'     => 'none' | 'hmac',
 *      'valid'    => true/false,
 *      'merchant' => <merchant row or null>
 *  ]
 */
function optional_hmac_auth(PDO $pdo): array {

    // -----------------------------------------------------------------------
    // 1) Extract headers
    // -----------------------------------------------------------------------
    $apiKey    = header_value('x-api-key');
    $timestamp = header_value('x-timestamp');
    $signature = header_value('x-signature');

    // -----------------------------------------------------------------------
    // 2) PUBLIC MODE — allow requests with no auth headers
    // -----------------------------------------------------------------------
    if (!$apiKey || !$timestamp || !$signature) {
        return [
            'auth'     => 'none',
            'valid'    => true,
            'merchant' => null
        ];
    }

    // -----------------------------------------------------------------------
    // 3) Lookup merchant by API key
    // -----------------------------------------------------------------------
    $stmt = $pdo->prepare("SELECT * FROM merchants WHERE api_key = :k LIMIT 1");
    $stmt->execute(['k' => $apiKey]);
    $merchant = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$merchant) {
        json_out(['error' => 'Invalid API key'], 401);
    }

    // -----------------------------------------------------------------------
    // 4) Timestamp freshness check — prevent replay attacks
    // Allowed drift: 5 minutes (300 seconds)
    // -----------------------------------------------------------------------
    $now = time();
    $ts  = (int)$timestamp;

    if (abs($now - $ts) > 300) {
        json_out(['error' => 'Request expired'], 408);
    }

    // -----------------------------------------------------------------------
    // 5) Read RAW BODY only ONCE
    // -----------------------------------------------------------------------
    if (!isset($GLOBALS['XAF_RAW_BODY'])) {
        $GLOBALS['XAF_RAW_BODY'] = file_get_contents("php://input") ?? '';
    }

    $raw = $GLOBALS['XAF_RAW_BODY'];

    // -----------------------------------------------------------------------
    // 6) Compute expected signature
    // Formula: HMAC_SHA256( raw_body + timestamp, merchant.secret_key )
    // -----------------------------------------------------------------------
    $expected = hash_hmac(
        'sha256',
        $raw . $timestamp,
        $merchant['secret_key']
    );

    if (!hash_equals($expected, $signature)) {
        json_out(['error' => 'Invalid signature'], 401);
    }

    // -----------------------------------------------------------------------
    // 7) VALID HMAC — return merchant info
    // -----------------------------------------------------------------------
    return [
        'auth'     => 'hmac',
        'valid'    => true,
        'merchant' => $merchant
    ];
}
