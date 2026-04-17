<?php
require_once __DIR__ . '/../config/database.php';

$secret = $_GET['secret'] ?? '';
if ($secret !== getenv('CRON_SECRET')) {
    http_response_code(403);
    die('Forbidden');
}

header('Content-Type: text/plain');

$db = new Database();

$tables = [
    'refund_log',
    'auto_refund_processed',
    'creator_payouts',
    'payouts',
    'payout_requests',
    'contributions',
    'topics',
    'creators',
    'users',
];

foreach ($tables as $table) {
    try {
        $db->query("DELETE FROM $table");
        $db->execute();
        echo "Cleared: $table\n";
    } catch (Exception $e) {
        echo "Skipped $table: " . $e->getMessage() . "\n";
    }
}

$db->query("SELECT count(*) as cnt FROM users");
$result = $db->single();
echo "\nUsers remaining: " . $result->cnt . "\n";
echo "Done.\n";
