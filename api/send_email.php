<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/mailer.php';

function send_receipt_email($to, $orderId, $amount, $phone)
{
    $cfg = mail_cfg();

    $subject  = "Your XafPay Receipt – Order #$orderId";
    $amountFmt = number_format($amount, 0) . " XAF";
    $dateFmt   = date("F j, Y, g:i a");

    // ------------------------------------------------
    // Plain text version (required to avoid spam)
    // ------------------------------------------------
    $plain = 
"Your XafPay Receipt

Order ID: $orderId
Amount: $amountFmt
Phone: $phone
Date: $dateFmt

Thank you for using XafPay.
If you need assistance: support@xafpay.com
© " . date("Y") . " XafPay";


    // ------------------------------------------------
    // HTML version (minimal + bank-style)
    // ------------------------------------------------
    $html = "
    <html>
    <body style='margin:0; padding:0; background:#f5f5f5; font-family:Arial, sans-serif;'>

        <div style='max-width:600px; margin:auto; padding:20px;'>

            <div style='text-align:left; margin-bottom:20px;'>
                <img src='https://pay.xafpay.com/assets/logo.png' alt='XafPay' style='height:40px; opacity:0.9;'>
            </div>

            <div style='background:#ffffff; padding:20px; border:1px solid #e0e0e0; border-radius:6px;'>

                <h2 style='font-size:20px; color:#222; margin-top:0;'>Payment Receipt</h2>

                <p style='font-size:14px; color:#444;'>
                    Thank you for your payment. Below are your transaction details.
                </p>

                <table style='width:100%; margin-top:15px; font-size:14px; color:#333; line-height:1.6;'>
                    <tr><td>Order ID:</td><td style='text-align:right; font-weight:600;'>$orderId</td></tr>
                    <tr><td>Amount:</td><td style='text-align:right; font-weight:600;'>$amountFmt</td></tr>
                    <tr><td>Phone:</td><td style='text-align:right;'>$phone</td></tr>
                    <tr><td>Date:</td><td style='text-align:right;'>$dateFmt</td></tr>
                </table>

            </div>

            <p style='text-align:center; color:#777; font-size:12px; margin-top:25px;'>
                Need help? Email <a href='mailto:support@xafpay.com' style='color:#555;'>support@xafpay.com</a><br>
                © " . date("Y") . " XafPay. All rights reserved.
            </p>

        </div>

    </body>
    </html>
    ";

    // Send multipart email (HTML + plain)
    return smtp_send($to, $subject, $html, $cfg, $plain);
}
?>
