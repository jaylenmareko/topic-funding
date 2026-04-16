<?php
// TEMPORARY - delete this file after use
header('Content-Type: application/json');

$secret = getenv('CRON_SECRET') ?: '';
$provided = $_GET['secret'] ?? '';

if (empty($secret) || !hash_equals($secret, $provided)) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../config/database.php';

try {
    $db = new Database();
    $db->query("
        TRUNCATE TABLE 
            refund_log,
            auto_refund_processed,
            creator_payouts,
            payouts,
            payout_requests,
            notifications,
            funding_milestones,
            contributions,
            topics,
            creators,
            users
        RESTART IDENTITY CASCADE
    ");
    $db->execute();
    echo json_encode(['success' => true, 'message' => 'All data cleared']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
