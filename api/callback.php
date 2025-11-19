<?php
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/../config.php';

// Log markers
log_event("callback.php reached", [
    'GET'     => $_GET,
    'raw'     => $_SERVER['QUERY_STRING'] ?? ''
]);

// ------------------------------------------------------------
// CORS
// ------------------------------------------------------------
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$orderId   = $_GET['order_id'] ?? '';
$status    = $_GET['status']   ?? '';
$signature = $_GET['signature'] ?? '';

if (!$orderId || !$status || !$signature) {
    json_out(['ok' => false, 'error' => 'Missing parameters'], 400);
}

// ------------------------------------------------------------
// 1️⃣ Verify signature
// ------------------------------------------------------------
$expected = hash_hmac('sha256', $orderId . 'success', hmac_secret());

if (!hash_equals($expected, $signature)) {
    json_out(['ok' => false, 'error' => 'Invalid signature'], 403);
}

$pdo = db_connect();

// ------------------------------------------------------------
// 2️⃣ Fetch session + payment
// ------------------------------------------------------------
$stmt = $pdo->prepare("
    SELECT 
        s.id AS session_id,
        p.reference_id,
        p.callback_sent
    FROM sessions s
    LEFT JOIN payments p ON p.session_id = s.id
    WHERE s.order_id = :oid
    LIMIT 1
");
$stmt->execute(['oid' => $orderId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    json_out(['ok' => false, 'error' => 'Order not found in DB'], 404);
}

$sessionId     = $row['session_id'];
$referenceId   = $row['reference_id'];
$callbackSent  = (bool)($row['callback_sent'] ?? false);

// ------------------------------------------------------------
// 3️⃣ Avoid duplicate callbacks (exit immediately)
// ------------------------------------------------------------
if ($callbackSent) {
    log_event("callback.php duplicate_callback", $orderId);
    // Still redirect user to order page
    $wcBase = wc_base_url();
    header("Location: {$wcBase}/?order_id={$orderId}&payment_status=success");
    exit;
}

// Normalize status
$internalStatus = strtolower($status) === 'success' ? 'SUCCESS' : 'FAILED';

// ------------------------------------------------------------
// 4️⃣ Update DB
// ------------------------------------------------------------
try {
    // Update session
    $pdo->prepare("
        UPDATE sessions
        SET status = :st, updated_at = NOW()
        WHERE id = :sid
    ")->execute([
        'st'  => $internalStatus,
        'sid' => $sessionId
    ]);

    // Update payment row
    $pdo->prepare("
        UPDATE payments
        SET status = :st,
            callback_sent = TRUE,
            updated_at = NOW()
        WHERE reference_id = :ref
    ")->execute([
        'st'  => $internalStatus,
        'ref' => $referenceId
    ]);

    log_event("callback.php db_update", [
        'order_id' => $orderId,
        'reference_id' => $referenceId,
        'status' => $internalStatus
    ]);

} catch (Throwable $e) {
    log_event("callback.php DB error", $e->getMessage());
    json_out(['ok' => false, 'error' => $e->getMessage()], 500);
}

// ------------------------------------------------------------
// 5️⃣ Redirect user to final WC order page
// ------------------------------------------------------------
$wcBase = wc_base_url();

$returnUrl = "{$wcBase}/?order_id={$orderId}&payment_status=" . strtolower($internalStatus);

log_event("callback.php redirecting", $returnUrl);

// WC redirect should happen AFTER JSON_OUT? No. For browser, redirect is correct
header("Location: {$returnUrl}");
exit;
