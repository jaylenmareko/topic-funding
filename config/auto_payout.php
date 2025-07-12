<?php
// config/auto_payout.php - Updated for cleaned database (removed Stripe Connect)
require_once 'database.php';
require_once 'notification_system.php';

class AutoPayoutManager {
    private $db;
    private $notificationSystem;
    
    public function __construct() {
        $this->db = new Database();
        $this->notificationSystem = new NotificationSystem();
    }
    
    /**
     * Process manual payout for creators (no Stripe Connect)
     */
    public function processManualPayout($creator_id, $topic_id, $amount, $reference = null) {
        try {
            // Record in payout_requests table (the one you kept)
            $this->db->query('
                INSERT INTO payout_requests 
                (creator_id, amount, paypal_email, status, requested_at, transaction_id, admin_notes)
                VALUES (:creator_id, :amount, 
                    (SELECT paypal_email FROM creators WHERE id = :creator_id), 
                    "completed", NOW(), :reference, :admin_notes)
            ');
            $this->db->bind(':creator_id', $creator_id);
            $this->db->bind(':amount', $amount);
            $this->db->bind(':reference', $reference);
            $this->db->bind(':admin_notes', 'Manual payout for topic ID: ' . $topic_id);
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
                   t.current_funding, t.completed_at
            FROM creator_payouts cp
            JOIN topics t ON cp.topic_id = t.id
            JOIN creators c ON cp.creator_id = c.id
            WHERE cp.status = "pending"
            ORDER BY t.completed_at DESC
        ');
        return $this->db->resultSet();
    }
    
    /**
     * Get creator's payout history from payout_requests table
     */
    public function getCreatorPayoutHistory($creator_id) {
        $this->db->query('
            SELECT pr.*, "manual" as payout_type
            FROM payout_requests pr
            WHERE pr.creator_id = :creator_id
            ORDER BY pr.requested_at DESC
        ');
        $this->db->bind(':creator_id', $creator_id);
        $payouts = $this->db->resultSet();
        
        // Convert to consistent format
        $all_payouts = [];
        foreach ($payouts as $payout) {
            $all_payouts[] = (object)[
                'id' => $payout->id,
                'topic_title' => 'Manual Payout', // Could enhance this with topic lookup
                'amount' => $payout->amount,
                'processed_at' => $payout->requested_at,
                'type' => 'manual',
                'reference' => $payout->transaction_id
            ];
        }
        
        return $all_payouts;
    }
    
    /**
     * Process content completion payout (simplified - no Stripe)
     */
    public function processContentCompletionPayout($topic_id) {
        try {
            $this->db->beginTransaction();
            
            // Get topic and creator info
            $this->db->query('
                SELECT t.*, c.display_name, c.paypal_email,
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
                SELECT id FROM creator_payouts 
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
            
            // Update platform fee tracking
            $this->db->query('
                UPDATE platform_fees 
                SET status = "collected", processed_at = NOW()
                WHERE topic_id = :topic_id
            ');
            $this->db->bind(':topic_id', $topic_id);
            $this->db->execute();
            
            // Update creator payout status (mark as pending manual payout)
            $this->db->query('
                UPDATE creator_payouts 
                SET status = "pending", processed_at = NOW()
                WHERE topic_id = :topic_id
            ');
            $this->db->bind(':topic_id', $topic_id);
            $this->db->execute();
            
            // Send notification to creator
            $this->sendCreatorPayoutNotification($topic, $creator_payout);
            
            $this->db->endTransaction();
            
            return [
                'success' => true,
                'gross_amount' => $gross_amount,
                'platform_fee' => $platform_fee,
                'creator_payout' => $creator_payout,
                'auto_payout_processed' => false // No auto-payout without Stripe
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
     * Send payout notification to creator
     */
    private function sendCreatorPayoutNotification($topic, $amount) {
        // Get creator email
        $creator_email = $topic->creator_email;
        if (!$creator_email) {
            return false;
        }
        
        $subject = "💰 Payment Ready - Content Completion for: " . $topic->title;
        
        $message = "
            Hi " . $topic->display_name . ",
            
            Great news! Your content has been completed and your payment is ready.
            
            📺 Topic: " . $topic->title . "
            💰 Gross Funding: $" . number_format($topic->current_funding, 2) . "
            📊 Platform Fee (10%): $" . number_format($topic->current_funding * 0.10, 2) . "
            ✅ Your Payout: $" . number_format($amount, 2) . "
            
            💳 Payment Method: Manual PayPal Processing
            ⏰ Timeline: Payment will be processed within 3-5 business days
            
            💡 To receive your payment faster in the future, you can request payouts 
            directly from your creator dashboard once you reach the minimum threshold.
            
            📱 Manage payments: https://topiclaunch.com/creators/request_payout.php
            
            Thank you for creating amazing content!
            
            Best regards,
            TopicLaunch Team
        ";
        
        $this->notificationSystem->sendEmail($creator_email, $subject, $message);
        return true;
    }
}
?>
