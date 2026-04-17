<?php
require_once __DIR__ . '/../config/database.php';

$secret = $_GET['secret'] ?? '';
if ($secret !== getenv('CRON_SECRET')) {
    http_response_code(403);
    die('Forbidden');
}

header('Content-Type: text/plain');

$db = new Database();

try {
    $db->query("
        ALTER TABLE auto_refund_processed 
            ADD COLUMN IF NOT EXISTS refunds_count INT NOT NULL DEFAULT 0,
            ADD COLUMN IF NOT EXISTS total_refunded DECIMAL(10,2) NOT NULL DEFAULT 0,
            ADD COLUMN IF NOT EXISTS status VARCHAR(50) DEFAULT 'pending'
    ");
    $db->execute();
    echo "Columns added.\n";

    $db->query("
        CREATE UNIQUE INDEX IF NOT EXISTS auto_refund_processed_topic_id_unique 
        ON auto_refund_processed (topic_id)
    ");
    $db->execute();
    echo "Unique index added.\n";

    echo "Migration complete.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
