<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/phpmailer/Exception.php';
require_once __DIR__ . '/phpmailer/PHPMailer.php';
require_once __DIR__ . '/phpmailer/SMTP.php';
require_once __DIR__ . '/../config.php';

function smtp_send($to, $subject, $html, $cfg)
{
    $mail = new PHPMailer();

    try {
        $mail->IsSMTP();         // SMTP only
        $mail->SMTPAuth = true;
        $mail->SMTPDebug = 2;    // SHOW REAL SMTP ERROR

        // SMTP CONFIG
        $mail->Host     = $cfg['host'];
        $mail->Port     = (int)$cfg['port'];
        $mail->Username = $cfg['username'];
        $mail->Password = $cfg['password'];
        $mail->SMTPSecure = "tls";

        // FROM/TO
        $mail->SetFrom($cfg['from_email'], $cfg['from_name']);
        $mail->AddAddress($to);

        // CONTENT
        $mail->IsHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;

        // SEND
        if (!$mail->Send()) {
            error_log("SMTP ERROR: " . $mail->ErrorInfo);
            return false;
        }
        return true;

    } catch (Exception $e) {
        error_log("MAIL EXCEPTION: " . $mail->ErrorInfo);
        return false;
    }
}
