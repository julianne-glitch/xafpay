<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

function smtp_send($to, $subject, $html, $cfg)
{
    $mail = new PHPMailer(true);

    try {
        // Force SMTP
        $mail->isSMTP();
        $mail->Mailer = "smtp";
        $mail->SMTPAuth = true;
        $mail->SMTPAutoTLS = true;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;

        // SMTP host
        $mail->Host       = $cfg['host'];      // smtp.mailersend.net
        $mail->Port       = (int)$cfg['port']; // 587
        $mail->Username   = $cfg['username'];
        $mail->Password   = $cfg['password'];

        // From / To
        $mail->setFrom($cfg['from_email'], $cfg['from_name']);
        $mail->addAddress($to);

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = strip_tags($html);

        return $mail->send();

    } catch (Exception $e) {
        error_log("SMTP ERROR: " . $mail->ErrorInfo);
        return false;
    }
}
