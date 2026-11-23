<?php
// webhooks/paypal.php - PayPal webhook handler
// Register this URL in PayPal Developer Dashboard → Webhooks

ini_set('display_errors', 0);
error_reporting(E_ALL);

function whlog($level, $message) {
    static $pdo = null;
    try {
        if ($pdo === null) {
            $url = getenv('DATABASE_URL');
            $p   = parse_url($url);
            $dsn = "pgsql:host={$p['host']};port=" . ($p['port'] ?? 5432) . ";dbname=" . ltrim($p['path'], '/');
            $pdo = new PDO($dsn, $p['user'], $p['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec("CREATE TABLE IF NOT EXISTS webhook_logs (id SERIAL PRIMARY KEY, level VARCHAR(20), message TEXT, created_at TIMESTAMP DEFAULT NOW())");
        }
        $stmt = $pdo->prepare("INSERT INTO webhook_logs (level, message) VALUES (?, ?)");
        $stmt->execute([$level, $message]);
    } catch (Exception $e) {
        error_log("whlog failed: " . $e->getMessage());
    }
}

whlog('info', '=== PayPal webhook called ===');
header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../config/paypal.php';
    require_once __DIR__ . '/../config/funding_processor.php';
} catch (Exception $e) {
    whlog('error', 'Bootstrap failed: ' . $e->getMessage());
    http_response_code(500);
    die(json_encode(['error' => 'Bootstrap failed']));
}

$payload = file_get_contents('php://input');
$event   = json_decode($payload, true);

if (!$event || empty($event['event_type'])) {
    whlog('error', 'Invalid payload: ' . substr($payload, 0, 200));
    http_response_code(400);
    die(json_encode(['error' => 'Invalid payload']));
}

$event_type = $event['event_type'];
whlog('info', 'Event type: ' . $event_type);

try {
    $processor = new FundingProcessor();

    switch ($event_type) {

        case 'CHECKOUT.ORDER.APPROVED':
            // Buyer approved — we capture immediately
            $order_id = $event['resource']['id'] ?? '';
            whlog('info', 'Order approved: ' . $order_id);

            if ($order_id) {
                $captured  = paypal_capture_order($order_id);
                $status    = $captured['status'] ?? '';
                $amount    = floatval($captured['purchase_units'][0]['payments']['captures'][0]['amount']['value'] ?? 0);
                $custom_id = $captured['purchase_units'][0]['custom_id'] ?? '{}';
                $metadata  = json_decode($custom_id, true) ?: [];

                whlog('info', "Captured: order=$order_id amount=$amount status=$status");

                if ($status === 'COMPLETED') {
                    $result = $processor->handlePayPalPaymentSuccess($order_id, $amount, $metadata);
                    whlog('info', 'Processing result: ' . json_encode($result));
                }
            }
            break;

        case 'PAYMENT.CAPTURE.COMPLETED':
            // Capture confirmed — safe to also process here (idempotent)
            $order_id  = $event['resource']['supplementary_data']['related_ids']['order_id'] ?? '';
            $amount    = floatval($event['resource']['amount']['value'] ?? 0);
            whlog('info', "Capture completed: order=$order_id amount=$amount");
            // Already handled by CHECKOUT.ORDER.APPROVED; duplicate check in handlePayPalPaymentSuccess handles dedup
            break;

        case 'PAYMENT.PAYOUTSBATCH.SUCCESS':
            whlog('info', 'Payout batch succeeded: ' . ($event['resource']['batch_header']['payout_batch_id'] ?? ''));
            break;

        case 'PAYMENT.PAYOUTSBATCH.DENIED':
            $batch_id = $event['resource']['batch_header']['payout_batch_id'] ?? '';
            whlog('error', 'Payout batch denied: ' . $batch_id);
            // Mark payout failed in DB
            if ($batch_id) {
                $db = new Database();
                $db->query("UPDATE payouts SET status = 'failed', failure_reason = 'PayPal batch denied' WHERE stripe_transfer_id = :batch_id");
                $db->bind(':batch_id', $batch_id);
                $db->execute();
            }
            break;

        default:
            whlog('info', 'Unhandled event: ' . $event_type);
    }

    http_response_code(200);
    echo json_encode(['success' => true, 'event_type' => $event_type]);

} catch (Exception $e) {
    whlog('error', 'Processing error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
