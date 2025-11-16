<?php
/**
 * _auth.php — Merchant Authentication Middleware
 * Ensures only authorized merchants can access XafPay API
 */

require_once __DIR__ . '/../config.php';

/**
 * Verifies merchant authentication and returns merchant record
 * Usage: $merchant = require_once __DIR__ . '/_auth.php';
 */

try {
    $pdo = db_connect();

    // Extract headers
    $headers = getallheaders();
    $apiKey    = $headers['X-API-KEY']    ?? null;
    $signature = $headers['X-SIGNATURE']  ?? null;
    $timestamp = $headers['X-TIMESTAMP']  ?? null;

    if (!$apiKey || !$signature || !$timestamp) {
        json_out(['error' => 'Missing authentication headers'], 401);
    }

    // Basic replay protection (timestamp not older than 5 minutes)
    $now = time();
    if (abs($now - (int)$timestamp) > 300) {
        json_out(['error' => 'Request timestamp expired'], 401);
    }

    // Find merchant by API key
    $stmt = $pdo->prepare("SELECT * FROM merchants WHERE api_key = :api_key LIMIT 1");
    $stmt->execute(['api_key' => $apiKey]);
    $merchant = $stmt->fetch();

    if (!$merchant || !$merchant['is_active']) {
        json_out(['error' => 'Invalid or inactive merchant'], 403);
    }

    // Recalculate signature using secret_key
    $rawBody = file_get_contents('php://input');
    $calcSig = hash_hmac('sha256', $rawBody . $timestamp, $merchant['secret_key']);

    if (!hash_equals($calcSig, $signature)) {
        json_out(['error' => 'Invalid signature'], 403);
    }

    // ✅ Authentication passed
    return $merchant;

} catch (Throwable $e) {
    json_out(['error' => 'Auth error', 'detail' => $e->getMessage()], 500);
}
