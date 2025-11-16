<?php
require_once __DIR__ . '/config.php';

try {
    $pdo = db_connect();

    echo "✅ Connected successfully!\n\n";

    $schemas = $pdo->query("
        SELECT schema_name 
        FROM information_schema.schemata
        ORDER BY schema_name
    ")->fetchAll(PDO::FETCH_COLUMN);

    echo "📦 Schemas in this database:\n";
    print_r($schemas);

    echo "\n🔍 Searching for all tables...\n";

    $tables = $pdo->query("
        SELECT table_schema, table_name 
        FROM information_schema.tables 
        WHERE table_type='BASE TABLE'
        ORDER BY table_schema, table_name
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($tables as $t) {
        echo "- {$t['table_schema']}.{$t['table_name']}\n";
    }

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}
