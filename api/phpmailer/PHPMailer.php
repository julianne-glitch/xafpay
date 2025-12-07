<?php

namespace PHPMailer\PHPMailer;

class PHPMailer
{
    public $isHTML = false;
    public $Subject = '';
    public $Body = '';
    public $ErrorInfo = '';
    public $Host = '';
    public $SMTPAuth = false;
    public $Username = '';
    public $Password = '';
    public $SMTPSecure = '';
    public $Port = 587;
    public $From = '';
    public $FromName = '';
    public $to = [];

    public function isSMTP() {}

    public function setFrom($address, $name = '') {
        $this->From = $address;
        $this->FromName = $name;
    }

    public function addAddress($address) {
        $this->to[] = $address;
    }

    public function isHTML($bool = true) {
        $this->isHTML = $bool;
    }

    public function send() {
        $headers = "From: {$this->FromName} <{$this->From}>\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";

        return mail($this->to[0], $this->Subject, $this->Body, $headers);
    }
}
