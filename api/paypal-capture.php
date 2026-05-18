<?php
// api/paypal-capture.php - Captures PayPal payment after buyer approves
// PayPal redirects here with ?token=ORDER_ID after buyer approves

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/paypal.php';
require_once __DIR__ . '/../config/funding_processor.php';

$order_id = $_GET['token'] ?? '';

if (!$order_id) {
    header('Location: /?error=missing_order');
    exit;
}

try {
    // Capture the payment
    $captured = paypal_capture_order($order_id);

    $status = $captured['status'] ?? '';
    if ($status !== 'COMPLETED') {
        error_log("PayPal capture not completed: order=$order_id status=$status");
        header('Location: /?error=payment_not_completed');
        exit;
    }

    // Extract metadata from custom_id field
    $custom_id = $captured['purchase_units'][0]['custom_id'] ?? '{}';
    $metadata  = json_decode($custom_id, true) ?: [];

    // Amount actually captured
    $amount = floatval($captured['purchase_units'][0]['payments']['captures'][0]['amount']['value'] ?? 0);

    // Process the funding
    $processor = new FundingProcessor();
    $result    = $processor->handlePayPalPaymentSuccess($order_id, $amount, $metadata);

    if (!$result['success']) {
        error_log("PayPal payment processing failed: order=$order_id error=" . ($result['error'] ?? 'unknown'));
        header('Location: /?error=processing_failed');
        exit;
    }

    // Redirect to creator page
    $handle = $metadata['creator_handle'] ?? '';
    $dest   = $handle ? '/' . ltrim($handle, '/') : '/';
    header('Location: ' . $dest . '?payment=success');
    exit;

} catch (Exception $e) {
    error_log("PayPal capture exception: order=$order_id " . $e->getMessage());
    header('Location: /?error=exception');
    exit;
}
