<?php
// server.php — simple router for local dev and Render

// Load Composer autoload, which (per composer.json) loads config.php + utils.php
require __DIR__ . '/vendor/autoload.php';

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Let the built-in server serve existing static files (CSS, JS, images)
if ($path !== '/' && file_exists(__DIR__ . $path)) {
  return false;
}

// API routes live under /api/*
if (str_starts_with($path, '/api/')) {
  $target = __DIR__ . $path . '.php'; // e.g. /api/health -> api/health.php
  if (file_exists($target)) {
    require $target;
    exit;
  }
  json_out(['ok' => false, 'error' => 'Not Found', 'path' => $path], 404);
}

// Public checkout page
if ($path === '/checkout') {
  require __DIR__ . '/public/checkout.php';
  exit;
}

// Default: app root
require __DIR__ . '/index.php';
