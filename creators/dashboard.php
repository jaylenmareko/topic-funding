<?php
// creators/dashboard.php - EXACT RIZZDEM LAYOUT
session_start();
require_once '../config/database.php';

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

$current_script = basename($_SERVER['PHP_SELF']);
$allowed_pages = ['dashboard.php', 'edit.php'];
if (!in_array($current_script, $allowed_pages)) {
    header('Location: /creators/dashboard.php');
    exit;
}

function validateContentUrl($url, $creator) {
    $errors = [];
    switch ($creator->platform_type) {
        case 'youtube':
            if (!(stripos($url, 'youtube.com/watch') !== false || stripos($url, 'youtube.com/shorts') !== false || stripos($url, 'youtu.be/') !== false)) {
                $errors[] = "Must be a valid YouTube video or Shorts URL";
            }
            break;
        case 'instagram':
            if (!(stripos($url, 'instagram.com/reel') !== false || stripos($url, 'instagram.com/reels') !== false || stripos($url, 'instagram.com/p/') !== false)) {
                $errors[] = "Must be an Instagram Reel or Post URL";
            }
            break;
        case 'tiktok':
            if (!(stripos($url, 'tiktok.com/@') !== false && stripos($url, '/video/') !== false)) {
                $errors[] = "Must be a TikTok video URL";
            }
            break;
    }
    return $errors;
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
        $validation_errors = validateContentUrl($content_url, $creator);
        if (!empty($validation_errors)) {
            $upload_error = implode(". ", $validation_errors);
            $uploaded_topic_id = $topic_id;
        } else {
            $db->query('SELECT * FROM topics WHERE id = :topic_id AND creator_id = :creator_id AND status = "funded"');
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
                        $db->query('UPDATE topics SET content_url = :content_url, status = "completed", completed_at = NOW() WHERE id = :topic_id');
                        $db->bind(':content_url', $content_url);
                        $db->bind(':topic_id', $topic_id);
                        $db->execute();
                        
                        try {
                            if (file_exists('../config/notification_system.php')) {
                                require_once '../config/notification_system.php';
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

$db->query('
    SELECT t.*, 
           UNIX_TIMESTAMP(t.content_deadline) as deadline_timestamp,
           TIMESTAMPDIFF(SECOND, NOW(), t.content_deadline) as seconds_remaining,
           (t.funding_threshold * 0.9) as potential_earnings
    FROM topics t 
    WHERE t.creator_id = :creator_id 
    AND t.status IN ("active", "funded", "on_hold") 
    AND (t.content_url IS NULL OR t.content_url = "")
    AND (t.status != "funded" OR t.content_deadline IS NULL OR t.content_deadline >= NOW())
    ORDER BY 
        CASE WHEN t.status = "funded" THEN 1 WHEN t.status = "active" THEN 2 WHEN t.status = "on_hold" THEN 3 END, 
        potential_earnings DESC, t.funded_at ASC, t.created_at DESC
');
$db->bind(':creator_id', $creator->id);
$topics = $db->resultSet();
?>
<!DOCTYPE html>
<html>
<head>
    <title>My Topics - TopicLaunch</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f5f5f5;
            color: #000;
        }
        
        /* Top Navigation */
        .top-nav {
            background: white;
            border-bottom: 1px solid #e0e0e0;
            padding: 16px 40px;
        }
        
        .top-nav-content {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 40px;
        }
        
        .logo {
            font-size: 20px;
            font-weight: 700;
            color: #FF0000;
            text-decoration: none;
        }
        
        .top-nav-links {
            display: flex;
            gap: 32px;
            align-items: center;
        }
        
        .top-nav-links a {
            color: #666;
            text-decoration: none;
            font-size: 15px;
        }
        
        .top-nav-links a:hover {
            color: #FF0000;
        }
        
        .top-nav-right {
            display: flex;
            gap: 24px;
            align-items: center;
        }
        
        .inbox-link {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #333;
            font-size: 15px;
            font-weight: 500;
            cursor: default;
        }
        
        .signout-btn {
            padding: 8px 20px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            background: white;
            color: #333;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .signout-btn:hover {
            border-color: #999;
        }
        
        /* Main Container */
        .container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 40px 280px;
        }
        
        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 32px;
        }
        
        .page-title-section {
            flex: 1;
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
            color: #FF0000;
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
        
        /* Browse Button */
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
            margin-bottom: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s;
        }
        
        .browse-btn:hover {
            border-color: #FF0000;
            color: #FF0000;
        }
        
        /* Main Content Box */
        .content-box {
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 16px;
            padding: 40px;
            min-height: 500px;
        }
        
        /* Topic Grid */
        .topics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 16px;
        }
        
        /* Empty State */
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
            background: #FF0000;
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
            background: #CC0000;
        }
        
        /* Topic Tile */
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
            border-color: #FF0000;
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
            background: #FF0000;
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
            background: #FF0000;
            color: white;
            border-color: #FF0000;
        }
        
        .tile-btn.primary:hover {
            background: #CC0000;
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
        
        @media (max-width: 768px) {
            .container { padding: 20px 30px; }
            .page-header { flex-direction: column; gap: 20px; }
            .header-buttons { width: 100%; }
            .btn { flex: 1; justify-content: center; }
            .topics-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <!-- Top Navigation -->
    <nav class="top-nav">
        <div class="top-nav-content">
            <a href="/" class="logo">TopicLaunch</a>
            <div style="flex: 1;"></div>
            <div class="top-nav-right">
                <div class="inbox-link">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect>
                        <path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path>
                    </svg>
                    My Topics
                </div>
                <a href="../auth/logout.php" class="signout-btn">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"></path>
                    </svg>
                    Log Out
                </a>
            </div>
        </div>
    </nav>

    <!-- Main Container -->
    <div class="container">
        <!-- Page Header -->
        <div class="page-header">
            <div class="page-title-section">
                <h1 class="page-title">
                    <svg class="title-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect>
                        <path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path>
                    </svg>
                    My Topics
                </h1>
                <p class="page-subtitle">Welcome back, <?php echo htmlspecialchars($creator->display_name); ?> !</p>
            </div>
            <div class="header-buttons">
                <a href="edit.php?id=<?php echo $creator->id; ?>" class="btn">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M12 15a3 3 0 100-6 3 3 0 000 6z"></path>
                        <path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-2 2 2 2 0 01-2-2v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06a1.65 1.65 0 00.33-1.82 1.65 1.65 0 00-1.51-1H3a2 2 0 01-2-2 2 2 0 012-2h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 010-2.83 2 2 0 012.83 0l.06.06a1.65 1.65 0 001.82.33H9a1.65 1.65 0 001-1.51V3a2 2 0 012-2 2 2 0 012 2v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 0 2 2 0 010 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 012 2 2 2 0 01-2 2h-.09a1.65 1.65 0 00-1.51 1z"></path>
                    </svg>
                    Edit Profile
                </a>
                <a href="../auth/logout.php" class="btn">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"></path>
                    </svg>
                    Log Out
                </a>
            </div>
        </div>

        <!-- Browse/Copy Profile Button -->
        <button onclick="copyProfileLink()" class="browse-btn" id="copyBtn">
            🔗 Copy Profile Link
        </button>

        <!-- Main Content Box -->
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
        // Update countdown timers every second
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
        
        function copyTopicLink(id) {
            event.stopPropagation();
            const url = window.location.origin + '/<?php echo $creator->display_name; ?>?topic=' + id;
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
            const url = prompt('Enter your video URL:');
            if (url) {
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
            const reason = prompt('Reason for hold:', 'Working on other content');
            if (reason !== null) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'topic_actions.php';
                form.innerHTML = `<input type="hidden" name="action" value="hold"><input type="hidden" name="topic_id" value="${id}"><input type="hidden" name="hold_reason" value="${reason}">`;
                document.body.appendChild(form);
                form.submit();
            }
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
