<?php
// creators/dashboard.php - Creator dashboard with FIXED empty state and profile link
session_start();
require_once '../config/database.php';
require_once '../config/navigation.php';
require_once '../config/notification_system.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$db = new Database();

// Get creator info
$db->query('SELECT c.*, u.email FROM creators c LEFT JOIN users u ON c.applicant_user_id = u.id WHERE c.applicant_user_id = :user_id AND c.is_active = 1');
$db->bind(':user_id', $_SESSION['user_id']);
$creator = $db->single();

if (!$creator) {
    header('Location: ../creators/index.php');
    exit;
}

// Handle content upload
$upload_message = '';
$upload_error = '';

if ($_POST && isset($_POST['upload_content']) && isset($_POST['topic_id']) && isset($_POST['content_url'])) {
    $topic_id = (int)$_POST['topic_id'];
    $content_url = trim($_POST['content_url']);
    
    // Validation
    if (empty($content_url)) {
        $upload_error = "Content URL is required";
    } elseif (!filter_var($content_url, FILTER_VALIDATE_URL)) {
        $upload_error = "Please enter a valid URL";
    } else {
        // Verify topic belongs to this creator and is funded
        $db->query('SELECT * FROM topics WHERE id = :topic_id AND creator_id = :creator_id AND status = "funded"');
        $db->bind(':topic_id', $topic_id);
        $db->bind(':creator_id', $creator->id);
        $topic_check = $db->single();
        
        if (!$topic_check) {
            $upload_error = "Topic not found or not eligible for upload";
        } else {
            // Check if deadline has passed
            $deadline_passed = strtotime($topic_check->content_deadline) < time();
            
            if ($deadline_passed) {
                $upload_error = "Sorry, the 48-hour deadline has passed";
            } else {
                try {
                    $db->beginTransaction();
                    
                    // Update topic with content
                    $db->query('
                        UPDATE topics 
                        SET content_url = :content_url, 
                            status = "completed", 
                            completed_at = NOW()
                        WHERE id = :topic_id
                    ');
                    $db->bind(':content_url', $content_url);
                    $db->bind(':topic_id', $topic_id);
                    $db->execute();
                    
                    // Notify all contributors
                    $notificationSystem = new NotificationSystem();
                    $notificationSystem->sendContentDeliveredNotifications($topic_id, $content_url);
                    
                    $db->endTransaction();
                    
                    $upload_message = "✅ Content uploaded successfully! Contributors have been notified.";
                    
                } catch (Exception $e) {
                    $db->cancelTransaction();
                    $upload_error = "Failed to upload content. Please try again.";
                    error_log("Content upload error: " . $e->getMessage());
                }
            }
        }
    }
}

// Get topics with enhanced deadline calculation and proper ordering
$db->query('
    SELECT t.*, 
           UNIX_TIMESTAMP(t.content_deadline) as deadline_timestamp,
           TIMESTAMPDIFF(SECOND, NOW(), t.content_deadline) as seconds_remaining,
           TIMESTAMPDIFF(HOUR, t.funded_at, NOW()) as hours_since_funded,
           (48 - TIMESTAMPDIFF(HOUR, t.funded_at, NOW())) as hours_remaining,
           (t.current_funding * 0.9) as potential_earnings
    FROM topics t 
    WHERE t.creator_id = :creator_id 
    AND t.status IN ("active", "funded", "on_hold") 
    AND (t.content_url IS NULL OR t.content_url = "") 
    ORDER BY 
        CASE 
            WHEN t.status = "funded" THEN 1 
            WHEN t.status = "active" THEN 2 
            WHEN t.status = "on_hold" THEN 3 
        END, 
        potential_earnings DESC,
        t.funded_at ASC, 
        t.created_at DESC
');
$db->bind(':creator_id', $creator->id);
$topics = $db->resultSet();

$message = isset($_GET['success']) ? $_GET['success'] : '';
$error = isset($_GET['error']) ? $_GET['error'] : '';
?>
<!DOCTYPE html>
<html>
<head>
    <title>Creator Dashboard - TopicLaunch</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; background: white; color: #333; }
        
        .header { background: white; padding: 40px 30px; text-align: center; border-bottom: 1px solid #f0f0f0; }
        .title { font-size: 36px; font-weight: 700; color: #333; }
        
        .message { background: #d4edda; color: #155724; padding: 15px 30px; text-align: center; }
        .error { background: #f8d7da; color: #721c24; padding: 15px 30px; text-align: center; }
        
        .upload-message { background: #d4edda; color: #155724; padding: 10px 15px; margin: 10px 0; border-radius: 6px; font-size: 14px; }
        .upload-error { background: #f8d7da; color: #721c24; padding: 10px 15px; margin: 10px 0; border-radius: 6px; font-size: 14px; }
        
        .container { padding: 40px 30px; max-width: 500px; margin: 0 auto; position: relative; }
        .swipe-area { position: relative; height: 450px; }
        
        .card {
            position: absolute; top: 0; left: 0; width: 100%; height: 100%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 20px; padding: 30px; color: white;
            display: flex; flex-direction: column;
            cursor: grab; transition: all 0.3s ease; 
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .card.empty {
            background: linear-gradient(135deg, #e9ecef 0%, #dee2e6 100%);
            color: #6c757d; cursor: default;
        }
        
        .card:hover { 
            transform: translateY(-5px) scale(1.02); 
            box-shadow: 0 15px 40px rgba(0,0,0,0.15); 
        }
        .card.empty:hover { 
            transform: none; 
            box-shadow: 0 10px 30px rgba(0,0,0,0.1); 
        }
        
        .status {
            background: rgba(255,255,255,0.2); 
            padding: 8px 16px; 
            border-radius: 20px;
            font-size: 14px; 
            font-weight: 500; 
            align-self: flex-start; 
            margin-bottom: 20px;
        }
        .status.funded { 
            background: rgba(40, 167, 69, 0.3); 
            animation: pulse 2s infinite; 
        }
        
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(40, 167, 69, 0.7); }
            70% { box-shadow: 0 0 0 10px rgba(40, 167, 69, 0); }
            100% { box-shadow: 0 0 0 0 rgba(40, 167, 69, 0); }
        }
        
        .card-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            text-align: center;
        }
        
        .click-hint {
            cursor: pointer;
            opacity: 0.7;
            font-size: 12px;
            margin-bottom: 10px;
            transition: opacity 0.3s ease;
        }
        .click-hint:hover { opacity: 1; }
        
        .topic-title {
            font-size: 24px; 
            font-weight: 600; 
            margin-bottom: 25px; 
            line-height: 1.3;
            cursor: pointer; 
            min-height: 60px; 
            display: flex; 
            align-items: center;
            justify-content: center; 
            text-align: center; 
            transition: all 0.3s ease;
        }
        
        .countdown-timer {
            font-family: 'Courier New', monospace;
            font-weight: bold;
            color: inherit;
        }
        
        .earning-display {
            background: rgba(255,255,255,0.05);
            border: 2px dashed rgba(255,255,255,0.2);
            color: rgba(255,255,255,0.5);
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 20px;
            transition: all 0.3s ease;
        }
        
        .earning-display.funded {
            background: rgba(40, 167, 69, 0.9);
            border: 2px solid rgba(40, 167, 69, 1);
            color: white;
            cursor: pointer;
            animation: glow 2s infinite;
        }
        
        .earning-display.funded:hover {
            background: rgba(40, 167, 69, 1);
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
        }
        
        @keyframes glow {
            0% { box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
            50% { box-shadow: 0 4px 20px rgba(40, 167, 69, 0.4); }
            100% { box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        }
        
        .earning-amount { 
            font-size: 28px; 
            font-weight: 700; 
            margin-bottom: 5px; 
        }
        
        .earning-text { 
            font-size: 14px; 
            font-weight: 500;
        }
        
        .upload-form {
            display: none;
            background: rgba(255,255,255,0.1);
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 20px;
        }
        
        .upload-form.active {
            display: block;
        }
        
        .upload-form input[type="url"] {
            width: 100%;
            padding: 12px;
            border: 2px solid rgba(255,255,255,0.3);
            border-radius: 8px;
            background: rgba(255,255,255,0.1);
            color: white;
            font-size: 16px;
            margin-bottom: 15px;
        }
        
        .upload-form input[type="url"]::placeholder {
            color: rgba(255,255,255,0.7);
        }
        
        .upload-form input[type="url"]:focus {
            outline: none;
            border-color: rgba(255,255,255,0.8);
            background: rgba(255,255,255,0.2);
        }
        
        .upload-buttons {
            display: flex;
            gap: 10px;
        }
        
        .upload-btn {
            flex: 1;
            padding: 10px 15px;
            border: none;
            border-radius: 8px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .upload-btn.submit {
            background: #28a745;
            color: white;
        }
        
        .upload-btn.submit:hover {
            background: #218838;
        }
        
        .upload-btn.cancel {
            background: rgba(255,255,255,0.2);
            color: white;
            border: 1px solid rgba(255,255,255,0.3);
        }
        
        .upload-btn.cancel:hover {
            background: rgba(255,255,255,0.3);
        }
        
        .topic-actions {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-bottom: 25px;
        }
        
        .action-btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s ease;
            background: rgba(220, 53, 69, 0.9);
            color: white;
        }
        .action-btn:hover {
            background: rgba(220, 53, 69, 1);
            transform: translateY(-1px);
        }
        .action-btn.hold {
            background: rgba(255, 193, 7, 0.9);
            color: #000;
        }
        .action-btn.hold:hover {
            background: rgba(255, 193, 7, 1);
        }
        .action-btn.resume {
            background: rgba(40, 167, 69, 0.9);
            color: white;
        }
        .action-btn.resume:hover {
            background: rgba(40, 167, 69, 1);
        }
        
        .funding-progress {
            margin-top: auto;
            background: rgba(255,255,255,0.1);
            padding: 15px;
            border-radius: 10px;
        }
        
        .funding-display {
            color: rgba(255,255,255,0.9); 
            font-size: 14px; 
            font-weight: 600;
            margin-bottom: 8px; 
            text-align: center;
        }
        
        .progress-bar {
            height: 6px; 
            background: rgba(255,255,255,0.3); 
            border-radius: 3px; 
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%; 
            background: rgba(255,255,255,0.8); 
            border-radius: 3px;
            transition: width 0.5s ease;
        }
        
        .nav-btn {
            position: absolute; 
            top: 50%; 
            transform: translateY(-50%);
            width: 50px; 
            height: 70px; 
            background: rgba(0,0,0,0.3);
            border: none; 
            border-radius: 12px; 
            color: white;
            font-size: 24px; 
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        .nav-btn:hover { 
            background: rgba(0,0,0,0.5); 
            transform: translateY(-50%) scale(1.1);
            box-shadow: 0 6px 16px rgba(0,0,0,0.3);
        }
        .nav-btn.left { left: -70px; }
        .nav-btn.right { right: -70px; }
        
        .mobile-menu-btn {
            position: fixed;
            top: 20px;
            right: 20px;
            background: rgba(102, 126, 234, 0.9);
            color: white;
            padding: 12px;
            border: none;
            border-radius: 8px;
            font-size: 18px;
            cursor: pointer;
            z-index: 1001;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            display: none;
        }
        
        .mobile-menu {
            position: fixed;
            top: 0;
            right: -300px;
            width: 300px;
            height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            transition: right 0.3s ease;
            z-index: 1000;
            padding: 80px 20px 20px 20px;
            box-shadow: -5px 0 15px rgba(0,0,0,0.3);
        }
        
        .mobile-menu.open { right: 0; }
        
        .mobile-menu-item {
            display: block;
            color: white;
            text-decoration: none;
            padding: 15px 20px;
            margin-bottom: 10px;
            background: rgba(255,255,255,0.1);
            border-radius: 10px;
            font-weight: bold;
            transition: all 0.3s ease;
        }
        
        .mobile-menu-item:hover {
            background: rgba(255,255,255,0.2);
            color: white;
            text-decoration: none;
            transform: translateX(5px);
        }
        
        .mobile-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(0,0,0,0.5);
            z-index: 999;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }
        
        .mobile-overlay.open {
            opacity: 1;
            visibility: visible;
        }
        
        /* FIXED: Copy Profile Link Button */
        .copy-profile-btn {
            background: #28a745;
            color: white;
            padding: 12px 20px;
            border: 1px solid #28a745;
            border-radius: 8px;
            cursor: pointer;
            font-weight: bold;
            font-size: 16px;
            transition: all 0.3s ease;
            margin-bottom: 20px;
            display: inline-block;
            width: auto;
            min-width: 180px;
        }
        
        .copy-profile-btn:hover {
            background: #218838;
            border-color: #1e7e34;
        }
        
        .copy-profile-btn.copied {
            background: #20c997;
            border-color: #1abc9c;
        }
        
        @media (max-width: 768px) {
            .header { padding: 30px 20px; }
            .title { font-size: 28px; }
            .container { padding: 30px 20px; }
            .swipe-area { height: 420px; }
            .card { padding: 25px; }
            .topic-title { font-size: 20px; min-height: 45px; }
            .nav-btn { 
                width: 45px; 
                height: 60px; 
                font-size: 20px;
                background: rgba(0,0,0,0.4);
            }
            .nav-btn.left { left: 15px; }
            .nav-btn.right { right: 15px; }
            .mobile-menu-btn { display: block; }
            .upload-buttons { flex-direction: column; }
        }
        
        .topiclaunch-nav .nav-mobile-toggle { display: none !important; }
    </style>
</head>
<body>
    <?php renderNavigation('dashboard'); ?>

    <?php if ($message): ?>
        <div class="message"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="header">
        <h1 class="title">Make Videos, Get Paid</h1>
    </div>

    <div class="container">
        <div class="swipe-area" id="swipeArea">
            <?php if (!empty($topics)): ?>
                <button class="nav-btn left" onclick="swipeLeft()">‹</button>
                <button class="nav-btn right" onclick="swipeRight()">›</button>
            <?php endif; ?>
            
            <?php if (!empty($topics)): ?>
                <?php foreach ($topics as $index => $topic): ?>
                    <div class="card" data-topic="<?php echo $topic->id; ?>" data-index="<?php echo $index; ?>">
                        
                        <?php if ($topic->status === 'funded'): ?>
                            <div class="status funded">
                                Funded<?php if ($topic->hours_remaining > 0): ?> - <span class="countdown-timer" 
                                 data-deadline="<?php echo $topic->deadline_timestamp; ?>"
                                 id="countdown-<?php echo $topic->id; ?>">00:00:00</span> left<?php endif; ?>
                            </div>
                        <?php elseif ($topic->status === 'on_hold'): ?>
                            <div class="status">⏸️ On Hold</div>
                        <?php endif; ?>
                        
                        <div class="card-content">
                            <div class="click-hint" onclick="toggle(<?php echo $topic->id; ?>)">Tap for details</div>
                            <h3 class="topic-title" onclick="toggle(<?php echo $topic->id; ?>)" id="title-<?php echo $topic->id; ?>">
                                <?php echo htmlspecialchars($topic->title); ?>
                            </h3>
                            
                            <div id="upload-messages-<?php echo $topic->id; ?>"></div>
                            
                            <?php if ($topic->status === 'funded'): ?>
                                <div class="earning-display funded" 
                                     onclick="showUploadForm(<?php echo $topic->id; ?>)"
                                     id="earning-display-<?php echo $topic->id; ?>">
                                    <div class="earning-amount">$<?php echo number_format($topic->current_funding * 0.9, 0); ?></div>
                                    <div class="earning-text">Upload & Get Paid</div>
                                </div>
                                
                                <form class="upload-form" id="upload-form-<?php echo $topic->id; ?>" method="POST">
                                    <input type="hidden" name="topic_id" value="<?php echo $topic->id; ?>">
                                    <input type="url" 
                                           name="content_url" 
                                           placeholder="https://youtube.com/watch?v=..." 
                                           required>
                                    <div class="upload-buttons">
                                        <button type="submit" name="upload_content" class="upload-btn submit">
                                            🎬 Complete Topic
                                        </button>
                                        <button type="button" onclick="hideUploadForm(<?php echo $topic->id; ?>)" class="upload-btn cancel">
                                            Cancel
                                        </button>
                                    </div>
                                </form>
                            <?php else: ?>
                                <div class="earning-display">
                                    <div class="earning-amount">$<?php echo number_format($topic->current_funding * 0.9, 0); ?></div>
                                    <div class="earning-text">Potential Earnings</div>
                                </div>
                            <?php endif; ?>
                            
                            <div class="topic-actions">
                                <?php if ($topic->status === 'active'): ?>
                                    <button onclick="declineTopic(<?php echo $topic->id; ?>)" class="action-btn">
                                        Decline
                                    </button>
                                <?php elseif ($topic->status === 'funded'): ?>
                                    <button onclick="declineTopic(<?php echo $topic->id; ?>)" class="action-btn">
                                        Decline
                                    </button>
                                    <button onclick="holdTopic(<?php echo $topic->id; ?>)" class="action-btn hold">
                                        Hold
                                    </button>
                                <?php elseif ($topic->status === 'on_hold'): ?>
                                    <button onclick="declineTopic(<?php echo $topic->id; ?>)" class="action-btn">
                                        Decline
                                    </button>
                                    <button onclick="resumeTopic(<?php echo $topic->id; ?>)" class="action-btn resume">
                                        ▶️ Resume
                                    </button>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($topic->status === 'active'): ?>
                            <div class="funding-progress">
                                <div class="funding-display">
                                    $<?php echo number_format($topic->current_funding, 0); ?> / $<?php echo number_format($topic->funding_threshold, 0); ?>
                                </div>
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: <?php echo min(100, ($topic->current_funding / $topic->funding_threshold) * 100); ?>%"></div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <!-- FIXED: Empty state with profile link and better styling -->
                <div class="card empty">
                    <div class="card-content">
                        <h3 class="topic-title">No Active Topics</h3>
                        <div style="font-size: 16px; opacity: 0.7; margin: 20px 0;">
                            Fans haven't created any topics for you yet. Share your profile to get started!
                        </div>
                        
                        <button onclick="copyProfileLink()" class="copy-profile-btn" id="copyLinkBtn">
                            Copy Profile Link
                        </button>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <button class="mobile-menu-btn" onclick="toggleMobileMenu()">☰</button>
    
    <div class="mobile-overlay" onclick="closeMobileMenu()"></div>
    
    <div class="mobile-menu" id="mobileMenu">
        <a href="edit.php?id=<?php echo $creator->id; ?>" class="mobile-menu-item">
            ✏️ Edit Profile
        </a>
        <a href="../auth/logout.php" class="mobile-menu-item">
            🚪 Logout
        </a>
    </div>

    <script>
        const topics = {<?php foreach ($topics as $t): ?>'<?php echo $t->id; ?>': {title: <?php echo json_encode($t->title); ?>, desc: <?php echo json_encode($t->description); ?>, showing: 'title'},<?php endforeach; ?>};
        let currentIndex = 0;
        const totalTopics = <?php echo count($topics); ?>;
        const cards = Array.from(document.querySelectorAll('.card'));

        function initializeCards() {
            if (totalTopics === 0) {
                // For empty state, just show the single card
                const emptyCard = document.querySelector('.card.empty');
                if (emptyCard) {
                    emptyCard.style.transform = 'scale(1) translateY(0px)';
                    emptyCard.style.zIndex = '100';
                    emptyCard.style.opacity = '1';
                    emptyCard.style.pointerEvents = 'auto';
                    emptyCard.style.display = 'block';
                }
                return;
            }
            
            cards.forEach((card, index) => {
                updateCardPosition(card, index);
            });
        }

        function updateCardPosition(card, cardIndex) {
            if (totalTopics === 0) return; // Don't process if no topics
            
            const relativeIndex = (cardIndex - currentIndex + totalTopics) % totalTopics;
            
            if (relativeIndex === 0) {
                card.style.transform = 'scale(1) translateY(0px)';
                card.style.zIndex = '100';
                card.style.opacity = '1';
                card.style.pointerEvents = 'auto';
                card.style.display = 'block';
            } else if (relativeIndex === 1) {
                card.style.transform = 'scale(0.95) translateY(10px)';
                card.style.zIndex = '99';
                card.style.opacity = '0.8';
                card.style.pointerEvents = 'none';
                card.style.display = 'block';
            } else if (relativeIndex === 2 && totalTopics > 2) {
                card.style.transform = 'scale(0.9) translateY(20px)';
                card.style.zIndex = '98';
                card.style.opacity = '0.6';
                card.style.pointerEvents = 'none';
                card.style.display = 'block';
            } else {
                card.style.display = 'none';
            }
        }

        function getCurrentCard() {
            if (totalTopics === 0) {
                return document.querySelector('.card.empty');
            }
            return cards[currentIndex];
        }

        function swipeLeft() {
            if (totalTopics <= 1) return;
            
            const currentCard = getCurrentCard();
            currentCard.style.transition = 'transform 0.3s ease, opacity 0.3s ease';
            currentCard.style.transform = 'translateX(-150%) rotate(-30deg)';
            currentCard.style.opacity = '0';
            
            setTimeout(() => {
                currentIndex = (currentIndex + 1) % totalTopics;
                currentCard.style.transition = '';
                currentCard.style.transform = '';
                currentCard.style.opacity = '1';
                
                cards.forEach((card, index) => {
                    updateCardPosition(card, index);
                });
            }, 300);
        }

        function swipeRight() {
            if (totalTopics <= 1) return;
            
            const currentCard = getCurrentCard();
            currentCard.style.transition = 'transform 0.3s ease, opacity 0.3s ease';
            currentCard.style.transform = 'translateX(150%) rotate(30deg)';
            currentCard.style.opacity = '0';
            
            setTimeout(() => {
                currentIndex = (currentIndex - 1 + totalTopics) % totalTopics;
                currentCard.style.transition = '';
                currentCard.style.transform = '';
                currentCard.style.opacity = '1';
                
                cards.forEach((card, index) => {
                    updateCardPosition(card, index);
                });
            }, 300);
        }

        function updateNavButtons() {
            const leftBtn = document.querySelector('.nav-btn.left');
            const rightBtn = document.querySelector('.nav-btn.right');
            
            if (leftBtn && rightBtn) {
                if (totalTopics > 1) {
                    leftBtn.style.display = 'flex';
                    rightBtn.style.display = 'flex';
                } else {
                    leftBtn.style.display = 'none';
                    rightBtn.style.display = 'none';
                }
            }
        }

        // Touch system
        let startX = 0, currentX = 0, isDragging = false;
        const swipeArea = document.getElementById('swipeArea');

        if (swipeArea && totalTopics > 0) {
            swipeArea.addEventListener('touchstart', (e) => {
                if (totalTopics <= 1) return;
                
                const touch = e.touches[0];
                const currentCard = getCurrentCard();
                if (!currentCard) return;
                
                const rect = currentCard.getBoundingClientRect();
                
                if (touch.clientX >= rect.left && touch.clientX <= rect.right &&
                    touch.clientY >= rect.top && touch.clientY <= rect.bottom) {
                    
                    if (e.target.closest('.earning-display, .topic-title, .action-btn, .click-hint, .upload-form')) {
                        return;
                    }
                    
                    startX = touch.clientX;
                    isDragging = true;
                    currentCard.style.transition = '';
                    e.preventDefault();
                }
            }, {passive: false});
            
            swipeArea.addEventListener('touchmove', (e) => {
                if (!isDragging || totalTopics <= 1) return;
                
                currentX = e.touches[0].clientX;
                const deltaX = currentX - startX;
                const currentCard = getCurrentCard();
                if (!currentCard) return;
                
                if (Math.abs(deltaX) > 5) {
                    currentCard.style.transform = `translateX(${deltaX}px) rotate(${deltaX * 0.1}deg)`;
                    currentCard.style.opacity = Math.max(0.3, 1 - Math.abs(deltaX) / 200);
                }
                
                e.preventDefault();
            }, {passive: false});
            
            swipeArea.addEventListener('touchend', () => {
                if (!isDragging || totalTopics <= 1) return;
                
                isDragging = false;
                const deltaX = currentX - startX;
                const currentCard = getCurrentCard();
                if (!currentCard) return;
                
                if (Math.abs(deltaX) > 100) {
                    if (deltaX > 0) {
                        swipeRight();
                    } else {
                        swipeLeft();
                    }
                } else {
                    currentCard.style.transition = 'transform 0.3s ease, opacity 0.3s ease';
                    currentCard.style.transform = 'scale(1) translateY(0px)';
                    currentCard.style.opacity = '1';
                    
                    setTimeout(() => {
                        currentCard.style.transition = '';
                    }, 300);
                }
            });
        }

        // FIXED: Copy profile link function
        function copyProfileLink() {
            const profileUrl = window.location.origin + '/creators/profile.php?id=<?php echo $creator->id; ?>';
            
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(profileUrl).then(() => {
                    showCopyFeedback();
                }).catch(() => {
                    fallbackCopyText(profileUrl);
                });
            } else {
                fallbackCopyText(profileUrl);
            }
        }

        function fallbackCopyText(text) {
            const textArea = document.createElement('textarea');
            textArea.value = text;
            textArea.style.position = 'fixed';
            textArea.style.left = '-999999px';
            textArea.style.top = '-999999px';
            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();
            
            try {
                document.execCommand('copy');
                showCopyFeedback();
            } catch (err) {
                console.error('Failed to copy');
                alert('Could not copy link. Please copy manually: ' + text);
            }
            
            document.body.removeChild(textArea);
        }

        function showCopyFeedback() {
            const btn = document.getElementById('copyLinkBtn');
            if (btn) {
                const originalText = btn.textContent;
                btn.textContent = '✅ Copied!';
                btn.classList.add('copied');
                
                setTimeout(() => {
                    btn.textContent = originalText;
                    btn.classList.remove('copied');
                }, 2000);
            }
        }

        <?php if ($upload_message): ?>
        showUploadMessage(<?php echo $_POST['topic_id'] ?? 0; ?>, '<?php echo addslashes($upload_message); ?>', 'success');
        <?php endif; ?>
        
        <?php if ($upload_error): ?>
        showUploadMessage(<?php echo $_POST['topic_id'] ?? 0; ?>, '<?php echo addslashes($upload_error); ?>', 'error');
        <?php endif; ?>

        function showUploadMessage(topicId, message, type) {
            const messageContainer = document.getElementById(`upload-messages-${topicId}`);
            if (messageContainer) {
                messageContainer.innerHTML = `<div class="upload-${type}">${message}</div>`;
                setTimeout(() => {
                    messageContainer.innerHTML = '';
                }, 5000);
            }
        }

        function updateCountdowns() {
            const countdownElements = document.querySelectorAll('.countdown-timer[data-deadline]');
            
            countdownElements.forEach(element => {
                const deadline = parseInt(element.getAttribute('data-deadline')) * 1000;
                const now = new Date().getTime();
                const timeLeft = deadline - now;
                
                if (timeLeft > 0) {
                    const hours = Math.floor(timeLeft / (1000 * 60 * 60));
                    const minutes = Math.floor((timeLeft % (1000 * 60 * 60)) / (1000 * 60));
                    const seconds = Math.floor((timeLeft % (1000 * 60)) / 1000);
                    
                    const formattedTime = 
                        String(hours).padStart(2, '0') + ':' + 
                        String(minutes).padStart(2, '0') + ':' + 
                        String(seconds).padStart(2, '0');
                    
                    element.textContent = formattedTime;
                    
                    element.classList.remove('expired', 'warning', 'safe');
                    
                    if (hours <= 1) {
                        element.classList.add('expired');
                    } else if (hours <= 6) {
                        element.classList.add('warning');
                    } else {
                        element.classList.add('safe');
                    }
                } else {
                    element.textContent = 'EXPIRED';
                    element.classList.remove('warning', 'safe');
                    element.classList.add('expired');
                }
            });
        }

        function showUploadForm(topicId) {
            const currentCard = getCurrentCard();
            const currentTopicId = currentCard.getAttribute('data-topic');
            
            if (currentTopicId == topicId) {
                document.getElementById(`earning-display-${topicId}`).style.display = 'none';
                document.getElementById(`upload-form-${topicId}`).classList.add('active');
            }
        }

        function hideUploadForm(topicId) {
            document.getElementById(`earning-display-${topicId}`).style.display = 'block';
            document.getElementById(`upload-form-${topicId}`).classList.remove('active');
        }

        function toggle(id) {
            const topic = topics[id];
            const el = document.getElementById(`title-${id}`);
            if (!topic || !el) return;
            
            if (topic.showing === 'title') {
                el.textContent = topic.desc;
                el.style.opacity = '0.8';
                el.style.fontSize = '18px';
                topic.showing = 'desc';
            } else {
                el.textContent = topic.title;
                el.style.opacity = '1';
                el.style.fontSize = '24px';
                topic.showing = 'title';
            }
        }

        function declineTopic(topicId) {
            if (confirm('Are you sure you want to decline this topic? All contributors will be fully refunded.')) {
                submitTopicAction(topicId, 'decline');
            }
        }

        function holdTopic(topicId) {
            const reason = prompt('Reason for putting topic on hold (optional):', 'Working on other content first');
            if (reason !== null) {
                submitTopicAction(topicId, 'hold', reason);
            }
        }

        function resumeTopic(topicId) {
            if (confirm('Resume this topic? The 48-hour deadline will restart.')) {
                submitTopicAction(topicId, 'resume');
            }
        }

        function submitTopicAction(topicId, action, reason = '') {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'topic_actions.php';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = action;
            
            const topicInput = document.createElement('input');
            topicInput.type = 'hidden';
            topicInput.name = 'topic_id';
            topicInput.value = topicId;
            
            if (reason) {
                const reasonInput = document.createElement('input');
                reasonInput.type = 'hidden';
                reasonInput.name = 'hold_reason';
                reasonInput.value = reason;
                form.appendChild(reasonInput);
            }
            
            form.appendChild(actionInput);
            form.appendChild(topicInput);
            document.body.appendChild(form);
            form.submit();
        }

        function toggleMobileMenu() {
            const menu = document.getElementById('mobileMenu');
            const overlay = document.querySelector('.mobile-overlay');
            menu.classList.toggle('open');
            overlay.classList.toggle('open');
        }
        
        function closeMobileMenu() {
            const menu = document.getElementById('mobileMenu');
            const overlay = document.querySelector('.mobile-overlay');
            menu.classList.remove('open');
            overlay.classList.remove('open');
        }

        document.addEventListener('click', function(e) {
            if (e.target.closest('.action-btn, .upload-form, .earning-display.funded')) {
                e.stopPropagation();
            }
        });

        document.querySelectorAll('form.upload-form').forEach(form => {
            form.addEventListener('submit', function(e) {
                const submitBtn = this.querySelector('.upload-btn.submit');
                const urlInput = this.querySelector('input[type="url"]');
                
                if (!urlInput.value.trim()) {
                    e.preventDefault();
                    alert('Please enter a content URL');
                    return;
                }
                
                if (!confirm('Upload this content and mark topic as completed?')) {
                    e.preventDefault();
                    return;
                }
                
                submitBtn.innerHTML = '⏳ Uploading...';
                submitBtn.disabled = true;
            });
        });

        document.addEventListener('DOMContentLoaded', function() {
            initializeCards();
            updateNavButtons();
            updateCountdowns();
            setInterval(updateCountdowns, 1000);
        });
    </script>
</body>
</html>
