<?php
// config/notification_system.php - COMPLETE FIXED VERSION
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
            $this->db->query("
                SELECT t.*, c.display_name as creator_name,
                       u.email as creator_user_email, proposer.username as proposer_name,
                       proposer.email as proposer_email
                FROM topics t 
                JOIN creators c ON t.creator_id = c.id 
                LEFT JOIN users u ON c.applicant_user_id = u.id
                JOIN users proposer ON t.initiator_user_id = proposer.id
                WHERE t.id = :topic_id
            ");
            $this->db->bind(':topic_id', $topic_id);
            $topic = $this->db->single();
            
            if (!$topic) {
                error_log("Topic not found for live notification: " . $topic_id);
                return false;
            }
            
            error_log("Found topic: " . $topic->title . " by creator: " . $topic->creator_name);
            
            // Notify creator that topic is live
            $creator_email = $topic->creator_user_email;
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

A new topic request has been created for your channel.

TOPIC DETAILS:
Title: " . $topic->title . "
Requested by: " . $topic->proposer_name . "
Funding Goal: $" . number_format($topic->funding_threshold, 2) . "

DESCRIPTION:
" . $topic->description . "

STATUS:
The topic is now live and accepting community funding. Once it reaches the goal, you'll have 48 hours to create the content.

Your dashboard: https://topiclaunch.com/creators/dashboard.php

— TopicLaunch";
        
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
        $message = "Your topic request is now live and accepting community funding.

TOPIC DETAILS:
Title: " . $topic->title . "
Creator: " . $topic->creator_name . "
Funding Goal: $" . number_format($topic->funding_threshold, 2) . "

NEXT STEPS:
Share your topic to help it reach the goal faster:
https://topiclaunch.com/topics/view.php?id=" . $topic->id . "

PROTECTION:
If the creator doesn't deliver, you'll receive a refund automatically.

— TopicLaunch";
        
        $result = $this->sendEmail($topic->proposer_email, $subject, $message);
        error_log("Proposer live notification sent to {$topic->proposer_email}: " . ($result ? 'SUCCESS' : 'FAILED'));
        
        // Log notification
        $this->logNotification($topic->initiator_user_id, 'proposer', 'topic_live', 
            "Topic went live: '" . $topic->title . "'", $topic->id);
    }
    
    /**
     * FIXED: Send notification when topic reaches funding goal - WITH GUARANTEED DEADLINE SETTING
     */
    public function handleTopicFunded($topic_id) {
        try {
            error_log("========================================");
            error_log("HANDLING TOPIC FUNDED: Topic ID = " . $topic_id);
            error_log("========================================");
            
            $this->db->beginTransaction();
            
            // Get topic and creator info BEFORE any updates
            $this->db->query("
                SELECT t.*, c.display_name as creator_name, u.email as creator_user_email
                FROM topics t 
                JOIN creators c ON t.creator_id = c.id 
                LEFT JOIN users u ON c.applicant_user_id = u.id
                WHERE t.id = :topic_id
            ");
            $this->db->bind(':topic_id', $topic_id);
            $topic = $this->db->single();
            
            if (!$topic) {
                throw new Exception("Topic not found");
            }
            
            error_log("Topic found: " . $topic->title);
            error_log("Current status: " . $topic->status);
            error_log("Current funding: $" . $topic->current_funding);
            
            // Topic threshold reached — queue it (deadline only starts when creator manually starts it)
            $funded_at = date('Y-m-d H:i:s');
            error_log("Setting funded_at to: " . $funded_at);
            
            // FIXED: Use explicit column names and ensure values are set
            $this->db->query("
                UPDATE topics 
                SET 
                    funded_at = :funded_at,
                    status = 'queued'
                WHERE id = :topic_id
            ");
            $this->db->bind(':funded_at', $funded_at);
            $this->db->bind(':topic_id', $topic_id);
            $result = $this->db->execute();
            
            error_log("UPDATE query executed. Rows affected: " . ($result ? "SUCCESS" : "FAILED"));
            
            // VERIFICATION: Check topic is now queued
            $this->db->query('SELECT funded_at, status FROM topics WHERE id = :topic_id');
            $this->db->bind(':topic_id', $topic_id);
            $verify = $this->db->single();
            error_log("VERIFIED: funded_at=" . ($verify->funded_at ?? "null") . " status=" . ($verify->status ?? "null"));
            
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
            
            error_log("Transaction committed successfully");
            
            // Send notifications AFTER committing transaction
            error_log("Sending funded notifications...");
            
            // 1. Notify Creator (with net amount after 10% fee)
            $creator_result = $this->sendCreatorFundedNotification($topic, $fee_result);
            error_log("Creator notification result: " . ($creator_result ? 'SUCCESS' : 'FAILED'));
            
            // 2. Notify All Contributors
            $contributor_result = $this->sendContributorFundedNotifications($topic_id, $topic);
            error_log("Contributor notifications result: " . ($contributor_result ? 'SUCCESS' : 'FAILED'));
            
            // Auto-refund is scheduled when creator starts the topic (not at queue time)
            
            error_log("========================================");
            error_log("TOPIC FUNDED HANDLING COMPLETE");
            error_log("========================================");
            
            return [
                'success' => true, 
                'funded_at' => $funded_at,
                'fee_info' => $fee_result,
                'creator_notified' => $creator_result,
                'contributors_notified' => $contributor_result
            ];
            
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->cancelTransaction();
            }
            error_log("❌ FUNDED NOTIFICATION ERROR: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Send notification to creator when topic is funded
     */
    private function sendCreatorFundedNotification($topic, $fee_info) {
        $creator_email = $topic->creator_user_email;
        
        if (!$creator_email) {
            error_log("No email found for creator ID: " . $topic->creator_id);
            return false;
        }
        
        error_log("Sending funded notification to creator: " . $creator_email);
        
        $subject = "Topic Funded - " . $topic->title;

        $message = "Hello " . $topic->creator_name . ",

Your topic has been fully funded and is ready for you to create.

TOPIC DETAILS:
Title: " . $topic->title . "
Total Funded: $" . number_format($topic->current_funding, 2) . "
Your Earnings: $" . number_format($fee_info['creator_amount'], 2) . " (after 10% platform fee)

DESCRIPTION:
" . $topic->description . "

Your dashboard: https://topiclaunch.com/creators/dashboard.php

— TopicLaunch";
        
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
    private function sendContributorFundedNotifications($topic_id, $topic) {
        // Get all contributors
        $this->db->query("
            SELECT DISTINCT u.email, u.username, c.amount, u.id as user_id
            FROM contributions c
            JOIN users u ON c.user_id = u.id
            WHERE c.topic_id = :topic_id AND c.payment_status = 'completed'
        ");
        $this->db->bind(':topic_id', $topic_id);
        $contributors = $this->db->resultSet();
        
        error_log("Found " . count($contributors) . " contributors to notify");
        
        $success_count = 0;
        
        foreach ($contributors as $contributor) {
            $subject = "Topic Funded - " . $topic->title;
            $message = "The topic you supported has reached its funding goal.

TOPIC DETAILS:
Title: " . $topic->title . "
Creator: " . $topic->creator_name . "

WHAT HAPPENS NEXT:
The creator has 48 hours to create and upload the content. You'll be notified as soon as it's ready.

PROTECTION:
If the creator doesn't deliver, you'll receive a refund automatically.

— TopicLaunch";
            
            $result = $this->sendEmail($contributor->email, $subject, $message);
            if ($result) {
                $success_count++;
            }
            
            error_log("Contributor notification sent to {$contributor->email}: " . ($result ? 'SUCCESS' : 'FAILED'));
            
            // Log notification for each contributor
            $this->logNotification($contributor->user_id ?? 0, 'contributor', 'topic_funded', 
                "Topic '" . $topic->title . "' fully funded and added to creator queue", $topic_id);
        }
        
        error_log("Contributor notifications: {$success_count}/" . count($contributors) . " sent successfully");
        
        return $success_count > 0;
    }
    
    /**
     * Send emails when a topic goes active (not yet fully funded)
     * Called from the webhook after processTopicCreation
     */
    public function sendTopicActiveEmails($topic_id) {
        try {
            $this->db->query("
                SELECT t.*, c.display_name as creator_name,
                       cu.email as creator_email,
                       COALESCE(u.email, t.initiator_email) as fan_email,
                       u.username as fan_name
                FROM topics t
                JOIN creators c ON t.creator_id = c.id
                LEFT JOIN users cu ON c.applicant_user_id = cu.id
                LEFT JOIN users u ON t.initiator_user_id = u.id
                WHERE t.id = :id
            ");
            $this->db->bind(':id', $topic_id);
            $topic = $this->db->single();
            if (!$topic) return;

            // Notify creator of new topic request
            if ($topic->creator_email) {
                $this->sendEmail($topic->creator_email,
                    "New Topic Request — " . $topic->title,
                    "Hello " . $topic->creator_name . ",\n\n"
                    . "A new topic request has been created for your channel.\n\n"
                    . "TOPIC DETAILS:\n"
                    . "Title: " . $topic->title . "\n"
                    . "Funding Goal: \$" . number_format($topic->funding_threshold, 2) . "\n\n"
                    . "DESCRIPTION:\n"
                    . $topic->description . "\n\n"
                    . "STATUS:\n"
                    . "The topic is now live and accepting community funding. Once it reaches the goal, you'll have 48 hours to create the content.\n\n"
                    . "Your dashboard: https://topiclaunch.com/creators/dashboard.php\n\n"
                    . "— TopicLaunch"
                );
            }

            // Notify fan their topic is live
            if ($topic->fan_email) {
                $this->sendEmail($topic->fan_email,
                    "Your Topic is Live — " . $topic->title,
                    "Your topic request is now live and accepting community funding.\n\n"
                    . "TOPIC DETAILS:\n"
                    . "Title: " . $topic->title . "\n"
                    . "Creator: " . $topic->creator_name . "\n"
                    . "Funding Goal: \$" . number_format($topic->funding_threshold, 2) . "\n\n"
                    . "NEXT STEPS:\n"
                    . "Share your topic to help it reach the goal faster:\n"
                    . "https://topiclaunch.com/topics/view.php?id=" . $topic->id . "\n\n"
                    . "PROTECTION:\n"
                    . "If the creator doesn't deliver, you'll receive a refund automatically.\n\n"
                    . "— TopicLaunch"
                );
            }
        } catch (Exception $e) {
            error_log("sendTopicActiveEmails error: " . $e->getMessage());
        }
    }

    /**
     * Send funded emails (no DB writes) — called from webhook after topic reaches goal
     */
    public function sendFundedEmails($topic_id) {
        try {
            $this->db->query("
                SELECT t.*, c.display_name as creator_name,
                       cu.email as creator_email,
                       COALESCE(u.email, t.initiator_email) as fan_email
                FROM topics t
                JOIN creators c ON t.creator_id = c.id
                LEFT JOIN users cu ON c.applicant_user_id = cu.id
                LEFT JOIN users u ON t.initiator_user_id = u.id
                WHERE t.id = :id
            ");
            $this->db->bind(':id', $topic_id);
            $topic = $this->db->single();
            if (!$topic) return;

            error_log("sendFundedEmails: topic $topic_id, fan_email=" . ($topic->fan_email ?? 'NULL') . ", creator_email=" . ($topic->creator_email ?? 'NULL'));

            $creator_earnings = $topic->funding_threshold * 0.9;
            $emailed = [];

            // Email creator
            if ($topic->creator_email) {
                $this->sendEmail($topic->creator_email,
                    "Topic Funded - " . $topic->title,
                    "Hello " . $topic->creator_name . ",\n\n"
                    . "Your topic has been fully funded and is ready for you to create.\n\n"
                    . "TOPIC DETAILS:\n"
                    . "Title: " . $topic->title . "\n"
                    . "Total Funded: \$" . number_format($topic->current_funding, 2) . "\n"
                    . "Your Earnings: \$" . number_format($creator_earnings, 2) . " (after 10% platform fee)\n\n"
                    . "DESCRIPTION:\n"
                    . $topic->description . "\n\n"
                    . "Your dashboard: https://topiclaunch.com/creators/dashboard.php\n\n"
                    . "— TopicLaunch"
                );
                $emailed[] = $topic->creator_email;
            }

            // Email the topic initiator (fan who created the request)
            if ($topic->fan_email && !in_array($topic->fan_email, $emailed)) {
                $this->sendEmail($topic->fan_email,
                    "Topic Fully Funded — " . $topic->title,
                    "The topic you requested has been fully funded.\n\n"
                    . "TOPIC DETAILS:\n"
                    . "Title: " . $topic->title . "\n"
                    . "Creator: " . $topic->creator_name . "\n\n"
                    . "WHAT HAPPENS NEXT:\n"
                    . $topic->creator_name . " has 48 hours to create and upload the content. You'll get an email as soon as it's ready.\n\n"
                    . "PROTECTION:\n"
                    . "If the creator doesn't deliver, you'll receive a refund automatically.\n\n"
                    . "— TopicLaunch"
                );
                $emailed[] = $topic->fan_email;
            }

            // Email any other contributors with accounts
            $this->db->query("
                SELECT DISTINCT u.email
                FROM contributions c
                JOIN users u ON c.user_id = u.id
                WHERE c.topic_id = :id AND c.payment_status = 'completed' AND u.email IS NOT NULL
            ");
            $this->db->bind(':id', $topic_id);
            $contributors = $this->db->resultSet();

            foreach ($contributors as $fan) {
                if (!$fan->email || in_array($fan->email, $emailed)) continue;
                $this->sendEmail($fan->email,
                    "Topic Fully Funded — " . $topic->title,
                    "The topic you supported has been fully funded.\n\n"
                    . "TOPIC DETAILS:\n"
                    . "Title: " . $topic->title . "\n"
                    . "Creator: " . $topic->creator_name . "\n\n"
                    . "WHAT HAPPENS NEXT:\n"
                    . $topic->creator_name . " has 48 hours to create and upload the content. You'll get an email as soon as it's ready.\n\n"
                    . "PROTECTION:\n"
                    . "If the creator doesn't deliver, you'll receive a refund automatically.\n\n"
                    . "— TopicLaunch"
                );
                $emailed[] = $fan->email;
            }

            error_log("sendFundedEmails: notified " . count($emailed) . " recipients for topic $topic_id");
        } catch (Exception $e) {
            error_log("sendFundedEmails error: " . $e->getMessage());
        }
    }

    /**
     * Send content delivered notifications
     */
    public function sendContentDeliveredNotifications($topic_id, $content_url) {
        try {
            $this->db->query("
                SELECT t.*, c.display_name as creator_name,
                       COALESCE(u.email, t.initiator_email) as fan_email
                FROM topics t
                JOIN creators c ON t.creator_id = c.id
                LEFT JOIN users u ON t.initiator_user_id = u.id
                WHERE t.id = :topic_id
            ");
            $this->db->bind(':topic_id', $topic_id);
            $topic = $this->db->single();

            if (!$topic) return false;

            error_log("sendContentDeliveredNotifications: topic $topic_id, fan_email=" . ($topic->fan_email ?? 'NULL'));

            $emailed = [];
            $success_count = 0;

            $subject = "Content Delivered — " . $topic->title;
            $body = "The content you requested has been delivered.\n\n"
                . "TOPIC DETAILS:\n"
                . "Title: " . $topic->title . "\n"
                . "Creator: " . $topic->creator_name . "\n\n"
                . "CONTENT:\n"
                . $content_url . "\n\n"
                . "— TopicLaunch";

            // Email the topic initiator
            if ($topic->fan_email) {
                $result = $this->sendEmail($topic->fan_email, $subject, $body);
                if ($result) $success_count++;
                error_log("Content delivered to initiator {$topic->fan_email}: " . ($result ? 'SUCCESS' : 'FAILED'));
                $emailed[] = $topic->fan_email;
            }

            // Email any other contributors with accounts
            $this->db->query("
                SELECT DISTINCT u.email
                FROM contributions c
                JOIN users u ON c.user_id = u.id
                WHERE c.topic_id = :topic_id AND c.payment_status = 'completed' AND u.email IS NOT NULL
            ");
            $this->db->bind(':topic_id', $topic_id);
            $contributors = $this->db->resultSet();

            foreach ($contributors as $contributor) {
                if (!$contributor->email || in_array($contributor->email, $emailed)) continue;
                $result = $this->sendEmail($contributor->email, $subject, $body);
                if ($result) $success_count++;
                error_log("Content delivered to contributor {$contributor->email}: " . ($result ? 'SUCCESS' : 'FAILED'));
                $emailed[] = $contributor->email;
            }

            error_log("sendContentDeliveredNotifications: {$success_count}/" . count($emailed) . " sent for topic $topic_id");
            return $success_count > 0;
        } catch (Exception $e) {
            error_log("sendContentDeliveredNotifications error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Schedule auto-refund check for topics past deadline
     */
    private function scheduleAutoRefundCheck($topic_id, $deadline) {
        try {
            $this->db->query("
                INSERT INTO auto_refund_schedule (topic_id, deadline, status, created_at)
                VALUES (:topic_id, :deadline, 'scheduled', NOW())
                ON DUPLICATE KEY UPDATE deadline = :deadline, status = 'scheduled'
            ");
            $this->db->bind(':topic_id', $topic_id);
            $this->db->execute();
            
            error_log("Auto-refund scheduled for topic {$topic_id} at {$deadline}");
        } catch (Exception $e) {
            error_log("Failed to schedule auto-refund: " . $e->getMessage());
        }
    }
    
    /**
     * FIXED: Process auto-refunds for overdue topics - IMPROVED SAFETY CHECKS
     */
    public function processAutoRefunds() {
        if (!$this->refundManager) {
            error_log("RefundManager not available for auto-refunds");
            return [];
        }
        
        // IMPROVED: Only get topics that are truly overdue AND haven't been completed
        $this->db->query("
            SELECT t.*, c.display_name as creator_name
            FROM topics t
            JOIN creators c ON t.creator_id = c.id
            WHERE t.status = 'funded' 
            AND t.content_deadline IS NOT NULL
            AND t.content_deadline < NOW()
            AND (t.content_url IS NULL OR t.content_url = '')
            AND (t.completed_at IS NULL)
        ");
        $overdue_topics = $this->db->resultSet();
        
        error_log("========================================");
        error_log("AUTO-REFUND PROCESSING");
        error_log("Found " . count($overdue_topics) . " overdue topics");
        error_log("========================================");
        
        $results = [];
        
        foreach ($overdue_topics as $topic) {
            try {
                error_log("Processing auto-refund for topic {$topic->id}: {$topic->title}");
                error_log("Deadline was: " . $topic->content_deadline);
                error_log("Current time: " . date('Y-m-d H:i:s'));
                
                $this->db->beginTransaction();
                
                // Process 90% refunds for all contributions
                $refund_result = $this->refundManager->refundAllTopicContributions90Percent(
                    $topic->id, 
                    'Creator failed to deliver content within 48 hours - 90% refund (10% platform fee retained)'
                );
                
                // Update topic status
                $this->db->query("UPDATE topics SET status = 'failed', failed_at = NOW() WHERE id = :id");
                $this->db->bind(':id', $topic->id);
                $this->db->execute();
                
                // Keep platform fee as revenue since topic failed
                if ($this->feeManager) {
                    try {
                        $this->db->query("
                            UPDATE platform_fees 
                            SET status = 'retained_failed_delivery', processed_at = NOW()
                            WHERE topic_id = :id
                        ");
                        $this->db->bind(':id', $topic->id);
                        $this->db->execute();
                        
                        // Mark creator payout as failed
                        $this->db->query("UPDATE creator_payouts SET status = 'failed' WHERE topic_id = :id");
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
                
                error_log("✅ Auto-refund completed for topic {$topic->id}");
                
                $results[] = [
                    'topic_id' => $topic->id,
                    'topic_title' => $topic->title,
                    'refunds_processed' => $refund_result['refunds_processed'],
                    'total_refunded' => $refund_result['total_refunded'],
                    'platform_revenue_retained' => $refund_result['total_platform_revenue']
                ];
                
            } catch (Exception $e) {
                $this->db->cancelTransaction();
                error_log("❌ Auto-refund failed for topic " . $topic->id . ": " . $e->getMessage());
            }
        }
        
        error_log("========================================");
        error_log("AUTO-REFUND PROCESSING COMPLETE");
        error_log("Processed " . count($results) . " refunds");
        error_log("========================================");
        
        return $results;
    }
    
    /**
     * Send failure notification to creator
     */
    private function sendCreatorFailureNotification($topic, $refund_result) {
        $this->db->query("
            SELECT u.email as user_email
            FROM creators c
            LEFT JOIN users u ON c.applicant_user_id = u.id
            WHERE c.id = :creator_id
        ");
        $this->db->bind(':creator_id', $topic->creator_id);
        $creator = $this->db->single();
        
        $creator_email = $creator->user_email;
        
        if ($creator_email) {
            $subject = "Content Deadline Missed - " . $topic->title;
            $message = "Hello " . $topic->creator_name . ",

The content deadline for your funded topic has been missed.

TOPIC DETAILS:
Title: " . $topic->title . "
Deadline: " . date('F j, Y \a\t g:i A', strtotime($topic->content_deadline)) . "

ACTIONS TAKEN:
All contributors have been automatically refunded. No creator payout will be processed for this topic.

— TopicLaunch";
            
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
                $message = "A refund has been processed for the following topic.

TOPIC DETAILS:
Title: " . $topic->title . "
Creator: " . $topic->creator_name . "

REASON:
The creator did not deliver the content within the 48-hour deadline.

PROCESSING:
Your refund will appear in your original payment method within 5-10 business days.

— TopicLaunch";
                
                $this->sendEmail($detail['user_email'], $subject, $message);
            }
        }
    }
    
    /**
     * Send email notification with improved error handling
     */
    private function sendEmail($to, $subject, $body) {
        // Validate
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            error_log("Email invalid address: $to");
            return false;
        }
        $subject = trim($subject);
        $body    = trim($body);
        if (empty($subject) || empty($body)) {
            error_log("Email subject or body is empty");
            return false;
        }

        $api_key = getenv('RESEND_API_KEY');
        if (empty($api_key)) {
            error_log("RESEND_API_KEY not set — email skipped");
            return false;
        }

        // Build a properly structured HTML email (better deliverability than raw <pre>)
        $safe_body = htmlspecialchars($body, ENT_QUOTES, 'UTF-8');
        $html_body = nl2br($safe_body);
        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>' . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . '</title></head>'
              . '<body style="margin:0;padding:0;background:#FAF8F6;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Arial,sans-serif;color:#222;">'
              . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#FAF8F6;padding:32px 16px;">'
              . '<tr><td align="center">'
              . '<table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;width:100%;background:#FFFFFF;border-radius:12px;padding:32px;">'
              . '<tr><td style="font-size:15px;line-height:1.6;color:#222;">' . $html_body . '</td></tr>'
              . '</table>'
              . '<p style="font-size:12px;color:#888;margin-top:24px;">TopicLaunch · <a href="https://topiclaunch.com" style="color:#888;text-decoration:underline;">topiclaunch.com</a></p>'
              . '</td></tr></table></body></html>';

        $from_domain = getenv('EMAIL_FROM_ADDRESS') ?: 'onboarding@resend.dev';
        $payload = json_encode([
            'from'     => 'TopicLaunch <' . $from_domain . '>',
            'reply_to' => 'support@topiclaunch.com',
            'to'       => [$to],
            'subject'  => $subject,
            'text'     => $body,
            'html'     => $html,
            'headers'  => [
                'List-Unsubscribe' => '<mailto:unsubscribe@topiclaunch.com>',
            ],
        ]);

        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $api_key,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 10,
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err  = curl_error($ch);
        curl_close($ch);

        if ($curl_err) {
            error_log("Resend curl error: $curl_err");
            return false;
        }

        $data = json_decode($response, true);

        if ($http_code === 200 || $http_code === 201) {
            error_log("Email sent via Resend to $to (id: " . ($data['id'] ?? 'n/a') . ")");
            return true;
        }

        error_log("Resend error $http_code: $response");
        return false;
    }
    
    /**
     * Log notification for audit trail
     */
    private function logNotification($user_id, $type, $category, $message, $topic_id = null) {
        try {
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
                $this->db->query("
                    INSERT INTO notifications (user_id, type, category, message, topic_id, created_at)
                    VALUES (:user_id, :type, :category, :message, :topic_id, NOW())
                ");
                $this->db->bind(':category', $category);
            } else {
                $this->db->query("
                    INSERT INTO notifications (user_id, type, message, topic_id, created_at)
                    VALUES (:user_id, :type, :message, :topic_id, NOW())
                ");
            }
            
            $this->db->bind(':user_id', $user_id);
            $this->db->bind(':type', $type);
            $this->db->bind(':message', $message);
            $this->db->bind(':topic_id', $topic_id);
            $this->db->execute();
            
        } catch (Exception $e) {
            error_log("Failed to log notification: " . $e->getMessage());
        }
    }
    
    /**
     * Create required database tables
     */
    private function createNotificationTables() {
        try {
            $this->db->query("
                CREATE TABLE IF NOT EXISTS notifications (
                    id SERIAL PRIMARY KEY,
                    user_id INT,
                    type VARCHAR(50) NOT NULL,
                    category VARCHAR(50) NOT NULL DEFAULT 'general',
                    message TEXT NOT NULL,
                    topic_id INT,
                    is_read SMALLINT DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");
            $this->db->execute();
            
        } catch (Exception $e) {
            error_log("Failed to create/update notifications table: " . $e->getMessage());
        }
        
        try {
            $this->db->query("
                CREATE TABLE IF NOT EXISTS auto_refund_schedule (
                    id SERIAL PRIMARY KEY,
                    topic_id INT NOT NULL UNIQUE,
                    deadline TIMESTAMP NOT NULL,
                    status VARCHAR(50) DEFAULT 'scheduled',
                    processed_at TIMESTAMP NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");
            $this->db->execute();
        } catch (Exception $e) {
            error_log("Failed to create auto_refund_schedule table: " . $e->getMessage());
        }
    }

    /**
     * Send notifications to contributors when creator declines a topic (full refund issued)
     */
    public function sendDeclineEmails($topic_id) {
        try {
            $this->db->query("
                SELECT t.*, c.display_name as creator_name,
                       COALESCE(u.email, t.initiator_email) as fan_email
                FROM topics t
                JOIN creators c ON t.creator_id = c.id
                LEFT JOIN users u ON t.initiator_user_id = u.id
                WHERE t.id = :id
            ");
            $this->db->bind(':id', $topic_id);
            $topic = $this->db->single();
            if (!$topic) return;

            error_log("sendDeclineEmails: topic $topic_id, fan_email=" . ($topic->fan_email ?? 'NULL'));

            $emailed = [];

            // Email the topic initiator
            if ($topic->fan_email) {
                $this->sendEmail($topic->fan_email,
                    "Topic Declined — " . $topic->title,
                    $topic->creator_name . " has declined your topic.\n\n"
                    . "TOPIC DETAILS:\n"
                    . "Title: " . $topic->title . "\n"
                    . "Creator: " . $topic->creator_name . "\n\n"
                    . "REFUND:\n"
                    . "A refund has been issued to your original payment method and will appear within 5–10 business days.\n\n"
                    . "— TopicLaunch"
                );
                $emailed[] = $topic->fan_email;
            }

            // Email any other contributors with accounts
            $this->db->query("
                SELECT DISTINCT u.email
                FROM contributions c
                JOIN users u ON c.user_id = u.id
                WHERE c.topic_id = :id AND c.payment_status IN ('completed', 'refunded') AND u.email IS NOT NULL
            ");
            $this->db->bind(':id', $topic_id);
            $contributors = $this->db->resultSet();

            foreach ($contributors as $fan) {
                if (!$fan->email || in_array($fan->email, $emailed)) continue;
                $this->sendEmail($fan->email,
                    "Topic Declined — " . $topic->title,
                    $topic->creator_name . " has declined the following topic.\n\n"
                    . "TOPIC DETAILS:\n"
                    . "Title: " . $topic->title . "\n"
                    . "Creator: " . $topic->creator_name . "\n\n"
                    . "REFUND:\n"
                    . "A refund has been issued to your original payment method and will appear within 5–10 business days.\n\n"
                    . "— TopicLaunch"
                );
                $emailed[] = $fan->email;
            }

            error_log("sendDeclineEmails: notified " . count($emailed) . " recipients for topic $topic_id");
        } catch (Exception $e) {
            error_log("sendDeclineEmails error: " . $e->getMessage());
        }
    }

    /**
     * Send notifications to contributors when creator puts a topic on hold
     */
    public function sendHoldEmails($topic_id) {
        try {
            $this->db->query("
                SELECT t.*, c.display_name as creator_name,
                       COALESCE(u.email, t.initiator_email) as fan_email
                FROM topics t
                JOIN creators c ON t.creator_id = c.id
                LEFT JOIN users u ON t.initiator_user_id = u.id
                WHERE t.id = :id
            ");
            $this->db->bind(':id', $topic_id);
            $topic = $this->db->single();
            if (!$topic) return;

            error_log("sendHoldEmails: topic $topic_id, fan_email=" . ($topic->fan_email ?? 'NULL'));

            $emailed = [];

            // Email the topic initiator
            if ($topic->fan_email) {
                $this->sendEmail($topic->fan_email,
                    "Topic Paused — " . $topic->title,
                    $topic->creator_name . " has temporarily put your topic on hold.\n\n"
                    . "TOPIC DETAILS:\n"
                    . "Title: " . $topic->title . "\n"
                    . "Creator: " . $topic->creator_name . "\n\n"
                    . "STATUS:\n"
                    . "The creator will resume it when they're ready. You'll be notified when the content is delivered.\n\n"
                    . "— TopicLaunch"
                );
                $emailed[] = $topic->fan_email;
            }

            // Email any other contributors with accounts
            $this->db->query("
                SELECT DISTINCT u.email
                FROM contributions c
                JOIN users u ON c.user_id = u.id
                WHERE c.topic_id = :id AND c.payment_status = 'completed' AND u.email IS NOT NULL
            ");
            $this->db->bind(':id', $topic_id);
            $contributors = $this->db->resultSet();

            foreach ($contributors as $fan) {
                if (!$fan->email || in_array($fan->email, $emailed)) continue;
                $this->sendEmail($fan->email,
                    "Topic Paused — " . $topic->title,
                    $topic->creator_name . " has temporarily put the following topic on hold.\n\n"
                    . "TOPIC DETAILS:\n"
                    . "Title: " . $topic->title . "\n"
                    . "Creator: " . $topic->creator_name . "\n\n"
                    . "STATUS:\n"
                    . "The creator will resume it when they're ready. You'll be notified when the content is delivered.\n\n"
                    . "— TopicLaunch"
                );
                $emailed[] = $fan->email;
            }

            error_log("sendHoldEmails: notified " . count($emailed) . " recipients for topic $topic_id");
        } catch (Exception $e) {
            error_log("sendHoldEmails error: " . $e->getMessage());
        }
    }

    /**
     * Notify initiator + contributors when a held topic is resumed
     */
    public function sendResumeEmails($topic_id) {
        try {
            $this->db->query("
                SELECT t.*, c.display_name as creator_name,
                       COALESCE(u.email, t.initiator_email) as fan_email
                FROM topics t
                JOIN creators c ON t.creator_id = c.id
                LEFT JOIN users u ON t.initiator_user_id = u.id
                WHERE t.id = :id
            ");
            $this->db->bind(':id', $topic_id);
            $topic = $this->db->single();
            if (!$topic) return;

            error_log("sendResumeEmails: topic $topic_id, fan_email=" . ($topic->fan_email ?? 'NULL'));

            $emailed = [];

            if ($topic->fan_email) {
                $this->sendEmail($topic->fan_email,
                    "Topic Resumed — " . $topic->title,
                    $topic->creator_name . " has resumed your topic.\n\n"
                    . "TOPIC DETAILS:\n"
                    . "Title: " . $topic->title . "\n"
                    . "Creator: " . $topic->creator_name . "\n\n"
                    . "STATUS:\n"
                    . "The topic is back and the creator will be working on it. You'll be notified when the content is delivered.\n\n"
                    . "— TopicLaunch"
                );
                $emailed[] = $topic->fan_email;
            }

            $this->db->query("
                SELECT DISTINCT u.email
                FROM contributions c
                JOIN users u ON c.user_id = u.id
                WHERE c.topic_id = :id AND c.payment_status = 'completed' AND u.email IS NOT NULL
            ");
            $this->db->bind(':id', $topic_id);
            $contributors = $this->db->resultSet();

            foreach ($contributors as $fan) {
                if (!$fan->email || in_array($fan->email, $emailed)) continue;
                $this->sendEmail($fan->email,
                    "Topic Resumed — " . $topic->title,
                    $topic->creator_name . " has resumed the following topic.\n\n"
                    . "TOPIC DETAILS:\n"
                    . "Title: " . $topic->title . "\n"
                    . "Creator: " . $topic->creator_name . "\n\n"
                    . "STATUS:\n"
                    . "The topic is back and the creator will be working on it. You'll be notified when the content is delivered.\n\n"
                    . "— TopicLaunch"
                );
                $emailed[] = $fan->email;
            }

            error_log("sendResumeEmails: notified " . count($emailed) . " recipients for topic $topic_id");
        } catch (Exception $e) {
            error_log("sendResumeEmails error: " . $e->getMessage());
        }
    }

    /**
     * Notify initiator + contributors when a topic moves from queue to active (started)
     */
    public function sendStartEmails($topic_id) {
        try {
            $this->db->query("
                SELECT t.*, c.display_name as creator_name,
                       COALESCE(u.email, t.initiator_email) as fan_email
                FROM topics t
                JOIN creators c ON t.creator_id = c.id
                LEFT JOIN users u ON t.initiator_user_id = u.id
                WHERE t.id = :id
            ");
            $this->db->bind(':id', $topic_id);
            $topic = $this->db->single();
            if (!$topic) return;

            error_log("sendStartEmails: topic $topic_id, fan_email=" . ($topic->fan_email ?? 'NULL'));

            $emailed = [];

            if ($topic->fan_email) {
                $this->sendEmail($topic->fan_email,
                    "Content In Progress — " . $topic->title,
                    $topic->creator_name . " has started working on your topic.\n\n"
                    . "TOPIC DETAILS:\n"
                    . "Title: " . $topic->title . "\n"
                    . "Creator: " . $topic->creator_name . "\n\n"
                    . "NEXT STEPS:\n"
                    . "The creator has 48 hours to deliver the content. You'll receive a notification as soon as it's ready.\n\n"
                    . "— TopicLaunch"
                );
                $emailed[] = $topic->fan_email;
            }

            $this->db->query("
                SELECT DISTINCT u.email
                FROM contributions c
                JOIN users u ON c.user_id = u.id
                WHERE c.topic_id = :id AND c.payment_status = 'completed' AND u.email IS NOT NULL
            ");
            $this->db->bind(':id', $topic_id);
            $contributors = $this->db->resultSet();

            foreach ($contributors as $fan) {
                if (!$fan->email || in_array($fan->email, $emailed)) continue;
                $this->sendEmail($fan->email,
                    "Content In Progress — " . $topic->title,
                    $topic->creator_name . " has started working on the following topic.\n\n"
                    . "TOPIC DETAILS:\n"
                    . "Title: " . $topic->title . "\n"
                    . "Creator: " . $topic->creator_name . "\n\n"
                    . "NEXT STEPS:\n"
                    . "The creator has 48 hours to deliver the content. You'll receive a notification as soon as it's ready.\n\n"
                    . "— TopicLaunch"
                );
                $emailed[] = $fan->email;
            }

            error_log("sendStartEmails: notified " . count($emailed) . " recipients for topic $topic_id");
        } catch (Exception $e) {
            error_log("sendStartEmails error: " . $e->getMessage());
        }
    }
}
?>
