<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/phpmailer/PHPMailer.php';
require_once __DIR__ . '/phpmailer/SMTP.php';
require_once __DIR__ . '/phpmailer/Exception.php';

require_once __DIR__ . '/../config.php';

function smtp_send($to, $subject, $html, $cfg)
{
    $mail = new PHPMailer(true);

    try {
        // 🔥 Force SMTP only — never fallback to sendmail
        $mail->isSMTP();
        $mail->Mailer = "smtp";
        $mail->SMTPAuth = true;
        $mail->SMTPAutoTLS = true;  // Ensures TLS 1.2+

        // SMTP CONFIG
        $mail->Host       = $cfg['host'];        // smtp.mailersend.net
        $mail->Port       = (int)$cfg['port'];   // 587
        $mail->Username   = $cfg['username'];    // SMTP user
        $mail->Password   = $cfg['password'];    // SMTP pass
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;

        // CHARSET
        $mail->CharSet = 'UTF-8';

        // FROM / TO
        $mail->setFrom($cfg['from_email'], $cfg['from_name']);
        $mail->addAddress($to);

        // CONTENT
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;

        // 🔥 Correct way to prevent sendmail fallback:
        $mail->AllowEmpty = false;   // Disable fallback
        $mail->Sendmail   = null;    // Must NOT be a fake path

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log("MAIL ERROR: " . $mail->ErrorInfo);
        return false;
    }
}
