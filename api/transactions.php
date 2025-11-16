<?php
// Allow requests from your React dev server
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Respond quickly to preflight checks
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(200);
  exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_auth.php'; // ✅ Merchant authentication
$pdo = db_connect();
$merchant = require_merchant($pdo); // Ensures only valid merchants access this

try {

    // ==============================
    // GET — List all transactions
    // ==============================
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $pdo->prepare("
            SELECT t.id, t.reference_id, t.session_id, t.order_id, t.amount, t.currency, 
                   t.status, t.created_at, p.carrier
            FROM transactions t
            LEFT JOIN payments p ON p.session_id = t.session_id
            ORDER BY t.created_at DESC
            LIMIT 50
        ");
        $stmt->execute();
        $data = $stmt->fetchAll();
        json_out(['status' => 'ok', 'data' => $data]);
    }

    // ==============================
    // POST — Create new transaction (system use only)
    // ==============================
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);

        if (empty($input['session_id']) || empty($input['amount']) || empty($input['order_id'])) {
            json_out(['status' => 'error', 'message' => 'Missing required fields'], 400);
        }

        // ✅ Ensure the session exists
        $check = $pdo->prepare("SELECT 1 FROM sessions WHERE id = :sid");
        $check->execute(['sid' => $input['session_id']]);
        if (!$check->fetch()) {
            json_out(['status' => 'error', 'message' => 'Invalid session_id'], 400);
        }

        // ✅ Create reference ID
        $reference_id = uuidv4();

        $stmt = $pdo->prepare("
            INSERT INTO transactions(reference_id, session_id, order_id, amount, currency, status, created_at)
            VALUES(:ref, :sid, :oid, :amt, :cur, :status, NOW())
        ");
        $stmt->execute([
            'ref' => $reference_id,
            'sid' => $input['session_id'],
            'oid' => $input['order_id'],
            'amt' => $input['amount'],
            'cur' => $input['currency'] ?? 'XAF',
            'status' => $input['status'] ?? 'PENDING'
        ]);

        // ✅ Log event
        log_event($pdo, 'transaction_created', 'New transaction recorded', [
            'merchant' => $merchant['email'],
            'reference_id' => $reference_id,
            'order_id' => $input['order_id']
        ]);

        json_out([
            'status' => 'ok',
            'message' => 'Transaction created successfully',
            'reference_id' => $reference_id
        ]);
    }

} catch (Throwable $e) {
    json_out(['status' => 'error', 'message' => $e->getMessage()], 500);
}
