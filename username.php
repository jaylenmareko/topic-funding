<?php
// username.php - Vanity URL handler
session_start();

$username = isset($_GET['username']) ? trim($_GET['username']) : '';
$topic_num = isset($_GET['topic_num']) ? intval($_GET['topic_num']) : 0;

if (empty($username)) {
    header('Location: /');
    exit;
}

// Load database
if (file_exists('config/database.php')) {
    require_once 'config/database.php';
} elseif (file_exists(__DIR__ . '/config/database.php')) {
    require_once __DIR__ . '/config/database.php';
} else {
    header('Location: /');
    exit;
}

// Redirect logged-in creators to dashboard
if (isset($_SESSION['user_id'])) {
    try {
        $db = new Database();
        $db->query('SELECT id FROM creators WHERE applicant_user_id = :user_id AND is_active = 1');
        $db->bind(':user_id', $_SESSION['user_id']);
        $is_creator = $db->single();
        
        if ($is_creator) {
            header('Location: /creators/dashboard.php');
            exit;
        }
    } catch (Exception $e) {
        error_log("Creator redirect check error: " . $e->getMessage());
    }
}

try {
    $db = new Database();
    
    // Look up creator by display_name
    $db->query('SELECT * FROM creators WHERE display_name = :username AND is_active = 1');
    $db->bind(':username', $username);
    $creator = $db->single();
    
    if (!$creator) {
        header('Location: /');
        exit;
    }
    
    // Creator found - display profile inline
    $creator_id = $creator->id;
    
    // Get creator's topics by status
    $db = new Database();

    // Active topics
    $db->query('SELECT * FROM topics WHERE creator_id = :creator_id AND status = "active" ORDER BY created_at DESC');
    $db->bind(':creator_id', $creator_id);
    $active_topics = $db->resultSet();
    
    // If topic_num is provided, convert it to topic_id
    $auto_open_topic_id = 0;
    if ($topic_num > 0 && $topic_num <= count($active_topics)) {
        $auto_open_topic_id = $active_topics[$topic_num - 1]->id;
    }

    // Waiting Upload topics
    $db->query('
        SELECT t.*, 
               UNIX_TIMESTAMP(t.content_deadline) as deadline_timestamp,
               TIMESTAMPDIFF(HOUR, t.funded_at, NOW()) as hours_since_funded,
               (48 - TIMESTAMPDIFF(HOUR, t.funded_at, NOW())) as hours_remaining,
               TIMESTAMPDIFF(HOUR, t.content_deadline, NOW()) as hours_past_deadline
        FROM topics t 
        WHERE t.creator_id = :creator_id 
        AND t.status = "funded" 
        AND (t.content_url IS NULL OR t.content_url = "")
        AND TIMESTAMPDIFF(HOUR, t.content_deadline, NOW()) <= 2
        ORDER BY t.funded_at ASC
    ');
    $db->bind(':creator_id', $creator_id);
    $waiting_upload_topics = $db->resultSet();

    // Completed topics
    $db->query('SELECT * FROM topics WHERE creator_id = :creator_id AND status = "completed" ORDER BY completed_at DESC');
    $db->bind(':creator_id', $creator_id);
    $completed_topics = $db->resultSet();
    
    // Now output the profile page HTML
    ?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo htmlspecialchars($creator->display_name); ?> - TopicLaunch</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700;800&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --hot-pink: #FF006B;
            --deep-pink: #E6005F;
            --black: #000000;
            --white: #FFFFFF;
            --gray-dark: #1A1A1A;
            --gray-med: #666666;
            --gray-light: #E5E5E5;
            --cream: #FAF8F6;
        }
        
        * { 
            margin: 0; 
            padding: 0; 
            box-sizing: border-box; 
        }
        
        body { 
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--cream);
        }
        
        /* Navigation */
        .topiclaunch-nav {
            background: var(--white);
            padding: 16px 0;
            border-bottom: 1px solid var(--gray-light);
            position: sticky;
            top: 0;
            z-index: 1000;
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
            font-family: 'Playfair Display', serif;
            font-size: 22px;
            font-weight: 700;
            text-decoration: none;
            letter-spacing: -0.5px;
        }
        
        .nav-logo .topic { color: var(--black); }
        .nav-logo .launch { color: var(--hot-pink); }
        
        .nav-center {
            display: flex;
            gap: 35px;
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
            gap: 20px;
            align-items: center;
        }
        
        .nav-login-btn {
            color: var(--gray-dark);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
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
            font-weight: 700;
            padding: 10px 24px;
            border-radius: 50px;
            transition: all 0.2s;
        }
        
        .nav-getstarted-btn:hover {
            background: var(--deep-pink);
            transform: translateY(-1px);
        }
        
        /* Container */
        .container { 
            max-width: 1400px; 
            margin: 0 auto; 
            padding: 50px 30px;
            display: grid;
            grid-template-columns: 380px 1fr;
            gap: 40px;
            align-items: start;
        }
        
        /* Profile Box */
        .profile-box {
            background: var(--white);
            border-radius: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            padding: 40px;
            position: sticky;
            top: 90px;
        }
        
        .profile-header {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            margin-bottom: 0;
        }
        
        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--hot-pink) 0%, var(--deep-pink) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-family: 'Playfair Display', serif;
            font-size: 48px;
            font-weight: 700;
            overflow: hidden;
            margin-bottom: 20px;
        }
        
        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .profile-name {
            font-family: 'Playfair Display', serif;
            font-size: 28px;
            font-weight: 700;
            color: var(--black);
            margin-bottom: 8px;
        }
        
        .profile-handle {
            font-size: 16px;
            color: var(--hot-pink);
            font-weight: 600;
            margin-bottom: 20px;
        }
        
        .profile-price {
            display: inline-flex;
            flex-direction: column;
            align-items: center;
            background: var(--cream);
            padding: 16px 24px;
            border-radius: 12px;
        }
        
        .profile-price-amount {
            font-family: 'Playfair Display', serif;
            font-size: 32px;
            font-weight: 700;
            color: var(--black);
        }
        
        .profile-price-label {
            font-size: 11px;
            color: var(--gray-med);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-top: 4px;
        }
        
        .profile-bio {
            text-align: left;
            font-size: 15px;
            color: var(--gray-dark);
            line-height: 1.6;
            margin-top: 24px;
            padding-top: 24px;
            border-top: 1px solid var(--gray-light);
        }
        
        /* Request Topic Box */
        .request-topic-box {
            background: var(--white);
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.06);
            padding: 32px;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 30px;
        }
        
        .request-content {
            flex: 1;
        }
        
        .request-title {
            font-family: 'Playfair Display', serif;
            font-size: 24px;
            font-weight: 700;
            color: var(--black);
            margin-bottom: 8px;
        }
        
        .request-text {
            font-size: 15px;
            color: var(--gray-med);
            line-height: 1.6;
        }
        
        .request-text strong {
            color: var(--black);
            font-weight: 600;
        }
        
        .request-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            background: var(--hot-pink);
            color: var(--white);
            padding: 14px 28px;
            text-decoration: none;
            border-radius: 50px;
            font-size: 15px;
            font-weight: 700;
            transition: all 0.2s;
            box-shadow: 0 4px 12px rgba(255, 0, 107, 0.25);
            white-space: nowrap;
            flex-shrink: 0;
        }
        
        .request-btn:hover {
            background: var(--deep-pink);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(255, 0, 107, 0.35);
        }
        
        .section { 
            padding: 0;
        }
        
        .section h2 { 
            font-family: 'Playfair Display', serif;
            margin-top: 0; 
            color: var(--black);
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 24px;
        }
        
        .topic-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); 
            gap: 24px; 
        }
        
        .topic-card { 
            background: var(--white);
            border: 1px solid var(--gray-light);
            padding: 24px; 
            border-radius: 16px;
            transition: all 0.2s;
            cursor: pointer;
        }
        
        .topic-card:hover { 
            transform: translateY(-4px);
            box-shadow: 0 8px 24px rgba(255, 0, 107, 0.12);
            border-color: var(--hot-pink);
        }
        
        .topic-title { 
            font-family: 'Playfair Display', serif;
            font-weight: 700;
            font-size: 20px;
            margin-bottom: 12px;
            color: var(--black);
        }
        
        .topic-description { 
            color: var(--gray-med);
            line-height: 1.6;
            margin-bottom: 20px;
            font-size: 14px;
        }
        
        .funding-bar { 
            background: var(--gray-light);
            height: 8px;
            border-radius: 4px;
            margin: 20px 0;
            overflow: hidden;
        }
        
        .funding-progress { 
            background: linear-gradient(90deg, var(--hot-pink), var(--deep-pink));
            height: 100%;
            border-radius: 4px;
            transition: width 0.3s;
        }
        
        .funding-info { 
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 16px;
        }
        
        .funding-amount { 
            font-weight: 700;
            color: var(--hot-pink);
            font-size: 16px;
        }
        
        .empty-state { 
            text-align: center;
            color: var(--gray-med);
            padding: 80px 30px;
            background: var(--white);
            border-radius: 16px;
        }
        
        .empty-state h3 {
            font-family: 'Playfair Display', serif;
            color: var(--black);
            margin-bottom: 12px;
            font-size: 22px;
            font-weight: 700;
        }
        
        .empty-state p {
            color: var(--gray-med);
            font-size: 15px;
        }
        
        @media (max-width: 768px) {
            .nav-center { 
                display: none; 
            }
            
            .container { 
                padding: 30px 20px;
                grid-template-columns: 1fr;
                gap: 30px;
            }
            
            .profile-box { 
                padding: 30px;
                position: static;
            }
            
            .topic-grid { 
                grid-template-columns: 1fr; 
            }
            
            /* Mobile: Show mobile-only box after profile */
            .request-topic-box-mobile {
                display: flex !important;
                flex-direction: column;
                align-items: flex-start;
                padding: 24px;
                margin-top: 30px;
            }
            
            /* Hide all other request boxes on mobile */
            .request-topic-box:not(.request-topic-box-mobile) {
                display: none !important;
            }
            
            .request-btn {
                width: 100%;
                justify-content: center;
                position: static !important;
            }
        }
        
        @media (min-width: 769px) {
            /* Desktop: Hide mobile box, show normal boxes */
            .request-topic-box-mobile {
                display: none !important;
            }
            
            .request-topic-box:first-of-type:not(.request-topic-box-empty-state) {
                display: none !important;
            }
            
            .request-topic-box-desktop {
                display: flex !important;
            }
        }
        
        @media (min-width: 769px) {
            .request-topic-box:first-of-type:not(.request-topic-box-empty-state) {
                display: none;
            }
            
            .request-topic-box-desktop {
                display: flex;
            }
        }
    </style>
