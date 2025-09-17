<?php
// config/funding_processor.php - COMPLETE FIXED VERSION
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
                    error_log("No session found for payment intent: " . $payment_intent_id);
                    return ['success' => false, 'error' => 'No session or metadata found'];
                }
                
                $session = $sessions->data[0];
                $metadata = $session->metadata;
                error_log("Using session metadata: " . json_encode($metadata));
            }
            
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
            $amount = floatval($metadata->amount);
            $is_guest = ($metadata->is_guest ?? 'false') === 'true';
            
            // Handle guest vs logged-in user
            if ($is_guest) {
                $user_id = $this->handleGuestUser($payment_intent_id, $amount);
            } else {
                $user_id = $metadata->user_id;
            }
            
            error_log("Processing topic funding - Topic: $topic_id, User: $user_id, Amount: $amount, Guest: " . ($is_guest ? 'yes' : 'no'));
            
            // Check if already processed
            $this->db->query('SELECT id FROM contributions WHERE payment_id = :payment_id');
            $this->db->bind(':payment_id', $payment_intent_id);
            if ($this->db->single()) {
                error_log("Payment already processed: " . $payment_intent_id);
                return ['success' => true, 'message' => 'Payment already processed'];
            }
            
            $this->db->beginTransaction();
            
            // Get topic info BEFORE adding contribution
            $this->db->query('SELECT * FROM topics WHERE id = :topic_id');
            $this->db->bind(':topic_id', $topic_id);
            $topic_before = $this->db->single();
            
            if (!$topic_before) {
                throw new Exception("Topic not found: " . $topic_id);
            }
            
            error_log("Topic before funding - Current: {$topic_before->current_funding}, Threshold: {$topic_before->funding_threshold}, Status: {$topic_before->status}");
            
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
            
            error_log("Topic after funding - Current: {$topic_after->current_funding}, Threshold: {$topic_after->funding_threshold}");
            
            $fully_funded = false;
            if ($topic_after && $topic_after->current_funding >= $topic_after->funding_threshold) {
                error_log("Topic is now fully funded! Updating status and sending notifications...");
                
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
                
                $fully_funded = true;
                
                // Commit transaction BEFORE sending notifications
                $this->db->endTransaction();
                
                // Send funding notifications AFTER transaction is committed
                try {
                    error_log("Sending funding notifications for topic: " . $topic_id);
                    $notification_result = $this->notificationSystem->handleTopicFunded($topic_id);
                    error_log("Notification result: " . json_encode($notification_result));
                    
                    if ($notification_result['success']) {
                        error_log("Funding notifications sent successfully");
                    } else {
                        error_log("Notification sending failed: " . $notification_result['error']);
                    }
                } catch (Exception $e) {
                    error_log("Notification error: " . $e->getMessage());
                    // Don't fail the payment for notification errors
                }
            } else {
                // Commit transaction if not fully funded
                $this->db->endTransaction();
                error_log("Topic not yet fully funded - need $" . ($topic_after->funding_threshold - $topic_after->current_funding) . " more");
            }
            
            error_log("Topic funding processed successfully");
            
            return [
                'success' => true, 
                'contribution_id' => $contribution_id,
                'topic_id' => $topic_id,
                'fully_funded' => $fully_funded,
                'new_total' => $topic_after->current_funding,
                'threshold' => $topic_after->funding_threshold,
                'is_guest' => $is_guest,
                'user_id' => $user_id
            ];
            
        } catch (Exception $e) {
            if (method_exists($this->db, 'inTransaction') && $this->db->inTransaction()) {
                $this->db->cancelTransaction();
            }
            error_log("Topic funding processing error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    private function processTopicCreation($metadata, $payment_intent_id) {
        try {
            $creator_id = $metadata->creator_id;
            $title = $metadata->title;
            $description = $metadata->description;
            $funding_threshold = floatval($metadata->funding_threshold);
            $initial_contribution = floatval($metadata->initial_contribution);
            $is_guest = ($metadata->is_guest ?? 'false') === 'true';
            
            // REMOVED: Free topic logic - all topics now require payment
            if ($initial_contribution < 1) {
                throw new Exception("Minimum payment of $1 required for topic creation");
            }
            
            // Handle guest vs logged-in user
            if ($is_guest) {
                $user_id = $this->handleGuestUser($payment_intent_id, $initial_contribution);
            } else {
                $user_id = $metadata->user_id;
            }
            
            error_log("Processing topic creation - Creator: $creator_id, User: $user_id, Amount: $initial_contribution, Guest: " . ($is_guest ? 'yes' : 'no'));
            
            // Check if already processed
            $this->db->query('SELECT id FROM contributions WHERE payment_id = :payment_id');
            $this->db->bind(':payment_id', $payment_intent_id);
            if ($this->db->single()) {
                error_log("Payment already processed: " . $payment_intent_id);
                return ['success' => true, 'message' => 'Payment already processed'];
            }
            
            $this->db->beginTransaction();
            
            // Create the topic (status = active, no approval needed)
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
            
            // Check if topic is immediately fully funded
            $fully_funded = $initial_contribution >= $funding_threshold;
            
            if ($fully_funded) {
                error_log("Topic is immediately fully funded! Updating status...");
                
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
                
                $fully_funded = true;
                
                // Commit transaction BEFORE sending notifications
                $this->db->endTransaction();
                
                // Send funding notifications AFTER transaction is committed
                try {
                    error_log("Sending funding notifications for topic: " . $topic_id);
                    $notification_result = $this->notificationSystem->handleTopicFunded($topic_id);
                    error_log("Notification result: " . json_encode($notification_result));
                    
                    if ($notification_result['success']) {
                        error_log("Funding notifications sent successfully");
                    } else {
                        error_log("Notification sending failed: " . $notification_result['error']);
                    }
                } catch (Exception $e) {
                    error_log("Notification error: " . $e->getMessage());
                    // Don't fail the payment for notification errors
                }
            } else {
                // Commit transaction if not immediately funded
                $this->db->endTransaction();
                error_log("Topic created but not fully funded yet");
            }
            
            // Send topic live notification AFTER transaction is committed
            try {
                error_log("Sending topic live notification for: " . $topic_id);
                $this->notificationSystem->sendTopicLiveNotification($topic_id);
                error_log("Topic live notifications sent");
            } catch (Exception $e) {
                error_log("Notification error: " . $e->getMessage());
                // Don't fail the payment for notification errors
            }
            
            error_log("Topic creation processed successfully");
            
            return [
                'success' => true, 
                'topic_id' => $topic_id,
                'fully_funded' => $fully_funded,
                'is_guest' => $is_guest,
                'user_id' => $user_id
            ];
            
        } catch (Exception $e) {
            if (method_exists($this->db, 'inTransaction') && $this->db->inTransaction()) {
                $this->db->cancelTransaction();
            }
            error_log("Topic creation processing error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Handle guest user creation for payments - FIXED VERSION
     */
    private function handleGuestUser($payment_intent_id, $amount) {
        try {
            // Create a temporary guest user that will be claimed when they register
            $guest_email = 'guest_' . time() . '_' . substr($payment_intent_id, -8) . '@temp.topiclaunch.com';
            $guest_username = 'guest_' . time() . '_' . substr($payment_intent_id, -8);
            
            // Check if is_guest column exists
            $this->db->query('DESCRIBE users');
            $columns = $this->db->resultSet();
            
            $has_is_guest = false;
            foreach ($columns as $column) {
                if ($column->Field === 'is_guest') {
                    $has_is_guest = true;
                    break;
                }
            }
            
            if ($has_is_guest) {
                // New version with is_guest column
                $this->db->query('
                    INSERT INTO users (username, email, password_hash, full_name, is_active, is_guest) 
                    VALUES (:username, :email, :password_hash, :full_name, 0, 1)
                ');
            } else {
                // Fallback for old version without is_guest column
                $this->db->query('
                    INSERT INTO users (username, email, password_hash, full_name, is_active) 
                    VALUES (:username, :email, :password_hash, :full_name, 0)
                ');
            }
            
            $this->db->bind(':username', $guest_username);
            $this->db->bind(':email', $guest_email);
            $this->db->bind(':password_hash', password_hash('temp_' . $payment_intent_id, PASSWORD_DEFAULT));
            $this->db->bind(':full_name', 'Guest User');
            $this->db->execute();
            
            $guest_user_id = $this->db->lastInsertId();
            
            // Store guest payment info for later account claiming
            $this->db->query('
                INSERT INTO guest_payments (guest_user_id, payment_intent_id, amount, created_at)
                VALUES (:guest_user_id, :payment_intent_id, :amount, NOW())
                ON DUPLICATE KEY UPDATE amount = :amount
            ');
            $this->db->bind(':guest_user_id', $guest_user_id);
            $this->db->bind(':payment_intent_id', $payment_intent_id);
            $this->db->bind(':amount', $amount);
            $this->db->execute();
            
            error_log("Created guest user ID: " . $guest_user_id . " for payment: " . $payment_intent_id);
            
            return $guest_user_id;
            
        } catch (Exception $e) {
            error_log("Failed to create guest user: " . $e->getMessage());
            throw $e;
        }
    }
    
    public function handlePaymentFailure($payment_intent_id, $error_message) {
        error_log("Payment failed: $payment_intent_id - $error_message");
        
        // Log the failure but don't fail the webhook
        // The user will see the failure in Stripe checkout
        
        return ['success' => true]; // Always return success for webhook
    }
    
    /**
     * Create required tables for guest payment handling - IMPROVED VERSION
     */
    public function createGuestPaymentTables() {
        try {
            // Check if is_guest column exists
            $this->db->query('DESCRIBE users');
            $columns = $this->db->resultSet();
            
            $has_is_guest = false;
            foreach ($columns as $column) {
                if ($column->Field === 'is_guest') {
                    $has_is_guest = true;
                    break;
                }
            }
            
            // Add is_guest column if it doesn't exist
            if (!$has_is_guest) {
                $this->db->query("
                    ALTER TABLE users 
                    ADD COLUMN is_guest TINYINT(1) DEFAULT 0 AFTER is_active
                ");
                $this->db->execute();
                error_log("Added is_guest column to users table");
                
                // Update existing temp guest users
                $this->db->query("
                    UPDATE users 
                    SET is_guest = 1 
                    WHERE email LIKE '%@temp.topiclaunch.com'
                ");
                $this->db->execute();
            }
            
            // Create guest_payments table
            $this->db->query("
                CREATE TABLE IF NOT EXISTS guest_payments (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    guest_user_id INT NOT NULL,
                    payment_intent_id VARCHAR(255) NOT NULL,
                    amount DECIMAL(10,2) NOT NULL,
                    claimed_by_user_id INT NULL,
                    claimed_at TIMESTAMP NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_guest_user (guest_user_id),
                    INDEX idx_payment_intent (payment_intent_id),
                    INDEX idx_claimed_by (claimed_by_user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $this->db->execute();
            
        } catch (Exception $e) {
            error_log("Failed to create guest payment tables: " . $e->getMessage());
        }
    }
}

// Initialize guest payment tables
$processor = new FundingProcessor();
$processor->createGuestPaymentTables();
?>
