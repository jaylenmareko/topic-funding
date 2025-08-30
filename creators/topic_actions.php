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
                    if (!in_array($topic->status, ['funded', 'on_hold'])) {
                        throw new Exception("Can only decline funded or held topics.");
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
                    if ($topic->status !== 'funded') {
                        throw new Exception("Can only put funded topics on hold.");
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
                    
                    $message = "Topic put on hold. The 48-hour deadline is paused.";
                    break;
                    
                case 'resume':
                    if ($topic->status !== 'on_hold') {
                        throw new Exception("Can only resume topics that are on hold.");
                    }
                    
                    // Resume topic - set new 48-hour deadline from now
                    $db->query('
                        UPDATE topics 
                        SET status = "funded",
                            content_deadline = DATE_ADD(NOW(), INTERVAL 48 HOUR),
                            hold_reason = NULL,
                            held_at = NULL
                        WHERE id = :topic_id
                    ');
                    $db->bind(':topic_id', $topic_id);
                    $db->execute();
                    
                    $message = "Topic resumed! You have 48 hours to create the content.";
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