</head>
<body>
    <nav class="topiclaunch-nav">
        <div class="nav-container">
            <a href="/" class="nav-logo">
                <span class="topic">Topic</span><span class="launch">Launch</span>
            </a>
            
            <div class="nav-center">
                <a href="/creators/" class="nav-link">Browse Creators</a>
                <a href="/creators/signup.php" class="nav-link">For Creators</a>
            </div>

            <div class="nav-buttons">
                <a href="/auth/login.php" class="nav-login-btn">Log In</a>
                <a href="/creators/signup.php" class="nav-getstarted-btn">Get Started</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="profile-box">
            <div class="profile-header">
                <div class="profile-avatar">
                    <?php if ($creator->profile_image && file_exists('uploads/creators/' . $creator->profile_image)): ?>
                        <img src="/uploads/creators/<?php echo htmlspecialchars($creator->profile_image); ?>" 
                             alt="<?php echo htmlspecialchars($creator->display_name); ?>">
                    <?php else: ?>
                        <?php echo strtoupper(substr($creator->display_name, 0, 1)); ?>
                    <?php endif; ?>
                </div>
                
                <div class="profile-name"><?php echo htmlspecialchars($creator->display_name); ?></div>
                <div class="profile-handle">@<?php echo htmlspecialchars($creator->display_name); ?></div>
                
                <div class="profile-price">
                    <span class="profile-price-amount">$<?php echo number_format($creator->minimum_topic_price ?? 100, 0); ?></span>
                    <span class="profile-price-label">per request</span>
                </div>
            </div>
            
            <?php if (!empty($creator->bio)): ?>
            <div class="profile-bio">
                <?php echo nl2br(htmlspecialchars($creator->bio)); ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Mobile-only request box -->
        <div class="request-topic-box request-topic-box-mobile" style="display: none;">
            <div class="request-content">
                <h3 class="request-title">Request Content</h3>
                <p class="request-text">Get specific content from <strong><?php echo htmlspecialchars($creator->display_name); ?></strong> for just <strong>$<?php echo number_format($creator->minimum_topic_price ?? 100, 0); ?></strong>.</p>
            </div>
            <a href="#" onclick="openCreateTopicModal(<?php echo $creator->id; ?>, <?php echo $creator->minimum_topic_price ?? 100; ?>); return false;" class="request-btn">
                Create Topic
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <line x1="12" y1="5" x2="12" y2="19"></line>
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
            </a>
        </div>

        <div>
            <div class="section">
            <h2>Active Topics</h2>
            <?php if (empty($active_topics)): ?>
                <div class="request-topic-box request-topic-box-empty-state">
                    <div class="request-content">
                        <h3 class="request-title">Request Content</h3>
                        <p class="request-text">Get specific content from <strong><?php echo htmlspecialchars($creator->display_name); ?></strong> for just <strong>$<?php echo number_format($creator->minimum_topic_price ?? 100, 0); ?></strong>.</p>
                    </div>
                    <a href="#" onclick="openCreateTopicModal(<?php echo $creator->id; ?>, <?php echo $creator->minimum_topic_price ?? 100; ?>); return false;" class="request-btn">
                        Create Topic
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                            <line x1="12" y1="5" x2="12" y2="19"></line>
                            <line x1="5" y1="12" x2="19" y2="12"></line>
                        </svg>
                    </a>
                </div>
                
                <div class="empty-state">
                    <h3>No Active Topics</h3>
                    <p>This creator doesn't have any topics seeking funding right now. Be the first to create one!</p>
                </div>
            <?php else: ?>
                <div class="request-topic-box">
                    <div class="request-content">
                        <h3 class="request-title">Request Content</h3>
                        <p class="request-text">Get specific content from <strong><?php echo htmlspecialchars($creator->display_name); ?></strong> for just <strong>$<?php echo number_format($creator->minimum_topic_price ?? 100, 0); ?></strong>.</p>
                    </div>
                    <a href="#" onclick="openCreateTopicModal(<?php echo $creator->id; ?>, <?php echo $creator->minimum_topic_price ?? 100; ?>); return false;" class="request-btn">
                        Create Topic
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                            <line x1="12" y1="5" x2="12" y2="19"></line>
                            <line x1="5" y1="12" x2="19" y2="12"></line>
                        </svg>
                    </a>
                </div>
                
                <div class="topic-grid">
                    <?php 
                    $topics_before_box = array_slice($active_topics, 0, 2);
                    $topics_after_box = array_slice($active_topics, 2);
                    
                    foreach ($topics_before_box as $topic): 
                    ?>
                    <div class="topic-card" onclick="openTopicModal(<?php echo $topic->id; ?>)">
                        <h3 class="topic-title"><?php echo htmlspecialchars($topic->title); ?></h3>
                        <p class="topic-description"><?php echo htmlspecialchars(substr($topic->description, 0, 150)) . (strlen($topic->description) > 150 ? '...' : ''); ?></p>
                        
                        <?php 
                        $progress_percent = ($topic->current_funding / $topic->funding_threshold) * 100;
                        $progress_percent = min($progress_percent, 100);
                        ?>
                        
                        <div class="funding-bar">
                            <div class="funding-progress" style="width: <?php echo $progress_percent; ?>%"></div>
                        </div>
                        
                        <div class="funding-info">
                            <div>
                                <span class="funding-amount">$<?php echo number_format($topic->current_funding, 0); ?></span>
                                <span style="color: var(--gray-med); font-size: 14px;"> of $<?php echo number_format($topic->funding_threshold, 0); ?></span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="request-topic-box request-topic-box-desktop">
                    <div class="request-content">
                        <h3 class="request-title">Request Content</h3>
                        <p class="request-text">Get specific content from <strong><?php echo htmlspecialchars($creator->display_name); ?></strong> for just <strong>$<?php echo number_format($creator->minimum_topic_price ?? 100, 0); ?></strong>.</p>
                    </div>
                    <a href="#" onclick="openCreateTopicModal(<?php echo $creator->id; ?>, <?php echo $creator->minimum_topic_price ?? 100; ?>); return false;" class="request-btn">
                        Create Topic
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                            <line x1="12" y1="5" x2="12" y2="19"></line>
                            <line x1="5" y1="12" x2="19" y2="12"></line>
                        </svg>
                    </a>
                </div>
                
                <?php if (count($topics_after_box) > 0): ?>
                <div class="topic-grid">
                    <?php foreach ($topics_after_box as $topic): ?>
                    <div class="topic-card" onclick="openTopicModal(<?php echo $topic->id; ?>)">
                        <h3 class="topic-title"><?php echo htmlspecialchars($topic->title); ?></h3>
                        <p class="topic-description"><?php echo htmlspecialchars(substr($topic->description, 0, 150)) . (strlen($topic->description) > 150 ? '...' : ''); ?></p>
                        
                        <?php 
                        $progress_percent = ($topic->current_funding / $topic->funding_threshold) * 100;
                        $progress_percent = min($progress_percent, 100);
                        ?>
                        
                        <div class="funding-bar">
                            <div class="funding-progress" style="width: <?php echo $progress_percent; ?>%"></div>
                        </div>
                        
                        <div class="funding-info">
                            <div>
                                <span class="funding-amount">$<?php echo number_format($topic->current_funding, 0); ?></span>
                                <span style="color: var(--gray-med); font-size: 14px;"> of $<?php echo number_format($topic->funding_threshold, 0); ?></span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        
        <?php if (!empty($waiting_upload_topics)): ?>
        <div class="section" style="margin-top: 50px;">
            <h2>Fully Funded - Awaiting Upload</h2>
            <div class="topic-grid">
                <?php foreach ($waiting_upload_topics as $topic): ?>
                <div class="topic-card" style="border-color: #10b981; background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span style="font-size: 20px;">✅</span>
                            <span style="font-size: 12px; font-weight: 700; color: #10b981; text-transform: uppercase; letter-spacing: 0.5px;">Funded</span>
                        </div>
                        <div style="font-size: 11px; font-weight: 700; color: #10b981;">
                            ⏱️ <span class="countdown-timer" data-deadline="<?php echo $topic->deadline_timestamp; ?>">
                                <?php
                                $seconds_left = max(0, strtotime($topic->content_deadline) - time());
                                $hours = floor($seconds_left / 3600);
                                $minutes = floor(($seconds_left % 3600) / 60);
                                $seconds = $seconds_left % 60;
                                echo sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
                                ?>
                            </span>
                        </div>
                    </div>
                    <h3 class="topic-title"><?php echo htmlspecialchars($topic->title); ?></h3>
                    <p class="topic-description"><?php echo htmlspecialchars(substr($topic->description, 0, 150)) . (strlen($topic->description) > 150 ? '...' : ''); ?></p>
                    
                    <div style="background: white; padding: 16px; border-radius: 12px; margin-top: 16px;">
                        <div style="font-size: 13px; color: #666; margin-bottom: 8px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Creator Earnings</div>
                        <div style="font-size: 24px; font-weight: 700; color: #10b981; font-family: 'Playfair Display', serif;">
                            $<?php echo number_format($topic->funding_threshold * 0.9, 0); ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

    <script>
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
    
    function openTopicModal(topicId) {
        fetch(`/api/get-topic.php?id=${topicId}`)
            .then(response => response.json())
            .then(topic => {
                if (!topic || topic.error) {
                    alert('Topic not found');
                    return;
                }

                const progress = Math.min(100, (topic.current_funding / topic.funding_threshold) * 100);

                let actionHTML = '';
                if (topic.status === 'completed' && topic.content_url) {
                    actionHTML = `<a href="${topic.content_url}" target="_blank" style="display: block; background: #10b981; color: white; text-align: center; padding: 14px; border-radius: 50px; text-decoration: none; font-weight: 700; font-size: 15px; transition: all 0.2s;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 16px rgba(16,185,129,0.3)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none'">▶️ Watch Content</a>`;
                } else if (topic.status === 'active') {
                    actionHTML = `
                        <div id="fundingFormContainer">
                            <div id="errorMessage" style="display: none; color: #DC2626; background: #FEF2F2; border-left: 4px solid #DC2626; padding: 14px 18px; border-radius: 12px; margin-bottom: 20px; font-size: 14px; font-weight: 500;"></div>

                            <div style="margin-bottom: 20px;">
                                <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #000; font-size: 14px;">Amount</label>
                                <input
                                    type="number"
                                    id="fundingAmount"
                                    placeholder="10"
                                    min="1"
                                    max="1000"
                                    step="1"
                                    value="10"
                                    style="width: 100%; padding: 12px 16px; border: 2px solid #E5E5E5; border-radius: 12px; font-size: 15px; transition: all 0.2s; outline: none; background: white; font-family: 'Inter', sans-serif;"
                                    oninput="validateFundingAmount()"
                                    onfocus="this.style.borderColor='#FF006B'; this.style.boxShadow='0 0 0 4px rgba(255, 0, 107, 0.1)'"
                                    onblur="this.style.borderColor='#E5E5E5'; this.style.boxShadow='none'"
                                >
                            </div>

                            <button
                                id="fundButton"
                                onclick="submitFunding(${topic.id})"
                                style="width: 100%; background: #FF006B; color: white; padding: 14px; border: none; border-radius: 50px; font-size: 16px; font-weight: 700; cursor: pointer; transition: all 0.2s;"
                                onmouseover="this.style.background='#E6005F'; this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 16px rgba(255, 0, 107, 0.3)'"
                                onmouseout="this.style.background='#FF006B'; this.style.transform='translateY(0)'; this.style.boxShadow='none'"
                            >
                                Fund This Topic
                            </button>

                        </div>
                    `;
                }

                const modalHTML = `
                    <div id="topicModal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; display: flex; align-items: center; justify-content: center; padding: 20px; backdrop-filter: blur(4px);" onclick="closeTopicModal(event)">
                        <div style="background: white; border-radius: 20px; max-width: 540px; width: 100%; max-height: 90vh; overflow-y: auto; padding: 40px; position: relative; box-shadow: 0 20px 50px rgba(0,0,0,0.2);" onclick="event.stopPropagation()">
                            <button onclick="closeTopicModal()" style="position: absolute; top: 20px; right: 20px; background: transparent; border: none; width: 32px; height: 32px; font-size: 28px; cursor: pointer; color: #666; transition: color 0.2s; padding: 0; line-height: 1; font-weight: 300;" onmouseover="this.style.color='#000'" onmouseout="this.style.color='#666'">×</button>

                            <h2 style="font-family: 'Playfair Display', serif; margin: 0 0 12px 0; font-size: 24px; color: #000; font-weight: 700; line-height: 1.3; padding-right: 30px;">${topic.title}</h2>

                            <p style="color: #666; line-height: 1.6; margin-bottom: 24px; font-size: 15px;">${topic.description}</p>

                            <div style="background: #FAF8F6; padding: 20px; border-radius: 16px; margin-bottom: 28px;">
                                <div style="display: flex; margin-bottom: 10px; align-items: center; justify-content: space-between;">
                                    <span style="font-size: 13px; color: #666; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Funding Progress</span>
                                </div>
                                <div style="height: 8px; background: #E5E5E5; border-radius: 4px; overflow: hidden; margin-bottom: 14px;">
                                    <div style="height: 100%; background: linear-gradient(90deg, #FF006B, #E6005F); width: ${progress}%; transition: width 0.3s; border-radius: 4px;"></div>
                                </div>
                                <div style="font-size: 20px; font-weight: 700; color: #000; font-family: 'Playfair Display', serif;">
                                    $${parseFloat(topic.current_funding).toFixed(0)} <span style="color: #666; font-size: 15px; font-weight: 500; font-family: 'Inter', sans-serif;">of $${parseFloat(topic.funding_threshold).toFixed(0)}</span>
                                </div>
                            </div>

                            ${actionHTML}
                        </div>
                    </div>
                `;

                document.body.insertAdjacentHTML('beforeend', modalHTML);
            })
            .catch(error => {
                console.error('Error loading topic:', error);
                alert('Failed to load topic details');
            });
    }

    function validateFundingAmount() {
        const amount = parseFloat(document.getElementById('fundingAmount').value);
        const button = document.getElementById('fundButton');

        if (amount >= 1 && amount <= 1000) {
            button.disabled = false;
            button.style.opacity = '1';
            button.style.cursor = 'pointer';
        } else {
            button.disabled = true;
            button.style.opacity = '0.6';
            button.style.cursor = 'not-allowed';
        }
    }

    function submitFunding(topicId) {
        const amount = parseFloat(document.getElementById('fundingAmount').value);
        const errorDiv = document.getElementById('errorMessage');
        const button = document.getElementById('fundButton');

        errorDiv.style.display = 'none';
        errorDiv.textContent = '';

        if (!amount || amount < 1) {
            errorDiv.textContent = 'Minimum contribution is $1';
            errorDiv.style.display = 'block';
            return;
        }

        if (amount > 1000) {
            errorDiv.textContent = 'Maximum contribution is $1,000';
            errorDiv.style.display = 'block';
            return;
        }

        button.disabled = true;
        button.innerHTML = 'Processing...';
        button.style.opacity = '0.6';

        const requestData = {
            topic_id: topicId,
            amount: amount
        };

        fetch('/api/get-topic.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(requestData)
        })
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                errorDiv.textContent = data.error;
                errorDiv.style.display = 'block';
                button.disabled = false;
                button.innerHTML = 'Fund This Topic';
                button.style.opacity = '1';
            } else if (data.checkout_url) {
                window.location.href = data.checkout_url;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            errorDiv.textContent = 'An error occurred. Please try again.';
            errorDiv.style.display = 'block';
            button.disabled = false;
            button.innerHTML = 'Fund This Topic';
            button.style.opacity = '1';
        });
    }

    function closeTopicModal(event) {
        if (event && event.target.id !== 'topicModal') return;
        const modal = document.getElementById('topicModal');
        if (modal) modal.remove();
    }

