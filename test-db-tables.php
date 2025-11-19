<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/api/logger.php';

log_event("DB_INSPECT_HIT", $_SERVER['REMOTE_ADDR'] ?? 'unknown');

// -------------------------------------------------------------
// CORS (browser safe)
// -------------------------------------------------------------
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// -------------------------------------------------------------
// Database Inspection
// -------------------------------------------------------------
try {
    $pdo = db_connect();

    // Current DB
    $dbname = $pdo->query("SELECT current_database()")->fetchColumn();

    // Current schema search path
    $schema = $pdo->query("SHOW search_path")->fetchColumn();

    // Tables
    $tables = $pdo->query("
        SELECT table_schema, table_name
        FROM information_schema.tables
        WHERE table_schema NOT IN ('pg_catalog', 'information_schema')
        ORDER BY table_schema, table_name
    ")->fetchAll(PDO::FETCH_ASSOC);

    json_out([
        'ok'       => true,
        'env'      => app_env(),
        'database' => $dbname,
        'schema'   => $schema,
        'tables'   => [
            'count' => count($tables),
            'list'  => $tables
        ],
        'time'     => gmdate('c')
    ]);

} catch (Throwable $e) {

    log_event("DB_INSPECT_ERROR", $e->getMessage());

    json_out([
        'ok'    => false,
        'error' => $e->getMessage(),
        'time'  => gmdate('c')
    ], 500);
}
