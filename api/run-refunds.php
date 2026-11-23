<?php
// api/run-refunds.php
// Protected HTTP endpoint that triggers the auto-refund cron job.
// Called by an external cron service (e.g. cron-job.org) every 15 minutes.
// Protected by CRON_SECRET environment variable.

header('Content-Type: application/json');

$secret = getenv('CRON_SECRET') ?: ($_ENV['CRON_SECRET'] ?? '');

if (empty($secret)) {
    http_response_code(500);
    echo json_encode(['error' => 'CRON_SECRET not configured']);
    exit;
}

$provided = $_GET['secret'] ?? $_SERVER['HTTP_X_CRON_SECRET'] ?? '';

if (!hash_equals($secret, $provided)) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Run the cron script in the background so this request returns immediately
ob_start();
require_once __DIR__ . '/../cron/auto_refund.php';
$output = ob_get_clean();

echo json_encode([
    'success' => true,
    'ran_at'  => date('Y-m-d H:i:s'),
    'note'    => 'Refund check complete — see server logs for details',
]);
