<?php
// webhooks/stripe.php - DB-backed logging for autoscale compatibility

ini_set('display_errors', 0);
error_reporting(E_ALL);

// DB logger function — writes to webhook_logs table (autoscale-safe)
function whlog($level, $message) {
    static $pdo = null;
    try {
        if ($pdo === null) {
            $url = getenv('DATABASE_URL');
            $p = parse_url($url);
            $dsn = "pgsql:host={$p['host']};port=" . ($p['port'] ?? 5432) . ";dbname=" . ltrim($p['path'], '/');
            $pdo = new PDO($dsn, $p['user'], $p['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec("CREATE TABLE IF NOT EXISTS webhook_logs (id SERIAL PRIMARY KEY, level VARCHAR(20), message TEXT, created_at TIMESTAMP DEFAULT NOW())");
        }
        $stmt = $pdo->prepare("INSERT INTO webhook_logs (level, message) VALUES (?, ?)");
        $stmt->execute([$level, $message]);
    } catch (Exception $e) {
        error_log("whlog failed: " . $e->getMessage() . " | original: $message");
    }
}

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    whlog('error', "PHP Error [$errno]: $errstr in $errfile:$errline");
    return false;
});

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        whlog('fatal', "FATAL: {$error['message']} in {$error['file']}:{$error['line']}");
    }
});

whlog('info', '=== Webhook called ===');

header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../config/stripe.php';
    require_once __DIR__ . '/../config/funding_processor.php';
    whlog('info', 'Dependencies loaded');
} catch (Exception $e) {
    whlog('error', 'Dependency load failed: ' . $e->getMessage());
    http_response_code(500);
    die(json_encode(['error' => 'Bootstrap failed: ' . $e->getMessage()]));
}

$payload = @file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
whlog('info', 'Payload length: ' . strlen($payload));

$endpoint_secret = STRIPE_WEBHOOK_SECRET;
if (empty($endpoint_secret)) {
    whlog('error', 'Webhook secret is empty!');
    http_response_code(500);
    die(json_encode(['error' => 'Webhook secret not configured']));
}

$event = null;
try {
    $event = \Stripe\Webhook::constructEvent($payload, $sig_header, $endpoint_secret);
    whlog('info', 'Signature verified, event type: ' . $event['type']);
} catch (\Exception $e) {
    whlog('error', 'Signature error: ' . $e->getMessage());
    http_response_code(400);
    die(json_encode(['error' => 'Invalid signature']));
}

try {
    $processor = new FundingProcessor();

    switch ($event['type']) {
        case 'payment_intent.succeeded':
            $payment_intent = $event['data']['object'];
            whlog('info', 'Processing payment: ' . $payment_intent['id'] . ' | metadata: ' . json_encode($payment_intent['metadata'] ?? []));

            $result = $processor->handlePaymentSuccess($payment_intent['id']);
            whlog('info', 'Payment result: ' . json_encode($result));

            if (!$result['success']) {
                whlog('error', 'Payment processing failed: ' . json_encode($result));
            }
            break;

        case 'checkout.session.completed':
            $session = $event['data']['object'];
            whlog('info', 'Checkout completed: ' . $session['id'] . ' | metadata: ' . json_encode($session['metadata'] ?? []));
            break;

        case 'account.updated':
            $account = $event['data']['object'];
            $stripe_account_id = $account['id'];
            $charges_enabled = $account['charges_enabled'] ?? false;
            $payouts_enabled = $account['payouts_enabled'] ?? false;

            whlog('info', "account.updated: $stripe_account_id charges=$charges_enabled payouts=$payouts_enabled");

            if ($charges_enabled && $payouts_enabled) {
                $db = new Database();
                $db->query("UPDATE creators SET stripe_account_status = 'active', updated_at = NOW() WHERE stripe_account_id = :account_id");
                $db->bind(':account_id', $stripe_account_id);
                $db->execute();
                whlog('info', 'Marked account active: ' . $db->rowCount() . ' row(s) updated');
            } else {
                $db = new Database();
                $db->query("UPDATE creators SET stripe_account_status = 'pending', updated_at = NOW() WHERE stripe_account_id = :account_id AND stripe_account_status != 'active'");
                $db->bind(':account_id', $stripe_account_id);
                $db->execute();
                whlog('info', 'Account still pending verification');
            }
            break;

        default:
            whlog('info', 'Unhandled event: ' . $event['type']);
    }

    whlog('info', 'SUCCESS ---');
    http_response_code(200);
    echo json_encode(['success' => true, 'event_type' => $event['type']]);

} catch (Exception $e) {
    $error_msg = $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
    whlog('error', 'Processing error: ' . $error_msg);
    whlog('error', 'Trace: ' . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['error' => $error_msg]);
}
