<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// -------------------------
// MANUAL PHPMailer includes
// -------------------------
require_once __DIR__ . '/phpmailer/PHPMailer.php';
require_once __DIR__ . '/phpmailer/SMTP.php';
require_once __DIR__ . '/phpmailer/Exception.php';

require_once __DIR__ . '/../config.php';

function smtp_send($to, $subject, $html, $cfg)
{
    $mail = new PHPMailer(true);

    try {
        // ---------------------------------------------------
        // SMTP SETTINGS
        // ---------------------------------------------------
        $mail->isSMTP();
        $mail->Host       = $cfg['host'];        // smtp.mailersend.net
        $mail->SMTPAuth   = true;
        $mail->Username   = $cfg['username'];    // SMTP user
        $mail->Password   = $cfg['password'];    // SMTP pass
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $cfg['port'];        // 587

        // ---------------------------------------------------
        // FROM / TO
        // ---------------------------------------------------
        $mail->setFrom($cfg['from_email'], $cfg['from_name']);
        $mail->addAddress($to);

        // ---------------------------------------------------
        // CONTENT
        // ---------------------------------------------------
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
