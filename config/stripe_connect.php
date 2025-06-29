<?php
// config/stripe_connect.php - Stripe Connect for creator payments
require_once 'stripe.php';
require_once 'database.php';

class StripeConnectManager {
    private $stripe_secret;
    private $db;
    
    public function __construct() {
        $this->stripe_secret = STRIPE_SECRET_KEY;
        $this->db = new Database();
        \Stripe\Stripe::setApiKey($this->stripe_secret);
    }
    
    /**
     * Create Stripe Connect account for creator
     */
    public function createConnectAccount($creator_id, $email, $display_name) {
        try {
            // Create Stripe Connect Express account
            $account = \Stripe\Account::create([
                'type' => 'express',
                'email' => $email,
                'capabilities' => [
                    'card_payments' => ['requested' => true],
                    'transfers' => ['requested' => true],
                ],
                'business_type' => 'individual',
                'individual' => [
                    'first_name' => $display_name,
                    'email' => $email,
                ],
                'metadata' => [
                    'creator_id' => $creator_id,
                    'platform' => 'TopicLaunch'
                ]
            ]);
            
            // Save account ID to database
            $this->db->query('
                UPDATE creators 
                SET stripe_account_id = :account_id, stripe_onboarding_status = "pending"
                WHERE id = :creator_id
            ');
            $this->db->bind(':account_id', $account->id);
            $this->db->bind(':creator_id', $creator_id);
            $this->db->execute();
            
            return [
                'success' => true,
                'account_id' => $account->id
            ];
            
        } catch (\Stripe\Exception\ApiErrorException $e) {
            error_log("Stripe Connect account creation error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Create onboarding link for creator
     */
    public function createOnboardingLink($creator_id) {
        try {
            // Get creator's Stripe account ID
            $this->db->query('SELECT stripe_account_id FROM creators WHERE id = :creator_id');
            $this->db->bind(':creator_id', $creator_id);
            $creator = $this->db->single();
            
            if (!$creator || !$creator->stripe_account_id) {
                throw new Exception("Creator doesn't have a Stripe account");
            }
            
            // Create account link for onboarding
            $account_link = \Stripe\AccountLink::create([
                'account' => $creator->stripe_account_id,
                'refresh_url' => 'https://topiclaunch.com/creators/stripe_onboarding.php?creator_id=' . $creator_id,
                'return_url' => 'https://topiclaunch.com/creators/stripe_success.php?creator_id=' . $creator_id,
                'type' => 'account_onboarding',
            ]);
            
            return [
                'success' => true,
                'onboarding_url' => $account_link->url
            ];
            
        } catch (Exception $e) {
            error_log("Onboarding link creation error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Check if creator's Stripe account is ready for payouts
     */
    public function isAccountReady($creator_id) {
        try {
            $this->db->query('SELECT stripe_account_id FROM creators WHERE id = :creator_id');
            $this->db->bind(':creator_id', $creator_id);
            $creator = $this->db->single();
            
            if (!$creator || !$creator->stripe_account_id) {
                return false;
            }
            
            // Check account status with Stripe
            $account = \Stripe\Account::retrieve($creator->stripe_account_id);
            
            $charges_enabled = $account->charges_enabled;
            $payouts_enabled = $account->payouts_enabled;
            $details_submitted = $account->details_submitted;
            
            $is_ready = $charges_enabled && $payouts_enabled && $details_submitted;
            
            // Update database status
            $status = $is_ready ? 'completed' : 'pending';
            $this->db->query('
                UPDATE creators 
                SET stripe_onboarding_status = :status,
                    stripe_charges_enabled = :charges_enabled,
                    stripe_payouts_enabled = :payouts_enabled
                WHERE id = :creator_id
            ');
            $this->db->bind(':status', $status);
            $this->db->bind(':charges_enabled', $charges_enabled ? 1 : 0);
            $this->db->bind(':payouts_enabled', $payouts_enabled ? 1 : 0);
            $this->db->bind(':creator_id', $creator_id);
            $this->db->execute();
            
            return $is_ready;
            
        } catch (Exception $e) {
            error_log("Account status check error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Process payout to creator
     */
    public function processCreatorPayout($topic_id, $creator_id, $amount, $description) {
        try {
            // Get creator's Stripe account
            $this->db->query('
                SELECT stripe_account_id, display_name 
                FROM creators 
                WHERE id = :creator_id AND stripe_payouts_enabled = 1
            ');
            $this->db->bind(':creator_id', $creator_id);
            $creator = $this->db->single();
            
            if (!$creator || !$creator->stripe_account_id) {
                throw new Exception("Creator's Stripe account not ready for payouts");
            }
            
            // Create transfer to creator's account
            $transfer = \Stripe\Transfer::create([
                'amount' => round($amount * 100), // Convert to cents
                'currency' => 'usd',
                'destination' => $creator->stripe_account_id,
                'description' => $description,
                'metadata' => [
                    'topic_id' => $topic_id,
                    'creator_id' => $creator_id,
                    'creator_name' => $creator->display_name,
                    'type' => 'content_completion_payout'
                ]
            ]);
            
            // Record payout in database
            $this->db->query('
                INSERT INTO creator_stripe_payouts 
                (creator_id, topic_id, stripe_transfer_id, amount, status, processed_at)
                VALUES (:creator_id, :topic_id, :transfer_id, :amount, "completed", NOW())
            ');
            $this->db->bind(':creator_id', $creator_id);
            $this->db->bind(':topic_id', $topic_id);
            $this->db->bind(':transfer_id', $transfer->id);
            $this->db->bind(':amount', $amount);
            $this->db->execute();
            
            return [
                'success' => true,
                'transfer_id' => $transfer->id,
                'amount' => $amount
            ];
            
        } catch (\Stripe\Exception\ApiErrorException $e) {
            error_log("Stripe payout error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Payment processing error: ' . $e->getMessage()
            ];
        } catch (Exception $e) {
            error_log("Payout processing error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get creator's payout history
     */
    public function getCreatorPayouts($creator_id) {
        $this->db->query('
            SELECT p.*, t.title as topic_title
            FROM creator_stripe_payouts p
            JOIN topics t ON p.topic_id = t.id
            WHERE p.creator_id = :creator_id
            ORDER BY p.processed_at DESC
        ');
        $this->db->bind(':creator_id', $creator_id);
        return $this->db->resultSet();
    }
    
}
?>
