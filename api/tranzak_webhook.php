<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/send_email.php';

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// -------------------------------------
// READ RAW PAYLOAD
// -------------------------------------
$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

$logFile = "/tmp/tranzak_webhook.log";
file_put_contents($logFile, date('c') . " RAW: " . $raw . PHP_EOL, FILE_APPEND);

// Tranzak XP021 payload wrapper
$payload = $data["resource"] ?? $data["data"] ?? $data;

// Extract references
$orderRef = $payload["mchTransactionRef"] ?? null;  // internal XafPay order ID
$txId     = $payload["transactionId"] ?? null;      // Tranzak transaction ID

// Normalize status
$statusRaw = strtolower($payload["transactionStatus"] ?? $payload["status"] ?? "");
$status = match ($statusRaw) {
    "successful", "success", "completed" => "successful",
    "failed", "error"                    => "failed",
    "canceled", "cancelled"              => "canceled",
    "expired"                            => "expired",
    default                               => "pending"
};

file_put_contents($logFile, date('c') . " REF:$orderRef STATUS:$status" . PHP_EOL, FILE_APPEND);

// -------------------------------------
// CONNECT DB
// -------------------------------------
try {
    $pdo = db_connect();
} catch (Throwable $e) {
    file_put_contents($logFile, "DB ERROR: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
    http_response_code(500);
    echo json_encode(["ok" => false]);
    exit;
}

// -------------------------------------
// SAVE WEBHOOK RAW
// -------------------------------------
try {
    $pdo->prepare("
        INSERT INTO webhooks(reference_id, payload)
        VALUES(:ref, :payload)
    ")->execute([
        ":ref" => $orderRef,
        ":payload" => json_encode($payload)
    ]);
} catch (Throwable $e) {
    file_put_contents($logFile, "WEBHOOK SAVE ERROR: ".$e->getMessage().PHP_EOL, FILE_APPEND);
}

// -------------------------------------
// GET SESSION (email, wc_order_id, etc.)
// -------------------------------------
$stmt = $pdo->prepare("
    SELECT email, amount, phone_number, wc_order_id
    FROM sessions
    WHERE order_id = :oid
    LIMIT 1
");
$stmt->execute([":oid" => $orderRef]);
$session = $stmt->fetch();

$customerEmail = $session["email"] ?? null;
$amount        = $session["amount"] ?? ($payload["amount"] ?? 0);
$customerPhone = $session["phone_number"] ?? null;
$wc_order_id   = $session["wc_order_id"] ?? null;

file_put_contents($logFile, date('c') . " WC_ORDER_ID: $wc_order_id" . PHP_EOL, FILE_APPEND);

// -------------------------------------
// UPDATE PAYMENTS TABLE
// -------------------------------------
try {
    $pdo->prepare("
        UPDATE payments SET 
            status = :st,
            transaction_id = :tx,
            response_payload = :payload,
            updated_at = NOW()
        WHERE reference_id = :ref
    ")->execute([
        ":st" => $status,
        ":tx" => $txId,
        ":payload" => json_encode($payload),
        ":ref" => $orderRef
    ]);
} catch (Throwable $e) {
    file_put_contents($logFile, "PAYMENTS UPDATE ERROR: ".$e->getMessage().PHP_EOL, FILE_APPEND);
}

// -------------------------------------
// UPDATE SESSIONS TABLE
// -------------------------------------
try {
    $pdo->prepare("
        UPDATE sessions SET 
            status = :st,
            updated_at = NOW()
        WHERE order_id = :ref
    ")->execute([
        ":st" => $status,
        ":ref" => $orderRef
    ]);
} catch (Throwable $e) {
    file_put_contents($logFile, "SESSION UPDATE ERROR: ".$e->getMessage().PHP_EOL, FILE_APPEND);
}


// ------------------------------------------------------------
// 🚀  WOO UPDATE (ONLY ON SUCCESS)
// ------------------------------------------------------------
if ($status === "successful" && $wc_order_id && $txId) {

    $wcListener = getenv("WC_LISTENER_URL");
    $secret     = getenv("WC_SECRET_KEY");

    if ($wcListener && $secret) {

        // SIGNATURE
        $signature = hash_hmac('sha256', $wc_order_id . $txId, $secret);

        // FINAL WC URL
        $wcUrl = rtrim($wcListener, "/") .
            "/?order_id={$wc_order_id}" .
            "&tx={$txId}" .
            "&sig={$signature}";

        // Hit WooCommerce
        $resp = @file_get_contents($wcUrl);

        file_put_contents(
            $logFile,
            date('c') . " WC_UPDATE_CALL: $wcUrl RESPONSE: " . $resp . PHP_EOL,
            FILE_APPEND
        );

    } else {
        file_put_contents(
            $logFile,
            date('c') . " ⚠ MISSING WC_LISTENER_URL or WC_SECRET_KEY" . PHP_EOL,
            FILE_APPEND
        );
    }
}


// ------------------------------------------------------------
// EMAIL NOTIFICATION
// ------------------------------------------------------------
if ($status === "successful" && $customerEmail) {

    $success = send_receipt_email($customerEmail, $orderRef, $amount, $customerPhone);

    if ($success) {
        file_put_contents($logFile, date('c') . " EMAIL SENT TO $customerEmail" . PHP_EOL, FILE_APPEND);
    } else {
        file_put_contents($logFile, date('c') . " EMAIL FAILED FOR $customerEmail" . PHP_EOL, FILE_APPEND);
    }
}


// ------------------------------------------------------------
// ALWAYS RETURN OK TO TRANZAK
// ------------------------------------------------------------
echo json_encode(["ok" => true, "status" => $status]);
exit;
