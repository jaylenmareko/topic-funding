<?php
// creators/topic_actions.php - Handle creator topic management actions
session_start();
require_once '../config/database.php';
require_once '../config/refund_helper.php';

// Check if user is logged in and is a creator
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$db = new Database();

// Get creator info
$db->query('SELECT * FROM creators WHERE applicant_user_id = :user_id AND is_active = 1');
$db->bind(':user_id', $_SESSION['user_id']);
$creator = $db->single();

if (!$creator) {
    header('Location: ../creators/dashboard.php');
    exit;
}

$message = '';
$error = '';

if ($_POST && isset($_POST['action']) && isset($_POST['topic_id'])) {
    $action = $_POST['action'];
    $topic_id = (int)$_POST['topic_id'];
    
    // Verify topic belongs to this creator
    $db->query('SELECT * FROM topics WHERE id = :topic_id AND creator_id = :creator_id');
    $db->bind(':topic_id', $topic_id);
    $db->bind(':creator_id', $creator->id);
    $topic = $db->single();
    
    if (!$topic) {
        $error = "Topic not found or doesn't belong to you.";
    } else {
        try {
            $db->beginTransaction();
            
            switch ($action) {
                case 'decline':
                    // UPDATED: Allow decline for active, funded, or on_hold topics
                    if (!in_array($topic->status, ['active', 'funded', 'on_hold'])) {
                        throw new Exception("Can only decline active, funded, or held topics.");
                    }
                    
                    // Process full refunds for all contributors
                    $refundManager = new RefundManager();
                    $refund_result = $refundManager->refundAllTopicContributions($topic_id, 
                        'Creator declined this topic - full refund processed');
                    
                    if (!$refund_result['success']) {
                        throw new Exception("Failed to process refunds: " . $refund_result['error']);
                    }
                    
                    // Update topic status
                    $db->query('UPDATE topics SET status = "cancelled" WHERE id = :topic_id');
                    $db->bind(':topic_id', $topic_id);
                    $db->execute();
                    
                    $message = "Topic declined and all contributors have been refunded.";
                    break;
                    
                case 'hold':
                    // UPDATED: Allow hold for active or funded topics
                    if (!in_array($topic->status, ['active', 'funded'])) {
                        throw new Exception("Can only put active or funded topics on hold.");
                    }
                    
                    $hold_reason = trim($_POST['hold_reason'] ?? 'Working on other content first');
                    
                    // Update topic status to on_hold
                    $db->query('
                        UPDATE topics 
                        SET status = "on_hold", 
                            hold_reason = :hold_reason,
                            held_at = NOW()
                        WHERE id = :topic_id
                    ');
                    $db->bind(':topic_id', $topic_id);
                    $db->bind(':hold_reason', $hold_reason);
                    $db->execute();
                    
                    if ($topic->status === 'funded') {
                        $message = "Topic put on hold. The 48-hour deadline is paused.";
                    } else {
                        $message = "Topic put on hold. Fans can still contribute but you'll review it later.";
                    }
                    break;
                    
                case 'resume':
                    if ($topic->status !== 'on_hold') {
                        throw new Exception("Can only resume topics that are on hold.");
                    }
                    
                    // Determine what status to resume to based on funding
                    $new_status = ($topic->current_funding >= $topic->funding_threshold) ? 'funded' : 'active';
                    
                    if ($new_status === 'funded') {
                        // Resume as funded - set new 48-hour deadline from now
                        $db->query('
                            UPDATE topics 
                            SET status = "funded",
                                content_deadline = DATE_ADD(NOW(), INTERVAL 48 HOUR),
                                hold_reason = NULL,
                                held_at = NULL,
                                funded_at = NOW()
                            WHERE id = :topic_id
                        ');
                        $message = "Topic resumed! You have 48 hours to create the content.";
                    } else {
                        // Resume as active - continue collecting funding
                        $db->query('
                            UPDATE topics 
                            SET status = "active",
                                hold_reason = NULL,
                                held_at = NULL
                            WHERE id = :topic_id
                        ');
                        $message = "Topic resumed! Fans can continue contributing to reach the funding goal.";
                    }
                    $db->bind(':topic_id', $topic_id);
                    $db->execute();
                    break;
                    
                default:
                    throw new Exception("Invalid action.");
            }
            
            $db->endTransaction();
            
        } catch (Exception $e) {
            $db->cancelTransaction();
            $error = $e->getMessage();
            error_log("Topic action error: " . $e->getMessage());
        }
    }
}

// Redirect back to dashboard with message
if ($message) {
    header('Location: dashboard.php?success=' . urlencode($message));
} elseif ($error) {
    header('Location: dashboard.php?error=' . urlencode($error));
} else {
    header('Location: dashboard.php');
}
exit;
?>
