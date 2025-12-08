<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/phpmailer/Exception.php';
require_once __DIR__ . '/phpmailer/PHPMailer.php';
require_once __DIR__ . '/phpmailer/SMTP.php';
require_once __DIR__ . '/../config.php';

function smtp_send($to, $subject, $html, $cfg)
{
    $mail = new PHPMailer(true);

    try {
        // 🔥 FORCE SMTP ONLY — disable all fallback
        $mail->isSMTP();
        $mail->Mailer = "smtp";
        $mail->SMTPAuth = true;
        $mail->SMTPAutoTLS = false;     // old PHPMailer fix
        $mail->SMTPDebug = 2;           // to see real error output

        // 🔥 SMTP CONFIG
        $mail->Host       = $cfg['host'];      // smtp.mailersend.net
        $mail->Port       = (int)$cfg['port']; // 587
        $mail->Username   = $cfg['username'];
        $mail->Password   = $cfg['password'];
        $mail->SMTPSecure = "tls";             // important

        // 🔥 HARD BLOCK sendmail fallback
        $mail->Sendmail = "/bin/false";

        // FROM / TO
        $mail->setFrom($cfg['from_email'], $cfg['from_name']);
        $mail->addAddress($to);

        // CONTENT
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = strip_tags($html);

        // SEND
        if (!$mail->send()) {
            error_log("SMTP ERROR: " . $mail->ErrorInfo);
            return false;
        }

        return true;

    } catch (Exception $e) {
        error_log("MAIL EXCEPTION: " . $e->getMessage());
        return false;
    }
}
?>
