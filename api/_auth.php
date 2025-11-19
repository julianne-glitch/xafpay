<?php
/**
 * _auth.php — Merchant Authentication Middleware
 * Used for merchant-facing API calls (pay.php, session.php)
 */

require_once __DIR__ . '/../config.php';

/**
 * Returns merchant record on success or stops execution with json_out()
 * Usage inside endpoint: $merchant = require __DIR__ . '/_auth.php';
 */

try {
    $pdo = db_connect();

    // -------------------------------
    // 1️⃣ Extract Auth Headers
    // -------------------------------
    $headers   = getallheaders();
    $apiKey    = $headers['X-API-KEY']    ?? null;
    $signature = $headers['X-SIGNATURE']  ?? null;
    $timestamp = $headers['X-TIMESTAMP']  ?? null;

    if (!$apiKey || !$signature || !$timestamp) {
        json_out(['error' => 'Missing authentication headers'], 401);
    }

    // -------------------------------
    // 2️⃣ Check Timestamp (Replay Attack Prevention)
    // -------------------------------
    $now = time();
    if (abs($now - (int)$timestamp) > 300) {
        json_out(['error' => 'Request timestamp expired'], 401);
    }

    // -------------------------------
    // 3️⃣ Find merchant by API key
    // -------------------------------
    $stmt = $pdo->prepare("SELECT * FROM merchants WHERE api_key = :api_key LIMIT 1");
    $stmt->execute(['api_key' => $apiKey]);
    $merchant = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$merchant || !$merchant['is_active']) {
        json_out(['error' => 'Invalid or inactive merchant'], 403);
    }

    // -------------------------------
    // 4️⃣ Read RAW BODY ONLY ONCE
    // -------------------------------
    $rawBody = $GLOBALS['XAF_RAW_BODY'] ?? null;

    if ($rawBody === null) {
        $rawBody = file_get_contents('php://input');
        $GLOBALS['XAF_RAW_BODY'] = $rawBody;
    }

    // -------------------------------
    // 5️⃣ Compute Expected Signature
    // Standard: HMAC_SHA256(timestamp + apiKey + body)
    // -------------------------------
    $expected = hash_hmac(
        'sha256',
        $timestamp . $apiKey . $rawBody,
        $merchant['secret_key']
    );

    if (!hash_equals($expected, $signature)) {
        log_event("AUTH_FAIL_SIGNATURE", [
            'expected' => $expected,
            'received' => $signature,
            'body'     => $rawBody
        ]);
        json_out(['error' => 'Invalid signature'], 403);
    }

    // -------------------------------
    // 6️⃣ Authentication Passed
    // -------------------------------
    return $merchant;

} catch (Throwable $e) {
    json_out(['error' => 'Auth error', 'detail' => $e->getMessage()], 500);
}
