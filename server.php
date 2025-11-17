<?php
// server.php — router for PHP built-in server on Render

require __DIR__ . '/vendor/autoload.php';

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// 1️⃣ Serve real files directly
$full = __DIR__ . $path;
if ($path !== '/' && file_exists($full) && !is_dir($full)) {
    return false; // Let PHP serve the file
}

// Helper for checking prefix (str_starts_with is not supported on older PHP)
function starts_with($haystack, $needle) {
    return substr($haystack, 0, strlen($needle)) === $needle;
}

// 2️⃣ Serve all /public/*.php endpoints correctly
if (starts_with($path, '/public/')) {
    $target = __DIR__ . $path;
    if (file_exists($target)) {
        require $target;
        exit;
    }
    http_response_code(404);
    echo json_encode(['error' => 'Public endpoint not found', 'path' => $path]);
    exit;
}

// 3️⃣ Serve /api/* endpoints
if (starts_with($path, '/api/')) {
    $target = __DIR__ . $path . '.php';
    if (file_exists($target)) {
        require $target;
        exit;
    }
    http_response_code(404);
    echo json_encode(['error' => 'API endpoint not found', 'path' => $path]);
    exit;
}

// 4️⃣ Route /checkout
if ($path === '/checkout') {
    require __DIR__ . '/public/checkout.php';
    exit;
}

// 5️⃣ Default fallback
require __DIR__ . '/index.php';
