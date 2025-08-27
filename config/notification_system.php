<?php
// config/notification_system.php - FIXED VERSION - Complete file to copy & paste
require_once 'database.php';

class NotificationSystem {
    private $db;
    private $refundManager;
    private $feeManager;
    
    public function __construct() {
        $this->db = new Database();
        
        // Initialize managers with error handling to prevent 500 errors
        try {
            if (class_exists('RefundManager')) {
                require_once 'refund_helper.php';
                $this->refundManager = new RefundManager();
            }
        } catch (Exception $e) {
            error_log("RefundManager not available: " . $e->getMessage());
            $this->refundManager = null;
        }
        
        try {
            if (class_exists('PlatformFeeManager')) {
                require_once 'platform_fee_helper.php';
                $this->feeManager = new PlatformFeeManager();
            }
        } catch (Exception $e) {
            error_log("PlatformFeeManager not available: " . $e->getMessage());
            $this->feeManager = null;
        }
        
        $this->createNotificationTables();
    }
    
    /**
     * Send notification when topic goes live (no approval needed)
     */
    public function sendTopicLiveNotification($topic_id) {
        try {
            error_log("Sending topic live notification for topic: " . $topic_id);
            
            // Get topic, creator, and proposer info
            $this->db->query('
                SELECT t.*, c.display_name as creator_name, c.email as creator_email, 
                       u.email as creator_user_email, proposer.username as proposer_name,
                       proposer.email as proposer_email
                FROM topics t 
                JOIN creators c ON t.creator_id = c.id 
                LEFT JOIN users u ON c.applicant_user_id = u.id
                JOIN users proposer ON t.initiator_user_id = proposer.id
                WHERE t.id = :topic_id
            ');
            $this->db->bind(':topic_id', $topic_id);
            $topic = $this->db->single();
            
            if (!$topic) {
                error_log("Topic not found for live notification: " . $topic_id);
                return false;
            }
            
            error_log("Found topic: " . $topic->title . " by creator: " . $topic->creator_name);
            
            // Notify creator that topic is live
            $creator_email = $topic->creator_user_email ?: $topic->creator_email;
            if ($creator_email) {
                error_log("Sending creator notification to: " . $creator_email);
                $this->sendCreatorTopicLiveNotification($topic, $creator_email);
            } else {
                error_log("No creator email found");
            }
            
            // Notify proposer that topic is live
            if ($topic->proposer_email) {
                error_log("Sending proposer notification to: " . $topic->proposer_email);
                $this->sendProposerTopicLiveNotification($topic);
            } else {
                error_log("No proposer email found");
            }
            
            return true;
            
        } catch (Exception $e) {
            error_log("Topic live notification error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send notification to creator when topic goes live
     */
    private function sendCreatorTopicLiveNotification($topic, $creator_email) {
        $subject = "New Topic Request - " . $topic->title;
        $message = "Hello " . $topic->creator_name . ",

A new topic request has been created for your channel:

TOPIC DETAILS:
Title: " . $topic->title . "
Requested by: " . $topic->proposer_name . "
Funding Goal: $" . number_format($topic->funding_threshold, 2) . "
Current Funding: $" . number_format($topic->current_funding, 2) . "

DESCRIPTION:
" . $topic->description . "

STATUS:
The topic is now live and accepting community funding. Once it reaches the funding goal, you'll have 48 hours to create the content and earn 90% of the funding.

View topic progress: https://topiclaunch.com/topics/view.php?id=" . $topic->id . "
Your dashboard: https://topiclaunch.com/creators/dashboard.php

Best regards,
TopicLaunch Team

---
TopicLaunch - Creator Content Platform
Support: support@topiclaunch.com";
        
        $result = $this->sendEmail($creator_email, $subject, $message);
        error_log("Creator live notification sent to {$creator_email}: " . ($result ? 'SUCCESS' : 'FAILED'));
        
        // Log notification
        $this->logNotification($topic->creator_id, 'creator', 'topic_live', 
            "New topic live: '" . $topic->title . "' by " . $topic->proposer_name, $topic->id);
    }
    
    /**
     * Send notification to proposer when topic goes live
     */
    private function sendProposerTopicLiveNotification($topic) {
        $subject = "Topic Live - " . $topic->title;
        $message = "Hello " . $topic->proposer_name . ",

Your topic request is now live and accepting community funding:

TOPIC DETAILS:
Title: " . $topic->title . "
Creator: " . $topic->creator_name . "
Funding Goal: $" . number_format($topic->funding_threshold, 2) . "
Your Contribution: $" . number_format($topic->current_funding, 2) . "

NEXT STEPS:
Your topic is live for the community to fund. Once funded, " . $topic->creator_name . " has 48 hours to create the content.

Share your topic: https://topiclaunch.com/topics/view.php?id=" . $topic->id . "

PROTECTION:
If the creator doesn't deliver content within 48 hours of funding, you'll receive a 90% refund (10% covers platform fees and delivery guarantee).

Thank you for using TopicLaunch.

Best regards,
TopicLaunch Team

---
TopicLaunch - Creator Content Platform
Support: support@topiclaunch.com";
        
        $result = $this->sendEmail($topic->proposer_email, $subject, $message);
        error_log("Proposer live notification sent to {$topic->proposer_email}: " . ($result ? 'SUCCESS' : 'FAILED'));
        
        // Log notification
        $this->logNotification($topic->initiator_user_id, 'proposer', 'topic_live', 
            "Topic went live: '" . $topic->title . "'", $topic->id);
    }
    
    /**
     * FIXED: Send notification when topic reaches funding goal
     */
    public function handleTopicFunded($topic_id) {
        try {
            error_log("Handling topic funded notifications for topic: " . $topic_id);
            
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
            
            error_log("Processing funded notifications for: " . $topic->title);
            
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
            
            // Process platform fees with error handling
            $fee_result = null;
            if ($this->feeManager) {
                try {
                    $fee_result = $this->feeManager->processTopicFunding($topic_id);
                    if (!$fee_result['success']) {
                        error_log("Platform fee processing failed: " . $fee_result['error']);
                    }
                } catch (Exception $e) {
                    error_log("Platform fee processing error: " . $e->getMessage());
                }
            }
            
            // Fallback fee calculation if platform fee manager fails
            if (!$fee_result || !$fee_result['success']) {
                error_log("Using fallback fee calculation");
                $total_funding = $topic->current_funding;
                $platform_fee = $total_funding * 0.10;
                $creator_amount = $total_funding * 0.90;
                
                $fee_result = [
                    'success' => true,
                    'total_funding' => $total_funding,
                    'platform_fee' => $platform_fee,
                    'creator_amount' => $creator_amount,
                    'fee_percent' => 10.0
                ];
            }
            
            // Get updated topic with fee information
            $this->db->query('SELECT * FROM topics WHERE id = :topic_id');
            $this->db->bind(':topic_id', $topic_id);
            $topic = $this->db->single();
            
            $this->db->endTransaction();
            
            // Send notifications AFTER committing transaction
            error_log("Sending funded notifications...");
            
            // 1. Notify Creator (with net amount after 10% fee)
            $creator_result = $this->sendCreatorFundedNotification($topic, $deadline, $fee_result);
            error_log("Creator notification result: " . ($creator_result ? 'SUCCESS' : 'FAILED'));
            
            // 2. Notify All Contributors
            $contributor_result = $this->sendContributorFundedNotifications($topic_id, $topic, $deadline);
            error_log("Contributor notifications result: " . ($contributor_result ? 'SUCCESS' : 'FAILED'));
            
            // 3. Schedule auto-refund check
            $this->scheduleAutoRefundCheck($topic_id, $deadline);
            
            return [
                'success' => true, 
                'deadline' => $deadline,
                'fee_info' => $fee_result,
                'creator_notified' => $creator_result,
                'contributors_notified' => $contributor_result
            ];
            
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->cancelTransaction();
            }
            error_log("Funded notification error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Send notification to creator when topic is funded - IMPROVED VERSION
     */
    private function sendCreatorFundedNotification($topic, $deadline, $fee_info) {
        $creator_email = $topic->creator_user_email ?: $topic->creator_email;
        
        if (!$creator_email) {
            error_log("No email found for creator ID: " . $topic->creator_id);
            return false;
        }
        
        error_log("Sending funded notification to creator: " . $creator_email);
        
        // IMPROVED: Less spammy subject line
        $subject = "Content Request Funded - " . $topic->title;
        
        // IMPROVED: Professional, less promotional email content
        $message = "Hello " . $topic->creator_name . ",

Your content request has been fully funded and is ready for creation.

REQUEST DETAILS:
Topic: " . $topic->title . "
Total Funding: $" . number_format($topic->current_funding, 2) . "
Your Earnings: $" . number_format($fee_info['creator_amount'], 2) . " (after 10% platform fee)

CONTENT DEADLINE:
" . date('F j, Y \a\t g:i A', strtotime($deadline)) . "

NEXT STEPS:
1. Create your content for the requested topic
2. Upload the content URL to mark as completed
3. Upload before the deadline to receive payment

Upload here: https://topiclaunch.com/creators/upload_content.php?topic=" . $topic->id . "

IMPORTANT NOTES:
- Content must be uploaded within 48 hours of this notification
- If content is not delivered on time, contributors will receive refunds
- Your payment will be processed after successful content delivery

Thank you for participating in TopicLaunch.

Best regards,
TopicLaunch Team

---
TopicLaunch - Creator Content Platform
Support: support@topiclaunch.com
Unsubscribe: mailto:unsubscribe@topiclaunch.com";
        
        $result = $this->sendEmail($creator_email, $subject, $message);
        error_log("Creator funded notification sent: " . ($result ? 'SUCCESS' : 'FAILED'));
        
        // Log notification
        $this->logNotification($topic->creator_id, 'creator', 'topic_funded', 
            "Topic '" . $topic->title . "' funded - Earning $" . number_format($fee_info['creator_amount'], 2) . " after fees", $topic->id);
        
        return $result;
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
        
        error_log("Found " . count($contributors) . " contributors to notify");
        
        $success_count = 0;
        
        foreach ($contributors as $contributor) {
            $subject = "Topic Funded - " . $topic->title;
            $message = "Hello " . $contributor->username . ",

The topic you supported has reached its funding goal:

TOPIC DETAILS:
Title: " . $topic->title . "
Creator: " . $topic->creator_name . "
Your Contribution: $" . number_format($contributor->amount, 2) . "

CONTENT DEADLINE:
" . date('F j, Y \a\t g:i A', strtotime($deadline)) . "

WHAT HAPPENS NEXT:
The creator now has 48 hours to create and upload the requested content. You'll be notified when the content is ready.

REFUND PROTECTION:
If the creator doesn't deliver content within 48 hours, you'll automatically receive a 90% refund ($" . number_format($contributor->amount * 0.9, 2) . ") to your original payment method. The 10% platform fee covers processing costs and delivery guarantee services.

Track progress: https://topiclaunch.com/topics/view.php?id=" . $topic_id . "

Thank you for supporting content creators on TopicLaunch.

Best regards,
TopicLaunch Team

---
TopicLaunch - Creator Content Platform
Support: support@topiclaunch.com
Unsubscribe: mailto:unsubscribe@topiclaunch.com";
            
            $result = $this->sendEmail($contributor->email, $subject, $message);
            if ($result) {
                $success_count++;
            }
            
            error_log("Contributor notification sent to {$contributor->email}: " . ($result ? 'SUCCESS' : 'FAILED'));
            
            // Log notification for each contributor
            $this->logNotification($contributor->user_id ?? 0, 'contributor', 'topic_funded', 
                "Funded topic '" . $topic->title . "' - Content deadline set", $topic_id);
        }
        
        error_log("Contributor notifications: {$success_count}/" . count($contributors) . " sent successfully");
        
        return $success_count > 0;
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
        
        $success_count = 0;
        
        foreach ($contributors as $contributor) {
            $subject = "Content Delivered - " . $topic->title;
            $message = "Hello " . $contributor->username . ",

The content you funded has been delivered:

CONTENT DETAILS:
Topic: " . $topic->title . "
Creator: " . $topic->creator_name . "
Your Contribution: $" . number_format($contributor->amount, 2) . "

ACCESS YOUR CONTENT:
" . $content_url . "

Thank you for supporting content creators through TopicLaunch.

Best regards,
TopicLaunch Team

---
TopicLaunch - Creator Content Platform
Support: support@topiclaunch.com";
            
            $result = $this->sendEmail($contributor->email, $subject, $message);
            if ($result) {
                $success_count++;
            }
            
            error_log("Content delivered notification sent to {$contributor->email}: " . ($result ? 'SUCCESS' : 'FAILED'));
        }
        
        error_log("Content delivery notifications: {$success_count}/" . count($contributors) . " sent successfully");
        
        return $success_count > 0;
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
            
            error_log("Auto-refund scheduled for topic {$topic_id} at {$deadline}");
        } catch (Exception $e) {
            error_log("Failed to schedule auto-refund: " . $e->getMessage());
        }
    }
    
    /**
     * Process auto-refunds for overdue topics (run via cron job) - 90% PARTIAL REFUND
     */
    public function processAutoRefunds() {
        if (!$this->refundManager) {
            error_log("RefundManager not available for auto-refunds");
            return [];
        }
        
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
                
                // Process 90% refunds for all contributions
                $refund_result = $this->refundManager->refundAllTopicContributions90Percent(
                    $topic->id, 
                    'Creator failed to deliver content within 48 hours - 90% refund (10% platform fee retained)'
                );
                
                // Update topic status
                $this->db->query('UPDATE topics SET status = "failed" WHERE id = :id');
                $this->db->bind(':id', $topic->id);
                $this->db->execute();
                
                // Keep platform fee as revenue since topic failed
                if ($this->feeManager) {
                    try {
                        $this->db->query('
                            UPDATE platform_fees 
                            SET status = "retained_failed_delivery", processed_at = NOW()
                            WHERE topic_id = :id
                        ');
                        $this->db->bind(':id', $topic->id);
                        $this->db->execute();
                        
                        // Mark creator payout as failed
                        $this->db->query('UPDATE creator_payouts SET status = "failed" WHERE topic_id = :id');
                        $this->db->bind(':id', $topic->id);
                        $this->db->execute();
                    } catch (Exception $e) {
                        error_log("Failed to update platform fees: " . $e->getMessage());
                    }
                }
                
                // Notify creator of failure
                $this->sendCreatorFailureNotification($topic, $refund_result);
                
                // Notify contributors of 90% refund
                $this->sendContributor90PercentRefundNotifications($topic->id, $topic, $refund_result);
                
                $this->db->endTransaction();
                
                $results[] = [
                    'topic_id' => $topic->id,
                    'topic_title' => $topic->title,
                    'refunds_processed' => $refund_result['refunds_processed'],
                    'total_refunded' => $refund_result['total_refunded'],
                    'platform_revenue_retained' => $refund_result['total_platform_revenue']
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
            $subject = "Content Deadline Missed - " . $topic->title;
            $message = "Hello " . $topic->creator_name . ",

The content deadline for your funded topic has been missed.

TOPIC DETAILS:
Title: " . $topic->title . "
Deadline: " . date('F j, Y \a\t g:i A', strtotime($topic->content_deadline)) . "

ACTIONS TAKEN:
- All contributors have been automatically refunded 90% of their contributions
- " . $refund_result['refunds_processed'] . " refunds processed
- Total refunded: $" . number_format($refund_result['total_refunded'], 2) . "
- Topic status changed to 'Failed'
- No creator payout will be processed

PLATFORM FEE:
The 10% platform fee has been retained to cover processing costs, delivery guarantee services, and platform operations.

IMPACT:
Failed deliveries may impact future topic approvals. Please ensure you can meet deadlines before accepting funded topics.

For questions, please contact support.

Best regards,
TopicLaunch Team

---
TopicLaunch - Creator Content Platform
Support: support@topiclaunch.com";
            
            $this->sendEmail($creator_email, $subject, $message);
        }
    }
    
    /**
     * Send 90% refund notifications to contributors
     */
    private function sendContributor90PercentRefundNotifications($topic_id, $topic, $refund_result) {
        foreach ($refund_result['details'] as $detail) {
            if ($detail['success']) {
                $original_amount = $detail['original_amount'];
                $refund_amount = $detail['refund_amount'];
                $platform_fee_kept = $detail['platform_fee_kept'];
                
                $subject = "Refund Processed - " . $topic->title;
                $message = "Hello,

A 90% refund has been automatically processed for your contribution.

REFUND DETAILS:
Topic: " . $topic->title . "
Creator: " . $topic->creator_name . "
Original Contribution: $" . number_format($original_amount, 2) . "
Refund Amount: $" . number_format($refund_amount, 2) . " (90%)
Platform Fee Retained: $" . number_format($platform_fee_kept, 2) . " (10%)

REASON:
The creator did not deliver the requested content within the 48-hour deadline.

PROCESSING:
Your refund will appear in your original payment method within 5-10 business days. No action is required from you.

PLATFORM FEE:
The 10% platform fee covers payment processing, hosting, customer support, delivery guarantee system, and platform maintenance costs.

We apologize for this inconvenience. Our delivery guarantee system ensures accountability while maintaining platform sustainability.

Best regards,
TopicLaunch Team

---
TopicLaunch - Creator Content Platform
Support: support@topiclaunch.com";
                
                $this->sendEmail($detail['user_email'], $subject, $message);
            }
        }
    }
    
    /**
     * FIXED: Send email notification with improved error handling
     */
    private function sendEmail($to, $subject, $message) {
        // For localhost testing - log emails and return true
        if (strpos($_SERVER['HTTP_HOST'] ?? 'localhost', 'localhost') !== false || 
            strpos($_SERVER['HTTP_HOST'] ?? '127.0.0.1', '127.0.0.1') !== false) {
            error_log("📧 EMAIL TO: $to | SUBJECT: $subject");
            return true; // Pretend it worked for local testing
        }
        
        // Validate email
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            error_log("❌ Invalid email address: " . $to);
            return false;
        }
        
        // Clean up message and subject
        $message = trim($message);
        $subject = trim($subject);
        
        // Check if subject and message are not empty
        if (empty($subject) || empty($message)) {
            error_log("❌ Email subject or message is empty");
            return false;
        }
        
        // IMPROVED: Enhanced email headers for better deliverability
        $headers = array();
        $headers[] = 'From: TopicLaunch Notifications <noreply@topiclaunch.com>';
        $headers[] = 'Reply-To: TopicLaunch Support <support@topiclaunch.com>';
        $headers[] = 'Return-Path: noreply@topiclaunch.com';
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: 8bit';
        $headers[] = 'X-Mailer: TopicLaunch Platform v1.0';
        $headers[] = 'X-Priority: 3'; // Normal priority
        $headers[] = 'X-MSMail-Priority: Normal';
        $headers[] = 'Importance: Normal';
        
        // Anti-spam headers
        $headers[] = 'X-Auto-Response-Suppress: All';
        $headers[] = 'Auto-Submitted: auto-generated';
        $headers[] = 'Precedence: bulk';
        
        // Message ID for threading
        $message_id = '<' . time() . '.' . uniqid() . '@topiclaunch.com>';
        $headers[] = 'Message-ID: ' . $message_id;
        
        // List headers (helps with spam filtering)
        $headers[] = 'List-Unsubscribe: <mailto:unsubscribe@topiclaunch.com>';
        $headers[] = 'List-Id: TopicLaunch Notifications <notifications.topiclaunch.com>';
        
        $formatted_headers = implode("\r\n", $headers);
        
        try {
            // Log email attempt
            error_log("📤 Attempting to send email to: " . $to . " with subject: " . $subject);
            
            // Use PHP's mail function with improved headers
            $result = mail($to, $subject, $message, $formatted_headers);
            
            if ($result) {
                error_log("✅ Email sent successfully to: " . $to);
            } else {
                error_log("❌ Failed to send email to: " . $to);
                error_log("Last PHP error: " . (error_get_last()['message'] ?? 'No PHP error'));
                
                // Check if mail function is available
                if (!function_exists('mail')) {
                    error_log("PHP mail() function is not available");
                }
            }
            
            return $result;
            
        } catch (Exception $e) {
            error_log("❌ Email sending exception: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * FIXED: Log notification for audit trail
     */
    private function logNotification($user_id, $type, $category, $message, $topic_id = null) {
        try {
            // Check if notifications table exists and has required columns
            $this->db->query('DESCRIBE notifications');
            $columns = $this->db->resultSet();
            
            $has_category = false;
            foreach ($columns as $column) {
                if ($column->Field === 'category') {
                    $has_category = true;
                    break;
                }
            }
            
            if ($has_category) {
                // New table structure with category column
                $this->db->query('
                    INSERT INTO notifications (user_id, type, category, message, topic_id, created_at)
                    VALUES (:user_id, :type, :category, :message, :topic_id, NOW())
                ');
                $this->db->bind(':category', $category);
            } else {
                // Fallback for older table structure without category column
                $this->db->query('
                    INSERT INTO notifications (user_id, type, message, topic_id, created_at)
                    VALUES (:user_id, :type, :message, :topic_id, NOW())
                ');
            }
            
            $this->db->bind(':user_id', $user_id);
            $this->db->bind(':type', $type);
            $this->db->bind(':message', $message);
            $this->db->bind(':topic_id', $topic_id);
            $this->db->execute();
            
        } catch (Exception $e) {
            error_log("Failed to log notification: " . $e->getMessage());
            // Non-critical error - don't fail the notification sending
        }
    }
    
    /**
     * FIXED: Create required database tables
     */
    private function createNotificationTables() {
        // Notifications table - UPDATED VERSION
        try {
            $this->db->query("
                CREATE TABLE IF NOT EXISTS notifications (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT,
                    type ENUM('creator', 'contributor', 'proposer', 'admin') NOT NULL,
                    category VARCHAR(50) NOT NULL DEFAULT 'general',
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
            
            // If table exists but missing category column, add it
            $this->db->query("
                ALTER TABLE notifications 
                ADD COLUMN IF NOT EXISTS category VARCHAR(50) NOT NULL DEFAULT 'general' AFTER type
            ");
            $this->db->execute();
            
        } catch (Exception $e) {
            error_log("Failed to create/update notifications table: " . $e->getMessage());
        }
        
        // Auto-refund schedule table
        try {
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
        } catch (Exception $e) {
            error_log("Failed to create auto_refund_schedule table: " . $e->getMessage());
        }
    }
}
?>
