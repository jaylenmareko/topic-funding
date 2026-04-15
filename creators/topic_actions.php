<?php
// creators/topic_actions.php - Handle creator topic management actions
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/refund_helper.php';

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
                case 'start':
                    // Move a queued topic to funded and start the 48-hour clock
                    if ($topic->status !== 'queued') {
                        throw new Exception("Can only start topics that are in the queue.");
                    }
                    
                    // Block if a topic is already running
                    $db->query("SELECT COUNT(*) as cnt FROM topics WHERE creator_id = :creator_id AND status = 'funded'");
                    $db->bind(':creator_id', $creator->id);
                    $running = $db->single();
                    if ((int)$running->cnt > 0) {
                        throw new Exception("You already have a topic in progress. Finish or hold that one before starting another.");
                    }
                    
                    $db->query("
                        UPDATE topics 
                        SET status = 'funded',
                            content_deadline = NOW() + INTERVAL '48 hours',
                            funded_at = COALESCE(funded_at, NOW())
                        WHERE id = :topic_id
                    ");
                    $db->bind(':topic_id', $topic_id);
                    $db->execute();
                    
                    $message = "Topic started! You have 48 hours to create and upload the content.";
                    break;
                    
                case 'decline':
                    // Allow decline for active, queued, funded, or on_hold topics
                    if (!in_array($topic->status, ['active', 'queued', 'funded', 'on_hold'])) {
                        throw new Exception("Can only decline active, queued, funded, or held topics.");
                    }
                    
                    // Process full refunds for all contributors
                    $refundManager = new RefundManager();
                    $refund_result = $refundManager->refundAllTopicContributions($topic_id, 
                        'Creator declined this topic - full refund processed');
                    
                    if (!$refund_result['success']) {
                        throw new Exception("Failed to process refunds: " . $refund_result['error']);
                    }
                    
                    // Update topic status
                    $db->query("UPDATE topics SET status = 'cancelled' WHERE id = :topic_id");
                    $db->bind(':topic_id', $topic_id);
                    $db->execute();
                    
                    $message = "Topic declined and all contributors have been refunded.";
                    break;
                    
                case 'hold':
                    // Allow hold for active, queued, or funded topics
                    if (!in_array($topic->status, ['active', 'queued', 'funded'])) {
                        throw new Exception("Can only put active, queued, or funded topics on hold.");
                    }
                    
                    $hold_reason = trim($_POST['hold_reason'] ?? 'Working on other content first');
                    
                    // Update topic status to on_hold
                    $db->query("
                        UPDATE topics 
                        SET status = 'on_hold', 
                            hold_reason = :hold_reason,
                            held_at = NOW()
                        WHERE id = :topic_id
                    ");
                    $db->bind(':topic_id', $topic_id);
                    $db->bind(':hold_reason', $hold_reason);
                    $db->execute();
                    
                    if ($topic->status === 'funded') {
                        // Auto-start the next topic in the queue
                        $db->query("
                            SELECT id FROM topics 
                            WHERE creator_id = :creator_id AND status = 'queued'
                            ORDER BY funded_at ASC, id ASC
                            LIMIT 1
                        ");
                        $db->bind(':creator_id', $creator->id);
                        $next = $db->single();
                        
                        if ($next) {
                            $db->query("
                                UPDATE topics 
                                SET status = 'funded',
                                    content_deadline = NOW() + INTERVAL '48 hours'
                                WHERE id = :next_id
                            ");
                            $db->bind(':next_id', $next->id);
                            $db->execute();
                            $message = "Topic put on hold. The next topic in your queue has been started automatically.";
                        } else {
                            $message = "Topic put on hold. No topics in queue — your slot is open when you resume.";
                        }
                    } elseif ($topic->status === 'queued') {
                        $message = "Topic put on hold. It will return to your queue when you resume it.";
                    } else {
                        $message = "Topic put on hold. Fans can still contribute but you'll review it later.";
                    }
                    break;
                    
                case 'resume':
                    if ($topic->status !== 'on_hold') {
                        throw new Exception("Can only resume topics that are on hold.");
                    }
                    
                    if ($topic->current_funding < $topic->funding_threshold) {
                        // Not fully funded — resume as active
                        $db->query("
                            UPDATE topics 
                            SET status = 'active',
                                hold_reason = NULL,
                                held_at = NULL
                            WHERE id = :topic_id
                        ");
                        $db->bind(':topic_id', $topic_id);
                        $db->execute();
                        $message = "Topic resumed! Fans can continue contributing to reach the funding goal.";
                    } elseif ($topic->content_deadline !== null) {
                        // Was fully funded and started (had a deadline) — resume as funded with a fresh deadline
                        $db->query("
                            UPDATE topics 
                            SET status = 'funded',
                                content_deadline = NOW() + INTERVAL '48 hours',
                                hold_reason = NULL,
                                held_at = NULL
                            WHERE id = :topic_id
                        ");
                        $db->bind(':topic_id', $topic_id);
                        $db->execute();
                        $message = "Topic resumed! You have 48 hours to create the content.";
                    } else {
                        // Was fully funded but not yet started (queued) — return to queue
                        $db->query("
                            UPDATE topics 
                            SET status = 'queued',
                                hold_reason = NULL,
                                held_at = NULL
                            WHERE id = :topic_id
                        ");
                        $db->bind(':topic_id', $topic_id);
                        $db->execute();
                        $message = "Topic returned to your queue. Click 'Start' whenever you're ready to begin.";
                    }
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
