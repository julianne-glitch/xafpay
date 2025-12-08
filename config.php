<?php

use Dotenv\Dotenv;

// ------------------------------------------------------------
// Prevent double-loading
// ------------------------------------------------------------
if (!defined('CONFIG_LOADED')) {
    define('CONFIG_LOADED', true);

    // Composer autoload (Dotenv / Guzzle if installed)
    if (file_exists(__DIR__ . '/vendor/autoload.php')) {
        require_once __DIR__ . '/vendor/autoload.php';
    }

    // Load .env only in local development
    if (class_exists(Dotenv::class) && file_exists(__DIR__ . '/.env')) {
        $dotenv = Dotenv::createImmutable(__DIR__);
        $dotenv->load();
    }

    // --------------------------------------------------------
    // ENV HELPER
    // --------------------------------------------------------
    function envv(string $key, ?string $default = null): ?string {
        $val = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        return ($val === false || $val === null || $val === '') ? $default : $val;
    }

    function app_env(): string {
        return envv('APP_ENV', 'sandbox');
    }

    function base_url(): string {
        return rtrim(envv('BASE_URL', ''), '/');
    }

    function wc_base_url(): string {
        return rtrim(envv('WC_BASE_URL', ''), '/');
    }

    function hmac_secret(): string {
        return envv('HMAC_SECRET', 'change_me');
    }

    // --------------------------------------------------------
    // MTN CONFIG
    // --------------------------------------------------------
    function mtn_cfg(): array {
        $raw = envv('MTN_BASE', 'sandbox.momodeveloper.mtn.com');
        $clean = trim(str_replace(["\n", "\r"], '', $raw));

        if (!str_starts_with($clean, 'http')) {
            $clean = 'https://' . $clean;
        }

        $base = rtrim($clean, '/');

        return [
            'env'        => envv('MTN_ENV', 'sandbox'),
            'base'       => $base,
            'subKey'     => envv('MTN_SUBSCRIPTION_KEY', ''),
            'apiUser'    => envv('MTN_API_USER', ''),
            'apiKey'     => envv('MTN_API_KEY', ''),
            'currency'   => envv('MTN_CURRENCY', 'XAF'),
            'payerMsisdn'=> preg_replace('/\D+/', '', envv('MTN_PAYER_MSISDN', '')),
            'payerMsg'   => envv('MTN_PAYER_MESSAGE', 'Payment for order'),
            'payeeNote'  => envv('MTN_PAYEE_NOTE', 'XafPay'),
        ];
    }

    // --------------------------------------------------------
    // TRANZAK CONFIG — FINAL & CORRECT
    // --------------------------------------------------------
    function tranzak_cfg(): array {
        return [
            // ✔ Correct Tranzak Sandbox Base URL
            'base'          => rtrim(envv('TRANZAK_BASE_URL', 'https://dsapi.tranzak.me'), '/'),

            'appId'         => envv('TRANZAK_APP_ID', ''),
            'apiKey'        => envv('TRANZAK_API_KEY', ''),
            'webhookId'     => envv('TRANZAK_WEBHOOK_ID', ''),
            'webhookSecret' => envv('TRANZAK_WEBHOOK_SECRET', ''),
        ];
    }

    // --------------------------------------------------------
    // DATABASE CONFIG
    // --------------------------------------------------------
    function db_cfg(): array {
        return [
            'host'     => envv('DB_HOST', 'localhost'),
            'port'     => envv('DB_PORT', '5432'),
            'dbname'   => envv('DB_NAME', 'xafpay'),
            'user'     => envv('DB_USER', 'xafpay_user'),
            'password' => envv('DB_PASS', ''),
        ];
    }

    function db_connect(): PDO {
        $cfg = db_cfg();
        $dsn = "pgsql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['dbname']}";

        $pdo = new PDO($dsn, $cfg['user'], $cfg['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $pdo->exec("SET search_path TO public");
        return $pdo;
    }

    // --------------------------------------------------------
    // JSON OUTPUT
    // --------------------------------------------------------
    function json_out($data, int $code = 200): void {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --------------------------------------------------------
    // HELPERS
    // --------------------------------------------------------
    function uuidv4(): string {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    function hmac_sign(array $payload, string $secret): string {
        ksort($payload);
        return hash_hmac('sha256', json_encode($payload), $secret);
    }

    // --------------------------------------------------------
    // MERCHANT AUTH
    // --------------------------------------------------------
    function require_merchant(PDO $pdo) {
        $headers = getallheaders();
        $apiKey = $headers['X-API-KEY'] ?? null;

        if (!$apiKey) {
            json_out(['error' => 'Missing X-API-KEY'], 401);
        }

        $stmt = $pdo->prepare("SELECT * FROM merchants WHERE api_key = :k LIMIT 1");
        $stmt->execute(['k' => $apiKey]);
        $merchant = $stmt->fetch();

        if (!$merchant || !$merchant['is_active']) {
            json_out(['error' => 'Invalid or inactive merchant'], 403);
        }

        return $merchant;
    }

    function require_admin(PDO $pdo) {
        $headers = getallheaders();
        $token = $headers['Authorization'] ?? '';

        if (!str_starts_with($token, 'Bearer ')) {
            json_out(['error' => 'Missing bearer token'], 401);
        }

        $token = trim(str_replace("Bearer ", "", $token));
        $data = json_decode(base64_decode($token), true);

        if (!$data || ($data['exp'] ?? 0) < time()) {
            json_out(['error' => 'Expired or invalid admin token'], 401);
        }

        if (($data['role'] ?? '') !== 'admin') {
            json_out(['error' => 'Admin only'], 403);
        }

        return $data['user'];
    }
        // --------------------------------------------------------
    // MAIL (SMTP) CONFIG — MailerSend
    // --------------------------------------------------------
    function mail_cfg(): array {
        return [
            'host'       => envv('SMTP_HOST', 'smtp.mailersend.net'),
            'port'       => envv('SMTP_PORT', '587'),
            'username'   => envv('SMTP_USERNAME', ''),   // SMTP user from MailerSend
            'password'   => envv('SMTP_PASSWORD', ''),   // SMTP password
            'from_email' => envv('MAIL_FROM', 'MS_Okq8Ib@mail.xafpay.com'),
            'from_name'  => envv('MAIL_FROM_NAME', 'XafPay'),
        ];
    }

}
