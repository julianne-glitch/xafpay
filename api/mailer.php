<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Include PHPMailer manually
require_once __DIR__ . '/phpmailer/PHPMailer.php';
require_once __DIR__ . '/phpmailer/SMTP.php';
require_once __DIR__ . '/phpmailer/Exception.php';

require_once __DIR__ . '/../config.php';

function smtp_send($to, $subject, $html, $cfg)
{
    $mail = new PHPMailer(true);

    try {
        // ---------------------------------------------------
        // SMTP MODE FIRST
        // ---------------------------------------------------
        $mail->isSMTP();

        // ---------------------------------------------------
        // DEBUG (prints to Render logs)
        // ---------------------------------------------------
        $mail->SMTPDebug = 2;
        $mail->Debugoutput = 'error_log';

        // ---------------------------------------------------
        // SMTP SETTINGS
        // ---------------------------------------------------
        $mail->Host       = $cfg['host'];      
        $mail->SMTPAuth   = true;
        $mail->Username   = $cfg['username'];  
        $mail->Password   = $cfg['password'];  
        $mail->SMTPSecure = 'tls';             
        $mail->Port       = $cfg['port'];      

        // ---------------------------------------------------
        // FROM / TO
        // ---------------------------------------------------
        $mail->setFrom($cfg['from_email'], $cfg['from_name']);
        $mail->addAddress($to);

        // ---------------------------------------------------
        // CONTENT
        // ---------------------------------------------------
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';     // ⭐ IMPORTANT FIX
        $mail->Subject = $subject;
        $mail->Body    = $html;

        // ---------------------------------------------------
        // SEND
        // ---------------------------------------------------
        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log("MAIL ERROR: " . $mail->ErrorInfo);
        return false;
    }
}
