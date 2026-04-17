<?php
require_once __DIR__ . '/../config/database.php';

$secret = $_GET['secret'] ?? '';
if ($secret !== getenv('CRON_SECRET')) {
    http_response_code(403);
    die('Forbidden');
}

header('Content-Type: text/plain');

$db = new Database();

// Delete all contributions, topics, creators, users
$db->query("DELETE FROM contributions"); $db->execute();
$db->query("DELETE FROM auto_refund_processed"); $db->execute();
$db->query("DELETE FROM topics"); $db->execute();
$db->query("DELETE FROM creators"); $db->execute();
$db->query("DELETE FROM users"); $db->execute();

$db->query("SELECT count(*) as cnt FROM users"); 
$result = $db->single();
echo "Users remaining: " . $result->cnt . "\n";
echo "Done — database is clean.\n";
