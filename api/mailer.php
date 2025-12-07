<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Manual PHPMailer include
require_once __DIR__ . '/phpmailer/PHPMailer.php';
require_once __DIR__ . '/phpmailer/SMTP.php';
require_once __DIR__ . '/phpmailer/Exception.php';

require_once __DIR__ . '/../config.php';

function smtp_send($to, $subject, $html, $cfg)
{
    $mail = new PHPMailer(true);

    try {
        // SMTP CONFIG
        $mail->isSMTP();
        $mail->Host       = $cfg['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $cfg['username'];
        $mail->Password   = $cfg['password'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;   // ⭐ correct constant
        $mail->Port       = $cfg['port'];

        // Charset (no deprecation warning)
        $mail->CharSet = PHPMailer::CHARSET_UTF8;

        // FROM / TO
        $mail->setFrom($cfg['from_email'], $cfg['from_name']);
        $mail->addAddress($to);

        // CONTENT
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log("MAIL ERROR: " . $mail->ErrorInfo);
        return false;
    }
}
