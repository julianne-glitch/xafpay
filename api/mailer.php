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
        // FORCE SMTP ONLY
        $mail->isSMTP();
        $mail->Mailer = "smtp";
        $mail->SMTPAuth = true;
        $mail->SMTPAutoTLS = false;  // disable auto-TLS guessing

        // SMTP CONFIG
        $mail->Host       = $cfg['host'];
        $mail->Port       = (int)$cfg['port']; // 587
        $mail->Username   = $cfg['username'];
        $mail->Password   = $cfg['password'];
        $mail->SMTPSecure = 'tls'; // use TLS explicitly

        // BLOCK SENDMAIL COMPLETELY
        $mail->Sendmail = '/bin/false';

        // FROM / TO
        $mail->setFrom($cfg['from_email'], $cfg['from_name']);
        $mail->addAddress($to);

        // CONTENT
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $subject;
        $mail->Body    = $html;

        // SEND
        if (!$mail->send()) {
            error_log("SMTP SEND FAILED: " . $mail->ErrorInfo);
            return false;
        }

        return true;

    } catch (Exception $e) {
        error_log("MAIL EXCEPTION: " . $e->getMessage());
        return false;
    }
}
