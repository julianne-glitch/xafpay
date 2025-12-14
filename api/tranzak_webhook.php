<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/send_email.php';

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ========================================================================
// 1️⃣ READ RAW PAYLOAD + BASIC VALIDATION
// ========================================================================
$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

$logFile = "/tmp/tranzak_webhook.log";
file_put_contents($logFile, "---- NEW HOOK " . date('c') . " ----\nRAW: $raw\n", FILE_APPEND);

if (!$data) {
    file_put_contents($logFile, "❌ Invalid JSON\n", FILE_APPEND);
    http_response_code(400);
    exit(json_encode(["ok" => false, "error" => "Invalid JSON"]));
}

// Tranzak wrapper normalization
$payload = $data["resource"] ?? $data["data"] ?? $data;

// Extract internal identifiers
$orderRef = $payload["mchTransactionRef"] ?? null;  // internal XafPay order ID
$txId     = $payload["transactionId"] ?? null;

// Normalize status
$statusRaw = strtolower($payload["transactionStatus"] ?? $payload["status"] ?? "");
$status = match ($statusRaw) {
    "successful", "success", "completed" => "successful",
    "failed", "error"                    => "failed",
    "canceled", "cancelled"              => "canceled",
    "expired"                            => "expired",
    default                               => "pending"
};

file_put_contents($logFile, "OrderRef=$orderRef  Status=$status  TxId=$txId\n", FILE_APPEND);

if (!$orderRef) {
    file_put_contents($logFile, "❌ Missing orderRef\n", FILE_APPEND);
    exit(json_encode(["ok" => false]));
}

// ========================================================================
// 2️⃣ CONNECT DATABASE
// ========================================================================
try {
    $pdo = db_connect();
} catch (Throwable $e) {
    file_put_contents($logFile, "❌ DB ERROR: {$e->getMessage()}\n", FILE_APPEND);
    http_response_code(500);
    exit(json_encode(["ok" => false]));
}

// ========================================================================
// 3️⃣ SAVE RAW WEBHOOK
// ========================================================================
try {
    $pdo->prepare("
        INSERT INTO webhooks(reference_id, payload)
        VALUES(:ref, :payload)
    ")->execute([
        ":ref"     => $orderRef,
        ":payload" => json_encode($payload)
    ]);
} catch (Throwable $e) {
    file_put_contents($logFile, "⚠ WEBHOOK SAVE ERROR: {$e->getMessage()}\n", FILE_APPEND);
}

// ========================================================================
// 4️⃣ GET SESSION DETAILS (wc_order_id, email, etc.)
// ========================================================================
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

file_put_contents($logFile, "WC_ORDER_ID=$wc_order_id\n", FILE_APPEND);

// ========================================================================
// 5️⃣ UPDATE PAYMENT + SESSION STATUS
// ========================================================================
try {
    $pdo->prepare("
        UPDATE payments SET 
            status = :st,
            transaction_id = :tx,
            response_payload = :payload,
            updated_at = NOW()
        WHERE reference_id = :ref
    ")->execute([
        ":st"      => $status,
        ":tx"      => $txId,
        ":payload" => json_encode($payload),
        ":ref"     => $orderRef
    ]);

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
    file_put_contents($logFile, "⚠ PAYMENT UPDATE ERROR: {$e->getMessage()}\n", FILE_APPEND);
}

// ========================================================================
// 6️⃣ NOTIFY WOOCOMMERCE (ONLY ONCE) → FINAL FIX
// ========================================================================
if ($status === "successful" && $wc_order_id && $txId) {

    // Check duplicate notification
    $stmt = $pdo->prepare("SELECT wc_notified FROM payments WHERE reference_id = :ref LIMIT 1");
    $stmt->execute([":ref" => $orderRef]);
    $row = $stmt->fetch();

    if (!$row || !$row["wc_notified"]) {

        $wcWebhookUrl = getenv("WC_LISTENER_URL"); // correct: /wp-json/xafpay/v1/webhook

        $payload = json_encode([
            "order_id"       => intval($wc_order_id),
            "status"         => "SUCCESS",
            "transaction_id" => $txId
        ]);

        // POST JSON →
        $ch = curl_init($wcWebhookUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "Content-Length: " . strlen($payload)
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        file_put_contents($logFile,
            "WOOCOMMERCE POST → $wcWebhookUrl\nPAYLOAD: $payload\nRESPONSE: $response\nERROR: $error\n",
            FILE_APPEND
        );

        // Mark WooCommerce notified
        $pdo->prepare("UPDATE payments SET wc_notified = TRUE WHERE reference_id = :ref")
            ->execute([":ref" => $orderRef]);
    } else {
        file_put_contents($logFile, "🟡 WooCommerce already notified — skipping.\n", FILE_APPEND);
    }
}

// ========================================================================
// 7️⃣ SEND EMAIL RECEIPT (ONLY ONCE)
// ========================================================================
if ($status === "successful" && $customerEmail) {

    $stmt = $pdo->prepare("SELECT email_sent FROM payments WHERE reference_id = :ref LIMIT 1");
    $stmt->execute([":ref" => $orderRef]);
    $row = $stmt->fetch();

    if (!$row || !$row["email_sent"]) {

        $success = send_receipt_email($customerEmail, $orderRef, $amount, $customerPhone);

        if ($success) {
            $pdo->prepare("UPDATE payments SET email_sent = TRUE WHERE reference_id = :ref")
                ->execute([":ref" => $orderRef]);

            file_put_contents($logFile, "EMAIL SENT to $customerEmail\n", FILE_APPEND);

        } else {
            file_put_contents($logFile, "❌ EMAIL FAILED for $customerEmail\n", FILE_APPEND);
        }

    } else {
        file_put_contents($logFile, "🟡 Email already sent — skipping.\n", FILE_APPEND);
    }
}

// ========================================================================
// 8️⃣ ALWAYS RETURN 200 TO TRANZAK
// ========================================================================
echo json_encode(["ok" => true, "status" => $status]);
exit;

