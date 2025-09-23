<?php
// cron/auto_refund.php - FIXED VERSION - Prevents duplicates and email spam
// Run this every 15 minutes via cron: */15 * * * * /usr/local/bin/php /home4/uunppite/public_html/cron/auto_refund.php

set_time_limit(300); // 5 minutes max execution
ini_set('memory_limit', '128M');

// Include required files
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/stripe.php';
require_once __DIR__ . '/../config/refund_helper.php';

// Log cron execution with PID to prevent overlap
$pid = getmypid();
error_log("TopicLaunch Auto-refund cron job started at " . date('Y-m-d H:i:s') . " (PID: $pid)");

try {
    $db = new Database();
    $refundManager = new RefundManager();
    
    // FIXED: Better query to prevent duplicate processing
    // Only process topics that are:
    // 1. Status = "funded" (not already failed)
    // 2. Past deadline
    // 3. No content uploaded
    // 4. Not already processed (check auto_refund_processed table)
    $db->query('
        SELECT t.*, c.display_name as creator_name, c.email as creator_email, 
               u.email as creator_user_email
        FROM topics t
        JOIN creators c ON t.creator_id = c.id
        LEFT JOIN users u ON c.applicant_user_id = u.id
        WHERE t.status = "funded" 
        AND t.content_deadline < NOW()
        AND (t.content_url IS NULL OR t.content_url = "")
        AND t.id NOT IN (
            SELECT topic_id FROM auto_refund_processed 
            WHERE processed_at > DATE_SUB(NOW(), INTERVAL 2 HOUR)
        )
        ORDER BY t.content_deadline ASC
        LIMIT 10
    ');
    $overdue_topics = $db->resultSet();
    
    if (empty($overdue_topics)) {
        error_log("Auto-refund cron: No overdue topics found requiring processing");
        return;
    }
    
    error_log("Found " . count($overdue_topics) . " overdue topics to process");
    
    $results = [];
    $total_refunds = 0;
    $total_amount = 0;
    
    foreach ($overdue_topics as $topic) {
        try {
            error_log("Processing auto-refund for topic ID: {$topic->id} - {$topic->title}");
            
            // FIXED: Use transaction and better duplicate prevention
            $db->beginTransaction();
            
            // Double-check this topic hasn't been processed in another cron run
            $db->query('
                SELECT id FROM auto_refund_processed 
                WHERE topic_id = :topic_id AND processed_at > DATE_SUB(NOW(), INTERVAL 2 HOUR)
            ');
            $db->bind(':topic_id', $topic->id);
            if ($db->single()) {
                error_log("Topic {$topic->id} already processed recently, skipping");
                $db->cancelTransaction();
                continue;
            }
            
            // FIXED: Immediately mark as processing to prevent duplicates
            $db->query('
                INSERT INTO auto_refund_processed (topic_id, refunds_count, total_refunded, processed_at, status)
                VALUES (:topic_id, 0, 0, NOW(), "processing")
                ON DUPLICATE KEY UPDATE status = "processing", processed_at = NOW()
            ');
            $db->bind(':topic_id', $topic->id);
            $db->execute();
            
            // Get all contributions for this topic
            $db->query('
                SELECT c.*, u.email 
                FROM contributions c 
                JOIN users u ON c.user_id = u.id 
                WHERE c.topic_id = :topic_id AND c.payment_status = "completed"
            ');
            $db->bind(':topic_id', $topic->id);
            $contributions = $db->resultSet();
            
            if (empty($contributions)) {
                error_log("No contributions found for topic {$topic->id}, marking as failed");
                
                // Update topic status to failed
                $db->query('UPDATE topics SET status = "failed", failed_at = NOW() WHERE id = :id');
                $db->bind(':id', $topic->id);
                $db->execute();
                
                // Update processing record
                $db->query('
                    UPDATE auto_refund_processed 
                    SET status = "completed", refunds_count = 0, total_refunded = 0
                    WHERE topic_id = :topic_id
                ');
                $db->bind(':topic_id', $topic->id);
                $db->execute();
                
                $db->endTransaction();
                continue;
            }
            
            $topic_refunds = 0;
            $topic_amount = 0;
            $refund_details = [];
            
            // Process 90% refunds for each contribution
            foreach ($contributions as $contribution) {
                if (!$contribution->payment_id) {
                    error_log("Skipping contribution {$contribution->id} - no payment_id");
                    continue;
                }
                
                $original_amount = $contribution->amount;
                $refund_amount = $original_amount * 0.90; // 90% refund
                $platform_fee_kept = $original_amount * 0.10; // Keep 10%
                
                try {
                    // Process Stripe refund for 90%
                    $refund = \Stripe\Refund::create([
                        'payment_intent' => $contribution->payment_id,
                        'amount' => round($refund_amount * 100), // Convert to cents
                        'reason' => 'requested_by_customer',
                        'metadata' => [
                            'reason' => 'Creator failed to deliver content within 48 hours - 90% auto-refund',
                            'contribution_id' => $contribution->id,
                            'topic_id' => $topic->id,
                            'original_amount' => $original_amount,
                            'refund_amount' => $refund_amount,
                            'platform_fee_kept' => $platform_fee_kept,
                            'auto_refund' => 'true',
                            'cron_pid' => $pid
                        ]
                    ]);
                    
                    // Update contribution status
                    $db->query('UPDATE contributions SET payment_status = "refunded_90_percent" WHERE id = :id');
                    $db->bind(':id', $contribution->id);
                    $db->execute();
                    
                    // Log the refund
                    $db->query('
                        INSERT INTO refund_log (contribution_id, amount, original_amount, platform_fee_kept, reason, stripe_refund_id, processed_at)
                        VALUES (:contribution_id, :amount, :original_amount, :platform_fee_kept, :reason, :stripe_refund_id, NOW())
                    ');
                    $db->bind(':contribution_id', $contribution->id);
                    $db->bind(':amount', $refund_amount);
                    $db->bind(':original_amount', $original_amount);
                    $db->bind(':platform_fee_kept', $platform_fee_kept);
                    $db->bind(':reason', 'Creator failed to deliver content within 48 hours - 90% auto-refund');
                    $db->bind(':stripe_refund_id', $refund->id);
                    $db->execute();
                    
                    $topic_refunds++;
                    $topic_amount += $refund_amount;
                    
                    $refund_details[] = [
                        'user_email' => $contribution->email,
                        'original_amount' => $original_amount,
                        'refund_amount' => $refund_amount,
                        'platform_fee_kept' => $platform_fee_kept,
                        'success' => true
                    ];
                    
                    error_log("Refunded $refund_amount to {$contribution->email} (kept $platform_fee_kept fee)");
                    
                } catch (\Stripe\Exception\ApiErrorException $e) {
                    error_log("Stripe refund failed for contribution {$contribution->id}: " . $e->getMessage());
                    $refund_details[] = [
                        'user_email' => $contribution->email,
                        'original_amount' => $original_amount,
                        'refund_amount' => 0,
                        'platform_fee_kept' => 0,
                        'success' => false,
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            // FIXED: Update topic status to failed to prevent re-processing
            $db->query('UPDATE topics SET status = "failed", failed_at = NOW() WHERE id = :id');
            $db->bind(':id', $topic->id);
            $db->execute();
            
            // Keep platform fee as revenue since topic failed
            $db->query('
                UPDATE platform_fees 
                SET status = "retained_failed_delivery", processed_at = NOW()
                WHERE topic_id = :id
            ');
            $db->bind(':id', $topic->id);
            $db->execute();
            
            // Mark creator payout as failed
            $db->query('UPDATE creator_payouts SET status = "failed" WHERE topic_id = :id');
            $db->bind(':id', $topic->id);
            $db->execute();
            
            // FIXED: Update processing record with final counts
            $db->query('
                UPDATE auto_refund_processed 
                SET status = "completed", refunds_count = :refunds_count, total_refunded = :total_refunded
                WHERE topic_id = :topic_id
            ');
            $db->bind(':topic_id', $topic->id);
            $db->bind(':refunds_count', $topic_refunds);
            $db->bind(':total_refunded', $topic_amount);
            $db->execute();
            
            $db->endTransaction();
            
            // FIXED: Only send notifications ONCE after successful processing
            if ($topic_refunds > 0) {
                sendContributor90PercentRefundNotifications($topic, $refund_details);
                sendCreatorFailureNotification($topic, $topic_refunds, $topic_amount);
            }
            
            $results[] = [
                'topic_id' => $topic->id,
                'topic_title' => $topic->title,
                'creator_name' => $topic->creator_name,
                'refunds_processed' => $topic_refunds,
                'total_refunded' => $topic_amount
            ];
            
            $total_refunds += $topic_refunds;
            $total_amount += $topic_amount;
            
            error_log("Successfully processed {$topic_refunds} refunds totaling ${topic_amount} for topic: {$topic->title}");
            
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->cancelTransaction();
            }
            error_log("Auto-refund failed for topic {$topic->id}: " . $e->getMessage());
            
            // Mark as failed in processing table to prevent retry
            try {
                $db->query('
                    UPDATE auto_refund_processed 
                    SET status = "failed", processed_at = NOW()
                    WHERE topic_id = :topic_id
                ');
                $db->bind(':topic_id', $topic->id);
                $db->execute();
            } catch (Exception $e2) {
                error_log("Failed to update processing status: " . $e2->getMessage());
            }
        }
    }
    
    // FIXED: Only send admin summary if we actually processed something
    if (!empty($results) && $total_refunds > 0) {
        sendAdminSummaryNotification($results, $total_refunds, $total_amount);
        error_log("Auto-refund completed: {$total_refunds} refunds, $" . number_format($total_amount, 2) . " total");
    } else {
        error_log("Auto-refund completed: No refunds processed");
    }
    
} catch (Exception $e) {
    error_log("Auto-refund cron error: " . $e->getMessage());
    sendAdminErrorNotification($e->getMessage());
}

error_log("TopicLaunch Auto-refund cron job completed at " . date('Y-m-d H:i:s') . " (PID: $pid)");

// FIXED: Helper functions with better email handling
function sendContributor90PercentRefundNotifications($topic, $refund_details) {
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
            
            if (sendEmail($detail['user_email'], $subject, $message)) {
                $emails_sent++;
            }
        }
    }
    
    error_log("Sent {$emails_sent} contributor refund notifications");
}

function sendCreatorFailureNotification($topic, $refunds_count, $total_refunded) {
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
        
        if (sendEmail($creator_email, $subject, $message)) {
            error_log("Sent creator failure notification to: " . $creator_email);
        }
    }
}

function sendAdminSummaryNotification($results, $total_refunds, $total_amount) {
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
    
    sendEmail('admin@topiclaunch.com', 'Auto-Refund Report - TopicLaunch', $admin_message);
}

function sendAdminErrorNotification($error_message) {
    $message = "Auto-refund cron job failed:\n\n" . $error_message . "\n\nTime: " . date('Y-m-d H:i:s');
    sendEmail('admin@topiclaunch.com', 'Auto-Refund CRON FAILED - TopicLaunch', $message);
}

function sendEmail($to, $subject, $message) {
    // FIXED: Better email handling with validation
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log("Invalid email address: " . $to);
        return false;
    }
    
    // For localhost testing - just log emails
    if (strpos($_SERVER['HTTP_HOST'] ?? 'localhost', 'localhost') !== false || 
        strpos($_SERVER['SERVER_NAME'] ?? 'localhost', 'localhost') !== false) {
        error_log("📧 EMAIL TO: $to | SUBJECT: $subject");
        return true;
    }
    
    // Send real email on production with better headers
    $headers = array();
    $headers[] = 'From: TopicLaunch <noreply@topiclaunch.com>';
    $headers[] = 'Reply-To: support@topiclaunch.com';
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'X-Mailer: TopicLaunch Auto-Refund v1.0';
    
    $formatted_headers = implode("\r\n", $headers);
    
    try {
        $result = mail($to, $subject, $message, $formatted_headers);
        if ($result) {
            error_log("✅ Email sent successfully to: " . $to);
        } else {
            error_log("❌ Failed to send email to: " . $to);
        }
        return $result;
    } catch (Exception $e) {
        error_log("❌ Email sending exception: " . $e->getMessage());
        return false;
    }
}

// FIXED: Create required tables with better structure
function createRequiredTables() {
    $db = new Database();
    
    // Enhanced auto_refund_processed table with status tracking
    $db->query("
        CREATE TABLE IF NOT EXISTS auto_refund_processed (
            id INT AUTO_INCREMENT PRIMARY KEY,
            topic_id INT NOT NULL,
            refunds_count INT NOT NULL DEFAULT 0,
            total_refunded DECIMAL(10,2) NOT NULL DEFAULT 0,
            status ENUM('processing', 'completed', 'failed') DEFAULT 'processing',
            processed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_topic (topic_id),
            INDEX idx_processed_at (processed_at),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $db->execute();
    
    // Enhanced refund_log table
    $db->query("
        CREATE TABLE IF NOT EXISTS refund_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            contribution_id INT NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            original_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            platform_fee_kept DECIMAL(10,2) NOT NULL DEFAULT 0,
            reason TEXT,
            stripe_refund_id VARCHAR(255),
            admin_user_id INT,
            processed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_contribution (contribution_id),
            INDEX idx_processed_at (processed_at),
            INDEX idx_stripe_refund (stripe_refund_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $db->execute();
    
    // Add failed_at column to topics table if not exists
    $db->query("
        ALTER TABLE topics 
        ADD COLUMN IF NOT EXISTS failed_at TIMESTAMP NULL AFTER completed_at
    ");
    $db->execute();
}

// Initialize tables
createRequiredTables();
?>
