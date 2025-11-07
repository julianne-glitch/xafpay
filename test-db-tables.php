<?php
require_once __DIR__ . '/config.php';

try {
    $pdo = db_connect();
    echo "✅ Database connection successful!\n\n";

    // List all tables in the public schema
    $tables = $pdo->query("
        SELECT tablename 
        FROM pg_tables 
        WHERE schemaname = 'public'
    ")->fetchAll(PDO::FETCH_COLUMN);

    if (!$tables) {
        echo "No tables found in public schema.\n";
        exit;
    }

    echo "📋 Tables in public schema:\n";
    foreach ($tables as $table) {
        echo " - $table\n";

        // Get first 5 rows from each table
        try {
            $rows = $pdo->query("SELECT * FROM $table LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
            if ($rows) {
                echo "   Sample rows:\n";
                foreach ($rows as $row) {
                    echo "    " . json_encode($row) . "\n";
                }
            } else {
                echo "   (No rows in this table)\n";
            }
        } catch (PDOException $e) {
            echo "   ❌ Error fetching rows: " . $e->getMessage() . "\n";
        }
    }

} catch (Exception $e) {
    die("❌ Connection failed: " . $e->getMessage());
}
