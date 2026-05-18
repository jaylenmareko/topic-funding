<?php
// config/funding_processor.php - UPDATED FOR PAYPAL
require_once 'database.php';
require_once __DIR__ . '/paypal-keys.php';
require_once __DIR__ . '/notification_system.php';

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
            $this->db->query('SELECT id FROM contributions WHERE stripe_payment_intent_id = :payment_id');
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
    
    /**
     * Handle a successful PayPal capture (called from api/paypal-capture.php)
     * $order_id  - PayPal order ID (replaces Stripe payment_intent_id)
     * $amount    - amount captured in USD
     * $metadata  - array decoded from custom_id field
     */
    public function handlePayPalPaymentSuccess($order_id, $amount, $metadata) {
        try {
            error_log("=== PROCESSING PAYPAL PAYMENT SUCCESS ===");
            error_log("Order ID: $order_id | Amount: $amount | Type: " . ($metadata['type'] ?? 'unknown'));

            // Deduplicate: check if already processed
            $this->db->query('SELECT id FROM contributions WHERE paypal_order_id = :order_id');
            $this->db->bind(':order_id', $order_id);
            if ($this->db->single()) {
                error_log("PayPal order already processed: $order_id");
                return ['success' => true, 'message' => 'Already processed'];
            }

            $type = $metadata['type'] ?? '';

            if ($type === 'topic_creation') {
                return $this->processTopicCreationPayPal($metadata, $order_id, $amount);
            } elseif ($type === 'topic_funding' || $type === 'topic_contribution') {
                return $this->processTopicFundingPayPal($metadata, $order_id, $amount);
            }

            return ['success' => false, 'error' => 'Unknown payment type: ' . $type];

        } catch (Exception $e) {
            error_log("PayPal payment processing error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function processTopicCreationPayPal($metadata, $order_id, $amount) {
        $creator_id        = $metadata['creator_id']        ?? null;
        $title             = $metadata['title']             ?? '';
        $description       = $metadata['description']       ?? '';
        $funding_threshold = floatval($metadata['funding_threshold'] ?? 0);
        $initiator_email   = $metadata['initiator_email']   ?? '';
        $initiator_user_id = !empty($metadata['initiator_user_id']) ? $metadata['initiator_user_id'] : null;

        if (!$creator_id || !$title) {
            return ['success' => false, 'error' => 'Missing required metadata for topic creation'];
        }

        // Create the topic
        $this->db->query("
            INSERT INTO topics (creator_id, title, description, funding_threshold, current_funding, status, created_at)
            VALUES (:creator_id, :title, :description, :funding_threshold, :initial_amount,
                    CASE WHEN :initial_amount2 >= :funding_threshold2 THEN 'funded' ELSE 'funding' END,
                    NOW())
        ");
        $this->db->bind(':creator_id',         $creator_id);
        $this->db->bind(':title',              $title);
        $this->db->bind(':description',        $description);
        $this->db->bind(':funding_threshold',  $funding_threshold);
        $this->db->bind(':initial_amount',     $amount);
        $this->db->bind(':initial_amount2',    $amount);
        $this->db->bind(':funding_threshold2', $funding_threshold);
        $this->db->execute();
        $topic_id = $this->db->lastInsertId();

        // Record contribution
        $this->db->query("
            INSERT INTO contributions (topic_id, user_id, email, amount, paypal_order_id, created_at)
            VALUES (:topic_id, :user_id, :email, :amount, :order_id, NOW())
        ");
        $this->db->bind(':topic_id', $topic_id);
        $this->db->bind(':user_id',  $initiator_user_id);
        $this->db->bind(':email',    $initiator_email);
        $this->db->bind(':amount',   $amount);
        $this->db->bind(':order_id', $order_id);
        $this->db->execute();

        error_log("Topic created via PayPal: topic_id=$topic_id order=$order_id amount=$amount");
        return ['success' => true, 'topic_id' => $topic_id];
    }

    private function processTopicFundingPayPal($metadata, $order_id, $amount) {
        $topic_id = $metadata['topic_id'] ?? null;
        $user_id  = !empty($metadata['user_id']) ? $metadata['user_id'] : null;
        $email    = $metadata['email'] ?? '';

        if (!$topic_id) {
            return ['success' => false, 'error' => 'Missing topic_id in metadata'];
        }

        $this->db->query('SELECT * FROM topics WHERE id = :topic_id');
        $this->db->bind(':topic_id', $topic_id);
        $topic = $this->db->single();

        if (!$topic) {
            return ['success' => false, 'error' => 'Topic not found'];
        }

        // Add contribution
        $this->db->query("
            INSERT INTO contributions (topic_id, user_id, email, amount, paypal_order_id, created_at)
            VALUES (:topic_id, :user_id, :email, :amount, :order_id, NOW())
        ");
        $this->db->bind(':topic_id', $topic_id);
        $this->db->bind(':user_id',  $user_id);
        $this->db->bind(':email',    $email);
        $this->db->bind(':amount',   $amount);
        $this->db->bind(':order_id', $order_id);
        $this->db->execute();

        // Update topic funding
        $new_total = floatval($topic->current_funding) + $amount;
        $funded    = $new_total >= floatval($topic->funding_threshold);

        $this->db->query("
            UPDATE topics SET current_funding = :total, status = :status, updated_at = NOW() WHERE id = :id
        ");
        $this->db->bind(':total',  $new_total);
        $this->db->bind(':status', $funded ? 'funded' : 'funding');
        $this->db->bind(':id',     $topic_id);
        $this->db->execute();

        error_log("Topic funded via PayPal: topic_id=$topic_id order=$order_id amount=$amount total=$new_total funded=" . ($funded ? 'yes' : 'no'));
        return ['success' => true, 'topic_id' => $topic_id, 'funded' => $funded];
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
            $this->db->query("
                INSERT INTO contributions (topic_id, user_id, amount, payment_status, stripe_payment_intent_id, contributed_at) 
                VALUES (:topic_id, :user_id, :amount, 'completed', :payment_id, NOW())
            ");
            $this->db->bind(':topic_id', $topic_id);
            $this->db->bind(':user_id', $user_id);
            $this->db->bind(':amount', $amount);
            $this->db->bind(':payment_id', $payment_intent_id);
            $this->db->execute();
            
            $contribution_id = $this->db->lastInsertId();
            error_log("âœ“ Created contribution ID: " . $contribution_id);
            
            // Update topic funding
            $this->db->query('UPDATE topics SET current_funding = current_funding + :amount WHERE id = :topic_id');
            $this->db->bind(':amount', $amount);
            $this->db->bind(':topic_id', $topic_id);
            $this->db->execute();
            
            error_log("âœ“ Updated topic funding by $amount");
            
            // Get updated topic info
            $this->db->query('SELECT * FROM topics WHERE id = :topic_id');
            $this->db->bind(':topic_id', $topic_id);
            $topic_after = $this->db->single();
            
            error_log("Topic after: Current={$topic_after->current_funding}, Threshold={$topic_after->funding_threshold}");
            
            $fully_funded = false;
            // Only transition if topic is still in 'active' state — never demote already-funded topics
            if ($topic_after && $topic_before->status === 'active' && $topic_after->current_funding >= $topic_after->funding_threshold) {
                error_log("TOPIC FULLY FUNDED! Checking for running topic...");

                // Check if creator has any OTHER running topic (exclude self to avoid self-demotion on stale contributions)
                $this->db->query("SELECT id FROM topics WHERE creator_id = :creator_id AND status = 'funded' AND id != :topic_id LIMIT 1");
                $this->db->bind(':creator_id', $topic_after->creator_id);
                $this->db->bind(':topic_id', $topic_id);
                $has_running = $this->db->single();

                if ($has_running) {
                    $this->db->query("UPDATE topics SET status = 'queued', funded_at = NOW() WHERE id = :topic_id");
                    error_log("Creator already has a running topic — added to queue");
                } else {
                    $this->db->query("UPDATE topics SET status = 'funded', funded_at = NOW(), content_deadline = NOW() + INTERVAL '48 hours' WHERE id = :topic_id");
                    error_log("No running topic — starting immediately with 48h deadline");
                }
                $this->db->bind(':topic_id', $topic_id);
                $this->db->execute();

                $fully_funded = true;
            } elseif ($topic_after && $topic_before->status !== 'active' && $topic_after->current_funding >= $topic_after->funding_threshold) {
                error_log("Skipping status transition — topic already in status '{$topic_before->status}' (no demotion)");
            }
            
            error_log("=== PAYMENT PROCESSED SUCCESSFULLY ===");

            // Send funded emails if topic just reached its goal
            if ($fully_funded) {
                try {
                    $notifier = new NotificationSystem();
                    $notifier->sendFundedEmails($topic_id);
                } catch (Exception $e) {
                    error_log("Notification error (non-fatal): " . $e->getMessage());
                }
            }

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
            $initiator_email = !empty($metadata->initiator_email) ? $metadata->initiator_email : null;
            $title = $metadata->title;
            $description = $metadata->description;
            $funding_threshold = floatval($metadata->funding_threshold);
            $initial_amount = floatval($metadata->initial_amount);
            $platform_fee_percent = floatval($metadata->platform_fee_percent ?? 10.00);
            
            error_log("Processing topic creation - Creator: $creator_id, funding_threshold=$funding_threshold, initial_amount=$initial_amount, fully_funded=" . ($initial_amount >= $funding_threshold ? 'YES' : 'no'));
            
            if ($initial_amount < 1) {
                throw new Exception("Minimum payment of $1 required");
            }
            
            if ($funding_threshold < 1) {
                throw new Exception("Invalid funding threshold: $funding_threshold");
            }
            
            // Authoritative validation: never allow a creation that would auto-fund itself
            $this->db->query('SELECT minimum_topic_price FROM creators WHERE id = :creator_id');
            $this->db->bind(':creator_id', $creator_id);
            $creator_row = $this->db->single();
            if ($creator_row && $funding_threshold < floatval($creator_row->minimum_topic_price)) {
                throw new Exception("funding_threshold ($funding_threshold) below creator minimum ({$creator_row->minimum_topic_price})");
            }
            if ($initial_amount > $funding_threshold) {
                throw new Exception("initial_amount ($initial_amount) exceeds funding_threshold ($funding_threshold)");
            }
            
            // Calculate fees
            $platform_fee_amount = $funding_threshold * ($platform_fee_percent / 100);
            $creator_payout_amount = $funding_threshold - $platform_fee_amount;
            
            // Ensure initiator_email column exists
            $this->db->query("ALTER TABLE topics ADD COLUMN IF NOT EXISTS initiator_email VARCHAR(255)");
            $this->db->execute();

            // Create the topic
            $this->db->query("
                INSERT INTO topics (
                    creator_id, 
                    initiator_user_id,
                    initiator_email,
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
                    :initiator_email,
                    :title, 
                    :description, 
                    :funding_threshold, 
                    :current_funding,
                    :platform_fee_percent,
                    :platform_fee_amount,
                    :creator_payout_amount,
                    'active', 
                    NOW()
                )
            ");
            $this->db->bind(':creator_id', $creator_id);
            $this->db->bind(':initiator_user_id', $initiator_user_id);
            $this->db->bind(':initiator_email', $initiator_email);
            $this->db->bind(':title', $title);
            $this->db->bind(':description', $description);
            $this->db->bind(':funding_threshold', $funding_threshold);
            $this->db->bind(':current_funding', $initial_amount);
            $this->db->bind(':platform_fee_percent', $platform_fee_percent);
            $this->db->bind(':platform_fee_amount', $platform_fee_amount);
            $this->db->bind(':creator_payout_amount', $creator_payout_amount);
            $this->db->execute();
            
            $topic_id = $this->db->lastInsertId();
            error_log("âœ“ Created topic ID: " . $topic_id);
            
            // Create initial contribution
            $this->db->query("
                INSERT INTO contributions (topic_id, user_id, amount, payment_status, stripe_payment_intent_id, contributed_at) 
                VALUES (:topic_id, :user_id, :amount, 'completed', :payment_id, NOW())
            ");
            $this->db->bind(':topic_id', $topic_id);
            $this->db->bind(':user_id', $initiator_user_id);
            $this->db->bind(':amount', $initial_amount);
            $this->db->bind(':payment_id', $payment_intent_id);
            $this->db->execute();
            
            error_log("âœ“ Created initial contribution");
            
            // Check if immediately funded
            $fully_funded = $initial_amount >= $funding_threshold;
            
            if ($fully_funded) {
                error_log("Topic immediately fully funded!");

                // Check if creator already has a running topic
                $this->db->query("SELECT id FROM topics WHERE creator_id = :creator_id AND status = 'funded' LIMIT 1");
                $this->db->bind(':creator_id', $creator_id);
                $has_running = $this->db->single();

                if ($has_running) {
                    // Another topic is already running — add to queue
                    $this->db->query("UPDATE topics SET status = 'queued', funded_at = NOW() WHERE id = :topic_id");
                    error_log("Creator already has a running topic — added to queue");
                } else {
                    // No running topic — start immediately
                    $this->db->query("UPDATE topics SET status = 'funded', funded_at = NOW(), content_deadline = NOW() + INTERVAL '48 hours' WHERE id = :topic_id");
                    error_log("No running topic — starting immediately with 48h deadline");
                }
                $this->db->bind(':topic_id', $topic_id);
                $this->db->execute();
            }
            
            error_log("=== TOPIC CREATION PROCESSED SUCCESSFULLY ===");

            // Send email notifications
            try {
                $notifier = new NotificationSystem();
                if ($fully_funded) {
                    $notifier->sendFundedEmails($topic_id);
                } else {
                    $notifier->sendTopicActiveEmails($topic_id);
                }
            } catch (Exception $e) {
                error_log("Notification error (non-fatal): " . $e->getMessage());
            }

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