function openCreateTopicModal(creatorId, minPrice) {
    const modalHTML = 
        '<div id="createTopicModal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; display: flex; align-items: center; justify-content: center; padding: 20px; backdrop-filter: blur(4px);" onclick="closeCreateTopicModal(event)">' +
            '<div style="background: white; border-radius: 20px; max-width: 540px; width: 100%; max-height: 90vh; overflow-y: auto; padding: 40px; position: relative; box-shadow: 0 20px 50px rgba(0,0,0,0.2);" onclick="event.stopPropagation()">'+
                '<button onclick="closeCreateTopicModal()" style="position: absolute; top: 20px; right: 20px; background: transparent; border: none; width: 32px; height: 32px; font-size: 28px; cursor: pointer; color: #666; transition: color 0.2s; padding: 0; line-height: 1; font-weight: 300;" onmouseover="this.style.color=\'#000\'" onmouseout="this.style.color=\'#666\'">×</button>' +
                '<h2 style="font-family: \'Playfair Display\', serif; margin: 0 0 12px 0; font-size: 24px; color: #000; font-weight: 700; line-height: 1.3; padding-right: 30px;">Create a Topic</h2>' +
                '<p style="color: #666; line-height: 1.6; margin-bottom: 28px; font-size: 15px;">Suggest a topic you\'d like to see covered.</p>' +
                '<div id="createTopicError" style="display: none; color: #DC2626; background: #FEF2F2; border-left: 4px solid #DC2626; padding: 14px 18px; border-radius: 12px; margin-bottom: 20px; font-size: 14px; font-weight: 500;"></div>' +
                '<form id="createTopicForm" onsubmit="submitCreateTopic(event, ' + creatorId + ', ' + minPrice + ')">' +
                    '<div style="margin-bottom: 20px;">' +
                        '<label style="display: block; font-weight: 600; margin-bottom: 8px; color: #000; font-size: 14px;">Topic Title</label>' +
                        '<input type="text" id="topicTitle" placeholder="e.g., How to Start a YouTube Channel" required maxlength="100" style="width: 100%; padding: 12px 16px; border: 2px solid #E5E5E5; border-radius: 12px; font-size: 15px; transition: all 0.2s; outline: none; background: white; font-family: \'Inter\', sans-serif;" onfocus="this.style.borderColor=\'#FF006B\'; this.style.boxShadow=\'0 0 0 4px rgba(255, 0, 107, 0.1)\'" onblur="this.style.borderColor=\'#E5E5E5\'; this.style.boxShadow=\'none\'">' +
                    '</div>' +
                    '<div style="margin-bottom: 20px;">' +
                        '<label style="display: block; font-weight: 600; margin-bottom: 8px; color: #000; font-size: 14px;">Description</label>' +
                        '<textarea id="topicDescription" placeholder="Describe what you\'d like to see in this content..." required maxlength="500" rows="4" style="width: 100%; padding: 12px 16px; border: 2px solid #E5E5E5; border-radius: 12px; font-size: 15px; transition: all 0.2s; outline: none; background: white; resize: vertical; font-family: \'Inter\', sans-serif;" onfocus="this.style.borderColor=\'#FF006B\'; this.style.boxShadow=\'0 0 0 4px rgba(255, 0, 107, 0.1)\'" onblur="this.style.borderColor=\'#E5E5E5\'; this.style.boxShadow=\'none\'"></textarea>' +
                    '</div>' +
                    '<div style="margin-bottom: 20px;">' +
                        '<label style="display: block; font-weight: 600; margin-bottom: 8px; color: #000; font-size: 14px;">Set Funding Goal</label>' +
                        '<input type="number" id="fundingGoal" placeholder="' + minPrice + '" min="' + minPrice + '" max="10000" step="1" value="' + minPrice + '" required style="width: 100%; padding: 12px 16px; border: 2px solid #E5E5E5; border-radius: 12px; font-size: 15px; transition: all 0.2s; outline: none; background: white; font-family: \'Inter\', sans-serif;" onfocus="this.style.borderColor=\'#FF006B\'; this.style.boxShadow=\'0 0 0 4px rgba(255, 0, 107, 0.1)\'" onblur="this.style.borderColor=\'#E5E5E5\'; this.style.boxShadow=\'none\'">' +
                        '<div style="font-size: 13px; color: #666; margin-top: 8px;">Minimum: $' + minPrice + '</div>' +
                    '</div>' +
                    '<div style="margin-bottom: 24px;">' +
                        '<label style="display: block; font-weight: 600; margin-bottom: 8px; color: #000; font-size: 14px;">Your Funding Amount</label>' +
                        '<input type="number" id="initialAmount" placeholder="10" min="1" max="1000" step="1" value="10" required style="width: 100%; padding: 12px 16px; border: 2px solid #E5E5E5; border-radius: 12px; font-size: 15px; transition: all 0.2s; outline: none; background: white; font-family: \'Inter\', sans-serif;" onfocus="this.style.borderColor=\'#FF006B\'; this.style.boxShadow=\'0 0 0 4px rgba(255, 0, 107, 0.1)\'" onblur="this.style.borderColor=\'#E5E5E5\'; this.style.boxShadow=\'none\'">' +
                    '</div>' +
                    '<button type="submit" id="createTopicButton" style="width: 100%; background: #FF006B; color: white; padding: 14px; border: none; border-radius: 50px; font-size: 16px; font-weight: 700; cursor: pointer; transition: all 0.2s;" onmouseover="this.style.background=\'#E6005F\'; this.style.transform=\'translateY(-2px)\'; this.style.boxShadow=\'0 6px 16px rgba(255, 0, 107, 0.3)\'" onmouseout="this.style.background=\'#FF006B\'; this.style.transform=\'translateY(0)\'; this.style.boxShadow=\'none\'">Create Topic & Fund</button>' +
                '</form>' +
            '</div>' +
        '</div>';
    
    document.body.insertAdjacentHTML('beforeend', modalHTML);
}

