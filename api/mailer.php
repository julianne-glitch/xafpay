<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Load PHPMailer from your local directory
require_once __DIR__ . '/phpmailer/Exception.php';
require_once __DIR__ . '/phpmailer/PHPMailer.php';
require_once __DIR__ . '/phpmailer/SMTP.php';

require_once __DIR__ . '/../config.php';

function smtp_send($to, $subject, $html, $cfg)
{
    $mail = new PHPMailer(true);

    try {
        // SMTP ONLY — do not allow fallback to sendmail
        $mail->isSMTP();
        $mail->SMTPAuth   = true;

        // Correct TLS (no deprecated constants)
        $mail->SMTPSecure = 'tls';
        $mail->Port       = (int)$cfg['port'];
        $mail->Host       = $cfg['host'];
        $mail->Username   = $cfg['username'];
        $mail->Password   = $cfg['password'];

        // FORCE PHPMailer not to fallback to sendmail
        $mail->SMTPAutoTLS = false;
        $mail->SMTPKeepAlive = false;

        // Set From / To
        $mail->setFrom($cfg['from_email'], $cfg['from_name']);
        $mail->addAddress($to);

        // HTML Content
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';  // Works fine in PHPMailer 6.1 or older
        $mail->Subject = $subject;
        $mail->Body    = $html;

        return $mail->send();

    } catch (Exception $e) {
        error_log("SMTP ERROR: " . $mail->ErrorInfo);
        return false;
    }
}

