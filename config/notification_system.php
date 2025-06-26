<?php
// config/notification_system.php - Updated with platform fee integration
require_once 'database.php';
require_once 'refund_helper.php';
require_once 'platform_fee_helper.php';

class NotificationSystem {
    private $db;
    private $refundManager;
    private $feeManager;
    
    public function __construct() {
        $this->db = new Database();
        $this->refundManager = new RefundManager();
        $this->feeManager = new PlatformFeeManager();
        $this->createNotificationTables();
    }
    
    /**
     * Send notification when topic reaches funding goal
     */
    public function handleTopicFunded($topic_id) {
        try {
            $this->db->beginTransaction();
            
            // Get topic and creator info
            $this->db->query('
                SELECT t.*, c.display_name as creator_name, c.email as creator_email, u.email as creator_user_email
                FROM topics t 
                JOIN creators c ON t.creator_id = c.id 
                LEFT JOIN users u ON c.applicant_user_id = u.id
                WHERE t.id = :topic_id
            ');
            $this->db->bind(':topic_id', $topic_id);
            $topic = $this->db->single();
            
            if (!$topic) {
                throw new Exception("Topic not found");
            }
            
            // Set content deadline (48 hours from now)
            $deadline = date('Y-m-d H:i:s', strtotime('+48 hours'));
            $this->db->query('
                UPDATE topics 
                SET content_deadline = :deadline, funded_at = NOW() 
                WHERE id = :topic_id
            ');
            $this->db->bind(':deadline', $deadline);
            $this->db->bind(':topic_id', $topic_id);
            $this->db->execute();
            
            // Process platform fees (10%)
            $fee_result = $this->feeManager->processTopicFunding($topic_id);
            
            if (!$fee_result['success']) {
                throw new Exception("Failed to process platform fees: " . $fee_result['error']);
            }
            
            // Get updated topic with fee information
            $this->db->query('SELECT * FROM topics WHERE id = :topic_id');
            $this->db->bind(':topic_id', $topic_id);
            $topic = $this->db->single();
            
            // 1. Notify Creator (with net amount after 10% fee)
            $this->sendCreatorFundedNotification($topic, $deadline, $fee_result);
            
            // 2. Notify All Contributors
            $this->sendContributorFundedNotifications($topic_id, $topic, $deadline);
            
            // 3. Schedule auto-refund check
            $this->scheduleAutoRefundCheck($topic_id, $deadline);
            
            $this->db->endTransaction();
            
            return [
                'success' => true, 
                'deadline' => $deadline,
                'fee_info' => $fee_result
            ];
            
        } catch (Exception $e) {
            $this->db->cancelTransaction();
            error_log("Funded notification error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Send notification to creator when topic is funded (updated with fee information)
     */
    private function sendCreatorFundedNotification($topic, $deadline, $fee_info) {
        $creator_email = $topic->creator_user_email ?: $topic->creator_email;
        
        if (!$creator_email) {
            error_log("No email found for creator ID: " . $topic->creator_id);
            return false;
        }
        
        $subject = "🎉 Your Topic is Fully Funded - 48 Hour Content Deadline";
        $message = "
            Hi " . $topic->creator_name . ",
            
            Great news! Your topic '" . $topic->title . "' has reached its funding goal!
            
            💰 FUNDING BREAKDOWN:
            • Total Raised: $" . number_format($topic->current_funding, 2) . "
            • Platform Fee (10%): $" . number_format($fee_info['platform_fee'], 2) . "
            • Your Earnings: $" . number_format($fee_info['creator_amount'], 2) . "
            
            📅 IMPORTANT: Content Deadline
            You have exactly 48 hours to create and upload your content for this topic.
            
            Deadline: " . date('M j, Y g:i A', strtotime($deadline)) . "
            
            📋 What you need to do:
            1. Create your video/live stream for: " . $topic->title . "
            2. Upload it to your platform (" . ucfirst($topic->platform_type ?? 'platform') . ")
            3. Update the topic with your content URL before the deadline
            
            ⚠️ CRITICAL: If you don't upload content within 48 hours:
            - All contributors will be automatically refunded
            - The topic will be marked as failed
            - This may affect your creator status
            
            💳 Payment Information:
            Your earnings of $" . number_format($fee_info['creator_amount'], 2) . " will be processed after successful content delivery.
            Payment processing may take 3-5 business days after content approval.
            
            📝 To upload your content:
            1. Go to: https://topiclaunch.com/creators/upload_content.php?topic=" . $topic->id . "
            2. Add your video/content URL
            3. Mark the topic as completed
            
            💡 Platform Fee Policy:
            TopicLaunch charges a 10% platform fee to cover payment processing, hosting, and platform maintenance.
            This industry-standard fee helps us keep the platform running and secure for all creators.
            
            Thank you for being part of TopicLaunch! Your supporters are excited to see your content.
            
            Questions? Reply to this email or contact support.
            
            Best regards,
            TopicLaunch Team
        ";
        
        $this->sendEmail($creator_email, $subject, $message);
        
        // Log notification
        $this->logNotification($topic->creator_id, 'creator', 'topic_funded', 
            "Topic '" . $topic->title . "' funded - Earning $" . number_format($fee_info['creator_amount'], 2) . " after fees", $topic->id);
        
        return true;
    }
    
    /**
     * Send notifications to all contributors when topic is funded
     */
    private function sendContributorFundedNotifications($topic_id, $topic, $deadline) {
        // Get all contributors
        $this->db->query('
            SELECT DISTINCT u.email, u.username, c.amount, u.id as user_id
            FROM contributions c
            JOIN users u ON c.user_id = u.id
            WHERE c.topic_id = :topic_id AND c.payment_status = "completed"
        ');
        $this->db->bind(':topic_id', $topic_id);
        $contributors = $this->db->resultSet();
        
        foreach ($contributors as $contributor) {
            $subject = "🎉 Topic Funded! Content Coming Soon - " . $topic->title;
            $message = "
                Hi " . $contributor->username . ",
                
                Exciting news! The topic you funded has reached its goal:
                
                📺 Topic: " . $topic->title . "
                💰 Your Contribution: $" . number_format($contributor->amount, 2) . "
                👥 Creator: " . $topic->creator_name . "
                
                🕐 Content Deadline: " . date('M j, Y g:i A', strtotime($deadline)) . "
                
                The creator now has 48 hours to create and upload the content you requested.
                
                📋 What happens next:
                ✅ Creator creates your requested content within 48 hours
                ✅ You'll be notified when content is ready
                ✅ You can access the content immediately
                
                ⚠️ Protection Policy:
                If the creator doesn't deliver content within 48 hours, you'll be automatically refunded the full amount ($" . number_format($contributor->amount, 2) . ") to your original payment method.
                
                💡 Platform Info:
                TopicLaunch operates on a 10% platform fee model - creators receive 90% of funding to ensure sustainable content creation.
                
                📱 Track Progress:
                View topic status: https://topiclaunch.com/topics/view.php?id=" . $topic_id . "
                
                Thank you for supporting content creators on TopicLaunch!
                
                Best regards,
                TopicLaunch Team
            ";
            
            $this->sendEmail($contributor->email, $subject, $message);
            
            // Log notification for each contributor
            $this->logNotification($contributor->user_id ?? 0, 'contributor', 'topic_funded', 
                "Funded topic '" . $topic->title . "' - Content deadline set", $topic_id);
        }
        
        return true;
    }
    
    /**
     * Send content delivered notifications
     */
    public function sendContentDeliveredNotifications($topic_id, $content_url) {
        // Get topic and contributors
        $this->db->query('
            SELECT t.*, c.display_name as creator_name
            FROM topics t 
            JOIN creators c ON t.creator_id = c.id 
            WHERE t.id = :topic_id
        ');
        $this->db->bind(':topic_id', $topic_id);
        $topic = $this->db->single();
        
        if (!$topic) return false;
        
        // Get all contributors
        $this->db->query('
            SELECT DISTINCT u.email, u.username, c.amount
            FROM contributions c
            JOIN users u ON c.user_id = u.id
            WHERE c.topic_id = :topic_id AND c.payment_status = "completed"
        ');
        $this->db->bind(':topic_id', $topic_id);
        $contributors = $this->db->resultSet();
        
        foreach ($contributors as $contributor) {
            $subject = "✅ Content Delivered! - " . $topic->title;
            $message = "
                Hi " . $contributor->username . ",
                
                Great news! The content you funded has been delivered:
                
                📺 Topic: " . $topic->title . "
                👥 Creator: " . $topic->creator_name . "
                💰 Your Contribution: $" . number_format($contributor->amount, 2) . "
                
                🎬 ACCESS YOUR CONTENT:
                " . $content_url . "
                
                ⭐ Your Impact:
                Thanks to your support, this content was successfully created and delivered!
                
                📱 More Options:
                • View topic details: https://topiclaunch.com/topics/view.php?id=" . $topic_id . "
                • Browse more topics: https://topiclaunch.com/topics/
                • Support more creators: https://topiclaunch.com/creators/
                
                Thank you for being part of the TopicLaunch community!
                
                Best regards,
                TopicLaunch Team
            ";
            
            $this->sendEmail($contributor->email, $subject, $message);
        }
        
        return true;
    }
    
    /**
     * Schedule auto-refund check for topics past deadline
     */
    private function scheduleAutoRefundCheck($topic_id, $deadline) {
        try {
            $this->db->query('
                INSERT INTO auto_refund_schedule (topic_id, deadline, status, created_at)
                VALUES (:topic_id, :deadline, "scheduled", NOW())
                ON DUPLICATE KEY UPDATE deadline = :deadline, status = "scheduled"
            ');
            $this->db->bind(':topic_id', $topic_id);
            $this->db->bind(':deadline', $deadline);
            $this->db->execute();
        } catch (Exception $e) {
            error_log("Failed to schedule auto-refund: " . $e->getMessage());
        }
    }
    
    /**
     * Process auto-refunds for overdue topics (run via cron job)
     */
    public function processAutoRefunds() {
        // Get topics past deadline without content
        $this->db->query('
            SELECT t.*, c.display_name as creator_name
            FROM topics t
            JOIN creators c ON t.creator_id = c.id
            WHERE t.status = "funded" 
            AND t.content_deadline < NOW()
            AND (t.content_url IS NULL OR t.content_url = "")
        ');
        $overdue_topics = $this->db->resultSet();
        
        $results = [];
        
        foreach ($overdue_topics as $topic) {
            try {
                $this->db->beginTransaction();
                
                // Process refunds for all contributions
                $refund_result = $this->refundManager->refundAllTopicContributions(
                    $topic->id, 
                    'Creator failed to deliver content within 48 hours'
                );
                
                // Update topic status
                $this->db->query('UPDATE topics SET status = "failed" WHERE id = :id');
                $this->db->bind(':id', $topic->id);
                $this->db->execute();
                
                // Reset platform fee processing since topic failed
                $this->db->query('
                    UPDATE topics 
                    SET fee_processed = 0, platform_fee_amount = 0, creator_payout_amount = 0 
                    WHERE id = :id
                ');
                $this->db->bind(':id', $topic->id);
                $this->db->execute();
                
                // Mark platform fee as failed
                $this->db->query('UPDATE platform_fees SET status = "failed" WHERE topic_id = :id');
                $this->db->bind(':id', $topic->id);
                $this->db->execute();
                
                // Notify creator of failure
                $this->sendCreatorFailureNotification($topic, $refund_result);
                
                // Notify contributors of refund
                $this->sendContributorRefundNotifications($topic->id, $topic, $refund_result);
                
                $this->db->endTransaction();
                
                $results[] = [
                    'topic_id' => $topic->id,
                    'topic_title' => $topic->title,
                    'refunds_processed' => $refund_result['refunds_processed'],
                    'total_refunded' => $refund_result['total_refunded']
                ];
                
            } catch (Exception $e) {
                $this->db->cancelTransaction();
                error_log("Auto-refund failed for topic " . $topic->id . ": " . $e->getMessage());
            }
        }
        
        return $results;
    }
    
    /**
     * Send failure notification to creator
     */
    private function sendCreatorFailureNotification($topic, $refund_result) {
        $this->db->query('
            SELECT c.email, u.email as user_email
            FROM creators c
            LEFT JOIN users u ON c.applicant_user_id = u.id
            WHERE c.id = :creator_id
        ');
        $this->db->bind(':creator_id', $topic->creator_id);
        $creator = $this->db->single();
        
        $creator_email = $creator->user_email ?: $creator->email;
        
        if ($creator_email) {
            $subject = "⚠️ Topic Failed - Content Deadline Missed";
            $message = "
                Hi " . $topic->creator_name . ",
                
                Unfortunately, your topic '" . $topic->title . "' has been marked as FAILED because content was not delivered within the 48-hour deadline.
                
                📅 Deadline was: " . date('M j, Y g:i A', strtotime($topic->content_deadline)) . "
                
                ⚠️ Actions Taken:
                • All contributors have been automatically refunded
                • " . $refund_result['refunds_processed'] . " refunds processed
                • Total refunded: $" . number_format($refund_result['total_refunded'], 2) . "
                • Topic status changed to 'Failed'
                • No creator payout will be processed
                
                💰 Financial Impact:
                Since the content deadline was missed, the platform fee has been reversed and no payout will be issued.
                
                📋 This affects your creator performance:
                • Failed deliveries may impact future topic approvals
                • Please ensure you can meet deadlines before accepting funded topics
                
                💡 For future topics:
                • Only accept topics you can realistically complete
                • Communicate with supporters if you face unexpected delays
                • Upload content well before the 48-hour deadline
                
                If you believe this was an error, please contact support immediately.
                
                Best regards,
                TopicLaunch Team
            ";
            
            $this->sendEmail($creator_email, $subject, $message);
        }
    }
    
    /**
     * Send refund notifications to contributors
     */
    private function sendContributorRefundNotifications($topic_id, $topic, $refund_result) {
        foreach ($refund_result['details'] as $detail) {
            if ($detail['success']) {
                $subject = "💰 Automatic Refund Processed - " . $topic->title;
                $message = "
                    Hi,
                    
                    A refund has been automatically processed for your contribution.
                    
                    📺 Topic: " . $topic->title . "
                    👥 Creator: " . $topic->creator_name . "
                    💰 Refund Amount: $" . number_format($detail['amount'], 2) . "
                    
                    🔄 Reason for Refund:
                    The creator did not deliver the requested content within the 48-hour deadline. As per our protection policy, all contributors have been automatically refunded.
                    
                    💳 Refund Details:
                    • Amount: $" . number_format($detail['amount'], 2) . "
                    • Will appear in your original payment method within 5-10 business days
                    • No action required from you
                    
                    We apologize for this inconvenience. Our 48-hour delivery guarantee ensures you only pay for content that gets created.
                    
                    🔍 Browse more topics: https://topiclaunch.com/topics/
                    
                    Questions? Contact our support team.
                    
                    Thank you for using TopicLaunch!
                    
                    Best regards,
                    TopicLaunch Team
                ";
                
                $this->sendEmail($detail['user_email'], $subject, $message);
            }
        }
    }
    
    /**
     * Send email notification with SMTP
     */
    private function sendEmail($to, $subject, $message) {
        // For localhost testing - just log emails instead of sending
        if ($_SERVER['HTTP_HOST'] === 'localhost' || strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false) {
            error_log("EMAIL TO: $to | SUBJECT: $subject | MESSAGE: $message");
            return true; // Pretend it worked
        }
        
        // SMTP Configuration for HostGator
        $smtp_host = 'mail.topiclaunch.com';
        $smtp_port = 587;
        $smtp_username = 'noreply@topiclaunch.com';
        $smtp_password = '@J71c6ah8@';
        
        // Email headers
        $headers = array(
            'From' => 'noreply@topiclaunch.com',
            'Reply-To' => 'support@topiclaunch.com',
            'Content-Type' => 'text/plain; charset=UTF-8',
            'X-Mailer' => 'TopicLaunch Platform'
        );
        
        // Try to send with SMTP (using PHP's mail function with proper headers)
        $formatted_headers = '';
        foreach ($headers as $key => $value) {
            $formatted_headers .= "$key: $value\r\n";
        }
        
        // Use mail() function with proper SMTP configuration
        ini_set('SMTP', $smtp_host);
        ini_set('smtp_port', $smtp_port);
        
        return mail($to, $subject, $message, $formatted_headers);
    }
    
    /**
     * Log notification for audit trail
     */
    private function logNotification($user_id, $type, $category, $message, $topic_id = null) {
        try {
            $this->db->query('
                INSERT INTO notifications (user_id, type, category, message, topic_id, created_at)
                VALUES (:user_id, :type, :category, :message, :topic_id, NOW())
            ');
            $this->db->bind(':user_id', $user_id);
            $this->db->bind(':type', $type);
            $this->db->bind(':category', $category);
            $this->db->bind(':message', $message);
            $this->db->bind(':topic_id', $topic_id);
            $this->db->execute();
        } catch (Exception $e) {
            error_log("Failed to log notification: " . $e->getMessage());
        }
    }
    
    /**
     * Send notification when topic is proposed to creator
     */
    public function sendTopicProposalNotification($topic_id) {
        try {
            // Get topic, creator, and proposer info
            $this->db->query('
                SELECT t.*, c.display_name as creator_name, c.email as creator_email, 
                       u.email as creator_user_email, proposer.username as proposer_name
                FROM topics t 
                JOIN creators c ON t.creator_id = c.id 
                LEFT JOIN users u ON c.applicant_user_id = u.id
                JOIN users proposer ON t.initiator_user_id = proposer.id
                WHERE t.id = :topic_id
            ');
            $this->db->bind(':topic_id', $topic_id);
            $topic = $this->db->single();
            
            if (!$topic) {
                error_log("Topic not found for proposal notification: " . $topic_id);
                return false;
            }
            
            $creator_email = $topic->creator_user_email ?: $topic->creator_email;
            
            if (!$creator_email) {
                error_log("No email found for creator ID: " . $topic->creator_id);
                return false;
            }
            
            $subject = "📋 New Topic Proposal - " . $topic->title;
            $message = "
                Hi " . $topic->creator_name . ",
                
                You have a new topic proposal waiting for your approval!
                
                📺 Topic: " . $topic->title . "
                👤 Proposed by: " . $topic->proposer_name . "
                💰 Suggested funding goal: $" . number_format($topic->funding_threshold, 2) . "
                
                📋 Description:
                " . $topic->description . "
                
                🎯 What you need to do:
                1. Review the topic proposal carefully
                2. Decide if you want to create content on this topic
                3. Approve or decline the proposal
                
                📱 Review and Approve:
                Visit your Creator Dashboard: https://topiclaunch.com/creators/dashboard.php
                
                ⏰ No rush - take your time to review the proposal.
                
                💡 Remember:
                • If you approve, the topic goes live for community funding
                • Once funded, you'll have 48 hours to create the content
                • You'll earn 90% of the funding (after 10% platform fee)
                
                Questions? Reply to this email or contact support.
                
                Best regards,
                TopicLaunch Team
            ";
            
            $this->sendEmail($creator_email, $subject, $message);
            
            // Log notification
            $this->logNotification($topic->creator_id, 'creator', 'topic_proposal', 
                "New topic proposal: '" . $topic->title . "' by " . $topic->proposer_name, $topic_id);
            
            return true;
            
        } catch (Exception $e) {
            error_log("Topic proposal notification error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Create required database tables
     */
    private function createNotificationTables() {
        // Notifications table
        $this->db->query("
            CREATE TABLE IF NOT EXISTS notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT,
                type ENUM('creator', 'contributor', 'admin') NOT NULL,
                category VARCHAR(50) NOT NULL,
                message TEXT NOT NULL,
                topic_id INT,
                is_read TINYINT(1) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user (user_id),
                INDEX idx_type (type),
                INDEX idx_category (category),
                INDEX idx_topic (topic_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $this->db->execute();
        
        // Auto-refund schedule table
        $this->db->query("
            CREATE TABLE IF NOT EXISTS auto_refund_schedule (
                id INT AUTO_INCREMENT PRIMARY KEY,
                topic_id INT NOT NULL,
                deadline DATETIME NOT NULL,
                status ENUM('scheduled', 'processed', 'cancelled') DEFAULT 'scheduled',
                processed_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_topic (topic_id),
                INDEX idx_topic (topic_id),
                INDEX idx_deadline (deadline),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $this->db->execute();
    }
}
?>
