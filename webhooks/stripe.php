<?php
// webhooks/stripe.php - Handle Stripe webhook events with direct topic creation
header('Content-Type: application/json');

// Set error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/webhook_errors.log');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/stripe.php';
require_once __DIR__ . '/../config/notification_system.php';

try {
    $payload = file_get_contents('php://input');
    $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
    
    // Parse the JSON event
    $event_json = json_decode($payload, true);
    
    if (!$event_json) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        exit;
    }
    
    $event_type = $event_json['type'];
    $event_data = $event_json['data']['object'];
    
    error_log("Webhook received: " . $event_type . " for " . ($event_data['id'] ?? 'unknown'));
    
    $db = new Database();
    
    switch ($event_type) {
        case 'checkout.session.completed':
            handleCheckoutCompleted($event_data, $db);
            break;
            
        case 'payment_intent.succeeded':
            handlePaymentSucceeded($event_data, $db);
            break;
            
        default:
            error_log("Unhandled webhook event: " . $event_type);
            break;
    }
    
    http_response_code(200);
    echo json_encode(['received' => true]);
    
} catch (Exception $e) {
    error_log("Webhook error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}

function handleCheckoutCompleted($session, $db) {
    try {
        $session_id = $session['id'];
        $payment_intent = $session['payment_intent'];
        $metadata = $session['metadata'] ?? [];
        
        error_log("Processing checkout completed: " . $session_id);
        
        if (isset($metadata['type'])) {
            switch ($metadata['type']) {
                case 'topic_creation':
                    handleTopicCreationPayment($metadata, $payment_intent, $db);
                    break;
                    
                case 'topic_funding':
                    handleTopicFundingPayment($metadata, $payment_intent, $db);
                    break;
                    
                default:
                    error_log("Unknown payment type: " . $metadata['type']);
                    break;
            }
        } else {
            // Try to determine payment type from metadata
            if (isset($metadata['topic_id']) && isset($metadata['user_id'])) {
                handleTopicFundingPayment($metadata, $payment_intent, $db);
            } else {
                error_log("Cannot determine payment type from metadata");
            }
        }
        
    } catch (Exception $e) {
        error_log("Error handling checkout completed: " . $e->getMessage());
        throw $e;
    }
}

function handleTopicCreationPayment($metadata, $payment_intent, $db) {
    try {
        $db->beginTransaction();
        
        $creator_id = $metadata['creator_id'];
        $user_id = $metadata['user_id'];
        $title = $metadata['title'];
        $description = $metadata['description'];
        $funding_threshold = $metadata['funding_threshold'];
        $initial_contribution = $metadata['initial_contribution'];
        
        error_log("Creating topic: $title for user $user_id");
        
        // Create the topic with ACTIVE status (no approval needed)
        $db->query('
            INSERT INTO topics (creator_id, initiator_user_id, title, description, funding_threshold, status, current_funding, created_at) 
            VALUES (:creator_id, :user_id, :title, :description, :funding_threshold, "active", :initial_funding, NOW())
        ');
        $db->bind(':creator_id', $creator_id);
        $db->bind(':user_id', $user_id);
        $db->bind(':title', $title);
        $db->bind(':description', $description);
        $db->bind(':funding_threshold', $funding_threshold);
        $db->bind(':initial_funding', $initial_contribution);
        $db->execute();
        
        $topic_id = $db->lastInsertId();
        
        // Create the initial contribution record
        $db->query('
            INSERT INTO contributions (topic_id, user_id, amount, payment_status, payment_id, contributed_at) 
            VALUES (:topic_id, :user_id, :amount, "completed", :payment_id, NOW())
        ');
        $db->bind(':topic_id', $topic_id);
        $db->bind(':user_id', $user_id);
        $db->bind(':amount', $initial_contribution);
        $db->bind(':payment_id', $payment_intent);
        $db->execute();
        
        // Check if topic is immediately fully funded
        if ($initial_contribution >= $funding_threshold) {
            $db->query('
                UPDATE topics 
                SET status = "funded", funded_at = NOW(), content_deadline = DATE_ADD(NOW(), INTERVAL 48 HOUR)
                WHERE id = :topic_id
            ');
            $db->bind(':topic_id', $topic_id);
            $db->execute();
            
            // Process platform fees and send funding notifications
            $notificationSystem = new NotificationSystem();
            $notificationSystem->handleTopicFunded($topic_id);
            
            error_log("Topic $topic_id created and immediately fully funded!");
        } else {
            // Send notification to creator that topic is live
            $notificationSystem = new NotificationSystem();
            $notificationSystem->sendTopicLiveNotification($topic_id);
            
            error_log("Topic $topic_id created and is now live for funding");
        }
        
        $db->endTransaction();
        
        error_log("Topic created successfully: ID $topic_id");
        
    } catch (Exception $e) {
        $db->cancelTransaction();
        error_log("Error creating topic: " . $e->getMessage());
        throw $e;
    }
}

function handleTopicFundingPayment($metadata, $payment_intent, $db) {
    try {
        $db->beginTransaction();
        
        $topic_id = $metadata['topic_id'];
        $user_id = $metadata['user_id'];
        $amount = $metadata['amount'];
        
        error_log("Processing funding payment: $amount for topic $topic_id by user $user_id");
        
        // Create contribution record
        $db->query('
            INSERT INTO contributions (topic_id, user_id, amount, payment_status, payment_id, contributed_at) 
            VALUES (:topic_id, :user_id, :amount, "completed", :payment_id, NOW())
        ');
        $db->bind(':topic_id', $topic_id);
        $db->bind(':user_id', $user_id);
        $db->bind(':amount', $amount);
        $db->bind(':payment_id', $payment_intent);
        $db->execute();
        
        // Update topic funding
        $db->query('
            UPDATE topics 
            SET current_funding = current_funding + :amount 
            WHERE id = :topic_id
        ');
        $db->bind(':amount', $amount);
        $db->bind(':topic_id', $topic_id);
        $db->execute();
        
        // Check if topic is now fully funded
        $db->query('SELECT * FROM topics WHERE id = :topic_id');
        $db->bind(':topic_id', $topic_id);
        $topic = $db->single();
        
        if ($topic && $topic->current_funding >= $topic->funding_threshold) {
            // Topic is now fully funded
            $db->query('
                UPDATE topics 
                SET status = "funded", funded_at = NOW(), content_deadline = DATE_ADD(NOW(), INTERVAL 48 HOUR)
                WHERE id = :topic_id
            ');
            $db->bind(':topic_id', $topic_id);
            $db->execute();
            
            // Send funding notifications
            $notificationSystem = new NotificationSystem();
            $notificationSystem->handleTopicFunded($topic_id);
            
            error_log("Topic $topic_id is now fully funded!");
        }
        
        $db->endTransaction();
        
        error_log("Funding payment processed successfully for topic $topic_id");
        
    } catch (Exception $e) {
        $db->cancelTransaction();
        error_log("Error processing funding payment: " . $e->getMessage());
        throw $e;
    }
}

function handlePaymentSucceeded($payment_intent, $db) {
    // Additional processing if needed when payment_intent succeeds
    error_log("Payment intent succeeded: " . $payment_intent['id']);
}
?>
