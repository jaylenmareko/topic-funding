<?php
// config/refund_helper.php - Stripe refund management helper
require_once 'stripe.php';
require_once 'database.php';

class RefundManager {
    private $db;
    
    public function __construct() {
        $this->db = new Database();
    }
    
    /**
     * Process refund for a single contribution
     */
    public function processContributionRefund($contribution_id, $reason = 'Admin requested refund') {
        try {
            // Get contribution details
            $this->db->query('
                SELECT c.*, u.email, t.title as topic_title 
                FROM contributions c 
                JOIN users u ON c.user_id = u.id 
                JOIN topics t ON c.topic_id = t.id 
                WHERE c.id = :id AND c.payment_status = "completed"
            ');
            $this->db->bind(':id', $contribution_id);
            $contribution = $this->db->single();
            
            if (!$contribution || !$contribution->payment_id) {
                return ['success' => false, 'error' => 'Contribution not found or already refunded'];
            }
            
            // Process Stripe refund
            $refund = \Stripe\Refund::create([
                'payment_intent' => $contribution->payment_id,
                'reason' => 'requested_by_customer',
                'metadata' => [
                    'reason' => $reason,
                    'contribution_id' => $contribution_id,
                    'admin_user_id' => $_SESSION['user_id'] ?? 'system'
                ]
            ]);
            
            // Update contribution status
            $this->db->query('UPDATE contributions SET payment_status = "refunded" WHERE id = :id');
            $this->db->bind(':id', $contribution_id);
            $this->db->execute();
            
            // Update topic funding
            $this->db->query('UPDATE topics SET current_funding = current_funding - :amount WHERE id = :topic_id');
            $this->db->bind(':amount', $contribution->amount);
            $this->db->bind(':topic_id', $contribution->topic_id);
            $this->db->execute();
            
            // Log the refund
            $this->logRefund($contribution_id, $contribution->amount, $reason, $refund->id);
            
            return [
                'success' => true,
                'refund_id' => $refund->id,
                'amount' => $contribution->amount,
                'user_email' => $contribution->email
            ];
            
        } catch (\Stripe\Exception\ApiErrorException $e) {
            error_log("Stripe refund error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Stripe API error: ' . $e->getMessage()];
        } catch (Exception $e) {
            error_log("Refund processing error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    /**
     * Process refunds for all contributions to a topic
     */
    public function refundAllTopicContributions($topic_id, $reason = 'Topic cancelled') {
        try {
            $this->db->beginTransaction();
            
            // Get all completed contributions for this topic
            $this->db->query('
                SELECT c.*, u.email 
                FROM contributions c 
                JOIN users u ON c.user_id = u.id 
                WHERE c.topic_id = :topic_id AND c.payment_status = "completed"
            ');
            $this->db->bind(':topic_id', $topic_id);
            $contributions = $this->db->resultSet();
            
            $results = [
                'success' => true,
                'refunds_processed' => 0,
                'total_refunded' => 0,
                'failed_refunds' => 0,
                'details' => []
            ];
            
            foreach ($contributions as $contribution) {
                $refund_result = $this->processContributionRefund($contribution->id, $reason);
                
                if ($refund_result['success']) {
                    $results['refunds_processed']++;
                    $results['total_refunded'] += $refund_result['amount'];
                } else {
                    $results['failed_refunds']++;
                }
                
                $results['details'][] = [
                    'contribution_id' => $contribution->id,
                    'amount' => $contribution->amount,
                    'user_email' => $contribution->email,
                    'success' => $refund_result['success'],
                    'error' => $refund_result['error'] ?? null
                ];
            }
            
            $this->db->endTransaction();
            return $results;
            
        } catch (Exception $e) {
            $this->db->cancelTransaction();
            error_log("Bulk refund error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Process refunds for all topics by a creator
     */
    public function refundAllCreatorTopics($creator_id, $reason = 'Creator removed') {
        try {
            // Get all active/funded topics for this creator
            $this->db->query('
                SELECT id, title FROM topics 
                WHERE creator_id = :creator_id AND status IN ("active", "funded")
            ');
            $this->db->bind(':creator_id', $creator_id);
            $topics = $this->db->resultSet();
            
            $overall_results = [
                'success' => true,
                'topics_processed' => 0,
                'total_refunds' => 0,
                'total_amount' => 0,
                'topic_details' => []
            ];
            
            foreach ($topics as $topic) {
                $topic_results = $this->refundAllTopicContributions($topic->id, $reason);
                
                $overall_results['topics_processed']++;
                $overall_results['total_refunds'] += $topic_results['refunds_processed'];
                $overall_results['total_amount'] += $topic_results['total_refunded'];
                
                $overall_results['topic_details'][] = [
                    'topic_id' => $topic->id,
                    'topic_title' => $topic->title,
                    'refunds_processed' => $topic_results['refunds_processed'],
                    'amount_refunded' => $topic_results['total_refunded']
                ];
                
                // Cancel the topic
                $this->db->query('UPDATE topics SET status = "cancelled" WHERE id = :id');
                $this->db->bind(':id', $topic->id);
                $this->db->execute();
            }
            
            return $overall_results;
            
        } catch (Exception $e) {
            error_log("Creator refund error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Get refund history for admin dashboard
     */
    public function getRefundHistory($limit = 50) {
        $this->db->query('
            SELECT r.*, c.amount, u.username, t.title as topic_title, cr.display_name as creator_name
            FROM refund_log r
            JOIN contributions c ON r.contribution_id = c.id
            JOIN users u ON c.user_id = u.id
            JOIN topics t ON c.topic_id = t.id
            JOIN creators cr ON t.creator_id = cr.id
            ORDER BY r.processed_at DESC
            LIMIT :limit
        ');
        $this->db->bind(':limit', $limit);
        return $this->db->resultSet();
    }
    
    /**
     * Log refund for audit trail
     */
    private function logRefund($contribution_id, $amount, $reason, $stripe_refund_id) {
        try {
            $this->db->query('
                INSERT INTO refund_log (contribution_id, amount, reason, stripe_refund_id, admin_user_id, processed_at)
                VALUES (:contribution_id, :amount, :reason, :stripe_refund_id, :admin_user_id, NOW())
            ');
            $this->db->bind(':contribution_id', $contribution_id);
            $this->db->bind(':amount', $amount);
            $this->db->bind(':reason', $reason);
            $this->db->bind(':stripe_refund_id', $stripe_refund_id);
            $this->db->bind(':admin_user_id', $_SESSION['user_id'] ?? null);
            $this->db->execute();
        } catch (Exception $e) {
            // Log creation is non-critical, don't fail the refund
            error_log("Failed to log refund: " . $e->getMessage());
        }
    }
    
    /**
     * Check if a contribution can be refunded
     */
    public function canRefund($contribution_id) {
        $this->db->query('
            SELECT payment_status, contributed_at 
            FROM contributions 
            WHERE id = :id
        ');
        $this->db->bind(':id', $contribution_id);
        $contribution = $this->db->single();
        
        if (!$contribution || $contribution->payment_status !== 'completed') {
            return false;
        }
        
        // Check if contribution is within Stripe's refund window (usually 120 days)
        $contribution_date = strtotime($contribution->contributed_at);
        $days_since = (time() - $contribution_date) / (24 * 60 * 60);
        
        return $days_since <= 120; // Stripe's typical refund window
    }
    
    /**
     * Send refund notification email
     */
    public function sendRefundNotification($user_email, $amount, $topic_title, $reason) {
        // Basic email notification - enhance with proper email service
        $subject = "Refund Processed - Topic Funding Platform";
        $message = "
            Hello,
            
            A refund of $" . number_format($amount, 2) . " has been processed for your contribution to: " . $topic_title . "
            
            Reason: " . $reason . "
            
            The refund will appear in your original payment method within 5-10 business days.
            
            If you have any questions, please contact our support team.
            
            Best regards,
            Topic Funding Platform
        ";
        
        $headers = "From: noreply@yourdomain.com\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        
        return mail($user_email, $subject, $message, $headers);
    }
}

/**
 * Create refund_log table if it doesn't exist
 */
function createRefundLogTable() {
    $db = new Database();
    $db->query("
        CREATE TABLE IF NOT EXISTS refund_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            contribution_id INT NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            reason TEXT,
            stripe_refund_id VARCHAR(255),
            admin_user_id INT,
            processed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_contribution (contribution_id),
            INDEX idx_admin (admin_user_id),
            INDEX idx_date (processed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $db->execute();
}

// Initialize refund log table
createRefundLogTable();
?>
