<?php
require_once __DIR__ . '/config.php';

try {
    $pdo = db_connect();
    echo "✅ Connected to the database successfully!";
} catch (Exception $e) {
    echo "❌ Connection failed: " . $e->getMessage();
}
