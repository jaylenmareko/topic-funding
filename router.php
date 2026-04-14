<?php
// PHP built-in server router for development
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Remove query string from path
$path = explode('?', $uri)[0];

// Serve static files directly (uploads, etc.)
if ($path !== '/' && file_exists(__DIR__ . $path) && !is_dir(__DIR__ . $path)) {
    return false;
}

// Handle directory paths - look for index.php
if (is_dir(__DIR__ . $path)) {
    $indexFile = rtrim(__DIR__ . $path, '/') . '/index.php';
    if (file_exists($indexFile)) {
        require $indexFile;
        return true;
    }
    return false;
}

// Handle .php extensions directly
if (file_exists(__DIR__ . $path . '.php')) {
    require __DIR__ . $path . '.php';
    return true;
}

if (file_exists(__DIR__ . $path) && pathinfo($path, PATHINFO_EXTENSION) === 'php') {
    require __DIR__ . $path;
    return true;
}

// Handle vanity username/topic URLs (mimics .htaccess rewrite)
// Pattern: /username/topicN
if (preg_match('/^\/([a-zA-Z0-9_.-]+)\/topic([0-9]+)$/', $path, $m)) {
    $_GET['username'] = $m[1];
    $_GET['topic_num'] = $m[2];
    require __DIR__ . '/username.php';
    return true;
}

// Pattern: /username (but not reserved paths)
$reserved = ['auth', 'creators', 'topics', 'admin', 'uploads', 'config', 'api', 'webhooks', 'cron'];
$parts = explode('/', ltrim($path, '/'));
$first = $parts[0] ?? '';

if ($first && !in_array($first, $reserved) && preg_match('/^[a-zA-Z0-9_.-]+$/', $first) && count($parts) === 1) {
    $_GET['username'] = $first;
    require __DIR__ . '/username.php';
    return true;
}

// Default: serve index.php
require __DIR__ . '/index.php';
