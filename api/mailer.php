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
        // ---- SMTP ONLY ----
        $mail->isSMTP();
        $mail->SMTPAuth = true;

        // ---- SMTP SERVER ----
        $mail->Host = $cfg['host'];          // smtp.mailersend.net
        $mail->Port = (int)$cfg['port'];     // 587
        $mail->Username = $cfg['username'];  // SMTP username
        $mail->Password = $cfg['password'];  // SMTP password

        // ---- ENCRYPTION (old PHPMailer syntax) ----
        $mail->SMTPSecure = 'tls';           // TLS mode

        // ---- FROM / TO ----
        $mail->setFrom($cfg['from_email'], $cfg['from_name']);
        $mail->addAddress($to);

        // ---- CONTENT ----
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $subject;
        $mail->Body    = $html;

        // ---- SEND ----
        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log("MAIL ERROR: " . $mail->ErrorInfo);
        return false;
    }
}
