<?php
// creators/dashboard.php - UPDATED WITH CONSISTENT BRANDING
session_start();
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /index.php');
    exit;
}

$db = new Database();
$db->query('SELECT c.*, u.email FROM creators c LEFT JOIN users u ON c.applicant_user_id = u.id WHERE c.applicant_user_id = :user_id AND c.is_active = 1');
$db->bind(':user_id', $_SESSION['user_id']);
$creator = $db->single();

if (!$creator) {
    header('Location: ../creators/index.php');
    exit;
}

// Get completed count
$db->query("SELECT COUNT(*) as count FROM topics WHERE creator_id = :creator_id AND status = 'completed'");
$db->bind(':creator_id', $creator->id);
$completed_result = $db->single();
$completed_count = $completed_result->count ?? 0;

$current_script = basename($_SERVER['PHP_SELF']);
$allowed_pages = ['dashboard.php', 'edit.php'];
if (!in_array($current_script, $allowed_pages)) {
    header('Location: /creators/dashboard.php');
    exit;
}

function validateContentUrl($url) {
    $isYouTube = stripos($url, 'youtube.com/watch') !== false
             || stripos($url, 'youtube.com/shorts') !== false
             || stripos($url, 'youtube.com/live') !== false
             || stripos($url, 'youtu.be/') !== false;
    $isInstagram = stripos($url, 'instagram.com/reel') !== false
                || stripos($url, 'instagram.com/p/') !== false
                || stripos($url, 'instagram.com/tv/') !== false;
    $isTikTok = (stripos($url, 'tiktok.com/@') !== false && stripos($url, '/video/') !== false)
             || stripos($url, 'vm.tiktok.com/') !== false
             || stripos($url, 'vt.tiktok.com/') !== false;
    
    if (!$isYouTube && !$isInstagram && !$isTikTok) {
        return ["Must be a valid YouTube, Instagram, or TikTok URL"];
    }
    return [];
}

$upload_message = '';
$upload_error = '';
$uploaded_topic_id = 0;

if (isset($_GET['upload_success']) && isset($_GET['topic_id'])) {
    $uploaded_topic_id = (int)$_GET['topic_id'];
    $upload_message = "✅ Content uploaded successfully!";
}

// Stripe Connect state
$stripe_account_id     = $creator->stripe_account_id ?? null;
$stripe_account_status = $creator->stripe_account_status ?? null;

if (!empty($stripe_account_id) && $stripe_account_status === 'active') {
    $stripe_connect_state = 'active';
} elseif (!empty($stripe_account_id)) {
    $stripe_connect_state = 'pending';
} else {
    $stripe_connect_state = 'not_connected';
}

$stripe_return_message = '';
if (isset($_GET['stripe_return'])) {
    if ($stripe_connect_state === 'active') {
        $stripe_return_message = 'success';
    } else {
        $stripe_return_message = 'pending';
    }
}

