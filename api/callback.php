<?php
require_once __DIR__ . '/logger.php';

// Log markers
log_event("wc_callback.php reached", $_GET);
log_event("wc_callback.php query", $_SERVER['QUERY_STRING'] ?? '');

// ------------------------------------------------------------
// CORS
// ------------------------------------------------------------
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
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

// ------------------------------------------------------------
// 1️⃣ Verify signature
// ------------------------------------------------------------
$expected = hash_hmac('sha256', $orderId . 'success', hmac_secret());

if (!hash_equals($expected, $signature)) {
    json_out(['error' => 'Invalid signature'], 403);
}

$pdo = db_connect();

// ------------------------------------------------------------
// 2️⃣ Fetch session + payment (correct join)
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

$sessionId     = $row['session_id'] ?? null;
$referenceId   = $row['reference_id'] ?? null;
$callbackSent  = $row['callback_sent'] ?? false;

// ------------------------------------------------------------
// 3️⃣ Avoid duplicate callbacks
// ------------------------------------------------------------
if ($callbackSent) {
    log_event("wc_callback.php duplicate", $orderId);
}

// Normalize status
$internalStatus = strtolower($status) === 'success' ? 'SUCCESS' : 'FAILED';

// ------------------------------------------------------------
// 4️⃣ Update database
// ------------------------------------------------------------
try {
    if ($sessionId) {
        $pdo->prepare("
            UPDATE sessions
            SET status = :st, updated_at = NOW()
            WHERE id = :sid
        ")->execute([
            'st'  => $internalStatus,
            'sid' => $sessionId
        ]);
    }

    if ($referenceId) {
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
    }

    log_event("wc_callback.php db_update", [
        'order_id' => $orderId,
        'reference_id' => $referenceId,
        'status' => $internalStatus
    ]);

} catch (Throwable $e) {
    log_event("wc_callback.php DB error", $e->getMessage());
}

// ------------------------------------------------------------
// 5️⃣ Redirect to WC "order-received" page
// ------------------------------------------------------------
$wcBase = wc_base_url();   // MUST return https

$returnUrl = $wcBase . "/?order_id={$orderId}&payment_status=" . strtolower($internalStatus);

header("Location: {$returnUrl}");
exit;
