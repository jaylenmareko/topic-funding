<?php
// creators/dashboard.php - Fixed to prevent redirect loops
session_start();
require_once '../config/database.php';
require_once '../config/navigation.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$db = new Database();

// Get creator info - FIXED: Don't redirect creators away from dashboard
$db->query('
    SELECT c.*, u.email 
    FROM creators c 
    LEFT JOIN users u ON c.applicant_user_id = u.id 
    WHERE c.applicant_user_id = :user_id AND c.is_active = 1
');
$db->bind(':user_id', $_SESSION['user_id']);
$creator = $db->single();

// If not a creator, redirect to fan dashboard instead of creators/index.php
if (!$creator) {
    header('Location: ../dashboard/index.php');
    exit;
}

// CREATOR DASHBOARD CONTINUES HERE - No more redirects
// Get urgent funded topics awaiting content (48-hour deadline)
$db->query('
    SELECT t.*, 
           (SELECT COUNT(*) FROM contributions WHERE topic_id = t.id AND payment_status = "completed") as contributor_count,
           TIMESTAMPDIFF(HOUR, t.funded_at, NOW()) as hours_since_funded,
           (48 - TIMESTAMPDIFF(HOUR, t.funded_at, NOW())) as hours_remaining
    FROM topics t 
    WHERE t.creator_id = :creator_id 
    AND t.status = "funded" 
    AND t.content_url IS NULL
    AND TIMESTAMPDIFF(HOUR, t.funded_at, NOW()) < 48
    ORDER BY t.funded_at ASC
');
$db->bind(':creator_id', $creator->id);
$urgent_topics = $db->resultSet();

// Get live topics being funded
$db->query('
    SELECT t.*, 
           (t.current_funding / t.funding_threshold * 100) as funding_percentage,
           (SELECT COUNT(*) FROM contributions WHERE topic_id = t.id AND payment_status = "completed") as contributor_count
    FROM topics t 
    WHERE t.creator_id = :creator_id 
    AND t.status = "active"
    ORDER BY funding_percentage DESC, t.created_at DESC
    LIMIT 5
');
$db->bind(':creator_id', $creator->id);
$live_topics = $db->resultSet();

// Get completed topics (last 5)
$db->query('
    SELECT t.*, t.content_url, t.completed_at
    FROM topics t 
    WHERE t.creator_id = :creator_id 
    AND t.status = "completed" 
    ORDER BY t.completed_at DESC 
    LIMIT 5
');
$db->bind(':creator_id', $creator->id);
$completed_topics = $db->resultSet();

// Get earnings summary
$db->query('
    SELECT 
        COALESCE(SUM(CASE WHEN t.status = "completed" THEN t.current_funding * 0.9 END), 0) as total_earned,
        COALESCE(SUM(CASE WHEN t.status = "funded" AND t.content_url IS NULL THEN t.current_funding * 0.9 END), 0) as pending_earnings,
        COUNT(CASE WHEN t.status = "completed" THEN 1 END) as topics_completed,
        COUNT(CASE WHEN t.status = "funded" AND t.content_url IS NULL THEN 1 END) as topics_pending
    FROM topics t 
    WHERE t.creator_id = :creator_id
');
$db->bind(':creator_id', $creator->id);
$earnings = $db->single();

// Check Stripe Connect status
$stripe_connected = !empty($creator->stripe_account_id);
?>
<!DOCTYPE html>
<html>
<head>
    <title>YouTuber Dashboard - TopicLaunch</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; background: #f8f9fa; }
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        
        /* Header */
        .dashboard-header { 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
            color: white; 
            padding: 30px 20px; 
            margin-bottom: 30px;
            border-radius: 12px;
        }
        .header-content { max-width: 1200px; margin: 0 auto; }
        .header-title { font-size: 32px; margin: 0 0 10px 0; font-weight: bold; }
        .header-subtitle { font-size: 18px; margin: 0; opacity: 0.9; }
        
        /* Alert Sections */
        .stripe-alert {
            background: #f8d7da;
            border: 2px solid #dc3545;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 30px;
            text-align: center;
        }
        .stripe-alert h3 {
            color: #721c24;
            margin: 0 0 10px 0;
        }
        .stripe-setup-btn {
            background: #28a745;
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 6px;
            text-decoration: none;
            font-weight: bold;
            font-size: 16px;
            display: inline-block;
            margin-top: 10px;
        }
        .stripe-setup-btn:hover {
            background: #218838;
            color: white;
            text-decoration: none;
        }
        
        /* Cards */
        .dashboard-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 30px; margin-bottom: 30px; }
        .dashboard-card { 
            background: white; 
            border-radius: 12px; 
            padding: 25px; 
            box-shadow: 0 4px 12px rgba(0,0,0,0.1); 
        }
        .card-header { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            margin-bottom: 20px; 
            border-bottom: 2px solid #f1f3f4;
            padding-bottom: 15px;
        }
        .card-title { 
            font-size: 20px; 
            font-weight: bold; 
            color: #333; 
            margin: 0; 
        }
        .card-icon { font-size: 24px; }
        
        /* Topic Items */
        .topic-item { 
            background: #f8f9fa; 
            border-radius: 8px; 
            padding: 20px; 
            margin-bottom: 15px; 
            border-left: 4px solid #007bff;
        }
        .urgent-topic { 
            background: #fff3cd; 
            border-left-color: #ffc107; 
        }
        .topic-title { 
            font-size: 18px; 
            font-weight: bold; 
            color: #333; 
            margin: 0 0 10px 0; 
        }
        .topic-meta { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            font-size: 14px; 
            color: #666; 
            margin-bottom: 15px;
        }
        .countdown { 
            background: #dc3545; 
            color: white; 
            padding: 5px 12px; 
            border-radius: 15px; 
            font-weight: bold; 
            font-size: 12px;
        }
        .progress-bar { 
            background: #e9ecef; 
            border-radius: 10px; 
            height: 8px; 
            overflow: hidden; 
            margin: 10px 0;
        }
        .progress-fill { 
            background: #28a745; 
            height: 100%; 
            transition: width 0.3s;
        }
        
        /* Buttons */
        .btn { 
            padding: 10px 20px; 
            border: none; 
            border-radius: 6px; 
            cursor: pointer; 
            text-decoration: none; 
            font-weight: bold; 
            display: inline-block;
            text-align: center;
        }
        .btn-primary { background: #007bff; color: white; }
        .btn-primary:hover { background: #0056b3; color: white; text-decoration: none; }
        .btn-success { background: #28a745; color: white; }
        .btn-success:hover { background: #218838; color: white; text-decoration: none; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-danger:hover { background: #c82333; color: white; text-decoration: none; }
        
        /* Stats */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 20px; }
        .stat-item { text-align: center; }
        .stat-value { font-size: 32px; font-weight: bold; color: #007bff; margin: 0; }
        .stat-label { font-size: 14px; color: #666; margin: 5px 0 0 0; }
        
        .empty-state { 
            text-align: center; 
            color: #666; 
            padding: 40px 20px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        @media (max-width: 768px) {
            .dashboard-grid { grid-template-columns: 1fr; }
            .container { padding: 10px; }
            .dashboard-header { margin: 10px; }
        }
    </style>
</head>
<body>
    <?php renderNavigation('dashboard'); ?>
    
    <div class="container">
        <!-- Header -->
        <div class="dashboard-header">
            <div class="header-content">
                <h1 class="header-title">📺 YouTuber Dashboard</h1>
                <p class="header-subtitle">Welcome back, <?php echo htmlspecialchars($creator->display_name); ?>!</p>
            </div>
        </div>
        
        <!-- Stripe Setup Alert -->
        <?php if (!$stripe_connected): ?>
        <div class="stripe-alert">
            <h3>⚠️ Payment Setup Required</h3>
            <p>You need to connect your Stripe account to receive payments when you complete topics.</p>
            <a href="stripe_onboarding.php?creator_id=<?php echo $creator->id; ?>" class="stripe-setup-btn">
                💳 Setup Instant Payments
            </a>
        </div>
        <?php endif; ?>
        
        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-item">
                <div class="stat-value">$<?php echo number_format($earnings->total_earned ?? 0, 0); ?></div>
                <div class="stat-label">Total Earnings</div>
            </div>
            <div class="stat-item">
                <div class="stat-value">$<?php echo number_format($earnings->pending_earnings ?? 0, 0); ?></div>
                <div class="stat-label">Pending Earnings</div>
            </div>
            <div class="stat-item">
                <div class="stat-value"><?php echo $earnings->topics_completed ?? 0; ?></div>
                <div class="stat-label">Completed Topics</div>
            </div>
            <div class="stat-item">
                <div class="stat-value"><?php echo $earnings->topics_pending ?? 0; ?></div>
                <div class="stat-label">Due Soon</div>
            </div>
        </div>

        <div class="dashboard-grid">
            <div>
                <!-- Live Topics Being Funded -->
                <div class="dashboard-card">
                    <div class="card-header">
                        <h2 class="card-title">📈 Topics Being Funded</h2>
                        <span class="card-icon">💰</span>
                    </div>
                    
                    <?php if (!empty($live_topics)): ?>
                        <?php foreach ($live_topics as $topic): ?>
                        <div class="topic-item">
                            <h3 class="topic-title"><?php echo htmlspecialchars($topic->title); ?></h3>
                            <div class="topic-meta">
                                <span>$<?php echo number_format($topic->current_funding, 2); ?> of $<?php echo number_format($topic->funding_threshold, 2); ?></span>
                                <span><?php echo $topic->contributor_count; ?> contributors</span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?php echo min(100, $topic->funding_percentage); ?>%"></div>
                            </div>
                            <a href="../topics/view.php?id=<?php echo $topic->id; ?>" class="btn btn-primary">
                                View Topic
                            </a>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <p>No topics currently being funded.</p>
                            <p>Fans will create topics for you - just wait for them to reach the funding goal!</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div>
                <!-- Quick Actions -->
                <div class="dashboard-card">
                    <h3>⚡ Quick Actions</h3>
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <a href="../topics/create.php?creator_id=<?php echo $creator->id; ?>" class="btn btn-success">💡 Propose New Topic</a>
                        <a href="../topics/index.php" class="btn">🔍 Browse All Topics</a>
                        <a href="../creators/edit.php?id=<?php echo $creator->id; ?>" class="btn">✏️ Edit Profile</a>
                        <?php if (!$stripe_connected): ?>
                            <a href="stripe_onboarding.php?creator_id=<?php echo $creator->id; ?>" class="btn btn-success">💳 Setup Payments</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- No Topics State -->
        <?php if (empty($live_topics) && empty($urgent_topics) && empty($completed_topics)): ?>
        <div style="text-align: center; margin-top: 40px; padding: 40px; background: white; border-radius: 12px;">
            <h3>🚀 Ready to Start Creating?</h3>
            <p>You don't have any topics yet. Wait for proposals or create your own!</p>
            <div style="margin-top: 20px;">
                <a href="../topics/create.php?creator_id=<?php echo $creator->id; ?>" class="btn btn-success">💡 Create First Topic</a>
                <a href="../topics/index.php" class="btn">🔍 Browse Community Topics</a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
