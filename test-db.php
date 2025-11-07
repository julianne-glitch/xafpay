
<?php
require_once __DIR__ . '/config.php';
var_dump(envv('DB_HOST'));
var_dump(envv('DB_USER'));
var_dump(envv('DB_PASS'));


try {
    $pdo = db_connect();
    echo "✅ Database connection successful!";
    $stmt = $pdo->query("SELECT * FROM carriers LIMIT 5");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    print_r($rows);
} catch (Exception $e) {
    echo "❌ Connection failed: " . $e->getMessage();
}
