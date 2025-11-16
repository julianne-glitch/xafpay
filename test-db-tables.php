<?php
require_once __DIR__ . '/config.php';

try {
    $pdo = db_connect();

    echo "✅ Connected to database successfully!\n\n";

    $dbname = $pdo->query("SELECT current_database()")->fetchColumn();
    echo "Current database: $dbname\n";

    $schema = $pdo->query("SHOW search_path")->fetchColumn();
    echo "Current schema path: $schema\n\n";

    $tables = $pdo->query("
        SELECT table_schema, table_name
        FROM information_schema.tables
        WHERE table_schema NOT IN ('pg_catalog', 'information_schema')
        ORDER BY table_schema, table_name
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (!$tables) {
        echo "⚠️ No user-defined tables found.\n";
    } else {
        echo "✅ Found the following tables:\n";
        foreach ($tables as $t) {
            echo "- {$t['table_schema']}.{$t['table_name']}\n";
        }
    }

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}
