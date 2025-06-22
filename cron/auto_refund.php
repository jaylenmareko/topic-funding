<?php
// cron/auto_refund.php - Cron job to process automatic refunds
// Run this every 15 minutes via cron: */15 * * * * /usr/bin/php /path/to/your/site/cron/auto_refund.php

set_time_limit(300); // 5 minutes max execution
ini_set('memory_limit', '128M');

// Include required files
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/notification_system.php';

// Log cron execution
error_log("Auto-refund cron job started at " . date('Y-m-d H:i:s'));

try {
    $notificationSystem = new NotificationSystem();
    
    // Process auto-refunds for overdue topics
    $results = $notificationSystem->processAutoRefunds();
    
    if (!empty($results)) {
        $total_topics = count($results);
        $total_refunds = array_sum(array_column($results, 'refunds_processed'));
        $total_amount = array_sum(array_column($results, 'total_refunded'));
        
        error_log("Auto-refund processed: {$total_topics} topics, {$total_refunds} refunds, $" . number_format($total_amount, 2) . " total");
        
        // Send admin summary notification
        $admin_message = "Auto-refund Summary:\n\n";
        foreach ($results as $result) {
            $admin_message .= "• Topic: {$result['topic_title']}\n";
            $admin_message .= "  Refunds: {$result['refunds_processed']}\n";
            $admin_message .= "  Amount: $" . number_format($result['total_refunded'], 2) . "\n\n";
        }
        
        // Send to admin email
        mail('admin@topiclaunch.com', 'Auto-Refund Report - TopicLaunch', $admin_message, 'From: system@topiclaunch.com');
        
    } else {
        error_log("Auto-refund cron: No overdue topics found");
    }
    
} catch (Exception $e) {
    error_log("Auto-refund cron error: " . $e->getMessage());
    
    // Alert admin of cron failure
    mail('admin@topiclaunch.com', 'Auto-Refund CRON FAILED - TopicLaunch', 
         "Auto-refund cron job failed:\n\n" . $e->getMessage() . "\n\nTime: " . date('Y-m-d H:i:s'),
         'From: system@topiclaunch.com');
}

error_log("Auto-refund cron job completed at " . date('Y-m-d H:i:s'));
?>
