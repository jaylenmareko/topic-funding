<?php
// config/auto_payout.php - Automatic creator payouts when content is delivered
require_once 'database.php';
require_once 'stripe_connect.php';
require_once 'notification_system.php';

class AutoPayoutManager {
    private $db;
    private $stripeConnect;
    private $notificationSystem;
    
    public function __construct() {
        $this->db = new Database();
        $this->stripeConnect = new StripeConnectManager();
        $this->notificationSystem = new NotificationSystem();
    }
    
    /**
     * Process automatic payout when content is completed
     */
    public function processContentCompletionPayout($topic_id) {
        try {
            $this->db->beginTransaction();
            
            // Get topic and creator info
            $this->db->query('
                SELECT t.*, c.display_name, c.stripe_account_id, c.stripe_payouts_enabled,
                       u.email as creator_email
                FROM topics t
                JOIN creators c ON t.creator_id = c.id
                LEFT JOIN users u ON c.applicant_user_id = u.id
                WHERE t.id = :topic_id AND t.status = "completed"
            ');
            $this->db->bind(':topic_id', $topic_id);
            $topic = $this->db->single();
            
            if (!$topic) {
                throw new Exception("Topic not found or not completed");
            }
            
            // Check if payout already processed
            $this->db->query('
                SELECT id FROM creator_stripe_payouts 
                WHERE topic_id = :topic_id AND status = "completed"
            ');
            $this->db->bind(':topic_id', $topic_id);
            if ($this->db->single()) {
                return [
                    'success' => true,
                    'message' => 'Payout already processed',
                    'already_processed' => true
                ];
            }
            
            // Calculate payout amounts
            $gross_amount = $topic->current_funding;
            $platform_fee = $gross_amount * 0.10; // 10% platform fee
            $creator_payout = $gross_amount * 0.90; // 90% to creator
            
            // Process Stripe payout if creator has Stripe setup
            $payout_result = null;
            if ($topic->stripe_payouts_enabled && $topic->stripe_account_id) {
                $payout_description = "Content completion payout for: " . $topic->title;
                
                $payout_result = $this->stripeConnect->processCreatorPayout(
                    $topic_id,
                    $topic->creator_id,
                    $creator_payout,
                    $payout_description
                );
                
                if (!$payout_result['success']) {
                    // Log error but don't fail the entire process
                    error_log("Stripe payout failed for topic {$topic_id}: " . $payout_result['error']);
                }
            }
            
            // Update platform fee tracking
            $this->db->query('
                UPDATE platform_fees 
                SET status = "collected", processed_at = NOW()
                WHERE topic_id = :topic_id
            ');
            $this->db->bind(':topic_id', $topic_id);
            $this->db->execute();
            
            // Update creator payout status
            $this->db->query('
                UPDATE creator_payouts 
                SET status = :status, processed_at = NOW()
                WHERE topic_id = :topic_id
            ');
            $status = ($payout_result && $payout_result['success']) ? 'completed' : 'pending_manual';
            $this->db->bind(':status', $status);
            $this->db->bind(':topic_id', $topic_id);
            $this->db->execute();
            
            // Send notification to creator
            $this->sendCreatorPayoutNotification($topic, $creator_payout, $payout_result);
            
            $this->db->endTransaction();
            
            return [
                'success' => true,
                'gross_amount' => $gross_amount,
                'platform_fee' => $platform_fee,
                'creator_payout' => $creator_payout,
                'stripe_payout' => $payout_result,
                'auto_payout_processed' => $payout_result && $payout_result['success']
            ];
            
        } catch (Exception $e) {
            $this->db->cancelTransaction();
            error_log("Auto-payout error for topic {$topic_id}: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Process manual payout for creators without Stripe setup
     */
    public function processManualPayout($creator_id, $topic_id, $amount, $reference = null) {
        try {
            // Record manual payout
            $this->db->query('
                INSERT INTO creator_manual_payouts 
                (creator_id, topic_id, amount, reference, status, processed_at)
                VALUES (:creator_id, :topic_id, :amount, :reference, "completed", NOW())
            ');
            $this->db->bind(':creator_id', $creator_id);
            $this->db->bind(':topic_id', $topic_id);
            $this->db->bind(':amount', $amount);
            $this->db->bind(':reference', $reference);
            $this->db->execute();
            
            // Update creator payout status
            $this->db->query('
                UPDATE creator_payouts 
                SET status = "completed", processed_at = NOW()
                WHERE topic_id = :topic_id
            ');
            $this->db->bind(':topic_id', $topic_id);
            $this->db->execute();
            
            return ['success' => true];
            
        } catch (Exception $e) {
            error_log("Manual payout error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Get pending payouts (for admin review)
     */
    public function getPendingPayouts() {
        $this->db->query('
            SELECT cp.*, t.title as topic_title, c.display_name as creator_name,
                   c.stripe_payouts_enabled, t.current_funding, t.completed_at
            FROM creator_payouts cp
            JOIN topics t ON cp.topic_id = t.id
            JOIN creators c ON cp.creator_id = c.id
            WHERE cp.status = "pending_manual"
            ORDER BY t.completed_at DESC
        ');
        return $this->db->resultSet();
    }
    
    /**
     * Get creator's payout history
     */
    public function getCreatorPayoutHistory($creator_id) {
        // Get Stripe payouts
        $stripe_payouts = $this->stripeConnect->getCreatorPayouts($creator_id);
        
        // Get manual payouts
        $this->db->query('
            SELECT mp.*, t.title as topic_title, "manual" as payout_type
            FROM creator_manual_payouts mp
            JOIN topics t ON mp.topic_id = t.id
            WHERE mp.creator_id = :creator_id
            ORDER BY mp.processed_at DESC
        ');
        $this->db->bind(':creator_id', $creator_id);
        $manual_payouts = $this->db->resultSet();
        
        // Combine and sort
        $all_payouts = [];
        
        foreach ($stripe_payouts as $payout) {
            $all_payouts[] = (object)[
                'id' => $payout->id,
                'topic_title' => $payout->topic_title,
                'amount' => $payout->amount,
                'processed_at' => $payout->processed_at,
                'type' => 'stripe',
                'reference' => $payout->stripe_transfer_id
            ];
        }
        
        foreach ($manual_payouts as $payout) {
            $all_payouts[] = (object)[
                'id' => $payout->id,
                'topic_title' => $payout->topic_title,
                'amount' => $payout->amount,
                'processed_at' => $payout->processed_at,
                'type' => 'manual',
                'reference' => $payout->reference
            ];
        }
        
        // Sort by date
        usort($all_payouts, function($a, $b) {
            return strtotime($b->processed_at) - strtotime($a->processed_at);
        });
        
        return $all_payouts;
    }
    
    /**
     * Send payout notification to creator
     */
    private function sendCreatorPayoutNotification($topic, $amount, $payout_result) {
        // Get creator email
        $creator_email = $topic->creator_email;
        if (!$creator_email) {
            return false;
        }
        
        $subject = "💰 Payment Processed - Content Completion for: " . $topic->title;
        
        if ($payout_result && $payout_result['success']) {
            // Automatic Stripe payout
            $message = "
                Hi " . $topic->display_name . ",
                
                Great news! Your payment has been automatically processed for completing your topic.
                
                📺 Topic: " . $topic->title . "
                💰 Gross Funding: $" . number_format($topic->current_funding, 2) . "
                📊 Platform Fee (10%): $" . number_format($topic->current_funding * 0.10, 2) . "
                ✅ Your Payout: $" . number_format($amount, 2) . "
                
                💳 Payment Method: Stripe Connect (Automatic)
                🏦 Transfer ID: " . $payout_result['transfer_id'] . "
                ⏰ Timing: Funds typically arrive in 1-2 business days
                
                🎉 Congratulations on completing another successful topic! Your supporters loved the content.
                
                📱 Track your earnings: https://topiclaunch.com/creators/dashboard.php
                
                Keep creating amazing content!
                
                Best regards,
                TopicLaunch Team
            ";
        } else {
            // Manual payout needed
            $message = "
                Hi " . $topic->display_name . ",
                
                Your topic has been completed successfully! Your payment is being processed.
                
                📺 Topic: " . $topic->title . "
                💰 Gross Funding: $" . number_format($topic->current_funding, 2) . "
                📊 Platform Fee (10%): $" . number_format($topic->current_funding * 0.10, 2) . "
                ✅ Your Payout: $" . number_format($amount, 2) . "
                
                💳 Payment Method: Manual Processing
                ⏰ Timeline: Payment will be processed within 3-5 business days
                
                💡 Want faster automatic payments? Set up Stripe Connect in your creator dashboard
                for instant payouts when you complete topics!
                
                📱 Manage payments: https://topiclaunch.com/creators/stripe_onboarding.php
                
                Thank you for creating amazing content!
                
                Best regards,
                TopicLaunch Team
            ";
        }
        
        $this->notificationSystem->sendEmail($creator_email, $subject, $message);
        return true;
    }
    
    /**
     * Create required database tables
     */
    public function createTables() {
        // Manual payouts table
        $this->db->query("
            CREATE TABLE IF NOT EXISTS creator_manual_payouts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                creator_id INT NOT NULL,
                topic_id INT NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                reference VARCHAR(255),
                status ENUM('pending', 'completed') DEFAULT 'completed',
                processed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_creator (creator_id),
                INDEX idx_topic (topic_id),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $this->db->execute();
    }
}

// Initialize tables when file is included
$autoPayoutManager = new AutoPayoutManager();
$autoPayoutManager->createTables();
?>
