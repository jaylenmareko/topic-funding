<?php
// config/email_throttle.php - Prevent email spam and duplicates
class EmailThrottle {
    private $db;
    private $throttle_minutes = 60; // Don't send same email type to same user within 60 minutes
    
    public function __construct() {
        $this->db = new Database();
        $this->createThrottleTable();
    }
    
    /**
     * Check if we can send an email to a user about a topic
     */
    public function canSendEmail($email, $topic_id, $email_type) {
        try {
            // Check if we've sent this type of email recently
            $this->db->query('
                SELECT id FROM email_throttle 
                WHERE email = :email 
                AND topic_id = :topic_id 
                AND email_type = :email_type 
                AND sent_at > DATE_SUB(NOW(), INTERVAL :minutes MINUTE)
            ');
            $this->db->bind(':email', $email);
            $this->db->bind(':topic_id', $topic_id);
            $this->db->bind(':email_type', $email_type);
            $this->db->bind(':minutes', $this->throttle_minutes);
            
            $recent_email = $this->db->single();
            
            return !$recent_email; // Can send if no recent email found
            
        } catch (Exception $e) {
            error_log("Email throttle check error: " . $e->getMessage());
            return true; // Allow sending if check fails
        }
    }
    
    /**
     * Record that we sent an email
     */
    public function recordEmail($email, $topic_id, $email_type, $subject) {
        try {
            $this->db->query('
                INSERT INTO email_throttle (email, topic_id, email_type, subject, sent_at)
                VALUES (:email, :topic_id, :email_type, :subject, NOW())
            ');
            $this->db->bind(':email', $email);
            $this->db->bind(':topic_id', $topic_id);
            $this->db->bind(':email_type', $email_type);
            $this->db->bind(':subject', $subject);
            $this->db->execute();
            
        } catch (Exception $e) {
            error_log("Email throttle record error: " . $e->getMessage());
        }
    }
    
    /**
     * Send email with throttling
     */
    public function sendThrottledEmail($email, $subject, $message, $topic_id = null, $email_type = 'general') {
        // Validate email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            error_log("Invalid email address: " . $email);
            return false;
        }
        
        // Check throttling for topic-specific emails
        if ($topic_id && !$this->canSendEmail($email, $topic_id, $email_type)) {
            error_log("Email throttled: {$email_type} to {$email} for topic {$topic_id}");
            return false;
        }
        
        // For localhost testing - just log emails
        if (strpos($_SERVER['HTTP_HOST'] ?? 'localhost', 'localhost') !== false || 
            strpos($_SERVER['SERVER_NAME'] ?? 'localhost', 'localhost') !== false) {
            error_log("📧 THROTTLED EMAIL TO: $email | SUBJECT: $subject | TYPE: $email_type");
            
            // Still record for throttling even in localhost
            if ($topic_id) {
                $this->recordEmail($email, $topic_id, $email_type, $subject);
            }
            return true;
        }
        
        // Send real email
        $headers = array();
        $headers[] = 'From: TopicLaunch <noreply@topiclaunch.com>';
        $headers[] = 'Reply-To: support@topiclaunch.com';
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'X-Mailer: TopicLaunch v1.0';
        $headers[] = 'X-Email-Type: ' . $email_type;
        
        if ($topic_id) {
            $headers[] = 'X-Topic-ID: ' . $topic_id;
        }
        
        $formatted_headers = implode("\r\n", $headers);
        
        try {
            $result = mail($email, $subject, $message, $formatted_headers);
            
            if ($result) {
                error_log("✅ Throttled email sent successfully to: " . $email);
                
                // Record successful send for throttling
                if ($topic_id) {
                    $this->recordEmail($email, $topic_id, $email_type, $subject);
                }
            } else {
                error_log("❌ Failed to send throttled email to: " . $email);
            }
            
            return $result;
            
        } catch (Exception $e) {
            error_log("❌ Email sending exception: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Clean up old throttle records (run periodically)
     */
    public function cleanupOldRecords() {
        try {
            $this->db->query('
                DELETE FROM email_throttle 
                WHERE sent_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ');
            $deleted = $this->db->execute();
            error_log("Cleaned up {$deleted} old email throttle records");
            
        } catch (Exception $e) {
            error_log("Email throttle cleanup error: " . $e->getMessage());
        }
    }
    
    /**
     * Get email stats for admin
     */
    public function getEmailStats() {
        try {
            $this->db->query('
                SELECT 
                    email_type,
                    COUNT(*) as count,
                    COUNT(DISTINCT email) as unique_recipients,
                    MAX(sent_at) as last_sent
                FROM email_throttle 
                WHERE sent_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                GROUP BY email_type
                ORDER BY count DESC
            ');
            
            return $this->db->resultSet();
            
        } catch (Exception $e) {
            error_log("Email stats error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Create the email throttle table
     */
    private function createThrottleTable() {
        try {
            $this->db->query("
                CREATE TABLE IF NOT EXISTS email_throttle (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    email VARCHAR(255) NOT NULL,
                    topic_id INT NULL,
                    email_type VARCHAR(50) NOT NULL,
                    subject VARCHAR(255) NOT NULL,
                    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_email_topic_type (email, topic_id, email_type),
                    INDEX idx_sent_at (sent_at),
                    INDEX idx_email_type (email_type)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $this->db->execute();
            
        } catch (Exception $e) {
            error_log("Failed to create email_throttle table: " . $e->getMessage());
        }
    }
}

// Usage example functions for the auto-refund system:

function sendThrottledContributorRefundNotification($topic, $refund_details) {
    $emailThrottle = new EmailThrottle();
    $emails_sent = 0;
    
    foreach ($refund_details as $detail) {
        if ($detail['success']) {
            $subject = "90% Refund Processed - " . $topic->title;
            $message = "Hi,

A 90% refund has been automatically processed for your contribution.

Topic: " . $topic->title . "
Creator: " . $topic->creator_name . "
Original Contribution: $" . number_format($detail['original_amount'], 2) . "
Refund Amount: $" . number_format($detail['refund_amount'], 2) . " (90%)
Platform Fee Retained: $" . number_format($detail['platform_fee_kept'], 2) . " (10%)

Reason: The creator did not deliver content within the 48-hour deadline.

Your refund will appear in your original payment method within 5-10 business days.

Best regards,
TopicLaunch Team";
            
            if ($emailThrottle->sendThrottledEmail(
                $detail['user_email'], 
                $subject, 
                $message, 
                $topic->id, 
                'auto_refund_contributor'
            )) {
                $emails_sent++;
            }
        }
    }
    
    error_log("Sent {$emails_sent} throttled contributor refund notifications");
    return $emails_sent;
}

function sendThrottledCreatorFailureNotification($topic, $refunds_count, $total_refunded) {
    $emailThrottle = new EmailThrottle();
    
    // Get creator email
    $creator_email = $topic->creator_user_email ?: $topic->creator_email;
    
    if ($creator_email) {
        $subject = "Topic Failed - Content Deadline Missed";
        $message = "Hi " . $topic->creator_name . ",

Your topic '" . $topic->title . "' has been marked as FAILED because content was not delivered within the 48-hour deadline.

Deadline was: " . date('M j, Y g:i A', strtotime($topic->content_deadline)) . "

Actions Taken:
• All contributors have been automatically refunded 90% of their contributions
• " . $refunds_count . " refunds processed
• Total refunded: $" . number_format($total_refunded, 2) . "
• Topic status changed to 'Failed'

This affects your creator performance. Please ensure you can meet deadlines before accepting funded topics.

Best regards,
TopicLaunch Team";
        
        if ($emailThrottle->sendThrottledEmail(
            $creator_email, 
            $subject, 
            $message, 
            $topic->id, 
            'creator_failure'
        )) {
            error_log("Sent throttled creator failure notification to: " . $creator_email);
            return true;
        }
    }
    
    return false;
}

function sendThrottledAdminSummary($results, $total_refunds, $total_amount) {
    $emailThrottle = new EmailThrottle();
    
    $admin_message = "Auto-refund Summary - " . date('Y-m-d H:i:s') . "\n\n";
    $admin_message .= "Topics processed: " . count($results) . "\n";
    $admin_message .= "Total refunds: {$total_refunds}\n";
    $admin_message .= "Total amount: $" . number_format($total_amount, 2) . "\n\n";
    
    foreach ($results as $result) {
        $admin_message .= "• Topic: {$result['topic_title']}\n";
        $admin_message .= "  Creator: {$result['creator_name']}\n";
        $admin_message .= "  Refunds: {$result['refunds_processed']}\n";
        $admin_message .= "  Amount: $" . number_format($result['total_refunded'], 2) . "\n\n";
    }
    
    return $emailThrottle->sendThrottledEmail(
        'admin@topiclaunch.com', 
        'Auto-Refund Report - TopicLaunch', 
        $admin_message, 
        null, 
        'admin_summary'
    );
}
?>
