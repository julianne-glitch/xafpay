<?php
// config.php — loads env, sets helpers

use Dotenv\Dotenv;

// ✅ Prevent double-loading
if (!defined('CONFIG_LOADED')) {
    define('CONFIG_LOADED', true);

    // Load Composer autoload if present
    if (file_exists(__DIR__ . '/vendor/autoload.php')) {
        require_once __DIR__ . '/vendor/autoload.php';
    }

    // Load .env for local development
    if (class_exists(Dotenv::class) && file_exists(__DIR__ . '/.env')) {
        $dotenv = Dotenv::createImmutable(__DIR__);
        $dotenv->load();
    }
}

/** ✅ Get env variable with default */
if (!function_exists('envv')) {
    function envv(string $key, ?string $default = null): ?string {
        return $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?: $default;
    }
}

/** ✅ Common config accessors */
if (!function_exists('app_env')) {
    function app_env(): string { return envv('APP_ENV', 'sandbox'); }
}
if (!function_exists('base_url')) {
    function base_url(): string { return rtrim(envv('BASE_URL', ''), '/'); }
}
if (!function_exists('hmac_secret')) {
    function hmac_secret(): string { return envv('HMAC_SECRET', 'change_me'); }
}

/** ✅ MTN MoMo configuration */
if (!function_exists('mtn_cfg')) {
    function mtn_cfg(): array {
        return [
            'env'        => envv('MTN_ENV', 'sandbox'),
            'base'       => rtrim(envv('MTN_BASE', 'https://sandbox.momodeveloper.mtn.com'), '/'),
            'subKey'     => envv('MTN_SUBSCRIPTION_KEY', ''),
            'apiUser'    => envv('MTN_API_USER', ''),
            'apiKey'     => envv('MTN_API_KEY', ''),
            'currency'   => envv('MTN_CURRENCY', 'XAF'),
            'payerMsisdn'=> preg_replace('/\D+/', '', envv('MTN_PAYER_MSISDN', '')),
            'payerMsg'   => envv('MTN_PAYER_MESSAGE', 'Payment for order'),
            'payeeNote'  => envv('MTN_PAYEE_NOTE', 'XafPay'),
        ];
    }
}

/** ✅ Database configuration */
if (!function_exists('db_cfg')) {
    function db_cfg(): array {
        return [
            'host'     => envv('DB_HOST', 'localhost'),
            'port'     => envv('DB_PORT', '5432'),
            'dbname'   => envv('DB_NAME', 'xafpay'),
            'user'     => envv('DB_USER', 'xafuser'),
            'password' => envv('DB_PASS', ''),
        ];
    }
}

/** ✅ Connect to PostgreSQL using PDO */
if (!function_exists('db_connect')) {
    function db_connect(): PDO {
        $cfg = db_cfg();
        $dsn = "pgsql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['dbname']};sslmode=require";

        try {
            $pdo = new PDO($dsn, $cfg['user'], $cfg['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            return $pdo;
        } catch (PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }
}

/** ✅ Helper functions */
if (!function_exists('json_out')) {
    function json_out($data, int $code = 200): void {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}

if (!function_exists('uuidv4')) {
    function uuidv4(): string {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

if (!function_exists('hmac_sign')) {
    function hmac_sign(array $payload, string $secret): string {
        ksort($payload);
        return hash_hmac('sha256', json_encode($payload), $secret);
    }
}
