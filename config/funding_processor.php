<?php
// config/funding_processor.php - UPDATED FOR NEW FLOW
require_once 'database.php';
require_once 'stripe-keys.php';

class FundingProcessor {
    private $db;
    
    public function __construct() {
        $this->db = new Database();
    }
    
    public function handlePaymentSuccess($payment_intent_id) {
        try {
            error_log("=== PROCESSING PAYMENT SUCCESS ===");
            error_log("Payment Intent ID: " . $payment_intent_id);
            
            // Check if already processed
            $this->db->query('SELECT id FROM contributions WHERE payment_id = :payment_id');
            $this->db->bind(':payment_id', $payment_intent_id);
            if ($this->db->single()) {
                error_log("Payment already processed: " . $payment_intent_id);
                return ['success' => true, 'message' => 'Payment already processed'];
            }
            
            // Get payment intent from Stripe
            \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
            $payment_intent = \Stripe\PaymentIntent::retrieve($payment_intent_id);
            
            error_log("Payment amount: " . ($payment_intent->amount / 100));
            
            // Check if we have metadata directly on the payment intent
            if (!empty($payment_intent->metadata->type)) {
                $metadata = $payment_intent->metadata;
                error_log("Using payment intent metadata: " . json_encode($metadata));
            } else {
                // Fallback: Find the checkout session for this payment intent
                $sessions = \Stripe\Checkout\Session::all([
                    'payment_intent' => $payment_intent_id,
                    'limit' => 1
                ]);
                
                if (empty($sessions->data)) {
                    error_log("ERROR: No session found for payment intent: " . $payment_intent_id);
                    return ['success' => false, 'error' => 'No session or metadata found'];
                }
                
                $session = $sessions->data[0];
                $metadata = $session->metadata;
                error_log("Using session metadata: " . json_encode($metadata));
            }
            
            $type = $metadata->type ?? '';
            error_log("Payment type: " . $type);
            
            if ($type === 'topic_funding' || $type === 'topic_contribution') {
                return $this->processTopicFunding($metadata, $payment_intent_id);
            } elseif ($type === 'topic_creation') {
                return $this->processTopicCreation($metadata, $payment_intent_id);
            }
            
            error_log("ERROR: Unknown payment type: " . $type);
            return ['success' => false, 'error' => 'Unknown payment type: ' . $type];
            
        } catch (Exception $e) {
            error_log("ERROR: Payment processing failed: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    private function processTopicFunding($metadata, $payment_intent_id) {
        try {
            $topic_id = $metadata->topic_id;
            $amount = floatval($metadata->amount);
            $user_id = !empty($metadata->user_id) ? $metadata->user_id : null;
            
            error_log("Processing topic funding - Topic: $topic_id, User: $user_id, Amount: $amount");
            
            // Get topic info BEFORE adding contribution
            $this->db->query('SELECT * FROM topics WHERE id = :topic_id');
            $this->db->bind(':topic_id', $topic_id);
            $topic_before = $this->db->single();
            
            if (!$topic_before) {
                throw new Exception("Topic not found: " . $topic_id);
            }
            
            error_log("Topic before: Current={$topic_before->current_funding}, Threshold={$topic_before->funding_threshold}, Status={$topic_before->status}");
            
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
            
            error_log("Updated topic funding by $amount");
            
            // Get updated topic info
            $this->db->query('SELECT * FROM topics WHERE id = :topic_id');
            $this->db->bind(':topic_id', $topic_id);
            $topic_after = $this->db->single();
            
            error_log("Topic after: Current={$topic_after->current_funding}, Threshold={$topic_after->funding_threshold}");
            
            $fully_funded = false;
            if ($topic_after && $topic_after->current_funding >= $topic_after->funding_threshold) {
                error_log("TOPIC FULLY FUNDED! Updating status...");
                
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
                
                error_log("Topic status updated to FUNDED");
                $fully_funded = true;
            }
            
            error_log("=== PAYMENT PROCESSED SUCCESSFULLY ===");
            
            return [
                'success' => true, 
                'contribution_id' => $contribution_id,
                'topic_id' => $topic_id,
                'fully_funded' => $fully_funded,
                'new_total' => $topic_after->current_funding,
                'threshold' => $topic_after->funding_threshold
            ];
            
        } catch (Exception $e) {
            error_log("ERROR: Topic funding failed: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    private function processTopicCreation($metadata, $payment_intent_id) {
        try {
            $creator_id = $metadata->creator_id;
            $initiator_user_id = !empty($metadata->initiator_user_id) ? $metadata->initiator_user_id : null;
            $title = $metadata->title;
            $description = $metadata->description;
            $funding_threshold = floatval($metadata->funding_threshold);
            $initial_amount = floatval($metadata->initial_amount);
            $platform_fee_percent = floatval($metadata->platform_fee_percent ?? 10.00);
            
            error_log("Processing topic creation - Creator: $creator_id, Amount: $initial_amount");
            error_log("Title: $title");
            error_log("Description: $description");
            error_log("Funding threshold: $funding_threshold");
            
            if ($initial_amount < 1) {
                throw new Exception("Minimum payment of $1 required");
            }
            
            // Calculate fees
            $platform_fee_amount = $funding_threshold * ($platform_fee_percent / 100);
            $creator_payout_amount = $funding_threshold - $platform_fee_amount;
            
            // Create the topic
            $this->db->query('
                INSERT INTO topics (
                    creator_id, 
                    initiator_user_id, 
                    title, 
                    description, 
                    funding_threshold, 
                    current_funding,
                    platform_fee_percent,
                    platform_fee_amount,
                    creator_payout_amount,
                    status, 
                    created_at
                ) VALUES (
                    :creator_id, 
                    :initiator_user_id, 
                    :title, 
                    :description, 
                    :funding_threshold, 
                    :current_funding,
                    :platform_fee_percent,
                    :platform_fee_amount,
                    :creator_payout_amount,
                    "active", 
                    NOW()
                )
            ');
            $this->db->bind(':creator_id', $creator_id);
            $this->db->bind(':initiator_user_id', $initiator_user_id);
            $this->db->bind(':title', $title);
            $this->db->bind(':description', $description);
            $this->db->bind(':funding_threshold', $funding_threshold);
            $this->db->bind(':current_funding', $initial_amount);
            $this->db->bind(':platform_fee_percent', $platform_fee_percent);
            $this->db->bind(':platform_fee_amount', $platform_fee_amount);
            $this->db->bind(':creator_payout_amount', $creator_payout_amount);
            $this->db->execute();
            
            $topic_id = $this->db->lastInsertId();
            error_log("Created topic ID: " . $topic_id);
            
            // Create initial contribution
            $this->db->query('
                INSERT INTO contributions (topic_id, user_id, amount, payment_status, payment_id, contributed_at) 
                VALUES (:topic_id, :user_id, :amount, "completed", :payment_id, NOW())
            ');
            $this->db->bind(':topic_id', $topic_id);
            $this->db->bind(':user_id', $initiator_user_id);
            $this->db->bind(':amount', $initial_amount);
            $this->db->bind(':payment_id', $payment_intent_id);
            $this->db->execute();
            
            error_log("Created initial contribution");
            
            // Check if immediately funded
            $fully_funded = $initial_amount >= $funding_threshold;
            
            if ($fully_funded) {
                error_log("Topic immediately fully funded!");
                
                $this->db->query('
                    UPDATE topics 
                    SET status = "funded", 
                        funded_at = NOW(), 
                        content_deadline = DATE_ADD(NOW(), INTERVAL 48 HOUR) 
                    WHERE id = :topic_id
                ');
                $this->db->bind(':topic_id', $topic_id);
                $this->db->execute();
            }
            
            error_log("=== TOPIC CREATION PROCESSED SUCCESSFULLY ===");
            
            return [
                'success' => true, 
                'topic_id' => $topic_id,
                'fully_funded' => $fully_funded
            ];
            
        } catch (Exception $e) {
            error_log("ERROR: Topic creation failed: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    public function handlePaymentFailure($payment_intent_id, $error_message) {
        error_log("Payment failed: $payment_intent_id - $error_message");
        return ['success' => true];
    }
}
?>
