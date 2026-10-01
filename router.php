<?php
/**
 * PHP Built-in Server Router
 * Handles routing, static asset passthrough, and clean URL resolution
 */

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$filePath = __DIR__ . $uri;

// 1. If physical file exists (static files like CSS, JS, images, or direct .php)
if ($uri !== '/' && file_exists($filePath)) {
    if (is_dir($filePath)) {
        if (file_exists($filePath . '/index.php')) {
            require $filePath . '/index.php';
            exit;
        }
    } else {
        // Return false to let the built-in server serve the static file directly
        return false;
    }
}

// 2. If URI without extension matches a .php file (e.g. /scientist/dashboard -> /scientist/dashboard.php)
if (file_exists($filePath . '.php')) {
    require $filePath . '.php';
    exit;
}

// 3. Root directory
if ($uri === '/' || $uri === '') {
    require __DIR__ . '/index.php';
    exit;
}

// Default fallback to 404 or return false
return false;
