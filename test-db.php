
<?php
require_once __DIR__ . '/config.php';
var_dump(envv('DB_HOST'));
var_dump(envv('DB_USER'));
var_dump(envv('DB_PASS'));


try {
    $pdo = db_connect();
    echo "✅ Database connection successful!";
} catch (Exception $e) {
    echo "❌ Connection failed: " . $e->getMessage();
}
