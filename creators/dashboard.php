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
    $isYouTube = stripos($url, 'youtube.com/watch') !== false || stripos($url, 'youtube.com/shorts') !== false || stripos($url, 'youtu.be/') !== false;
    $isInstagram = stripos($url, 'instagram.com/reel') !== false || stripos($url, 'instagram.com/reels') !== false || stripos($url, 'instagram.com/p/') !== false;
    $isTikTok = stripos($url, 'tiktok.com/@') !== false && stripos($url, '/video/') !== false;
    
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

$db->query("
    SELECT t.*, 
           EXTRACT(EPOCH FROM t.content_deadline) as deadline_timestamp,
           EXTRACT(EPOCH FROM (t.content_deadline - NOW())) as seconds_remaining,
           (t.funding_threshold * 0.9) as potential_earnings
    FROM topics t 
    WHERE t.creator_id = :creator_id 
    AND t.status IN ('active', 'funded', 'on_hold') 
    AND (t.content_url IS NULL OR t.content_url = '')
    AND (t.status != 'funded' OR t.content_deadline IS NULL OR t.content_deadline >= NOW())
    ORDER BY 
        CASE WHEN t.status = 'funded' THEN 1 WHEN t.status = 'active' THEN 2 WHEN t.status = 'on_hold' THEN 3 END, 
        potential_earnings DESC, t.funded_at ASC, t.created_at DESC
");
$db->bind(':creator_id', $creator->id);
$topics = $db->resultSet();

$funded_count = 0;
$active_count = 0;
foreach ($topics as $topic) {
    if ($topic->status === 'funded') {
        $funded_count++;
    } elseif ($topic->status === 'active') {
        $active_count++;
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
            --hot-pink: #FF006B;
            --deep-pink: #E6005F;
            --black: #000000;
            --white: #FFFFFF;
            --gray-dark: #1A1A1A;
            --gray-med: #666666;
            --gray-light: #E5E5E5;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f5f5f5;
            color: #000;
        }
        
        /* Navigation - Match Landing Page */
        .topiclaunch-nav {
            background: var(--white);
            padding: 16px 0;
            border-bottom: 1px solid var(--gray-light);
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        
        .nav-container {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 30px;
        }
        
        .nav-logo {
            font-family: 'Inter', sans-serif;
            font-size: 22px;
            font-weight: 700;
            color: var(--black);
            text-decoration: none;
            letter-spacing: -0.5px;
        }
        
        .nav-logo span {
            color: var(--hot-pink);
        }
        
        .nav-center {
            display: flex;
            gap: 30px;
            align-items: center;
        }
        
        .nav-link {
            color: var(--gray-dark);
            text-decoration: none;
            font-size: 14px;
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
            font-size: 14px;
            font-weight: 600;
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
            font-size: 14px;
            font-weight: 600;
            padding: 10px 24px;
            border-radius: 50px;
            transition: all 0.2s;
        }
        
        .nav-getstarted-btn:hover {
            background: var(--deep-pink);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(255, 0, 107, 0.3);
        }
        
        .container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 40px 100px;
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 32px;
        }
        
        .page-title-section {
            flex: 1;
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .page-title {
            font-size: 32px;
            font-weight: 700;
            color: #000;
            margin-bottom: 8px;
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
            font-size: 16px;
            color: #666;
        }
        
        .header-buttons {
            display: flex;
            gap: 12px;
        }
        
        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: 1px solid #e0e0e0;
            background: white;
            color: #333;
            transition: all 0.2s;
        }
        
        .btn:hover {
            border-color: #999;
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
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            color: #333;
            cursor: pointer;
            margin-bottom: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s;
        }
        
        .browse-btn:hover {
            border-color: var(--hot-pink);
            color: var(--hot-pink);
        }
        
        .content-box {
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 16px;
            padding: 40px;
            min-height: 500px;
        }
        
        .topics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 16px;
        }
        
        .empty-state {
            text-align: center;
            max-width: 600px;
            margin: 0 auto;
            padding: 40px 0;
        }
        
        .empty-icon {
            width: 80px;
            height: 80px;
            color: #d0d0d0;
            margin: 0 auto 24px;
        }
        
        .empty-title {
            font-size: 24px;
            font-weight: 700;
            color: #000;
            margin-bottom: 12px;
        }
        
        .empty-text {
            font-size: 16px;
            color: #666;
            line-height: 1.6;
            margin-bottom: 32px;
        }
        
        .empty-btn {
            background: var(--hot-pink);
            color: white;
            padding: 12px 32px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            display: inline-block;
        }
        
        .empty-btn:hover {
            background: var(--deep-pink);
        }
        
        .topic-tile {
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 12px;
            padding: 16px;
            transition: all 0.2s;
            cursor: pointer;
            position: relative;
        }
        
        .topic-tile:hover {
            border-color: var(--hot-pink);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .topic-tile-header {
            margin-bottom: 12px;
        }
        
        .topic-status-badge {
            display: inline-block;
            background: #f0f0f0;
            color: #666;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            margin-bottom: 10px;
        }
        
        .topic-status-badge.funded {
            background: #d4edda;
            color: #155724;
        }
        
        .topic-status-badge.on-hold {
            background: #fff3cd;
            color: #856404;
        }
        
        .topic-tile-title {
            font-size: 15px;
            font-weight: 700;
            color: #000;
            margin-bottom: 6px;
            line-height: 1.3;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .topic-tile-subtitle {
            font-size: 12px;
            color: #999;
            margin-bottom: 12px;
        }
        
        .topic-tile-earnings {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        .earnings-amount {
            font-size: 20px;
            font-weight: 700;
            color: #28a745;
        }
        
        .earnings-label {
            font-size: 11px;
            color: #666;
        }
        
        .topic-tile-progress {
            margin-bottom: 12px;
        }
        
        .progress-label {
            font-size: 12px;
            color: #666;
            margin-bottom: 6px;
            text-align: center;
        }
        
        .progress-bar-container {
            height: 6px;
            background: #e0e0e0;
            border-radius: 3px;
            overflow: hidden;
        }
        
        .progress-bar-fill {
            height: 100%;
            background: var(--hot-pink);
            border-radius: 3px;
            transition: width 0.3s;
        }
        
        .topic-tile-actions {
            display: flex;
            gap: 6px;
        }
        
        .tile-btn {
            flex: 1;
            padding: 8px 12px;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            background: white;
            color: #333;
            transition: all 0.2s;
        }
        
        .tile-btn:hover {
            border-color: #999;
        }
        
        .tile-btn.primary {
            background: var(--hot-pink);
            color: white;
            border-color: var(--hot-pink);
        }
        
        .tile-btn.primary:hover {
            background: var(--deep-pink);
        }
        
        .tile-btn.danger {
            color: #dc3545;
            border-color: #dc3545;
        }
        
        .tile-btn.danger:hover {
            background: #dc3545;
            color: white;
        }
        
        .countdown-timer {
            font-size: 10px;
            font-weight: 600;
            color: #28a745;
        }
        
        /* UPDATED: Earnings section with consistent pink */
        .earnings-section {
            background: linear-gradient(135deg, #fef3f8 0%, #fdeef4 100%);
            border-radius: 16px;
            padding: 32px;
            margin: 24px 0;
            border: 1px solid #fecde0;
        }
        
        .earnings-stats {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
        }
        
        .earnings-stats > div {
            flex: 0 0 auto;
        }
        
        .earnings-stats .stat-label {
            font-size: 13px;
            color: #991b4d;
            font-weight: 500;
            margin-bottom: 8px;
        }
        
        .earnings-stats .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: #1f2937;
        }
        
        .earnings-stats .stat-value.balance {
            color: var(--deep-pink);
        }
        
        .earnings-stats .stat-value.pending {
            color: #f97316;
        }
        
        .earnings-stats .stat-value.paid {
            color: #10b981;
        }
        
        .payout-wrapper {
            flex: 0 0 auto;
            text-align: center;
        }
        
        .payout-button {
            background: linear-gradient(135deg, var(--hot-pink) 0%, var(--deep-pink) 100%);
            color: white;
            border: none;
            padding: 14px 28px;
            border-radius: 50px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(255, 0, 107, 0.3);
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
        }
        
        .payout-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(255, 0, 107, 0.4);
        }
        
        .payout-note {
            font-size: 11px;
            color: #991b4d;
            margin-top: 6px;
        }
        
        @media (max-width: 768px) {
            .nav-center {
                display: none;
            }
            .container { padding: 20px 30px; }
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
            .earnings-stats > div {
                text-align: center;
            }
            .earnings-stats .stat-label {
                font-size: 12px;
            }
            .earnings-stats .stat-value {
                font-size: 24px;
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
                    </h1>
                    <p class="page-subtitle">Welcome back, <?php echo htmlspecialchars($creator->display_name); ?>! You have <?php echo $funded_count; ?> fully funded topic<?php echo $funded_count != 1 ? 's' : ''; ?> and <?php echo $active_count; ?> active topic<?php echo $active_count != 1 ? 's' : ''; ?>.</p>
                </div>
                
                <div style="background: white; border: 2px solid #e5e7eb; border-radius: 12px; padding: 12px 20px; display: flex; flex-direction: column; align-items: center; gap: 4px;">
                    <div style="font-size: 12px; color: #6b7280; font-weight: 500;">Your Price</div>
                    <div style="font-size: 24px; font-weight: 700; color: #111827;">$<?php echo number_format($creator->minimum_topic_price ?? 100, 2); ?></div>
                    <div style="font-size: 11px; color: #9ca3af;">per video topic</div>
                </div>
                
                <div style="background: white; border: 2px solid #e5e7eb; border-radius: 12px; padding: 12px 20px; display: flex; flex-direction: column; align-items: center; gap: 4px;">
                    <div style="font-size: 12px; color: #6b7280; font-weight: 500;">Completed Videos</div>
                    <div style="font-size: 24px; font-weight: 700; color: #111827;"><?php echo $completed_count; ?></div>
                    <div style="font-size: 11px; color: #9ca3af;">videos delivered</div>
                </div>
            </div>
            
            <div class="header-buttons">
                <a href="edit.php?id=<?php echo $creator->id; ?>" class="btn">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M12 15a3 3 0 100-6 3 3 0 000 6z"></path>
                        <path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-2 2 2 2 0 01-2-2v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06a1.65 1.65 0 00.33-1.82 1.65 1.65 0 00-1.51-1H3a2 2 0 01-2-2 2 2 0 012-2h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 010-2.83 2 2 0 012.83 0l.06.06a1.65 1.65 0 001.82.33H9a1.65 1.65 0 001-1.51V3a2 2 0 012-2 2 2 0 012 2v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 0 2 2 0 010 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 012 2 2 2 0 01-2 2h-.09a1.65 1.65 0 00-1.51 1z"></path>
                    </svg>
                    Edit Profile
                </a>
                <a href="../auth/logout.php" class="btn mobile-logout-btn">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"></path>
                    </svg>
                    Log Out
                </a>
            </div>
        </div>

        <div style="margin-bottom: 12px;">
            <button onclick="copyProfileLink()" class="browse-btn" id="copyBtn">
                🔗 Copy Profile Link
            </button>
            <p style="font-size: 13px; color: #999; text-align: center; margin-top: 10px;">Share this with your fans to start getting requests</p>
        </div>

        <button onclick="openCreateTopicModal()" class="browse-btn" style="background: linear-gradient(135deg, var(--hot-pink) 0%, var(--deep-pink) 100%); color: white; border-color: var(--hot-pink); margin-bottom: 32px;">
            🎯 Create New Topic
        </button>

        <div class="earnings-section">
            <div class="earnings-stats">
                <div>
                    <div class="stat-label">Total Earnings</div>
                    <div class="stat-value">$<?php echo number_format($creator->total_earnings ?? 0, 2); ?></div>
                </div>
                
                <div>
                    <div class="stat-label">Available Balance</div>
                    <div class="stat-value balance">$<?php echo number_format($creator->available_balance ?? 0, 2); ?></div>
                </div>
                
                <div>
                    <div class="stat-label">Pending Payout</div>
                    <div class="stat-value pending">$<?php echo number_format($creator->pending_payout ?? 0, 2); ?></div>
                </div>
                
                <div>
                    <div class="stat-label">Paid Out</div>
                    <div class="stat-value paid">$<?php echo number_format($creator->paid_out ?? 0, 2); ?></div>
                </div>
                
                <div class="payout-wrapper">
                    <button onclick="requestPayout()" class="payout-button">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect>
                            <line x1="1" y1="10" x2="23" y2="10"></line>
                        </svg>
                        Request Payout
                    </button>
                    <div class="payout-note">Min. $15</div>
                </div>
            </div>
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
                <div class="topics-grid">
                    <?php foreach ($topics as $topic): ?>
                        <div class="topic-tile" onclick="openTopicModal(<?php echo $topic->id; ?>)">
                            <div class="topic-tile-header">
                                <?php if ($topic->status === 'funded'): ?>
                                    <div class="topic-status-badge funded">
                                        ⏱️ <span class="countdown-timer" data-deadline="<?php echo $topic->deadline_timestamp; ?>" id="timer-<?php echo $topic->id; ?>">
                                            <?php
                                            $seconds_left = max(0, $topic->seconds_remaining);
                                            $hours = floor($seconds_left / 3600);
                                            $minutes = floor(($seconds_left % 3600) / 60);
                                            $seconds = $seconds_left % 60;
                                            echo sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
                                            ?>
                                        </span>
                                    </div>
                                <?php elseif ($topic->status === 'on_hold'): ?>
                                    <div class="topic-status-badge on-hold">On Hold</div>
                                <?php else: ?>
                                    <div class="topic-status-badge">Active</div>
                                <?php endif; ?>
                                
                                <h3 class="topic-tile-title"><?php echo htmlspecialchars($topic->title); ?></h3>
                                <p class="topic-tile-subtitle">
                                    Click for details
                                </p>
                            </div>
                            
                            <div class="topic-tile-earnings">
                                <div>
                                    <div class="earnings-amount">$<?php echo number_format($topic->funding_threshold * 0.9, 0); ?></div>
                                    <div class="earnings-label">Your Earnings</div>
                                </div>
                                <?php if ($topic->status === 'funded'): ?>
                                    <div style="text-align: right;">
                                        <div style="font-size: 24px;">✅</div>
                                        <div class="earnings-label">Funded</div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($topic->status === 'active'): ?>
                            <div class="topic-tile-progress">
                                <div class="progress-label">
                                    $<?php echo number_format($topic->current_funding, 0); ?> / $<?php echo number_format($topic->funding_threshold, 0); ?>
                                </div>
                                <div class="progress-bar-container">
                                    <div class="progress-bar-fill" style="width: <?php echo min(100, ($topic->current_funding / $topic->funding_threshold) * 100); ?>%"></div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <div class="topic-tile-actions" onclick="event.stopPropagation();">
                                <?php if ($topic->status === 'funded'): ?>
                                    <button class="tile-btn primary" onclick="openUploadModal(<?php echo $topic->id; ?>)">Upload</button>
                                    <button class="tile-btn" onclick="holdTopic(<?php echo $topic->id; ?>)" style="background: #ffc107; color: #000; border-color: #ffc107;">Hold</button>
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
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function openCreateTopicModal() {
            const minPrice = <?php echo $creator->minimum_topic_price ?? 100; ?>;
            const creatorId = <?php echo $creator->id; ?>;
            
            const modalHTML = `
                <div id="createTopicModal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; display: flex; align-items: center; justify-content: center; padding: 20px; backdrop-filter: blur(4px);" onclick="closeCreateTopicModal(event)">
                    <div style="background: white; border-radius: 20px; max-width: 540px; width: 100%; max-height: 90vh; overflow-y: auto; padding: 40px; position: relative; box-shadow: 0 20px 50px rgba(0,0,0,0.2);" onclick="event.stopPropagation()">
                        <button onclick="closeCreateTopicModal()" style="position: absolute; top: 20px; right: 20px; background: transparent; border: none; width: 32px; height: 32px; font-size: 28px; cursor: pointer; color: #666; transition: color 0.2s; padding: 0; line-height: 1; font-weight: 300;" onmouseover="this.style.color='#000'" onmouseout="this.style.color='#666'">×</button>
                        
                        <h2 style="font-family: 'Inter', sans-serif; margin: 0 0 12px 0; font-size: 24px; color: #000; font-weight: 700; line-height: 1.3; padding-right: 30px;">Create New Topic</h2>
                        <p style="color: #666; line-height: 1.6; margin-bottom: 28px; font-size: 15px;">List a topic for your fans to fund.</p>
                        
                        <div id="createTopicError" style="display: none; color: #DC2626; background: #FEF2F2; border-left: 4px solid #DC2626; padding: 14px 18px; border-radius: 12px; margin-bottom: 20px; font-size: 14px; font-weight: 500;"></div>
                        
                        <form id="createTopicForm" onsubmit="submitCreatorTopic(event, ${creatorId}, ${minPrice})">
                            <div style="margin-bottom: 20px;">
                                <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #000; font-size: 14px;">Topic Title</label>
                                <input type="text" id="topicTitle" placeholder="e.g., My Morning Routine" required maxlength="100" style="width: 100%; padding: 12px 16px; border: 2px solid #E5E5E5; border-radius: 12px; font-size: 15px; transition: all 0.2s; outline: none; background: white; font-family: 'Inter', sans-serif;" onfocus="this.style.borderColor='#FF006B'; this.style.boxShadow='0 0 0 4px rgba(255, 0, 107, 0.1)'" onblur="this.style.borderColor='#E5E5E5'; this.style.boxShadow='none'">
                            </div>
                            
                            <div style="margin-bottom: 20px;">
                                <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #000; font-size: 14px;">Description</label>
                                <textarea id="topicDescription" placeholder="Describe what this content will be about..." required maxlength="500" rows="4" style="width: 100%; padding: 12px 16px; border: 2px solid #E5E5E5; border-radius: 12px; font-size: 15px; transition: all 0.2s; outline: none; background: white; resize: vertical; font-family: 'Inter', sans-serif;" onfocus="this.style.borderColor='#FF006B'; this.style.boxShadow='0 0 0 4px rgba(255, 0, 107, 0.1)'" onblur="this.style.borderColor='#E5E5E5'; this.style.boxShadow='none'"></textarea>
                            </div>
                            
                            <div style="margin-bottom: 24px;">
                                <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #000; font-size: 14px;">Funding Goal</label>
                                <input type="number" id="fundingGoal" placeholder="${minPrice}" min="${minPrice}" max="10000" step="1" value="${minPrice}" required style="width: 100%; padding: 12px 16px; border: 2px solid #E5E5E5; border-radius: 12px; font-size: 15px; transition: all 0.2s; outline: none; background: white; font-family: 'Inter', sans-serif;" onfocus="this.style.borderColor='#FF006B'; this.style.boxShadow='0 0 0 4px rgba(255, 0, 107, 0.1)'" onblur="this.style.borderColor='#E5E5E5'; this.style.boxShadow='none'">
                                <div style="font-size: 13px; color: #666; margin-top: 8px;">Minimum: $${minPrice}</div>
                            </div>
                            
                            <button type="submit" id="createTopicButton" style="width: 100%; background: #FF006B; color: white; padding: 14px; border: none; border-radius: 50px; font-size: 16px; font-weight: 700; cursor: pointer; transition: all 0.2s;" onmouseover="this.style.background='#E6005F'; this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 16px rgba(255, 0, 107, 0.3)'" onmouseout="this.style.background='#FF006B'; this.style.transform='translateY(0)'; this.style.boxShadow='none'">Create Topic</button>
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
        
        function requestPayout() {
            const availableBalance = <?php echo $creator->available_balance ?? 0; ?>;
            
            if (availableBalance < 15) {
                alert('Minimum payout amount is $15. Your current available balance is $' + availableBalance.toFixed(2));
                return;
            }
            
            if (confirm('Request payout of $' + availableBalance.toFixed(2) + '?')) {
                alert('Payout request feature coming soon!');
            }
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
                <div id="uploadModal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; display: flex; align-items: center; justify-content: center; padding: 20px; backdrop-filter: blur(4px);" onclick="closeUploadModal(event)">
                    <div style="background: white; border-radius: 20px; max-width: 540px; width: 100%; padding: 40px; position: relative; box-shadow: 0 20px 50px rgba(0,0,0,0.2);" onclick="event.stopPropagation()">
                        <button onclick="closeUploadModal()" style="position: absolute; top: 20px; right: 20px; background: transparent; border: none; width: 32px; height: 32px; font-size: 28px; cursor: pointer; color: #666; padding: 0; line-height: 1; font-weight: 300;" onmouseover="this.style.color='#000'" onmouseout="this.style.color='#666'">×</button>
                        
                        <h2 style="font-family: 'Inter', sans-serif; margin: 0 0 8px 0; font-size: 24px; color: #000; font-weight: 700; padding-right: 30px;">Upload Content</h2>
                        <p style="color: #666; font-size: 15px; margin-bottom: 20px;">Paste your video link below.</p>
                        
                        <div style="background: #f8f9fa; border-radius: 10px; padding: 14px 16px; margin-bottom: 20px; display: flex; gap: 16px;">
                            <div style="flex: 1; text-align: center;">
                                <div style="font-size: 20px; margin-bottom: 4px;">▶️</div>
                                <div style="font-size: 11px; color: #666; font-weight: 600;">YouTube</div>
                            </div>
                            <div style="flex: 1; text-align: center;">
                                <div style="font-size: 20px; margin-bottom: 4px;">📸</div>
                                <div style="font-size: 11px; color: #666; font-weight: 600;">Instagram</div>
                            </div>
                            <div style="flex: 1; text-align: center;">
                                <div style="font-size: 20px; margin-bottom: 4px;">🎵</div>
                                <div style="font-size: 11px; color: #666; font-weight: 600;">TikTok</div>
                            </div>
                        </div>
                        
                        <div id="uploadError" style="display: none; color: #DC2626; background: #FEF2F2; border-left: 4px solid #DC2626; padding: 12px 16px; border-radius: 10px; margin-bottom: 16px; font-size: 13px; font-weight: 500;"></div>
                        
                        <input type="text" id="uploadUrl" placeholder="Paste your video URL here..." autofocus style="width: 100%; padding: 12px 16px; border: 2px solid #E5E5E5; border-radius: 12px; font-size: 15px; outline: none; font-family: 'Inter', sans-serif; margin-bottom: 16px;" onfocus="this.style.borderColor='#FF006B'; this.style.boxShadow='0 0 0 4px rgba(255, 0, 107, 0.1)'" onblur="this.style.borderColor='#E5E5E5'; this.style.boxShadow='none'" onkeydown="if(event.key==='Enter'){event.preventDefault(); submitUpload(${id});}">
                        
                        <button id="uploadButton" onclick="submitUpload(${id})" style="width: 100%; background: #FF006B; color: white; padding: 14px; border: none; border-radius: 50px; font-size: 16px; font-weight: 700; cursor: pointer;" onmouseover="this.style.background='#E6005F'" onmouseout="this.style.background='#FF006B'">Upload</button>
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
        
        function resumeTopic(id) {
            event.stopPropagation();
            if (confirm('Resume this topic? The 48-hour deadline will restart.')) {
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
