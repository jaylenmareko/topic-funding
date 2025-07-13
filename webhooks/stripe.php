<?php
// webhooks/stripe.php - Production webhook handler

require_once '../config/database.php';
require_once '../config/stripe.php';
require_once '../config/funding_processor.php';

// Set content type
header('Content-Type: application/json');

// Get the payload and signature
$payload = @file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

// Verify webhook signature
$endpoint_secret = STRIPE_WEBHOOK_SECRET;

$event = null;
try {
    $event = \Stripe\Webhook::constructEvent($payload, $sig_header, $endpoint_secret);
} catch (\UnexpectedValueException $e) {
    // Invalid payload
    error_log('Webhook error: Invalid payload - ' . $e->getMessage());
    http_response_code(400);
    echo json_encode(['error' => 'Invalid payload']);
    exit;
} catch (\Stripe\Exception\SignatureVerificationException $e) {
    // Invalid signature
    error_log('Webhook error: Invalid signature - ' . $e->getMessage());
    error_log('Signature header: ' . $sig_header);
    error_log('Endpoint secret: ' . substr($endpoint_secret, 0, 10) . '...');
    http_response_code(400);
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

// Log webhook for debugging
error_log('Stripe webhook received: ' . $event['type']);

try {
    $processor = new FundingProcessor();
    
    // Handle the event
    switch ($event['type']) {
        case 'payment_intent.succeeded':
            $payment_intent = $event['data']['object'];
            
            // Log metadata for debugging
            error_log('Payment intent metadata: ' . json_encode($payment_intent['metadata']));
            
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
            
        case 'checkout.session.completed':
            // Log but don't process - we handle everything via payment_intent.succeeded
            error_log('Checkout session completed');
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
?>