function closeCreateTopicModal(event) {
    if (event && event.target.id !== 'createTopicModal') return;
    const modal = document.getElementById('createTopicModal');
    if (modal) modal.remove();
}

function submitCreateTopic(event, creatorId, minPrice) {
    event.preventDefault();
    const title = document.getElementById('topicTitle').value;
    const description = document.getElementById('topicDescription').value;
    const fundingGoal = parseFloat(document.getElementById('fundingGoal').value);
    const amount = parseFloat(document.getElementById('initialAmount').value);
    const errorDiv = document.getElementById('createTopicError');
    const button = document.getElementById('createTopicButton');
    errorDiv.style.display = 'none';
    errorDiv.textContent = '';
    if (!title || !description || !fundingGoal || !amount) {
        errorDiv.textContent = 'Please fill in all fields';
        errorDiv.style.display = 'block';
        return;
    }
    if (amount < 1 || amount > 1000) {
        errorDiv.textContent = 'Your funding amount must be between $1 and $1,000';
        errorDiv.style.display = 'block';
        return;
    }
    if (fundingGoal < minPrice || fundingGoal > 10000) {
        errorDiv.textContent = 'Funding goal must be between $' + minPrice + ' and $10,000';
        errorDiv.style.display = 'block';
        return;
    }
    if (amount > fundingGoal) {
        errorDiv.textContent = 'Your funding amount cannot exceed the funding goal';
        errorDiv.style.display = 'block';
        return;
    }
    button.disabled = true;
    button.innerHTML = 'Processing...';
    button.style.opacity = '0.6';
    
    const requestData = { 
        creator_id: creatorId, 
        title: title, 
        description: description, 
        funding_goal: fundingGoal, 
        initial_amount: amount 
    };
    
    console.log('Submitting topic with data:', requestData);
    
    fetch('/api/create-topic.php', { 
        method: 'POST', 
        headers: { 'Content-Type': 'application/json' }, 
        body: JSON.stringify(requestData) 
    })
    .then(response => {
        console.log('Response status:', response.status);
        return response.json();
    })
    .then(data => {
        console.log('Response data:', data);
        if (data.error) {
            errorDiv.textContent = data.error;
            errorDiv.style.display = 'block';
            button.disabled = false;
            button.innerHTML = 'Create Topic & Fund';
            button.style.opacity = '1';
        } else if (data.checkout_url) {
            window.location.href = data.checkout_url;
        } else {
            errorDiv.textContent = 'Unexpected response from server';
            errorDiv.style.display = 'block';
            button.disabled = false;
            button.innerHTML = 'Create Topic & Fund';
            button.style.opacity = '1';
        }
    })
    .catch(error => {
        console.error('Fetch error:', error);
        errorDiv.textContent = 'Network error. Please check your connection and try again.';
        errorDiv.style.display = 'block';
        button.disabled = false;
        button.innerHTML = 'Create Topic & Fund';
        button.style.opacity = '1';
    });
}

    if (window.innerWidth <= 768) {
        window.addEventListener('load', function() {
            const activeTopicsSection = document.querySelector('.section');
            if (activeTopicsSection) {
                setTimeout(function() {
                    activeTopicsSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }, 100);
            }
        });
    }
    
    window.addEventListener('load', function() {
        const autoOpenTopicId = <?php echo $auto_open_topic_id; ?>;
        
        if (autoOpenTopicId > 0) {
            setTimeout(function() {
                openTopicModal(autoOpenTopicId);
            }, 300);
        } else {
            const urlParams = new URLSearchParams(window.location.search);
            const topicId = urlParams.get('topic_id');
            if (topicId) {
                setTimeout(function() {
                    openTopicModal(parseInt(topicId));
                }, 300);
            }
        }
    });
</script>
</body>
</html>
<?php
    exit;
    
} catch (Exception $e) {
    error_log("Vanity URL error: " . $e->getMessage());
    header('Location: /');
    exit;
}
