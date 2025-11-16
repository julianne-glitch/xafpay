<?php
require_once __DIR__ . '/config.php';

try {
    $pdo = db_connect();

    $stmt = $pdo->query("SELECT current_database(), current_user;");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    echo "✅ Database connection successful!\n";
    print_r($result);

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}
