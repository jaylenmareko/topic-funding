<?php
// webhooks/stripe.php - Ultra-defensive version with maximum error catching

// Catch ANY PHP error and log it
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    $error = "PHP Error [$errno]: $errstr in $errfile on line $errline";
    error_log($error);
    file_put_contents(__DIR__ . '/webhook-errors.txt', date('Y-m-d H:i:s') . " - $error\n", FILE_APPEND);
    return false;
});

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        $msg = "FATAL: {$error['message']} in {$error['file']} on line {$error['line']}";
        error_log($msg);
        file_put_contents(__DIR__ . '/webhook-errors.txt', date('Y-m-d H:i:s') . " - $msg\n", FILE_APPEND);
    }
});

ini_set('display_errors', 0);
error_reporting(E_ALL);

file_put_contents(__DIR__ . '/webhook-calls.txt', date('Y-m-d H:i:s') . " - Webhook called\n", FILE_APPEND);

header('Content-Type: application/json');

try {
    file_put_contents(__DIR__ . '/webhook-calls.txt', "Loading database...\n", FILE_APPEND);
    require_once __DIR__ . '/../config/database.php';
    file_put_contents(__DIR__ . '/webhook-calls.txt', "Database loaded\n", FILE_APPEND);
} catch (Exception $e) {
    file_put_contents(__DIR__ . '/webhook-errors.txt', date('Y-m-d H:i:s') . " - DB Error: " . $e->getMessage() . "\n", FILE_APPEND);
    http_response_code(500);
    die(json_encode(['error' => 'Database config failed: ' . $e->getMessage()]));
}

try {
    file_put_contents(__DIR__ . '/webhook-calls.txt', "Loading Stripe...\n", FILE_APPEND);
    require_once __DIR__ . '/../config/stripe.php';
    file_put_contents(__DIR__ . '/webhook-calls.txt', "Stripe loaded\n", FILE_APPEND);
} catch (Exception $e) {
    file_put_contents(__DIR__ . '/webhook-errors.txt', date('Y-m-d H:i:s') . " - Stripe Error: " . $e->getMessage() . "\n", FILE_APPEND);
    http_response_code(500);
    die(json_encode(['error' => 'Stripe config failed: ' . $e->getMessage()]));
}

try {
    file_put_contents(__DIR__ . '/webhook-calls.txt', "Loading funding processor...\n", FILE_APPEND);
    require_once __DIR__ . '/../config/funding_processor.php';
    file_put_contents(__DIR__ . '/webhook-calls.txt', "Funding processor loaded\n", FILE_APPEND);
} catch (Exception $e) {
    file_put_contents(__DIR__ . '/webhook-errors.txt', date('Y-m-d H:i:s') . " - Processor Error: " . $e->getMessage() . "\n", FILE_APPEND);
    http_response_code(500);
    die(json_encode(['error' => 'Funding processor failed: ' . $e->getMessage()]));
}

// Get payload
$payload = @file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

file_put_contents(__DIR__ . '/webhook-calls.txt', "Payload length: " . strlen($payload) . "\n", FILE_APPEND);

// Verify webhook
$endpoint_secret = STRIPE_WEBHOOK_SECRET;

if (empty($endpoint_secret)) {
    file_put_contents(__DIR__ . '/webhook-errors.txt', date('Y-m-d H:i:s') . " - Webhook secret is empty!\n", FILE_APPEND);
    http_response_code(500);
    die(json_encode(['error' => 'Webhook secret not configured']));
}

$event = null;
try {
    file_put_contents(__DIR__ . '/webhook-calls.txt', "Verifying signature...\n", FILE_APPEND);
    $event = \Stripe\Webhook::constructEvent($payload, $sig_header, $endpoint_secret);
    file_put_contents(__DIR__ . '/webhook-calls.txt', "Signature verified\n", FILE_APPEND);
} catch (\Exception $e) {
    file_put_contents(__DIR__ . '/webhook-errors.txt', date('Y-m-d H:i:s') . " - Signature Error: " . $e->getMessage() . "\n", FILE_APPEND);
    http_response_code(400);
    die(json_encode(['error' => 'Invalid signature']));
}

file_put_contents(__DIR__ . '/webhook-calls.txt', "Event type: " . $event['type'] . "\n", FILE_APPEND);

try {
    $processor = new FundingProcessor();
    file_put_contents(__DIR__ . '/webhook-calls.txt', "Processor instantiated\n", FILE_APPEND);
    
    switch ($event['type']) {
        case 'payment_intent.succeeded':
            $payment_intent = $event['data']['object'];
            file_put_contents(__DIR__ . '/webhook-calls.txt', "Processing payment: " . $payment_intent['id'] . "\n", FILE_APPEND);
            
            $result = $processor->handlePaymentSuccess($payment_intent['id']);
            
            if (!$result['success']) {
                file_put_contents(__DIR__ . '/webhook-errors.txt', date('Y-m-d H:i:s') . " - Payment processing failed: " . json_encode($result) . "\n", FILE_APPEND);
            } else {
                file_put_contents(__DIR__ . '/webhook-calls.txt', "Payment processed successfully\n", FILE_APPEND);
            }
            break;
            
        case 'checkout.session.completed':
            $session = $event['data']['object'];
            file_put_contents(__DIR__ . '/webhook-calls.txt', "Checkout completed: " . $session['id'] . "\n", FILE_APPEND);
            break;
            
        default:
            file_put_contents(__DIR__ . '/webhook-calls.txt', "Unhandled event: " . $event['type'] . "\n", FILE_APPEND);
    }
    
    file_put_contents(__DIR__ . '/webhook-calls.txt', "SUCCESS\n---\n", FILE_APPEND);
    http_response_code(200);
    echo json_encode(['success' => true, 'event_type' => $event['type']]);
    
} catch (Exception $e) {
    $error_msg = $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine();
    file_put_contents(__DIR__ . '/webhook-errors.txt', date('Y-m-d H:i:s') . " - Processing Error: $error_msg\n", FILE_APPEND);
    file_put_contents(__DIR__ . '/webhook-errors.txt', $e->getTraceAsString() . "\n\n", FILE_APPEND);
    http_response_code(500);
    echo json_encode(['error' => $error_msg]);
}
?>
