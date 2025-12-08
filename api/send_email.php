<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/mailer.php';

function send_receipt_email($to, $orderId, $amount, $phone)
{
    $cfg = mail_cfg();

    $subject = "Payment Receipt – XafPay (#$orderId)";
    $amountFmt = number_format($amount, 0) . " XAF";
    $dateFmt   = date("F j, Y, g:i a");

    $html = "
    <html>
    <body style='font-family: Arial, sans-serif; background:#f6f6f6; padding:20px;'>

        <div style='max-width:600px; margin:auto; background:white; border-radius:10px; overflow:hidden; box-shadow:0 4px 12px rgba(0,0,0,0.1);'>

            <div style='background:#e60023; padding:20px; text-align:center;'>
                <img src='https://pay.xafpay.com/assets/logo.png' alt='XafPay' style='height:60px;' />
            </div>

            <div style='padding:25px;'>
                <h2 style='color:#111;'>Payment Successful</h2>
                <p style='font-size:15px; color:#444;'>
                    Thank you for using <strong>XafPay</strong>.
                </p>

                <table style='width:100%; margin-top:20px; font-size:15px;'>
                    <tr><td>Order ID:</td><td align='right'><strong>$orderId</strong></td></tr>
                    <tr><td>Amount:</td><td align='right'><strong>$amountFmt</strong></td></tr>
                    <tr><td>Phone:</td><td align='right'>$phone</td></tr>
                    <tr><td>Date:</td><td align='right'>$dateFmt</td></tr>
                </table>

                <p style='margin-top:25px; font-size:14px; color:#777; text-align:center;'>
                    Need help? Email us at 
                    <a href='mailto:support@xafpay.com' style='color:#e60023;'>
                        support@xafpay.com
                    </a>
                </p>
            </div>

            <div style='background:#111; padding:20px; text-align:center; color:#bbb; font-size:13px;'>
                © " . date("Y") . " XafPay
            </div>

        </div>

    </body>
    </html>";

    return smtp_send($to, $subject, $html, $cfg);
}
?>
