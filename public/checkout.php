<?php
echo "<pre>";
echo "Base URL: " . base_url() . "\n";
echo "HMAC_SECRET: " . hmac_secret() . "\n";
$amount   = isset($_REQUEST['amount']) ? (int)$_REQUEST['amount'] : 0;
$currency = $_REQUEST['currency'] ?? mtn_cfg()['currency'];
$orderId  = $_REQUEST['order_id'] ?? ('ORD-' . time());

if ($amount <= 0) {
  json_out(['error' => 'amount must be > 0'], 400);
}

$sessionId   = 'sess_' . bin2hex(random_bytes(6));
$payload     = ['session_id' => $sessionId, 'order_id' => $orderId, 'amount' => $amount, 'currency' => $currency];
$sig         = hmac_sign($payload, hmac_secret());

$checkoutUrl = base_url()
  ? base_url() . "/checkout?session_id={$sessionId}&order_id={$orderId}&amount={$amount}&currency={$currency}&sig={$sig}"
  : "http://localhost:8000/checkout?session_id={$sessionId}&order_id={$orderId}&amount={$amount}&currency={$currency}&sig={$sig}";

json_out([
  'ok'           => true,
  'session_id'   => $sessionId,
  'order_id'     => $orderId,
  'amount'       => $amount,
  'currency'     => $currency,
  'checkout_url' => $checkoutUrl,
]);
