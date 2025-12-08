<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/send_email.php';

// CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// RAW PAYLOAD
$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

$logFile = "/tmp/tranzak_webhook.log";
file_put_contents($logFile, date('c')." RAW: ".$raw.PHP_EOL, FILE_APPEND);

// Extract XP021 structure
$payload = $data["resource"] ?? $data["data"] ?? $data;

$orderRef = $payload["mchTransactionRef"] ?? null;
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

file_put_contents($logFile, date('c')." REF:$orderRef STATUS:$status".PHP_EOL, FILE_APPEND);

// DB CONNECT
try {
    $pdo = db_connect();
} catch (Throwable $e) {
    file_put_contents($logFile, "DB ERROR: ".$e->getMessage().PHP_EOL, FILE_APPEND);
    http_response_code(500);
    echo json_encode(["ok" => false]);
    exit;
}

// SAVE WEBHOOK RAW
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

// FETCH SESSION — WE NEED EMAIL + AMOUNT
$stmt = $pdo->prepare("
    SELECT email, amount, phone_number
    FROM sessions
    WHERE order_id = :oid
    LIMIT 1
");
$stmt->execute([":oid" => $orderRef]);
$session = $stmt->fetch();

$customerEmail = $session["email"] ?? null;
$amount        = $session["amount"] ?? ($payload["amount"] ?? 0);  // fallback
$customerPhone = $session["phone_number"] ?? null;

// UPDATE PAYMENTS
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

// UPDATE SESSION STATUS
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

// SEND EMAIL ONLY ON SUCCESSFUL PAYMENT
if ($status === "successful" && $customerEmail) {

    $success = send_receipt_email($customerEmail, $orderRef, $amount, $customerPhone);

    if ($success) {
        file_put_contents($logFile, date('c')." EMAIL SENT TO $customerEmail".PHP_EOL, FILE_APPEND);
    } else {
        file_put_contents($logFile, date('c')." EMAIL FAILED FOR $customerEmail".PHP_EOL, FILE_APPEND);
    }
}

// ALWAYS RETURN OK (Tranzak expects this)
echo json_encode(["ok" => true, "status" => $status]);
exit;

