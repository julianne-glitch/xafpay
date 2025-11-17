<?php
// server.php — router for PHP built-in server (Render)

// Load Composer autoload
require __DIR__ . '/vendor/autoload.php';

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// 1️⃣ Serve static files (CSS, JS, images, HTML, etc.)
if ($path !== '/' && file_exists(__DIR__ . $path) && !is_dir(__DIR__ . $path)) {
    return false; // Let PHP serve it directly
}

// 2️⃣ Serve public API files under /public/*
if (str_starts_with($path, '/public/')) {
    $target = __DIR__ . $path;
    if (file_exists($target)) {
        require $target;
        exit;
    }
    http_response_code(404);
    echo json_encode(['error' => 'Not found', 'path' => $path]);
    exit;
}

// 3️⃣ Serve /api/* endpoints (your previous config)
if (str_starts_with($path, '/api/')) {
    $target = __DIR__ . $path . '.php';
    if (file_exists($target)) {
        require $target;
        exit;
    }
    json_out(['ok' => false, 'error' => 'Not Found', 'path' => $path], 404);
}

// 4️⃣ Serve /checkout
if ($path === '/checkout') {
    require __DIR__ . '/public/checkout.php';
    exit;
}

// 5️⃣ Default fallback
require __DIR__ . '/index.php';
