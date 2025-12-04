<?php
require_once __DIR__ . '/../config.php';

/**
 * send_receipt_email($to, $orderId, $amount, $phone)
 * Sends a professional HTML XafPay-branded receipt.
 */
function send_receipt_email($to, $orderId, $amount, $phone)
{
    $subject = "Payment Receipt – XafPay (#$orderId)";
    $from    = "no-reply@xafpay.com";

    $amountFmt = number_format($amount, 0) . " XAF";
    $dateFmt   = date("F j, Y, g:i a");

    $html = "
    <html>
    <body style='font-family: Arial, sans-serif; background:#f6f6f6; padding:20px;'>

        <div style='max-width:600px; margin:auto; background:white; border-radius:10px; overflow:hidden; box-shadow:0 4px 12px rgba(0,0,0,0.1);'>

            <!-- HEADER -->
            <div style='background:#e60023; padding:20px; text-align:center;'>
                <img src='https://xafpay.com/logo.png' alt='XafPay' style='height:60px;' />
            </div>

            <!-- BODY -->
            <div style='padding:25px;'>
                <h2 style='color:#111; margin-bottom:10px;'>Payment Successful</h2>
                <p style='font-size:15px; color:#444;'>
                    Thank you for using <strong>XafPay</strong>.  
                    Your transaction has been completed successfully.
                </p>

                <div style='margin-top:25px;'>
                    <table style='width:100%; border-collapse:collapse;'>
                        <tr>
                            <td style='padding:8px 0; color:#555;'>Order ID:</td>
                            <td style='padding:8px 0; text-align:right; color:#111;'><strong>$orderId</strong></td>
                        </tr>
                        <tr>
                            <td style='padding:8px 0; color:#555;'>Amount Paid:</td>
                            <td style='padding:8px 0; text-align:right; color:#111;'><strong>$amountFmt</strong></td>
                        </tr>
                        <tr>
                            <td style='padding:8px 0; color:#555;'>Phone Number:</td>
                            <td style='padding:8px 0; text-align:right; color:#111;'>$phone</td>
                        </tr>
                        <tr>
                            <td style='padding:8px 0; color:#555;'>Date:</td>
                            <td style='padding:8px 0; text-align:right; color:#111;'>$dateFmt</td>
                        </tr>
                    </table>
                </div>

                <div style='margin-top:30px; padding:15px; background:#fafafa; border-radius:8px; text-align:center;'>
                    <p style='color:#777; font-size:14px;'>
                        If you have any questions, contact us at  
                        <a href='mailto:support@xafpay.com' style='color:#e60023; font-weight:bold;'>support@xafpay.com</a>
                    </p>
                </div>

            </div>

            <!-- FOOTER -->
            <div style='background:#111; padding:20px; text-align:center; color:#bbb; font-size:13px;'>
                © " . date("Y") . " XafPay. All rights reserved.
            </div>

        </div>
    </body>
    </html>";

    $headers  = "From: XafPay <$from>\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";

    return mail($to, $subject, $html, $headers);
}
?>
