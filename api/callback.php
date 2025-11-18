<?php
require_once __DIR__ . '/logger.php';
log_event("status.php started", $_GET);


header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config.php';

$orderId   = $_GET['order_id'] ?? '';
$status    = $_GET['status']   ?? '';
$signature = $_GET['signature'] ?? '';

if (!$orderId || !$status || !$signature) {
    json_out(['error' => 'Missing parameters'], 400);
}

$expected = hash_hmac('sha256', $orderId . $status, hmac_secret());

if (!hash_equals($expected, $signature)) {
    json_out(['error' => 'Invalid signature'], 403);
}

$pdo = db_connect();

// -------------------------------------------------------
// 1️⃣ Fetch session + payment
// -------------------------------------------------------
$stmt = $pdo->prepare("
    SELECT s.id AS session_id, p.reference_id
    FROM sessions s
    LEFT JOIN payments p ON p.order_id = s.order_id
    WHERE s.order_id = :oid
    LIMIT 1
");
$stmt->execute(['oid' => $orderId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$sessionId   = $row['session_id'] ?? null;
$referenceId = $row['reference_id'] ?? null;

$internalStatus = strtoupper($status) === 'success' ? 'SUCCESS' : 'FAILED';

// -------------------------------------------------------
// 2️⃣ Update DB
// -------------------------------------------------------
try {
    if ($sessionId) {
        $pdo->prepare("
            UPDATE sessions
            SET status = :st, updated_at = NOW()
            WHERE id = :sid
        ")->execute([
            'st' => $internalStatus,
            'sid' => $sessionId
        ]);
    }

    if ($referenceId) {
        $pdo->prepare("
            UPDATE payments
            SET status = :st, updated_at = NOW()
            WHERE reference_id = :ref
        ")->execute([
            'st' => $internalStatus,
            'ref' => $referenceId
        ]);
    }

} catch (Throwable $e) {
    error_log("DB update error: " . $e->getMessage());
}

// -------------------------------------------------------
// 3️⃣ Build final WC return URL
// -------------------------------------------------------
$wcBase = wc_base_url(); // defined in config.php

$returnUrl = $wcBase
    . "/?order_id={$orderId}"
    . "&payment_status=" . strtolower($internalStatus);

// -------------------------------------------------------
// 4️⃣ Redirect back to WC checkout result page
// -------------------------------------------------------
header("Location: {$returnUrl}");
exit;
