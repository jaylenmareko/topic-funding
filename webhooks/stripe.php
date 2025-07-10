<?php
// webhooks/stripe.php - Stripe webhook handler

require_once '../config/database.php';
require_once '../config/stripe.php';
require_once '../config/test_config.php';

// Set content type
header('Content-Type: application/json');

// Get the payload and signature
$payload = @file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

// For testing, you can disable signature verification
$verify_signature = defined('STRIPE_WEBHOOK_SECRET') && STRIPE_WEBHOOK_SECRET !== 'whsec_test_your_webhook_secret_here';

if ($verify_signature) {
    // Verify webhook signature
    $endpoint_secret = STRIPE_WEBHOOK_SECRET;
    
    $event = null;
    try {
        $event = verifyWebhookSignature($payload, $sig_header, $endpoint_secret);
    } catch (Exception $e) {
        error_log('Webhook signature verification failed: ' . $e->getMessage());
        http_response_code(400);
        echo json_encode(['error' => 'Invalid signature']);
        exit;
    }
} else {
    // For testing - parse payload directly (NOT for production)
    $event = json_decode($payload, true);
    if (!$event) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        exit;
    }
}

// Log webhook for debugging
error_log('Stripe webhook received: ' . $event['type']);

try {
    $processor = new FundingProcessor();
    
    // Handle the event
    switch ($event['type']) {
        case 'payment_intent.succeeded':
            $payment_intent = $event['data']['object'];
            $result = $processor->handlePaymentSuccess($payment_intent['id']);
            
            if (!$result['success']) {
                throw new Exception('Failed to process successful payment: ' . $result['error']);
            }
            
            error_log('Payment processed successfully: ' . $payment_intent['id']);
            break;
            
        case 'payment_intent.payment_failed':
            $payment_intent = $event['data']['object'];
            $error_message = $payment_intent['last_payment_error']['message'] ?? 'Payment failed';
            
            $result = $processor->handlePaymentFailure($payment_intent['id'], $error_message);
            
            if (!$result['success']) {
                throw new Exception('Failed to process payment failure: ' . $result['error']);
            }
            
            error_log('Payment failure processed: ' . $payment_intent['id']);
            break;
            
        case 'payment_intent.canceled':
            $payment_intent = $event['data']['object'];
            $result = $processor->handlePaymentFailure($payment_intent['id'], 'Payment canceled');
            
            error_log('Payment canceled: ' . $payment_intent['id']);
            break;
            
        default:
            error_log('Unhandled webhook type: ' . $event['type']);
    }
    
    http_response_code(200);
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    error_log('Webhook processing error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

// Webhook signature verification function
function verifyWebhookSignature($payload, $sig_header, $endpoint_secret) {
    $elements = explode(',', $sig_header);
    $sig_data = [];
    
    foreach ($elements as $element) {
        list($key, $value) = explode('=', $element, 2);
        $sig_data[$key] = $value;
    }
    
    if (!isset($sig_data['t']) || !isset($sig_data['v1'])) {
        throw new Exception('Missing signature elements');
    }
    
    $timestamp = $sig_data['t'];
    $signature = $sig_data['v1'];
    
    // Check timestamp (within 5 minutes)
    if (abs(time() - $timestamp) > 300) {
        throw new Exception('Timestamp outside tolerance');
    }
    
    // Verify signature
    $signed_payload = $timestamp . '.' . $payload;
    $expected_signature = hash_hmac('sha256', $signed_payload, $endpoint_secret);
    
    if (!hash_equals($expected_signature, $signature)) {
        throw new Exception('Invalid signature');
    }
    
    return json_decode($payload, true);
}
?>
