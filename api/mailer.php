<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Use Composer autoload (loads full PHPMailer library)
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

function smtp_send($to, $subject, $html, $cfg)
{
    $mail = new PHPMailer(true);

    try {
        // SMTP ONLY
        $mail->isSMTP();
        $mail->SMTPAuth   = true;
        $mail->SMTPAutoTLS = true;
        $mail->Host       = $cfg['host'];
        $mail->Port       = (int)$cfg['port'];
        $mail->Username   = $cfg['username'];
        $mail->Password   = $cfg['password'];
        $mail->SMTPSecure = 'tls';

        // FROM / TO
        $mail->setFrom($cfg['from_email'], $cfg['from_name']);
        $mail->addAddress($to);

        // EMAIL CONTENT
        $mail->isHTML(true);
        $mail->setCharSet('UTF-8');
        $mail->Subject = $subject;
        $mail->Body    = $html;

        return $mail->send();

    } catch (Exception $e) {
        error_log("SMTP ERROR: " . $mail->ErrorInfo);
        return false;
    }
}
