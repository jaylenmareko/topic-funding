<?php
$secret = $_GET['secret'] ?? '';
if ($secret !== getenv('CRON_SECRET')) {
    http_response_code(403);
    die('Forbidden');
}
header('Content-Type: text/plain');

$calls_file = __DIR__ . '/../webhooks/webhook-calls.txt';
$errors_file = __DIR__ . '/../webhooks/webhook-errors.txt';

echo "=== WEBHOOK CALLS (last 200 lines) ===\n";
if (file_exists($calls_file)) {
    $lines = file($calls_file);
    echo implode('', array_slice($lines, -200));
} else {
    echo "(no calls file)\n";
}

echo "\n\n=== WEBHOOK ERRORS (last 200 lines) ===\n";
if (file_exists($errors_file)) {
    $lines = file($errors_file);
    echo implode('', array_slice($lines, -200));
} else {
    echo "(no errors file)\n";
}
