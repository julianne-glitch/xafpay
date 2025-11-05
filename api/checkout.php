<?php
// Minimal hosted page (works local and Render)
$session = $_GET['session_id'] ?? '';
$orderId = $_GET['order_id'] ?? '';
$amount  = (int)($_GET['amount'] ?? 0);
$currency= $_GET['currency'] ?? 'XAF';
$sig     = $_GET['sig'] ?? '';
$calcSig = hmac_sign(['session_id'=>$session,'order_id'=>$orderId,'amount'=>$amount,'currency'=>$currency], hmac_secret());

// Very light integrity check
if (!$session || !$orderId || !$amount || $sig !== $calcSig) {
  http_response_code(400);
  echo "<h1>Invalid checkout session</h1>";
  exit;
}
$base = base_url() ?: 'http://localhost:8000';
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>XafPay Checkout</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <style>
    body { font-family: system-ui, sans-serif; max-width: 560px; margin: 40px auto; padding: 0 16px; }
    .card { border: 1px solid #eee; border-radius: 12px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,.05); }
    .row { display:flex; justify-content:space-between; margin:8px 0; }
    button { padding: 12px 16px; border: 0; border-radius: 10px; cursor: pointer; }
    .primary { background:#111; color:#fff; }
    .muted { color:#666; font-size:14px; }
    .status { margin-top:12px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size:13px; white-space:pre-wrap; }
  </style>
</head>
<body>
  <h1>Pay with XafPay</h1>
  <div class="card">
    <div class="row"><div>Order</div><div><b><?=htmlspecialchars($orderId)?></b></div></div>
    <div class="row"><div>Amount</div><div><b><?=number_format($amount)?> <?=$currency?></b></div></div>
    <div class="row"><div>Session</div><div class="muted"><?=htmlspecialchars($session)?></div></div>
    <hr>
    <p>Confirm to send a MoMo push (sandbox) to the demo payer.</p>
    <button id="pay" class="primary">Pay with MTN MoMo (sandbox)</button>
    <div class="status" id="status"></div>
  </div>

  <script>
    const statusBox = document.getElementById('status');
    const payBtn = document.getElementById('pay');

    function log(msg){ statusBox.textContent += msg + "\n"; }

    async function doPay() {
      payBtn.disabled = true;
      log("Starting payment...");

      try {
        const url = "<?=$base?>/api/pay.php?amount=<?=$amount?>&currency=<?=$currency?>&order_id=<?=urlencode($orderId)?>";
        const r = await fetch(url);
        const j = await r.json();
        if(!j.ok){ log("Error: " + (j.error || "unknown")); payBtn.disabled=false; return; }

        log("reference_id: " + j.reference_id);
        const statusUrl = j.status_url;

        // poll status every 3s
        const timer = setInterval(async ()=>{
          const rs = await fetch(statusUrl);
          const js = await rs.json();
          if(js.ok){
            log("status: " + js.data.status);
            if(js.data.status === 'SUCCESSFUL'){
              clearInterval(timer);
              log("✅ Payment successful.");
            }
            if(js.data.status === 'FAILED'){
              clearInterval(timer);
              log("❌ Payment failed.");
            }
          } else {
            log("status error: " + (js.error||''));
          }
        }, 3000);

      } catch(e){
        log("Exception: " + e.message);
        payBtn.disabled = false;
      }
    }

    payBtn.addEventListener('click', doPay);
  </script>
</body>
</html>
