<?php
require_once __DIR__ . '/config.php';

try {
    $pdo = db_connect();
    $stmt = $pdo->query("SHOW search_path");
    $searchPath = $stmt->fetchColumn();
    echo "Current search_path: $searchPath\n";
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}
