<?php
// webhooks/stripe.php - FIXED webhook handler with better error handling

require_once '../config/database.php';
require_once '../config/stripe.php';
require_once '../config/funding_processor.php';

// Set content type and prevent any output buffering
header('Content-Type: application/json');
ob_clean();

// Get the payload and signature
$payload = @file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

error_log("Webhook received - Signature: " . substr($sig_header, 0, 20) . "...");
error_log("Webhook payload length: " . strlen($payload));

// Verify webhook signature
$endpoint_secret = STRIPE_WEBHOOK_SECRET;

$event = null;
try {
    $event = \Stripe\Webhook::constructEvent($payload, $sig_header, $endpoint_secret);
    error_log("Webhook signature verified successfully");
} catch (\UnexpectedValueException $e) {
    error_log('Webhook error: Invalid payload - ' . $e->getMessage());
    http_response_code(400);
    echo json_encode(['error' => 'Invalid payload']);
    exit;
} catch (\Stripe\Exception\SignatureVerificationException $e) {
    error_log('Webhook error: Invalid signature - ' . $e->getMessage());
    error_log('Expected signature header but got: ' . $sig_header);
    error_log('Endpoint secret starts with: ' . substr($endpoint_secret, 0, 10) . '...');
    http_response_code(400);
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

// Log webhook details for debugging
error_log('=== STRIPE WEBHOOK START ===');
error_log('Event type: ' . $event['type']);
error_log('Event ID: ' . $event['id']);

try {
    $processor = new FundingProcessor();
    
    // Handle the event
    switch ($event['type']) {
        case 'payment_intent.succeeded':
            $payment_intent = $event['data']['object'];
            
            error_log('Payment Intent ID: ' . $payment_intent['id']);
            error_log('Payment Amount: ' . ($payment_intent['amount'] / 100));
            error_log('Payment Currency: ' . $payment_intent['currency']);
            error_log('Payment Status: ' . $payment_intent['status']);
            
            // Log metadata for debugging
            $metadata = $payment_intent['metadata'];
            error_log('Payment metadata: ' . json_encode($metadata));
            
            // Validate metadata
            if (empty($metadata) || empty($metadata['type'])) {
                error_log('ERROR: Payment intent missing required metadata');
                
                // Try to get metadata from associated checkout session
                try {
                    $sessions = \Stripe\Checkout\Session::all([
                        'payment_intent' => $payment_intent['id'],
                        'limit' => 1
                    ]);
                    
                    if (!empty($sessions->data)) {
                        $session = $sessions->data[0];
                        $metadata = $session->metadata;
                        error_log('Found metadata in checkout session: ' . json_encode($metadata));
                    } else {
                        throw new Exception('No checkout session found and no metadata on payment intent');
                    }
                } catch (Exception $e) {
                    error_log('Failed to find metadata: ' . $e->getMessage());
                    throw new Exception('Payment missing required metadata - cannot process');
                }
            }
            
            // Process the payment
            $result = $processor->handlePaymentSuccess($payment_intent['id']);
            
            if (!$result['success']) {
                error_log('ERROR: Payment processing failed: ' . $result['error']);
                throw new Exception('Failed to process successful payment: ' . $result['error']);
            }
            
            error_log('SUCCESS: Payment processed successfully');
            error_log('Result: ' . json_encode($result));
            break;
            
        case 'payment_intent.payment_failed':
            $payment_intent = $event['data']['object'];
            $error_message = $payment_intent['last_payment_error']['message'] ?? 'Payment failed';
            
            error_log('Payment failed: ' . $payment_intent['id'] . ' - ' . $error_message);
            
            $result = $processor->handlePaymentFailure($payment_intent['id'], $error_message);
            
            if (!$result['success']) {
                throw new Exception('Failed to process payment failure: ' . $result['error']);
            }
            break;
            
        case 'payment_intent.canceled':
            $payment_intent = $event['data']['object'];
            error_log('Payment canceled: ' . $payment_intent['id']);
            
            $result = $processor->handlePaymentFailure($payment_intent['id'], 'Payment canceled');
            break;
            
        case 'checkout.session.completed':
            $session = $event['data']['object'];
            error_log('Checkout session completed: ' . $session['id']);
            error_log('Session payment intent: ' . $session['payment_intent']);
            error_log('Session metadata: ' . json_encode($session['metadata']));
            // Don't process here - we handle everything via payment_intent.succeeded
            break;
            
        default:
            error_log('Unhandled webhook type: ' . $event['type']);
    }
    
    error_log('=== STRIPE WEBHOOK SUCCESS ===');
    http_response_code(200);
    echo json_encode(['success' => true, 'event_type' => $event['type']]);
    
} catch (Exception $e) {
    error_log('=== STRIPE WEBHOOK ERROR ===');
    error_log('Error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    
    // Still return 200 to Stripe to prevent retries for some errors
    // Only return 500 for genuine processing errors
    if (strpos($e->getMessage(), 'metadata') !== false || 
        strpos($e->getMessage(), 'already processed') !== false) {
        http_response_code(200);
        echo json_encode(['error' => $e->getMessage(), 'warning' => 'Non-critical error']);
    } else {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}
?>
