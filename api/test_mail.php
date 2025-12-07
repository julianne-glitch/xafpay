<?php
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/mailer.php";

$cfg = mail_cfg();

// Your testing email
$to = "julianneasanji6@gmail.com";
$subject = "XafPay SMTP Test Email";
$html = "
    <h2 style='color:#e60023;'>XafPay Email Test</h2>
    <p>If you received this email, your MailerSend SMTP setup is working correctly.</p>
";

$result = smtp_send($to, $subject, $html, $cfg);

if ($result) {
    echo json_encode(["ok" => true, "message" => "Email sent successfully to $to"]);
} else {
    echo json_encode(["ok" => false, "message" => "Email FAILED — check SMTP credentials or logs"]);
}
