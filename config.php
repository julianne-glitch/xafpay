<?php
// config.php — loads env, sets helpers

use Dotenv\Dotenv;

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
  require_once __DIR__ . '/vendor/autoload.php';
}

// Load .env if present (local dev)
if (class_exists(Dotenv::class) && file_exists(__DIR__ . '/.env')) {
  $dotenv = Dotenv::createImmutable(__DIR__);
  $dotenv->load();
}

/** Get env with default */
function envv(string $key, ?string $default = null): ?string {
  return $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?: $default;
}

/** Common config accessors */
function app_env(): string { return envv('APP_ENV', 'sandbox'); }
function base_url(): string { return rtrim(envv('BASE_URL', ''), '/'); }
function hmac_secret(): string { return envv('HMAC_SECRET', 'change_me'); }

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
