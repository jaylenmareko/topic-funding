<?php
// config/funding_processor.php - Processes Stripe webhook payments

require_once 'database.php';
require_once 'notification_system.php';

class FundingProcessor {
    private $db;
    private $notificationSystem;
    
    public function __construct() {
        $this->db = new Database();
        $this->notificationSystem = new NotificationSystem();
    }
    
    public function handlePaymentSuccess($payment_intent_id) {
        try {
            error_log("Processing payment success for: " . $payment_intent_id);
            
            // Get payment intent from Stripe
            $payment_intent = \Stripe\PaymentIntent::retrieve($payment_intent_id);
            
            // Find the checkout session for this payment intent
            $sessions = \Stripe\Checkout\Session::all([
                'payment_intent' => $payment_intent_id,
                'limit' => 1
            ]);
            
            if (empty($sessions->data)) {
                error_log("No session found for payment intent: " . $payment_intent_id);
                return ['success' => false, 'error' => 'No session found'];
            }
            
            $session = $sessions->data[0];
            $metadata = $session->metadata;
            
            error_log("Session metadata: " . json_encode($metadata));
            
            $type = $metadata->type ?? '';
            
            if ($type === 'topic_funding') {
                return $this->processTopicFunding($metadata, $payment_intent_id);
            } elseif ($type === 'topic_creation') {
                return $this->processTopicCreation($metadata, $payment_intent_id);
            }
            
            error_log("Unknown payment type: " . $type);
            return ['success' => false, 'error' => 'Unknown payment type: ' . $type];
            
        } catch (Exception $e) {
            error_log("Payment processing error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    private function processTopicFunding($metadata, $payment_intent_id) {
        try {
            $topic_id = $metadata->topic_id;
            $user_id = $metadata->user_id;
            $amount = floatval($metadata->amount);
            
            error_log("Processing topic funding - Topic: $topic_id, User: $user_id, Amount: $amount");
            
            $this->db->beginTransaction();
            
            // Add contribution to database
            $this->db->query('
                INSERT INTO contributions (topic_id, user_id, amount, payment_status, payment_id, contributed_at) 
                VALUES (:topic_id, :user_id, :amount, "completed", :payment_id, NOW())
            ');
            $this->db->bind(':topic_id', $topic_id);
            $this->db->bind(':user_id', $user_id);
            $this->db->bind(':amount', $amount);
            $this->db->bind(':payment_id', $payment_intent_id);
            $this->db->execute();
            
            $contribution_id = $this->db->lastInsertId();
            error_log("Created contribution ID: " . $contribution_id);
            
            // Update topic funding
            $this->db->query('UPDATE topics SET current_funding = current_funding + :amount WHERE id = :topic_id');
            $this->db->bind(':amount', $amount);
            $this->db->bind(':topic_id', $topic_id);
            $this->db->execute();
            
            error_log("Updated topic funding");
            
            // Check if topic is now fully funded
            $this->db->query('SELECT * FROM topics WHERE id = :topic_id');
            $this->db->bind(':topic_id', $topic_id);
            $topic = $this->db->single();
            
            if ($topic && $topic->current_funding >= $topic->funding_threshold) {
                error_log("Topic is now fully funded!");
                
                // Update topic status to funded
                $this->db->query('
                    UPDATE topics 
                    SET status = "funded", 
                        funded_at = NOW(), 
                        content_deadline = DATE_ADD(NOW(), INTERVAL 48 HOUR) 
                    WHERE id = :topic_id
                ');
                $this->db->bind(':topic_id', $topic_id);
                $this->db->execute();
                
                // Send funding notifications
                try {
                    $notification_result = $this->notificationSystem->handleTopicFunded($topic_id);
                    error_log("Funding notifications sent: " . json_encode($notification_result));
                } catch (Exception $e) {
                    error_log("Notification error: " . $e->getMessage());
                    // Don't fail the payment for notification errors
                }
            }
            
            $this->db->endTransaction();
            error_log("Topic funding processed successfully");
            
            return ['success' => true, 'contribution_id' => $contribution_id];
            
        } catch (Exception $e) {
            $this->db->cancelTransaction();
            error_log("Topic funding processing error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    private function processTopicCreation($metadata, $payment_intent_id) {
        try {
            $creator_id = $metadata->creator_id;
            $user_id = $metadata->user_id;
            $title = $metadata->title;
            $description = $metadata->description;
            $funding_threshold = floatval($metadata->funding_threshold);
            $initial_contribution = floatval($metadata->initial_contribution);
            
            error_log("Processing topic creation - Creator: $creator_id, User: $user_id, Amount: $initial_contribution");
            
            $this->db->beginTransaction();
            
            // Create the topic
            $this->db->query('
                INSERT INTO topics (creator_id, initiator_user_id, title, description, funding_threshold, status, current_funding, created_at) 
                VALUES (:creator_id, :user_id, :title, :description, :funding_threshold, "active", :initial_funding, NOW())
            ');
            $this->db->bind(':creator_id', $creator_id);
            $this->db->bind(':user_id', $user_id);
            $this->db->bind(':title', $title);
            $this->db->bind(':description', $description);
            $this->db->bind(':funding_threshold', $funding_threshold);
            $this->db->bind(':initial_funding', $initial_contribution);
            $this->db->execute();
            
            $topic_id = $this->db->lastInsertId();
            error_log("Created topic ID: " . $topic_id);
            
            // Create the initial contribution record
            $this->db->query('
                INSERT INTO contributions (topic_id, user_id, amount, payment_status, payment_id, contributed_at) 
                VALUES (:topic_id, :user_id, :amount, "completed", :payment_id, NOW())
            ');
            $this->db->bind(':topic_id', $topic_id);
            $this->db->bind(':user_id', $user_id);
            $this->db->bind(':amount', $initial_contribution);
            $this->db->bind(':payment_id', $payment_intent_id);
            $this->db->execute();
            
            error_log("Created initial contribution");
            
            // Send topic live notification
            try {
                $this->notificationSystem->sendTopicLiveNotification($topic_id);
                error_log("Topic live notifications sent");
            } catch (Exception $e) {
                error_log("Notification error: " . $e->getMessage());
                // Don't fail the payment for notification errors
            }
            
            $this->db->endTransaction();
            error_log("Topic creation processed successfully");
            
            return ['success' => true, 'topic_id' => $topic_id];
            
        } catch (Exception $e) {
            $this->db->cancelTransaction();
            error_log("Topic creation processing error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    public function handlePaymentFailure($payment_intent_id, $error_message) {
        error_log("Payment failed: $payment_intent_id - $error_message");
        
        // Log the failure but don't fail the webhook
        // The user will see the failure in Stripe checkout
        
        return ['success' => true]; // Always return success for webhook
    }
}
?>
