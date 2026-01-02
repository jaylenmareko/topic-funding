<?php
// username.php - Vanity URL handler
session_start();

$username = isset($_GET['username']) ? trim($_GET['username']) : '';

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
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0; 
            padding: 0; 
            background: #fafafa;
        }
        
        /* Navigation - Rizzdem Style */
        .topiclaunch-nav {
            background: white;
            padding: 15px 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border-bottom: 1px solid #f0f0f0;
        }
        
        .nav-container {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 20px;
        }
        
        .nav-logo {
            font-size: 24px;
            font-weight: bold;
            color: #FF0000;
            text-decoration: none;
        }

        /* Nav Center Links */
        .nav-center {
            display: flex;
            gap: 30px;
            align-items: center;
        }
        
        .nav-link {
            color: #6b7280;
            text-decoration: none;
            font-size: 15px;
            font-weight: 500;
            transition: color 0.2s;
        }
        
        .nav-link:hover {
            color: #FF0000;
        }
        
        .nav-buttons {
            display: flex;
            gap: 15px;
            align-items: center;
        }
        
        .nav-login-btn {
            color: #333;
            text-decoration: none;
            font-size: 15px;
            font-weight: 500;
            padding: 8px 16px;
            transition: color 0.2s;
        }
        
        .nav-login-btn:hover {
            color: #FF0000;
        }
        
        .nav-getstarted-btn {
            background: #FF0000;
            color: white;
            text-decoration: none;
            font-size: 15px;
            font-weight: 600;
            padding: 10px 24px;
            border-radius: 50px;
            transition: all 0.2s;
        }
        
        .nav-getstarted-btn:hover {
            background: #CC0000;
            transform: translateY(-1px);
        }
        
        /* Container */
        .container { 
            max-width: 1200px; 
            margin: 0 auto; 
            padding: 40px 20px; 
        }
        
        /* Profile Box */
        .profile-box {
            background: white;
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            border: 1px solid #e5e7eb;
            padding: 32px;
            margin-bottom: 30px;
            max-width: 700px;
            margin-left: auto;
            margin-right: auto;
        }
        
        .profile-header {
            display: flex;
            align-items: flex-start;
            gap: 24px;
            margin-bottom: 24px;
        }
        
        .profile-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: linear-gradient(135deg, #FF0000 0%, #CC0000 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 40px;
            font-weight: bold;
            overflow: hidden;
            flex-shrink: 0;
        }
        
        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .profile-info {
            flex: 1;
            min-width: 0;
        }
        
        .profile-name {
            font-size: 28px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 4px;
        }
        
        .profile-handle {
            font-size: 16px;
            color: #6b7280;
            margin-bottom: 16px;
        }
        
        .profile-price {
            display: inline-flex;
            align-items: baseline;
            gap: 6px;
            background: #f3f4f6;
            padding: 8px 16px;
            border-radius: 8px;
        }
        
        .profile-price-amount {
            font-size: 24px;
            font-weight: 700;
            color: #111827;
        }
        
        .profile-price-label {
            font-size: 13px;
            color: #6b7280;
            font-weight: 500;
        }
        
        .profile-bio {
            font-size: 15px;
            color: #4b5563;
            line-height: 1.6;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
        }
        
        .create-topic-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #10b981;
            color: white;
            padding: 14px 28px;
            text-decoration: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            transition: all 0.2s;
            margin-top: 24px;
        }
        
        .create-topic-btn:hover {
            background: #059669;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16,185,129,0.3);
        }
        
        .section { 
            background: white; 
            padding: 25px; 
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            margin-bottom: 20px;
            border: 1px solid #e5e7eb;
        }
        
        .section h2 { 
            margin-top: 0; 
            color: #111827;
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 20px;
        }
        
        .topic-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); 
            gap: 20px; 
        }
        
        .topic-card { 
            border: 1px solid #e5e7eb;
            padding: 20px; 
            border-radius: 12px;
            transition: all 0.2s;
            cursor: pointer;
            background: white;
        }
        
        .topic-card:hover { 
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            border-color: #FF0000;
        }
        
        .topic-title { 
            font-weight: 600;
            font-size: 18px;
            margin-bottom: 10px;
            color: #111827;
        }
        
        .topic-description { 
            color: #6b7280;
            line-height: 1.5;
            margin-bottom: 15px;
            font-size: 14px;
        }
        
        .funding-bar { 
            background: #e5e7eb;
            height: 8px;
            border-radius: 4px;
            margin: 15px 0;
            overflow: hidden;
        }
        
        .funding-progress { 
            background: linear-gradient(90deg, #10b981, #059669);
            height: 100%;
            border-radius: 4px;
            transition: width 0.3s;
        }
        
        .funding-info { 
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 15px;
        }
        
        .funding-amount { 
            font-weight: 700;
            color: #10b981;
            font-size: 16px;
        }
        
        .empty-state { 
            text-align: center;
            color: #6b7280;
            padding: 60px 20px;
        }
        
        .empty-state h3 {
            color: #374151;
            margin-bottom: 8px;
            font-size: 18px;
        }
        
        .empty-state p {
            color: #9ca3af;
            font-size: 14px;
        }
        
        @media (max-width: 768px) {
            .container { padding: 20px 15px; }
            .profile-box { padding: 24px; }
            .profile-header { flex-direction: column; text-align: center; }
            .profile-avatar { margin: 0 auto; }
            .topic-grid { grid-template-columns: 1fr; }
            .nav-center { display: none; }
        }
    </style>
</head>
<body>
    <nav class="topiclaunch-nav">
        <div class="nav-container">
            <a href="/" class="nav-logo">TopicLaunch</a>
            
            <div class="nav-center">
                <a href="/#creators" class="nav-link">Browse YouTubers</a>
                <a href="/creators/signup.php" class="nav-link">For YouTubers</a>
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
                
                <div class="profile-info">
                    <div class="profile-name"><?php echo htmlspecialchars($creator->display_name); ?></div>
                    <div class="profile-handle">@<?php echo htmlspecialchars($creator->display_name); ?></div>
                    
                    <div class="profile-price">
                        <span class="profile-price-amount">$<?php echo number_format($creator->minimum_topic_price ?? 100, 2); ?></span>
                        <span class="profile-price-label">/ PER TOPIC</span>
                    </div>
                </div>
            </div>
            
            <?php if (!empty($creator->bio)): ?>
            <div class="profile-bio">
                <?php echo nl2br(htmlspecialchars($creator->bio)); ?>
            </div>
            <?php endif; ?>
            
            <a href="/topics/create.php?creator_id=<?php echo $creator->id; ?>" class="create-topic-btn">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="12" y1="5" x2="12" y2="19"></line>
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
                Create a Topic
            </a>
        </div>

        <div class="section">
            <h2>Active Topics</h2>
            <?php if (empty($active_topics)): ?>
                <div class="empty-state">
                    <h3>No active topics</h3>
                    <p>This creator doesn't have any topics seeking funding right now.</p>
                </div>
            <?php else: ?>
                <div class="topic-grid">
                    <?php foreach ($active_topics as $topic): ?>
                    <div class="topic-card" onclick="alert('Topic details coming soon!')">
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
                                <span class="funding-amount">$<?php echo number_format($topic->current_funding, 2); ?></span>
                                <span style="color: #9ca3af; font-size: 14px;"> of $<?php echo number_format($topic->funding_threshold, 2); ?></span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
<?php
    exit;
    
} catch (Exception $e) {
    error_log("Vanity URL error: " . $e->getMessage());
    header('Location: /');
    exit;
}