if ($_POST && isset($_POST['upload_content']) && isset($_POST['topic_id']) && isset($_POST['content_url'])) {
    $topic_id = (int)$_POST['topic_id'];
    $content_url = trim($_POST['content_url']);
    
    if (empty($content_url)) {
        $upload_error = "Content URL is required";
        $uploaded_topic_id = $topic_id;
    } elseif (!filter_var($content_url, FILTER_VALIDATE_URL)) {
        $upload_error = "Please enter a valid URL";
        $uploaded_topic_id = $topic_id;
    } else {
        $validation_errors = validateContentUrl($content_url);
        if (!empty($validation_errors)) {
            $upload_error = implode(". ", $validation_errors);
            $uploaded_topic_id = $topic_id;
        } else {
            $db->query("SELECT * FROM topics WHERE id = :topic_id AND creator_id = :creator_id AND status = 'funded'");
            $db->bind(':topic_id', $topic_id);
            $db->bind(':creator_id', $creator->id);
            $topic_check = $db->single();
            
            if (!$topic_check) {
                $upload_error = "Topic not found";
                $uploaded_topic_id = $topic_id;
            } else {
                $deadline_passed = $topic_check->content_deadline && strtotime($topic_check->content_deadline) < time();
                if ($deadline_passed) {
                    $upload_error = "Deadline has passed";
                    $uploaded_topic_id = $topic_id;
                } else {
                    try {
                        $db->query("UPDATE topics SET content_url = :content_url, status = 'completed', completed_at = NOW() WHERE id = :topic_id");
                        $db->bind(':content_url', $content_url);
                        $db->bind(':topic_id', $topic_id);
                        $db->execute();

                        // --- Stripe Connect auto-payout (90% to creator) ---
                        try {
                            $payout_already_exists = false;
                            $db->query("SELECT id FROM creator_payouts WHERE topic_id = :topic_id LIMIT 1");
                            $db->bind(':topic_id', $topic_id);
                            if ($db->single()) {
                                $payout_already_exists = true;
                            }

                            if (!$payout_already_exists) {
                                $gross        = floatval($topic_check->current_funding);
                                $platform_fee = round($gross * 0.10, 2);
                                $payout_amt   = round($gross * 0.90, 2);

                                $transfer_id     = null;
                                $payout_status   = 'pending';

                                if (!empty($creator->stripe_account_id) && $creator->stripe_account_status === 'active') {
                                    if (!file_exists(__DIR__ . '/../vendor/autoload.php')) {
                                        throw new Exception('Stripe library missing');
                                    }
                                    require_once __DIR__ . '/../vendor/autoload.php';
                                    require_once __DIR__ . '/../config/stripe-keys.php';
                                    \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

                                    $transfer = \Stripe\Transfer::create([
                                        'amount'      => (int)round($payout_amt * 100),
                                        'currency'    => 'usd',
                                        'destination' => $creator->stripe_account_id,
                                        'description' => 'TopicLaunch payout — topic #' . $topic_id,
                                        'metadata'    => [
                                            'topic_id'   => $topic_id,
                                            'creator_id' => $creator->id,
                                        ],
                                    ]);

                                    $transfer_id   = $transfer->id;
                                    $payout_status = 'completed';
                                    error_log("Stripe Transfer created: $transfer_id — \$$payout_amt to {$creator->stripe_account_id}");
                                }

                                $db->query("INSERT INTO creator_payouts (creator_id, topic_id, amount, platform_fee, stripe_transfer_id, status, created_at, processed_at)
                                            VALUES (:creator_id, :topic_id, :amount, :platform_fee, :transfer_id, :status, NOW(), " . ($payout_status === 'completed' ? 'NOW()' : 'NULL') . ")");
                                $db->bind(':creator_id',   $creator->id);
                                $db->bind(':topic_id',     $topic_id);
                                $db->bind(':amount',       $payout_amt);
                                $db->bind(':platform_fee', $platform_fee);
                                $db->bind(':transfer_id',  $transfer_id);
                                $db->bind(':status',       $payout_status);
                                $db->execute();
                            }
                        } catch (Exception $payout_ex) {
                            error_log("Auto-payout failed for topic $topic_id: " . $payout_ex->getMessage());
                            // Record the failure so it can be retried manually
                            try {
                                $gross        = floatval($topic_check->current_funding);
                                $platform_fee = round($gross * 0.10, 2);
                                $payout_amt   = round($gross * 0.90, 2);
                                $db->query("INSERT INTO creator_payouts (creator_id, topic_id, amount, platform_fee, stripe_transfer_id, status, created_at)
                                            VALUES (:creator_id, :topic_id, :amount, :platform_fee, NULL, 'failed', NOW())");
                                $db->bind(':creator_id',   $creator->id);
                                $db->bind(':topic_id',     $topic_id);
                                $db->bind(':amount',       $payout_amt);
                                $db->bind(':platform_fee', $platform_fee);
                                $db->execute();
                            } catch (Exception $e2) {}
                        }
                        // --- End auto-payout ---

                        // Auto-start the next queued topic now that the slot is open
                        $db->query("
                            SELECT id FROM topics 
                            WHERE creator_id = :creator_id AND status = 'queued'
                            ORDER BY funded_at ASC, id ASC
                            LIMIT 1
                        ");
                        $db->bind(':creator_id', $creator->id);
                        $next_queued = $db->single();
                        if ($next_queued) {
                            $db->query("
                                UPDATE topics 
                                SET status = 'funded', content_deadline = NOW() + INTERVAL '48 hours'
                                WHERE id = :next_id
                            ");
                            $db->bind(':next_id', $next_queued->id);
                            $db->execute();
                        }
                        
                        try {
                            if (file_exists('../config/notification_system.php')) {
                                require_once __DIR__ . '/../config/notification_system.php';
                                $notificationSystem = new NotificationSystem();
                                $notificationSystem->sendContentDeliveredNotifications($topic_id, $content_url);
                            }
                        } catch (Exception $e) {}
                        
                        header('Location: dashboard.php?upload_success=1&topic_id=' . $topic_id);
                        exit;
                    } catch (Exception $e) {
                        $upload_error = "Failed to upload";
                        $uploaded_topic_id = $topic_id;
                    }
                }
            }
        }
    }
}

// Auto-promote: if no topic is currently running but one is queued, start it now
$db->query("SELECT id FROM topics WHERE creator_id = :creator_id AND status = 'funded' LIMIT 1");
$db->bind(':creator_id', $creator->id);
$running_check = $db->single();
if (!$running_check) {
    $db->query("SELECT id FROM topics WHERE creator_id = :creator_id AND status = 'queued' ORDER BY funded_at ASC, id ASC LIMIT 1");
    $db->bind(':creator_id', $creator->id);
    $next = $db->single();
    if ($next) {
        $db->query("UPDATE topics SET status = 'funded', content_deadline = NOW() + INTERVAL '48 hours' WHERE id = :id");
        $db->bind(':id', $next->id);
        $db->execute();
    }
}

$db->query("
    SELECT t.*, 
           EXTRACT(EPOCH FROM t.content_deadline) as deadline_timestamp,
           EXTRACT(EPOCH FROM (t.content_deadline - NOW())) as seconds_remaining,
           (t.funding_threshold * 0.9) as potential_earnings
    FROM topics t 
    WHERE t.creator_id = :creator_id 
    AND t.status IN ('active', 'funded', 'queued', 'on_hold') 
    AND (t.content_url IS NULL OR t.content_url = '')
    AND (t.status != 'funded' OR t.content_deadline IS NULL OR t.content_deadline >= NOW())
    ORDER BY 
        CASE WHEN t.status = 'funded' THEN 1 WHEN t.status = 'queued' THEN 2 WHEN t.status = 'on_hold' THEN 3 WHEN t.status = 'active' THEN 4 END, 
        t.funded_at ASC, t.created_at DESC
");
$db->bind(':creator_id', $creator->id);
$topics = $db->resultSet();

// Group topics into sections
$section_running  = [];
$section_queued   = [];
$section_on_hold  = [];
$section_active   = [];
foreach ($topics as $topic) {
    if ($topic->status === 'funded')       $section_running[]  = $topic;
    elseif ($topic->status === 'queued')   $section_queued[]   = $topic;
    elseif ($topic->status === 'on_hold')  $section_on_hold[]  = $topic;
    elseif ($topic->status === 'active')   $section_active[]   = $topic;
}

$funded_count = count($section_running);
$queued_count = count($section_queued);
$active_count = count($section_active);
$has_running  = $funded_count > 0;

// Fetch queue positions for queued topics
$queue_positions = [];
if ($queued_count > 0) {
    $db->query("SELECT id, ROW_NUMBER() OVER (ORDER BY funded_at ASC, id ASC) as queue_pos FROM topics WHERE creator_id = :creator_id AND status = 'queued'");
    $db->bind(':creator_id', $creator->id);
    $queue_rows = $db->resultSet();
    foreach ($queue_rows as $row) {
        $queue_positions[$row->id] = (int)$row->queue_pos;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>My Topics - TopicLaunch</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        /* Color Variables */
        :root {
            --hot-pink: #E8305A;
            --deep-pink: #B01F3F;
            --black: #111010;
            --white: #FFFFFF;
            --gray-dark: #111010;
            --gray-med: #888888;
            --gray-light: #E5E5E5;
            --bg: #FAF8F6;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--bg);
            color: var(--black);
        }
        
        /* Navigation - Match Landing Page */
        .topiclaunch-nav {
            background: var(--white);
            padding: 16px 0;
            border-bottom: 1px solid var(--gray-light);
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 1px 4px rgba(0,0,0,0.04);
        }
        
        .nav-container {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 30px;
        }
        
        .nav-logo {
            font-family: 'Inter', sans-serif;
            font-size: 20px;
            font-weight: 500;
            color: var(--black);
            text-decoration: none;
            letter-spacing: -0.3px;
        }
        
        .nav-logo span {
            color: var(--hot-pink);
        }
        
        .nav-center {
            display: flex;
            gap: 28px;
            align-items: center;
        }
        
        .nav-link {
            color: var(--gray-dark);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: color 0.2s;
        }
        
        .nav-link:hover {
            color: var(--hot-pink);
        }
        
        .nav-buttons {
            display: flex;
            gap: 15px;
            align-items: center;
        }
        
        .nav-login-btn {
            color: var(--gray-dark);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            padding: 0;
            transition: color 0.2s;
        }
        
        .nav-login-btn:hover {
            color: var(--hot-pink);
        }
        
        .nav-getstarted-btn {
            background: var(--hot-pink);
            color: var(--white);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            padding: 9px 20px;
            border-radius: 8px;
            transition: all 0.2s;
        }
        
        .nav-getstarted-btn:hover {
            background: var(--deep-pink);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(255, 0, 107, 0.3);
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 40px 30px 80px;
        }
        
        .page-header {
            display: flex;
            justify-content: flex-start;
            align-items: flex-start;
            gap: 18px;
            margin-bottom: 28px;
            flex-wrap: wrap;
        }
        
        .page-title-section {
            flex: 1;
            display: flex;
            align-items: center;
            gap: 18px;
            flex-wrap: wrap;
        }
        
        .page-title {
            font-size: 30px;
            font-weight: 600;
            color: #111010;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .title-icon {
            width: 32px;
            height: 32px;
            color: var(--hot-pink);
        }
        
        .page-subtitle {
            font-size: 15px;
            color: #888;
        }
        
        .header-buttons {
            display: flex;
            gap: 12px;
            margin-left: auto;
        }
        
        .btn {
            padding: 9px 18px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: 1px solid #E5E5E5;
            background: white;
            color: #111010;
            transition: all 0.2s;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
        }
        
        .btn:hover {
            border-color: var(--hot-pink);
            color: var(--hot-pink);
            transform: translateY(-1px);
        }
        
        .btn svg {
            width: 16px;
            height: 16px;
        }
        
        .mobile-logout-btn {
            display: none;
        }
        
        .browse-btn {
            width: 100%;
            padding: 16px;
            background: white;
            border: 1px solid #E5E5E5;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 500;
            color: #111010;
            cursor: pointer;
            margin-bottom: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
        }
        
        .browse-btn:hover {
            border-color: var(--hot-pink);
            color: var(--hot-pink);
        }

        .dashboard-action-btns {
            display: flex;
            gap: 10px;
            margin-bottom: 28px;
        }

        .dash-btn {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            padding: 11px 16px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.18s;
            border: 1.5px solid transparent;
            letter-spacing: -0.1px;
        }

        .dash-btn-secondary {
            background: white;
            border-color: #E5E5E5;
            color: #444;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
        }

        .dash-btn-secondary:hover {
            border-color: var(--hot-pink);
            color: var(--hot-pink);
        }

        .dash-btn-primary {
            background: linear-gradient(135deg, var(--hot-pink) 0%, var(--deep-pink) 100%);
            color: white;
            box-shadow: 0 2px 8px rgba(232,48,90,0.25);
        }

        .dash-btn-primary:hover {
            box-shadow: 0 4px 14px rgba(232,48,90,0.35);
            transform: translateY(-1px);
        }
        
        .content-box {
            background: white;
            border: 1px solid #E5E5E5;
            border-radius: 16px;
            padding: 40px;
            min-height: 500px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
        }
        
        .topics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 16px;
        }
        
        .empty-state {
            text-align: center;
            max-width: 640px;
            margin: 0 auto;
            padding: 40px 0;
        }
        
        .empty-icon {
            width: 80px;
            height: 80px;
            color: #D9D9D9;
            margin: 0 auto 24px;
        }
        
        .empty-title {
            font-size: 24px;
            font-weight: 600;
            color: #111010;
            margin-bottom: 12px;
        }
        
        .empty-text {
            font-size: 15px;
            color: #888;
            line-height: 1.6;
            margin-bottom: 32px;
        }
        
        .empty-btn {
            background: var(--hot-pink);
            color: white;
            padding: 12px 28px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 500;
            border: none;
            cursor: pointer;
            display: inline-block;
        }
        
        .empty-btn:hover {
            background: var(--deep-pink);
        }
        
        .topic-tile {
            background: white;
            border: 1px solid #E5E5E5;
            border-radius: 10px;
            padding: 12px 14px;
            transition: border-color 0.15s;
            cursor: pointer;
            position: relative;
        }

        .topic-tile:hover {
            border-color: var(--hot-pink);
        }

        .topic-tile.tile-running {
            background: #F0FDF4;
            border-color: #BBF7D0;
        }

        .topic-tile.tile-running:hover {
            border-color: #86EFAC;
        }

        .topic-tile.tile-on-hold {
            background: #FFFBF0;
            border-color: #FDE68A;
        }

        .topic-tile.tile-on-hold:hover {
            border-color: #FCD34D;
        }

        /* Top row: dot + badge + earnings */
        .tile-top {
            display: flex;
            align-items: center;
            gap: 7px;
            margin-bottom: 8px;
        }

        .tile-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            flex-shrink: 0;
            background: #D1D5DB;
        }

        .tile-dot.dot-running { background: #22C55E; }
        .tile-dot.dot-queued  { background: #3B82F6; }
        .tile-dot.dot-hold    { background: #F59E0B; }
        .tile-dot.dot-active  { background: var(--hot-pink); }

        .tile-badge {
            font-size: 10px;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 20px;
            letter-spacing: 0.3px;
            background: #F3F4F6;
            color: #6B7280;
        }

        .tile-badge.badge-running {
            background: #DCFCE7;
            color: #15803D;
        }

        .tile-badge.badge-queued {
            background: #DBEAFE;
            color: #1D4ED8;
        }

        .tile-badge.badge-hold {
            background: #FEF3C7;
            color: #B45309;
        }

        .tile-badge.badge-active {
            background: #FCE7F0;
            color: var(--deep-pink);
        }

        .tile-earnings-inline {
            margin-left: auto;
            font-size: 11px;
            font-weight: 600;
            color: #111010;
        }

        /* Title */
        .topic-tile-title {
            font-size: 13px;
            font-weight: 600;
            color: #111010;
            margin-bottom: 8px;
            line-height: 1.35;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        /* Progress bar */
        .progress-bar-container {
            height: 5px;
            background: #F0F0F0;
            border-radius: 4px;
            overflow: hidden;
            margin-bottom: 5px;
        }

        .progress-bar-fill {
            height: 100%;
            background: var(--hot-pink);
            border-radius: 4px;
            transition: width 0.3s;
        }

        /* Meta row */
        .tile-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 10px;
            color: #888;
            margin-bottom: 10px;
        }

        /* Actions */
        .topic-tile-actions {
            display: flex;
            gap: 5px;
        }

        .tile-btn {
            flex: 1;
            padding: 6px 10px;
            border: 1px solid #E5E5E5;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            background: white;
            color: #374151;
            transition: all 0.15s;
        }

        .tile-btn:hover {
            border-color: var(--hot-pink);
            color: var(--hot-pink);
        }

        .tile-btn.primary {
            background: var(--hot-pink);
            color: white;
            border-color: var(--hot-pink);
        }

        .tile-btn.primary:hover {
            background: var(--deep-pink);
            border-color: var(--deep-pink);
        }

        .tile-btn.danger {
            color: #DC2626;
            border-color: #FECACA;
        }

        .tile-btn.danger:hover {
            background: #DC2626;
            color: white;
            border-color: #DC2626;
        }

        .countdown-timer {
            font-size: 10px;
            font-weight: 600;
            color: #15803D;
        }

        /* Topic sections */
        .topic-section {
            margin-bottom: 28px;
            border-radius: 10px;
            border: 1.5px solid #E5E5E5;
            padding: 14px 16px;
        }

        .topic-section:last-child {
            margin-bottom: 0;
        }

        .topic-section.section-running {
            border-color: #BBF7D0;
            background: #F0FDF4;
        }

        .topic-section.section-queued {
            border-color: #BFDBFE;
            background: #EFF6FF;
        }

        .topic-section.section-onhold {
            border-color: #FDE68A;
            background: #FFFBEB;
        }

        .topic-section.section-active {
            border-color: #FBCFE8;
            background: #FDF2F8;
        }

        .section-label {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.6px;
            text-transform: uppercase;
            color: #888;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .section-label-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        .section-label-count {
            background: #F3F4F6;
            color: #6B7280;
            font-size: 10px;
            font-weight: 600;
            padding: 1px 7px;
            border-radius: 20px;
        }

        .section-divider {
            border: none;
            border-top: 1px solid #F0F0F0;
            margin: 24px 0;
        }

        /* Collapsible section toggle */
        .section-label {
            cursor: default;
        }

        .section-label.collapsible {
            cursor: pointer;
            user-select: none;
            border-radius: 8px;
            padding: 5px 6px;
            margin: -5px -6px 10px;
            transition: background 0.15s;
        }

        .section-label.collapsible:hover {
            background: #F5F5F5;
        }

        .section-chevron {
            margin-left: auto;
            width: 14px;
            height: 14px;
            color: #BBB;
            transition: transform 0.2s ease;
            flex-shrink: 0;
        }

        .section-label.collapsed .section-chevron {
            transform: rotate(-90deg);
        }

        .section-body {
            overflow: hidden;
            transition: max-height 0.25s ease, opacity 0.2s ease;
            max-height: 2000px;
            opacity: 1;
        }

        .section-body.collapsed {
            max-height: 0;
            opacity: 0;
        }
        
        /* UPDATED: Earnings section with consistent pink */
        .earnings-section {
            background: #fff;
            border-radius: 16px;
            padding: 32px;
            margin: 24px 0;
            border: 1px solid #E5E5E5;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
        }
        
        .earnings-stats {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
        }
        
        .earnings-stats > .stat-card {
            flex: 1 1 140px;
        }
        
        .payout-wrapper {
            flex: 0 0 auto;
            text-align: center;
        }
        
        .payout-button {
            background: linear-gradient(135deg, #2ecc71 0%, #1f9d55 100%);
            color: white;
            border: none;
            padding: 13px 24px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            box-shadow: none;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
        }
        
        .payout-button:hover {
            background: #1f9d55;
        }
        
        .payout-note {
            font-size: 11px;
            color: #888;
            margin-top: 6px;
        }

        .connect-alert {
            display: flex;
            align-items: center;
            gap: 16px;
            background: #FFF8E6;
            border: 1px solid #F5D98A;
            border-radius: 12px;
            padding: 16px 20px;
            margin-top: 16px;
        }
        .connect-alert.connect-alert-pending {
            background: #EFF6FF;
            border-color: #BFDBFE;
        }
        .connect-alert.connect-alert-active {
            background: #F0FDF4;
            border-color: #BBF7D0;
        }
        .connect-alert-icon {
            flex: 0 0 auto;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #F5B93A;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .connect-alert-content {
            flex: 1 1 auto;
            min-width: 0;
        }
        .connect-alert-title {
            font-size: 14px;
            font-weight: 600;
            color: #1a1a1a;
            margin-bottom: 2px;
        }
        .connect-alert-text {
            font-size: 13px;
            color: #6b6b6b;
            line-height: 1.4;
        }
        .connect-alert-btn {
            flex: 0 0 auto;
            background: #635BFF;
            color: white;
            border: none;
            padding: 10px 18px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            white-space: nowrap;
            transition: background 0.15s ease;
        }
        .connect-alert-btn:hover {
            background: #5248E0;
        }
        @media (max-width: 640px) {
            .connect-alert {
                flex-direction: column;
                align-items: flex-start;
                text-align: left;
            }
            .connect-alert-btn {
                width: 100%;
            }
        }

        /* Unified stat card — used in both header and earnings section */
        .stat-card {
            background: white;
            border: 1px solid #E5E5E5;
            border-radius: 12px;
            padding: 16px 20px;
            min-width: 140px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
            text-align: center;
        }
        .stat-card-label {
            font-size: 11px;
            color: #888;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        .stat-card-value {
            font-size: 26px;
            font-weight: 600;
            color: #111010;
            line-height: 1.1;
        }
        .stat-card-value.balance { color: var(--hot-pink); }
        .stat-card-value.pending { color: #B45309; }
        .stat-card-value.paid    { color: #15803D; }
        .stat-card-sub {
            font-size: 11px;
            color: #aaa;
        }
        
        @media (max-width: 768px) {
            .nav-center {
                display: none;
            }
            .container { padding: 24px 16px 48px; }
            .page-header { flex-direction: column; gap: 20px; }
            .header-buttons { width: 100%; }
            .btn { flex: 1; justify-content: center; }
            .topics-grid { grid-template-columns: 1fr; }
            .mobile-logout-btn { display: inline-flex; }
            .page-title-section {
                flex-wrap: wrap;
                gap: 12px;
            }
            .earnings-section {
                padding: 20px 16px;
            }
            .earnings-stats {
                flex-direction: column;
                gap: 16px;
                align-items: stretch;
            }
            .earnings-stats > .stat-card {
                flex: 1 1 100%;
            }
            .stat-card-value {
                font-size: 22px;
            }
            .payout-wrapper {
                margin-top: 8px;
            }
            .payout-button {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="topiclaunch-nav">
        <div class="nav-container">
            <a href="/" class="nav-logo">Topic<span>Launch</span></a>
            
            <div style="flex: 1;"></div>
            
            <div class="nav-buttons">
                <a href="edit.php?id=<?php echo $creator->id; ?>" class="nav-login-btn">
                    Edit Profile
                </a>
                <a href="../auth/logout.php" class="nav-login-btn">Log Out</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="page-header">
            <div class="page-title-section">
                <div>
                    <h1 class="page-title">
                        <svg class="title-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect>
                            <path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path>
                        </svg>
                        My Topics
                        <span class="inline-price-card">
                            <span class="stat-card-value">$<?php echo number_format($creator->minimum_topic_price ?? 100, 2); ?></span>
                            <span class="stat-card-sub">per topic</span>
                        </span>
                    </h1>
                    <p class="page-subtitle"><?php echo $funded_count; ?> running, <?php echo $queued_count; ?> in queue, <?php echo count($section_on_hold); ?> on hold, <?php echo $active_count; ?> active.</p>
                </div>
            </div>
            
            <div class="header-buttons">
            </div>
        </div>

        <div class="dashboard-action-btns">
            <div style="flex:1;display:flex;flex-direction:column;gap:6px;">
                <button onclick="copyProfileLink()" class="dash-btn dash-btn-secondary" id="copyBtn">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>
                        <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>
                    </svg>
                    Copy Profile Link
                </button>
                <span style="font-size:11px;color:#999;text-align:center;letter-spacing:-0.1px;">Share this link with your fans</span>
            </div>
            <button onclick="openCreateTopicModal()" class="dash-btn dash-btn-primary">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="8" x2="12" y2="16"/>
                    <line x1="8" y1="12" x2="16" y2="12"/>
                </svg>
                New Topic
            </button>
        </div>

        <div class="earnings-section">
            <div class="earnings-stats">
                <div class="stat-card">
                    <div class="stat-card-label">Total Earnings</div>
                    <div class="stat-card-value">$<?php echo number_format($creator->total_earnings ?? 0, 2); ?></div>
                    <div class="stat-card-sub">all time</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-card-label">Pending Payout</div>
                    <div class="stat-card-value pending">$<?php echo number_format($creator->pending_payout ?? 0, 2); ?></div>
                    <div class="stat-card-sub">in progress</div>
                </div>
                
            </div>

            <?php if ($stripe_connect_state === 'not_connected'): ?>
            <div class="connect-alert" id="connectAlert">
                <div class="connect-alert-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="2" y="5" width="20" height="14" rx="2"></rect>
                        <line x1="2" y1="10" x2="22" y2="10"></line>
                    </svg>
                </div>
                <div class="connect-alert-content">
                    <div class="connect-alert-title">Connect your payout account</div>
                    <div class="connect-alert-text">Set up Stripe to receive your earnings automatically when topics complete.</div>
                </div>
                <button class="connect-alert-btn" onclick="connectStripeAccount(this)">Connect Account</button>
            </div>
            <?php elseif ($stripe_connect_state === 'pending'): ?>
            <div class="connect-alert connect-alert-pending" id="connectAlert">
                <div class="connect-alert-icon" style="background:#3B82F6;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <polyline points="12 6 12 12 16 14"></polyline>
                    </svg>
                </div>
                <div class="connect-alert-content">
                    <div class="connect-alert-title">Payout account verification in progress</div>
                    <div class="connect-alert-text">Stripe is reviewing your information. This usually takes a few minutes. If you haven't finished onboarding, click to continue.</div>
                </div>
                <button class="connect-alert-btn" style="background:#3B82F6;" onclick="connectStripeAccount(this)">Continue Setup</button>
            </div>
            <?php elseif ($stripe_connect_state === 'active'): ?>
            <div class="connect-alert connect-alert-active" id="connectAlert">
                <div class="connect-alert-icon" style="background:#22C55E;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="20 6 9 17 4 12"></polyline>
                    </svg>
                </div>
                <div class="connect-alert-content">
                    <div class="connect-alert-title">Payout account connected</div>
                    <div class="connect-alert-text">Your Stripe account is active. Earnings will transfer automatically when topics complete.</div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($stripe_return_message === 'pending'): ?>
            <div id="stripeReturnBanner" style="margin-top:12px;background:#EFF6FF;border:1px solid #BFDBFE;border-radius:10px;padding:14px 18px;font-size:13px;color:#1E40AF;display:flex;align-items:center;gap:10px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                <span>Thanks for completing onboarding! Stripe is finishing verification — we'll update your status automatically.</span>
            </div>
            <?php elseif ($stripe_return_message === 'success'): ?>
            <div id="stripeReturnBanner" style="margin-top:12px;background:#F0FDF4;border:1px solid #BBF7D0;border-radius:10px;padding:14px 18px;font-size:13px;color:#166534;display:flex;align-items:center;gap:10px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>
                <span>Payout account connected successfully! You're all set to receive earnings.</span>
            </div>
            <?php endif; ?>
        </div>

        <div class="content-box">
            <?php if (empty($topics)): ?>
                <div class="empty-state">
                    <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"></path>
                    </svg>
                    <h2 class="empty-title">No topics yet</h2>
                    <p class="empty-text">You haven't received any topic requests yet. Share your profile link with fans to get started!</p>
                    <button onclick="copyProfileLink()" class="empty-btn">Copy Profile Link</button>
                </div>
            <?php else: ?>

                <?php
                // Reusable card renderer — outputs the tile HTML for one topic
                function renderTopicTile($topic, $queue_positions, $has_running) {
                    $tileClass = 'topic-tile';
                    if ($topic->status === 'funded')  $tileClass .= ' tile-running';
                    if ($topic->status === 'on_hold') $tileClass .= ' tile-on-hold';
                    $pct = $topic->funding_threshold > 0
                        ? min(100, round(($topic->current_funding / $topic->funding_threshold) * 100))
                        : 0;
                    ?>
                    <div class="<?php echo $tileClass; ?>" onclick="openTopicModal(<?php echo $topic->id; ?>)">

                        <div class="tile-top">
                            <?php if ($topic->status === 'funded'): ?>
                                <div class="tile-dot dot-running"></div>
                                <span class="tile-badge badge-running">
                                    ⏱ <span class="countdown-timer" data-deadline="<?php echo $topic->deadline_timestamp; ?>" id="timer-<?php echo $topic->id; ?>"><?php
                                        $seconds_left = max(0, $topic->seconds_remaining);
                                        $hours   = floor($seconds_left / 3600);
                                        $minutes = floor(($seconds_left % 3600) / 60);
                                        $secs    = $seconds_left % 60;
                                        echo sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
                                    ?></span>
                                </span>
                            <?php elseif ($topic->status === 'queued'): ?>
                                <div class="tile-dot dot-queued"></div>
                                <span class="tile-badge badge-queued">#<?php echo $queue_positions[$topic->id] ?? '?'; ?> In Queue</span>
                            <?php elseif ($topic->status === 'on_hold'): ?>
                                <div class="tile-dot dot-hold"></div>
                                <span class="tile-badge badge-hold">On Hold</span>
                            <?php else: ?>
                                <div class="tile-dot dot-active"></div>
                                <span class="tile-badge badge-active">Active</span>
                            <?php endif; ?>
                            <span class="tile-earnings-inline">$<?php echo number_format($topic->funding_threshold * 0.9, 0); ?> earnings</span>
                        </div>

                        <h3 class="topic-tile-title"><?php echo htmlspecialchars($topic->title); ?></h3>

                        <div class="progress-bar-container">
                            <div class="progress-bar-fill" style="width:<?php echo $pct; ?>%"></div>
                        </div>

                        <div class="tile-meta">
                            <span>$<?php echo number_format($topic->current_funding, 0); ?> raised of $<?php echo number_format($topic->funding_threshold, 0); ?></span>
                            <span><?php echo $pct; ?>%</span>
                        </div>

                        <div class="topic-tile-actions" onclick="event.stopPropagation();">
                            <?php if ($topic->status === 'funded'): ?>
                                <button class="tile-btn primary" onclick="openUploadModal(<?php echo $topic->id; ?>)">Upload</button>
                                <button class="tile-btn" onclick="holdTopic(<?php echo $topic->id; ?>)" style="background:#FFF7ED;color:#9A3412;border-color:#FED7AA;">Hold</button>
                                <button class="tile-btn danger" onclick="declineTopic(<?php echo $topic->id; ?>)">Decline</button>
                            <?php elseif ($topic->status === 'queued'): ?>
                                <?php if ($has_running): ?>
                                    <button class="tile-btn" disabled style="opacity:0.45;cursor:not-allowed;flex:1;" title="Finish the current running topic first">Auto-queued</button>
                                <?php else: ?>
                                    <button class="tile-btn primary" onclick="startTopic(<?php echo $topic->id; ?>)">Start</button>
                                <?php endif; ?>
                                <button class="tile-btn" onclick="holdTopic(<?php echo $topic->id; ?>)" style="background:#FFF7ED;color:#9A3412;border-color:#FED7AA;">Hold</button>
                                <button class="tile-btn danger" onclick="declineTopic(<?php echo $topic->id; ?>)">Decline</button>
                            <?php elseif ($topic->status === 'on_hold'): ?>
                                <button class="tile-btn primary" onclick="resumeTopic(<?php echo $topic->id; ?>)">Resume</button>
                                <button class="tile-btn danger" onclick="declineTopic(<?php echo $topic->id; ?>)">Decline</button>
                            <?php else: ?>
                                <button class="tile-btn" onclick="copyTopicLink(<?php echo $topic->id; ?>)">Copy Link</button>
                                <button class="tile-btn danger" onclick="declineTopic(<?php echo $topic->id; ?>)">Decline</button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php
                }
                ?>

                <?php $first_section = true; ?>

                <?php if (!empty($section_running)): ?>
                    <div class="topic-section section-running">
                        <div class="section-label">
                            <div class="section-label-dot" style="background:#22C55E;"></div>
                            Now Running
                        </div>
                        <div class="topics-grid">
                            <?php foreach ($section_running as $topic): renderTopicTile($topic, $queue_positions, $has_running); endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($section_queued)): ?>
                    <div class="topic-section section-queued">
                        <div class="section-label collapsible" id="label-queued" onclick="toggleSection('queued')">
                            <div class="section-label-dot" style="background:#3B82F6;"></div>
                            Up Next
                            <span class="section-label-count"><?php echo count($section_queued); ?></span>
                            <svg class="section-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
                        </div>
                        <div class="section-body" id="body-queued">
                            <div class="topics-grid" style="margin-top:10px;">
                                <?php foreach ($section_queued as $topic): renderTopicTile($topic, $queue_positions, $has_running); endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($section_on_hold)): ?>
                    <div class="topic-section section-onhold">
                        <div class="section-label collapsible collapsed" id="label-onhold" onclick="toggleSection('onhold')">
                            <div class="section-label-dot" style="background:#F59E0B;"></div>
                            On Hold
                            <span class="section-label-count"><?php echo count($section_on_hold); ?></span>
                            <svg class="section-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
                        </div>
                        <div class="section-body collapsed" id="body-onhold">
                            <div class="topics-grid" style="margin-top:10px;">
                                <?php foreach ($section_on_hold as $topic): renderTopicTile($topic, $queue_positions, $has_running); endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($section_active)): ?>
                    <div class="topic-section section-active">
                        <div class="section-label collapsible collapsed" id="label-active" onclick="toggleSection('active')">
                            <div class="section-label-dot" style="background:var(--hot-pink);"></div>
                            Active Topics
                            <span class="section-label-count"><?php echo count($section_active); ?></span>
                            <svg class="section-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
                        </div>
                        <div class="section-body collapsed" id="body-active">
                            <div class="topics-grid" style="margin-top:10px;">
                                <?php foreach ($section_active as $topic): renderTopicTile($topic, $queue_positions, $has_running); endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

            <?php endif; ?>
        </div>
    </div>

    <script>
        function openCreateTopicModal() {
            const minPrice = <?php echo $creator->minimum_topic_price ?? 100; ?>;
            const creatorId = <?php echo $creator->id; ?>;
            
            const INP = 'width:100%;padding:11px 14px;border:1px solid #E5E5E5;border-radius:8px;font-size:14px;outline:none;background:#fff;font-family:Inter,sans-serif;transition:border-color 0.2s,box-shadow 0.2s;';
            const LBL = 'display:block;font-size:11px;font-weight:500;color:#888;text-transform:uppercase;letter-spacing:0.4px;margin-bottom:8px;';
            const modalHTML = `
                <div id="createTopicModal" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.45);z-index:10000;display:flex;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(4px);" onclick="closeCreateTopicModal(event)">
                    <div style="background:#fff;border-radius:16px;border:1px solid #E5E5E5;max-width:500px;width:100%;max-height:90vh;overflow-y:auto;padding:32px 28px;position:relative;box-shadow:0 8px 32px rgba(0,0,0,0.10);" onclick="event.stopPropagation()">
                        <button onclick="closeCreateTopicModal()" style="position:absolute;top:18px;right:18px;background:transparent;border:none;width:28px;height:28px;font-size:22px;cursor:pointer;color:#aaa;padding:0;line-height:1;" onmouseover="this.style.color='#111'" onmouseout="this.style.color='#aaa'">×</button>
                        <h2 style="margin:0 0 6px 0;font-size:20px;color:#111010;font-weight:600;letter-spacing:-0.3px;padding-right:28px;">Create New Topic</h2>
                        <p style="color:#888;line-height:1.5;margin-bottom:24px;font-size:14px;">List a topic for your fans to fund.</p>
                        <div id="createTopicError" style="display:none;color:#DC2626;background:#FEF2F2;border-left:3px solid #DC2626;padding:12px 16px;border-radius:8px;margin-bottom:18px;font-size:13px;font-weight:500;"></div>
                        <form id="createTopicForm" onsubmit="submitCreatorTopic(event, ${creatorId}, ${minPrice})">
                            <div style="margin-bottom:18px;">
                                <label style="${LBL}">Topic Title</label>
                                <input type="text" id="topicTitle" placeholder="e.g., My Morning Routine" required maxlength="100" style="${INP}" onfocus="this.style.borderColor='#E8305A';this.style.boxShadow='0 0 0 3px rgba(232,48,90,0.08)'" onblur="this.style.borderColor='#E5E5E5';this.style.boxShadow='none'">
                            </div>
                            <div style="margin-bottom:18px;">
                                <label style="${LBL}">Description</label>
                                <textarea id="topicDescription" placeholder="Describe what this content will be about..." required maxlength="500" rows="4" style="${INP}resize:vertical;" onfocus="this.style.borderColor='#E8305A';this.style.boxShadow='0 0 0 3px rgba(232,48,90,0.08)'" onblur="this.style.borderColor='#E5E5E5';this.style.boxShadow='none'"></textarea>
                            </div>
                            <div style="margin-bottom:24px;">
                                <label style="${LBL}">Funding Goal</label>
                                <input type="number" id="fundingGoal" placeholder="${minPrice}" min="${minPrice}" max="10000" step="1" value="${minPrice}" required style="${INP}" onfocus="this.style.borderColor='#E8305A';this.style.boxShadow='0 0 0 3px rgba(232,48,90,0.08)'" onblur="this.style.borderColor='#E5E5E5';this.style.boxShadow='none'">
                                <div style="font-size:12px;color:#aaa;margin-top:6px;">Minimum: $${minPrice}</div>
                            </div>
                            <button type="submit" id="createTopicButton" style="width:100%;background:#E8305A;color:#fff;padding:13px;border:none;border-radius:10px;font-size:14px;font-weight:500;cursor:pointer;transition:background 0.2s;font-family:inherit;" onmouseover="this.style.background='#B01F3F'" onmouseout="this.style.background='#E8305A'">Create Topic</button>
                        </form>
                    </div>
                </div>
            `;
            
            document.body.insertAdjacentHTML('beforeend', modalHTML);
        }
        
        function closeCreateTopicModal(event) {
            if (event && event.target.id !== 'createTopicModal') return;
            const modal = document.getElementById('createTopicModal');
            if (modal) modal.remove();
        }
        
        function submitCreatorTopic(event, creatorId, minPrice) {
            event.preventDefault();
            
            const title = document.getElementById('topicTitle').value;
            const description = document.getElementById('topicDescription').value;
            const fundingGoal = parseFloat(document.getElementById('fundingGoal').value);
            const errorDiv = document.getElementById('createTopicError');
            const button = document.getElementById('createTopicButton');
            
            errorDiv.style.display = 'none';
            errorDiv.textContent = '';
            
            if (!title || !description || !fundingGoal) {
                errorDiv.textContent = 'Please fill in all fields';
                errorDiv.style.display = 'block';
                return;
            }
            
            if (fundingGoal < minPrice || fundingGoal > 10000) {
                errorDiv.textContent = 'Funding goal must be between $' + minPrice + ' and $10,000';
                errorDiv.style.display = 'block';
                return;
            }
            
            button.disabled = true;
            button.innerHTML = 'Creating...';
            button.style.opacity = '0.6';
            
            const requestData = {
                creator_id: creatorId,
                title: title,
                description: description,
                funding_goal: fundingGoal,
                creator_initiated: true
            };
            
            fetch('/api/create-creator-topic.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(requestData)
            })
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    errorDiv.textContent = data.error;
                    errorDiv.style.display = 'block';
                    button.disabled = false;
                    button.innerHTML = 'Create Topic';
                    button.style.opacity = '1';
                } else if (data.success) {
                    closeCreateTopicModal();
                    location.reload();
                } else {
                    errorDiv.textContent = 'Unexpected response from server';
                    errorDiv.style.display = 'block';
                    button.disabled = false;
                    button.innerHTML = 'Create Topic';
                    button.style.opacity = '1';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                errorDiv.textContent = 'Network error. Please try again.';
                errorDiv.style.display = 'block';
                button.disabled = false;
                button.innerHTML = 'Create Topic';
                button.style.opacity = '1';
            });
        }
        
        // Section collapse/expand with localStorage persistence
        const SECTION_KEY = 'tl_dashboard_sections_v2';

        function getSectionState() {
            try { return JSON.parse(localStorage.getItem(SECTION_KEY)) || {}; } catch(e) { return {}; }
        }

        function saveSectionState(key, collapsed) {
            const state = getSectionState();
            state[key] = collapsed;
            localStorage.setItem(SECTION_KEY, JSON.stringify(state));
        }

        function toggleSection(key) {
            const label = document.getElementById('label-' + key);
            const body  = document.getElementById('body-' + key);
            if (!label || !body) return;
            const isCollapsed = body.classList.contains('collapsed');
            if (isCollapsed) {
                body.classList.remove('collapsed');
                label.classList.remove('collapsed');
                saveSectionState(key, false);
            } else {
                body.classList.add('collapsed');
                label.classList.add('collapsed');
                saveSectionState(key, true);
            }
        }

        // Restore saved section states on load
        (function restoreSections() {
            const state = getSectionState();
            // queued defaults open, onhold/active default collapsed
            const defaults = { queued: false, onhold: true, active: true };
            ['queued', 'onhold', 'active'].forEach(key => {
                const collapsed = (key in state) ? state[key] : defaults[key];
                const label = document.getElementById('label-' + key);
                const body  = document.getElementById('body-' + key);
                if (!label || !body) return;
                if (collapsed) {
                    body.classList.add('collapsed');
                    label.classList.add('collapsed');
                } else {
                    body.classList.remove('collapsed');
                    label.classList.remove('collapsed');
                }
            });
        })();

        function updateCountdowns() {
            document.querySelectorAll('.countdown-timer[data-deadline]').forEach(element => {
                const deadline = parseInt(element.getAttribute('data-deadline')) * 1000;
                const now = new Date().getTime();
                const timeLeft = deadline - now;
                
                if (timeLeft > 0) {
                    const hours = Math.floor(timeLeft / (1000 * 60 * 60));
                    const minutes = Math.floor((timeLeft % (1000 * 60 * 60)) / (1000 * 60));
                    const seconds = Math.floor((timeLeft % (1000 * 60)) / 1000);
                    
                    element.textContent = 
                        String(hours).padStart(2, '0') + ':' + 
                        String(minutes).padStart(2, '0') + ':' + 
                        String(seconds).padStart(2, '0');
                } else {
                    element.textContent = '00:00:00';
                }
            });
        }
        
        setInterval(updateCountdowns, 1000);
        updateCountdowns();
        
        function copyProfileLink() {
            const url = window.location.origin + '/<?php echo $creator->display_name; ?>';
            navigator.clipboard.writeText(url).then(() => {
                const btn = document.getElementById('copyBtn');
                const orig = btn.textContent;
                btn.textContent = '✅ Copied!';
                setTimeout(() => btn.textContent = orig, 2000);
            });
        }
        
        function connectStripeAccount(btn) {
            if (btn) {
                btn.disabled = true;
                btn.textContent = 'Loading…';
            }
            fetch('/api/stripe-connect-onboard.php', { method: 'POST' })
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.url) {
                        window.location.href = data.url;
                    } else {
                        alert('Could not start Stripe onboarding: ' + (data.error || 'Unknown error'));
                        if (btn) {
                            btn.disabled = false;
                            btn.textContent = btn.dataset.label || 'Connect Account';
                        }
                    }
                })
                .catch(() => {
                    alert('Network error — please try again.');
                    if (btn) {
                        btn.disabled = false;
                        btn.textContent = btn.dataset.label || 'Connect Account';
                    }
                });
        }

        
        function copyTopicLink(id) {
            event.stopPropagation();
            const allTopics = <?php echo json_encode($topics); ?>;
            const activeTopics = allTopics.filter(t => t.status == 'active').map(t => parseInt(t.id));
            const topicNum = activeTopics.indexOf(parseInt(id)) + 1;
            
            if (topicNum === 0) {
                alert('Only active topics can be shared. Status: ' + allTopics.find(t => t.id == id)?.status);
                return;
            }
            
            const url = window.location.origin + '/<?php echo $creator->display_name; ?>/topic' + topicNum;
            navigator.clipboard.writeText(url).then(() => {
                alert('Topic link copied!');
            });
        }
        
        function openTopicModal(id) {
            const topic = <?php echo json_encode($topics); ?>.find(t => t.id == id);
            if (topic) {
                alert('Topic: ' + topic.title + '\n\nDescription: ' + topic.description);
            }
        }
        
        function openUploadModal(id) {
            event.stopPropagation();
            
            const modalHTML = `
                <div id="uploadModal" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.45);z-index:10000;display:flex;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(4px);" onclick="closeUploadModal(event)">
                    <div style="background:#fff;border-radius:16px;border:1px solid #E5E5E5;max-width:480px;width:100%;padding:32px 28px;position:relative;box-shadow:0 8px 32px rgba(0,0,0,0.10);" onclick="event.stopPropagation()">
                        <button onclick="closeUploadModal()" style="position:absolute;top:18px;right:18px;background:transparent;border:none;width:28px;height:28px;font-size:22px;cursor:pointer;color:#aaa;padding:0;line-height:1;" onmouseover="this.style.color='#111'" onmouseout="this.style.color='#aaa'">×</button>
                        <h2 style="margin:0 0 6px 0;font-size:20px;color:#111010;font-weight:600;letter-spacing:-0.3px;padding-right:28px;">Upload Content</h2>
                        <p style="color:#888;font-size:14px;margin-bottom:20px;">Paste your video link below.</p>
                        <div style="background:#FAF8F6;border:1px solid #E5E5E5;border-radius:10px;padding:12px 16px;margin-bottom:20px;display:flex;gap:0;">
                            <div style="flex:1;text-align:center;padding:6px 0;">
                                <div style="font-size:18px;margin-bottom:4px;">▶️</div>
                                <div style="font-size:11px;color:#888;font-weight:500;letter-spacing:0.3px;text-transform:uppercase;">YouTube</div>
                            </div>
                            <div style="width:1px;background:#E5E5E5;margin:4px 0;"></div>
                            <div style="flex:1;text-align:center;padding:6px 0;">
                                <div style="font-size:18px;margin-bottom:4px;">📸</div>
                                <div style="font-size:11px;color:#888;font-weight:500;letter-spacing:0.3px;text-transform:uppercase;">Instagram</div>
                            </div>
                            <div style="width:1px;background:#E5E5E5;margin:4px 0;"></div>
                            <div style="flex:1;text-align:center;padding:6px 0;">
                                <div style="font-size:18px;margin-bottom:4px;">🎵</div>
                                <div style="font-size:11px;color:#888;font-weight:500;letter-spacing:0.3px;text-transform:uppercase;">TikTok</div>
                            </div>
                        </div>
                        <div id="uploadError" style="display:none;color:#DC2626;background:#FEF2F2;border-left:3px solid #DC2626;padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:13px;font-weight:500;"></div>
                        <div style="margin-bottom:16px;">
                            <label style="display:block;font-size:11px;font-weight:500;color:#888;text-transform:uppercase;letter-spacing:0.4px;margin-bottom:8px;">Content URL</label>
                            <input type="text" id="uploadUrl" placeholder="Paste your video URL here..." autofocus style="width:100%;padding:11px 14px;border:1px solid #E5E5E5;border-radius:8px;font-size:14px;outline:none;background:#fff;font-family:Inter,sans-serif;transition:border-color 0.2s,box-shadow 0.2s;" onfocus="this.style.borderColor='#E8305A';this.style.boxShadow='0 0 0 3px rgba(232,48,90,0.08)'" onblur="this.style.borderColor='#E5E5E5';this.style.boxShadow='none'" onkeydown="if(event.key==='Enter'){event.preventDefault();submitUpload(${id});}">
                        </div>
                        <button id="uploadButton" onclick="submitUpload(${id})" style="width:100%;background:#E8305A;color:#fff;padding:13px;border:none;border-radius:10px;font-size:14px;font-weight:500;cursor:pointer;transition:background 0.2s;font-family:inherit;" onmouseover="this.style.background='#B01F3F'" onmouseout="this.style.background='#E8305A'">Upload Content</button>
                    </div>
                </div>
            `;
            
            document.body.insertAdjacentHTML('beforeend', modalHTML);
            document.getElementById('uploadUrl').focus();
        }
        
        function closeUploadModal(event) {
            if (event && event.target.id !== 'uploadModal') return;
            const modal = document.getElementById('uploadModal');
            if (modal) modal.remove();
        }
        
        function submitUpload(id) {
            const url = document.getElementById('uploadUrl').value.trim();
            const errorDiv = document.getElementById('uploadError');
            const button = document.getElementById('uploadButton');
            
            errorDiv.style.display = 'none';
            
            if (!url) {
                errorDiv.textContent = 'Please enter a URL';
                errorDiv.style.display = 'block';
                return;
            }
            
            button.disabled = true;
            button.textContent = 'Uploading...';
            button.style.opacity = '0.6';
            
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="topic_id" value="${id}">
                <input type="hidden" name="content_url" value="${url}">
                <input type="hidden" name="upload_content" value="1">
            `;
            document.body.appendChild(form);
            form.submit();
        }
        
        function declineTopic(id) {
            event.stopPropagation();
            if (confirm('Decline this topic? All contributors will be refunded.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'topic_actions.php';
                form.innerHTML = `<input type="hidden" name="action" value="decline"><input type="hidden" name="topic_id" value="${id}">`;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function holdTopic(id) {
            event.stopPropagation();
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'topic_actions.php';
            form.innerHTML = `<input type="hidden" name="action" value="hold"><input type="hidden" name="topic_id" value="${id}">`;
            document.body.appendChild(form);
            form.submit();
        }
        
        function startTopic(id) {
            event.stopPropagation();
            if (confirm('Start this topic now? Your 48-hour content deadline will begin immediately.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'topic_actions.php';
                form.innerHTML = `<input type="hidden" name="action" value="start"><input type="hidden" name="topic_id" value="${id}">`;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function resumeTopic(id) {
            event.stopPropagation();
            if (confirm('Resume this topic?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'topic_actions.php';
                form.innerHTML = `<input type="hidden" name="action" value="resume"><input type="hidden" name="topic_id" value="${id}">`;
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
</body>
</html>
