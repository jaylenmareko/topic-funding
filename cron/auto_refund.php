<?php
// cron/auto_refund.php - Updated for cleaned database
// Run this every 15 minutes via cron: */15 * * * * /usr/local/bin/php /home4/uunppite/public_html/cron/auto_refund.php

set_time_limit(300); // 5 minutes max execution
ini_set('memory_limit', '128M');

// Include required files
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/stripe.php';
require_once __DIR__ . '/../config/refund_helper.php';

// Log cron execution
error_log("TopicLaunch Auto-refund cron job started at " . date('Y-m-d H:i:s'));

try {
    $db = new Database();
    $refundManager = new RefundManager();
    
    // Find topics that are past their 48-hour deadline without content
    $db->query('
        SELECT t.*, c.display_name as creator_name
        FROM topics t
        JOIN creators c ON t.creator_id = c.id
        WHERE t.status = "funded" 
        AND t.content_deadline < NOW()
        AND (t.content_url IS NULL OR t.content_url = "")
        AND t.id NOT IN (
            SELECT topic_id FROM auto_refund_processed 
            WHERE processed_at > DATE_SUB(NOW(), INTERVAL 1 DAY)
        )
    ');
    $overdue_topics = $db->resultSet();
    
    $results = [];
    $total_refunds = 0;
    $total_amount = 0;
    
    if (!empty($overdue_topics)) {
        foreach ($overdue_topics as $topic) {
            try {
                error_log("Processing auto-refund for topic ID: {$topic->id} - {$topic->title}");
                
                $db->beginTransaction();
                
                // Get all contributions for this topic
                $db->query('
                    SELECT c.*, u.email 
                    FROM contributions c 
                    JOIN users u ON c.user_id = u.id 
                    WHERE c.topic_id = :topic_id AND c.payment_status = "completed"
                ');
                $db->bind(':topic_id', $topic->id);
                $contributions = $db->resultSet();
                
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
                                'reason' => 'Creator failed to deliver content within 48 hours - 90% refund',
                                'contribution_id' => $contribution->id,
                                'topic_id' => $topic->id,
                                'original_amount' => $original_amount,
                                'refund_amount' => $refund_amount,
                                'platform_fee_kept' => $platform_fee_kept,
                                'auto_refund' => 'true'
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
                
                // Update topic status to failed
                $db->query('UPDATE topics SET status = "failed" WHERE id = :id');
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
                
                // UPDATED: Record processing in auto_refund_processed table (you kept this one)
                $db->query('
                    INSERT INTO auto_refund_processed (topic_id, refunds_count, total_refunded, processed_at)
                    VALUES (:topic_id, :refunds_count, :total_refunded, NOW())
                    ON DUPLICATE KEY UPDATE 
                    refunds_count = :refunds_count, 
                    total_refunded = :total_refunded, 
                    processed_at = NOW()
                ');
                $db->bind(':topic_id', $topic->id);
                $db->bind(':refunds_count', $topic_refunds);
                $db->bind(':total_refunded', $topic_amount);
                $db->execute();
                
                // Send notifications to contributors
                sendContributor90PercentRefundNotifications($topic, $refund_details);
                
                // Send failure notification to creator
                sendCreatorFailureNotification($topic, $topic_refunds, $topic_amount);
                
                $db->endTransaction();
                
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
                $db->cancelTransaction();
                error_log("Auto-refund failed for topic {$topic->id}: " . $e->getMessage());
            }
        }
        
        // Send admin summary notification
        if (!empty($results)) {
            sendAdminSummaryNotification($results, $total_refunds, $total_amount);
        }
        
        error_log("Auto-refund completed: {$total_refunds} refunds, $" . number_format($total_amount, 2) . " total");
        
    } else {
        error_log("Auto-refund cron: No overdue topics found");
    }
    
} catch (Exception $e) {
    error_log("Auto-refund cron error: " . $e->getMessage());
    
    // Alert admin of cron failure
    $error_message = "Auto-refund cron job failed:\n\n" . $e->getMessage() . "\n\nTime: " . date('Y-m-d H:i:s');
    sendAdminErrorNotification($error_message);
}

error_log("TopicLaunch Auto-refund cron job completed at " . date('Y-m-d H:i:s'));

// Helper functions for notifications
function sendContributor90PercentRefundNotifications($topic, $refund_details) {
    foreach ($refund_details as $detail) {
        if ($detail['success']) {
            $subject = "💰 90% Refund Processed - " . $topic->title;
            $message = "
Hi,

A 90% refund has been automatically processed for your contribution.

📺 Topic: " . $topic->title . "
👥 Creator: " . $topic->creator_name . "
💰 Original Contribution: $" . number_format($detail['original_amount'], 2) . "
💰 Refund Amount: $" . number_format($detail['refund_amount'], 2) . " (90%)
💰 Platform Fee Retained: $" . number_format($detail['platform_fee_kept'], 2) . " (10%)

🔄 Reason for Refund:
The creator did not deliver the requested content within the 48-hour deadline.

💳 Refund Details:
• Refund will appear in your original payment method within 5-10 business days
• Platform fee retained to cover processing costs and delivery guarantee services
• No action required from you

We apologize for this inconvenience. Our delivery guarantee system ensures accountability.

Thank you for using TopicLaunch!

Best regards,
TopicLaunch Team
            ";
            
            sendEmail($detail['user_email'], $subject, $message);
        }
    }
}

function sendCreatorFailureNotification($topic, $refunds_count, $total_refunded) {
    // Get creator email
    $db = new Database();
    $db->query('
        SELECT c.email, u.email as user_email
        FROM creators c
        LEFT JOIN users u ON c.applicant_user_id = u.id
        WHERE c.id = :creator_id
    ');
    $db->bind(':creator_id', $topic->creator_id);
    $creator = $db->single();
    
    $creator_email = $creator->user_email ?: $creator->email;
    
    if ($creator_email) {
        $subject = "⚠️ Topic Failed - Content Deadline Missed";
        $message = "
Hi " . $topic->creator_name . ",

Unfortunately, your topic '" . $topic->title . "' has been marked as FAILED because content was not delivered within the 48-hour deadline.

📅 Deadline was: " . date('M j, Y g:i A', strtotime($topic->content_deadline)) . "

⚠️ Actions Taken:
• All contributors have been automatically refunded 90% of their contributions
• " . $refunds_count . " refunds processed
• Total refunded to users: $" . number_format($total_refunded, 2) . "
• Topic status changed to 'Failed'
• No creator payout will be processed

This affects your creator performance. Please ensure you can meet deadlines before accepting funded topics.

Best regards,
TopicLaunch Team
        ";
        
        sendEmail($creator_email, $subject, $message);
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
    sendEmail('admin@topiclaunch.com', 'Auto-Refund CRON FAILED - TopicLaunch', $error_message);
}

function sendEmail($to, $subject, $message) {
    // For localhost testing - just log emails
    if ($_SERVER['HTTP_HOST'] === 'localhost' || strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false) {
        error_log("EMAIL TO: $to | SUBJECT: $subject");
        return true;
    }
    
    // Send real email on production
    $headers = "From: noreply@topiclaunch.com\r\n";
    $headers .= "Reply-To: support@topiclaunch.com\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    
    return mail($to, $subject, $message, $headers);
}

// UPDATED: Create required tables for cleaned database
function createRequiredTables() {
    $db = new Database();
    
    // Make sure auto_refund_processed table exists (you kept this one)
    $db->query("
        CREATE TABLE IF NOT EXISTS auto_refund_processed (
            id INT AUTO_INCREMENT PRIMARY KEY,
            topic_id INT NOT NULL,
            refunds_count INT NOT NULL,
            total_refunded DECIMAL(10,2) NOT NULL,
            processed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_topic (topic_id),
            INDEX idx_processed_at (processed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $db->execute();
    
    // Make sure refund_log table exists (you kept this one)
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
            INDEX idx_processed_at (processed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $db->execute();
}

// Initialize tables
createRequiredTables();
?>
