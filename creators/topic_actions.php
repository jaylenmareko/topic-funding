<?php
// creators/topic_actions.php - Handle creator topic management actions
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/refund_helper.php';
require_once __DIR__ . '/../config/notification_system.php';

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
$notify_start_id = null;    // topic to send sendStartEmails for
$notify_resume_id = null;   // topic to send sendResumeEmails for
$notify_auto_start_id = null; // auto-started queued topic to notify

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
                    $notify_start_id = $topic_id;
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

                    $db->endTransaction();

                    // Send decline notification emails (after commit so data is visible)
                    try {
                        $notifier = new NotificationSystem();
                        $notifier->sendDeclineEmails($topic_id);
                    } catch (Exception $e) {
                        error_log("Decline notification error (non-fatal): " . $e->getMessage());
                    }

                    $message = "Topic declined and all contributors have been refunded.";
                    header('Location: dashboard.php?success=' . urlencode($message));
                    exit;
                    break;
                    
                case 'hold':
                    // Allow hold for queued or funded topics only (active topics can only be declined)
                    if (!in_array($topic->status, ['queued', 'funded'])) {
                        throw new Exception("Can only put queued or funded topics on hold.");
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
                            $notify_auto_start_id = $next->id;
                            $message = "Topic put on hold. The next topic in your queue has been started automatically.";
                        } else {
                            $message = "Topic put on hold. No topics in queue — your slot is open when you resume.";
                        }
                    } elseif ($topic->status === 'queued') {
                        $message = "Topic put on hold. It will return to your queue when you resume it.";
                    }

                    $db->endTransaction();

                    // Send hold notification emails (after commit)
                    try {
                        $notifier = new NotificationSystem();
                        $notifier->sendHoldEmails($topic_id);
                        if ($notify_auto_start_id) {
                            $notifier->sendStartEmails($notify_auto_start_id);
                        }
                    } catch (Exception $e) {
                        error_log("Hold notification error (non-fatal): " . $e->getMessage());
                    }

                    header('Location: dashboard.php?success=' . urlencode($message));
                    exit;
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
                        $notify_resume_id = $topic_id;
                    } elseif ($topic->content_deadline !== null) {
                        // Was previously started — check if another topic is already running
                        $db->query("SELECT COUNT(*) as cnt FROM topics WHERE creator_id = :creator_id AND status = 'funded'");
                        $db->bind(':creator_id', $creator->id);
                        $already_running = $db->single();
                        
                        if ((int)$already_running->cnt > 0) {
                            // Slot is taken — place at front of queue (earliest funded_at so it sorts #1)
                            $db->query("
                                UPDATE topics 
                                SET status = 'queued',
                                    funded_at = '1970-01-01 00:00:00',
                                    content_deadline = NULL,
                                    hold_reason = NULL,
                                    held_at = NULL
                                WHERE id = :topic_id
                            ");
                            $db->bind(':topic_id', $topic_id);
                            $db->execute();
                            $message = "Topic moved to #1 in queue — it will start as soon as the current topic finishes or is held.";
                            $notify_resume_id = $topic_id;
                        } else {
                            // Slot is free — start immediately with a fresh deadline
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
                            $message = "Topic started! You have 48 hours to create the content.";
                            $notify_start_id = $topic_id;
                        }
                    } else {
                        // Was fully funded but never started — return to queue normally
                        $db->query("
                            UPDATE topics 
                            SET status = 'queued',
                                hold_reason = NULL,
                                held_at = NULL
                            WHERE id = :topic_id
                        ");
                        $db->bind(':topic_id', $topic_id);
                        $db->execute();
                        $message = "Topic returned to your queue.";
                        $notify_resume_id = $topic_id;
                    }
                    break;
                    
                default:
                    throw new Exception("Invalid action.");
            }
            
            $db->endTransaction();

            // Send notifications after transaction commits
            try {
                $notifier = new NotificationSystem();
                if ($notify_start_id) {
                    $notifier->sendStartEmails($notify_start_id);
                }
                if ($notify_resume_id) {
                    $notifier->sendResumeEmails($notify_resume_id);
                }
                if ($notify_auto_start_id) {
                    $notifier->sendStartEmails($notify_auto_start_id);
                }
            } catch (Exception $e) {
                error_log("Post-action notification error (non-fatal): " . $e->getMessage());
            }

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
