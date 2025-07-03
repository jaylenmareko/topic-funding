<?php
// dashboard/index.php - Unified dashboard that shows creator view for YouTubers, fan view for regular users
session_start();
require_once '../config/database.php';
require_once '../config/navigation.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$helper = new DatabaseHelper();
$user_id = $_SESSION['user_id'];

// Check if user is a creator
$db = new Database();
$db->query('SELECT * FROM creators WHERE applicant_user_id = :user_id AND is_active = 1');
$db->bind(':user_id', $user_id);
$creator = $db->single();

// CREATOR DASHBOARD - Only for verified creators
if ($creator) {
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
        .header-actions { margin-top: 20px; }
        .header-btn { 
            background: rgba(255,255,255,0.2); 
            color: white; 
            padding: 10px 20px; 
            border: none; 
            border-radius: 6px; 
            text-decoration: none; 
            margin-right: 10px;
            display: inline-block;
            font-weight: bold;
        }
        .header-btn:hover { 
            background: rgba(255,255,255,0.3); 
            color: white;
            text-decoration: none;
        }
        
        /* Alert Sections */
        .urgent-section { 
            background: #fff3cd; 
            border: 2px solid #ffc107; 
            border-radius: 12px; 
            padding: 25px; 
            margin-bottom: 30px;
        }
        .urgent-header { 
            display: flex; 
            align-items: center; 
            margin-bottom: 20px; 
        }
        .urgent-icon { 
            font-size: 28px; 
            margin-right: 15px; 
        }
        .urgent-title { 
            font-size: 24px; 
            color: #856404; 
            margin: 0; 
            font-weight: bold;
        }
        
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
            <a href="../creators/stripe_onboarding.php?creator_id=<?php echo $creator->id; ?>" class="stripe-setup-btn">
                💳 Setup Instant Payments
            </a>
        </div>
        <?php endif; ?>
        
        <!-- Urgent: Funded Topics Awaiting Content -->
        <?php if (!empty($urgent_topics)): ?>
        <div class="urgent-section">
            <div class="urgent-header">
                <span class="urgent-icon">🔥</span>
                <h2 class="urgent-title">Action Required - Deliver Content</h2>
            </div>
            
            <?php foreach ($urgent_topics as $topic): ?>
            <div class="topic-item urgent-topic">
                <h3 class="topic-title"><?php echo htmlspecialchars($topic->title); ?></h3>
                <div class="topic-meta">
                    <span><strong>$<?php echo number_format($topic->current_funding, 2); ?></strong> funded</span>
                    <span class="countdown">
                        ⏰ <?php echo max(0, $topic->hours_remaining); ?> hours left
                    </span>
                </div>
                <p style="margin: 10px 0; color: #666;">
                    <?php echo htmlspecialchars(substr($topic->description, 0, 150)); ?>...
                </p>
                <div style="margin-top: 15px;">
                    <a href="../creators/upload_content.php?topic=<?php echo $topic->id; ?>" class="btn btn-danger">
                        🎬 Upload Content Now
                    </a>
                    <a href="../topics/view.php?id=<?php echo $topic->id; ?>" class="btn btn-primary" style="margin-left: 10px;">
                        👁️ View Details
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <!-- Main Dashboard Grid -->
        <div class="dashboard-grid">
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
            
            <!-- Earnings & Stats -->
            <div class="dashboard-card">
                <div class="card-header">
                    <h2 class="card-title">💵 Earnings</h2>
                    <span class="card-icon">📊</span>
                </div>
                
                <div class="stats-grid">
                    <div class="stat-item">
                        <p class="stat-value">$<?php echo number_format($earnings->total_earned ?? 0, 2); ?></p>
                        <p class="stat-label">Total Earned</p>
                    </div>
                    <div class="stat-item">
                        <p class="stat-value">$<?php echo number_format($earnings->pending_earnings ?? 0, 2); ?></p>
                        <p class="stat-label">Pending</p>
                    </div>
                    <div class="stat-item">
                        <p class="stat-value"><?php echo $earnings->topics_completed ?? 0; ?></p>
                        <p class="stat-label">Completed</p>
                    </div>
                    <div class="stat-item">
                        <p class="stat-value"><?php echo $earnings->topics_pending ?? 0; ?></p>
                        <p class="stat-label">Due Soon</p>
                    </div>
                </div>
                
                <?php if ($stripe_connected): ?>
                <div style="margin-top: 20px; text-align: center;">
                    <a href="../creators/stripe_onboarding.php?creator_id=<?php echo $creator->id; ?>" class="btn btn-success">
                        💳 View Payouts
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Recently Completed Topics -->
        <div class="dashboard-card">
            <div class="card-header">
                <h2 class="card-title">✅ Recent Completed Topics</h2>
                <span class="card-icon">🎉</span>
            </div>
            
            <?php if (!empty($completed_topics)): ?>
                <?php foreach (array_slice($completed_topics, 0, 5) as $topic): ?>
                <div class="topic-item">
                    <h3 class="topic-title"><?php echo htmlspecialchars($topic->title); ?></h3>
                    <div class="topic-meta">
                        <span><strong>$<?php echo number_format($topic->current_funding * 0.9, 2); ?></strong> earned</span>
                        <span>Completed <?php echo date('M j, Y', strtotime($topic->completed_at)); ?></span>
                    </div>
                    <div style="margin-top: 10px;">
                        <a href="<?php echo htmlspecialchars($topic->content_url); ?>" target="_blank" class="btn btn-primary">
                            🎬 View Content
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <p>No completed topics yet.</p>
                    <p>Complete your first funded topic to see it here!</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
<?php
    // CRITICAL: Exit here to prevent fan dashboard from loading
    exit();
}

// ==============================================================================
// FAN DASHBOARD - Only for regular users (non-creators)
// ==============================================================================

// Get user's contribution statistics
$db->query('
    SELECT 
        COUNT(*) as total_contributions,
        COALESCE(SUM(amount), 0) as total_contributed,
        COUNT(DISTINCT topic_id) as topics_funded
    FROM contributions 
    WHERE user_id = :user_id AND payment_status = "completed"
');
$db->bind(':user_id', $user_id);
$user_stats = $db->single();

// Get user's recent contributions with topic and creator info
$db->query('
    SELECT c.*, t.title as topic_title, t.status as topic_status, 
           cr.display_name as creator_name, t.funding_threshold, t.current_funding
    FROM contributions c
    JOIN topics t ON c.topic_id = t.id
    JOIN creators cr ON t.creator_id = cr.id
    WHERE c.user_id = :user_id AND c.payment_status = "completed"
    ORDER BY c.contributed_at DESC
    LIMIT 10
');
$db->bind(':user_id', $user_id);
$recent_contributions = $db->resultSet();

// Get topics the user has created
$db->query('
    SELECT t.*, c.display_name as creator_name, c.profile_image as creator_image
    FROM topics t
    JOIN creators c ON t.creator_id = c.id
    WHERE t.initiator_user_id = :user_id
    ORDER BY t.created_at DESC
    LIMIT 5
');
$db->bind(':user_id', $user_id);
$user_topics = $db->resultSet();

// Get funding milestones reached
$db->query('
    SELECT t.title, t.funded_at, cr.display_name as creator_name
    FROM topics t
    JOIN creators cr ON t.creator_id = cr.id
    JOIN contributions c ON t.id = c.topic_id
    WHERE c.user_id = :user_id AND t.status IN ("funded", "completed")
    GROUP BY t.id
    ORDER BY t.funded_at DESC
    LIMIT 5
');
$db->bind(':user_id', $user_id);
$funded_topics = $db->resultSet();
?>
<!DOCTYPE html>
<html>
<head>
    <title>My Dashboard - TopicLaunch</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; 
            margin: 0; 
            padding: 0; 
            background: #f8f9fa; 
            color: #212529;
        }
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        
        /* Welcome Header */
        .welcome-header { 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
            color: white; 
            padding: 40px 30px; 
            border-radius: 16px; 
            margin-bottom: 30px;
            box-shadow: 0 8px 32px rgba(102, 126, 234, 0.3);
        }
        .welcome-content { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            flex-wrap: wrap; 
            gap: 20px; 
        }
        .welcome-text h1 { 
            font-size: 2.5rem; 
            margin: 0 0 0.5rem 0; 
            font-weight: 700; 
            letter-spacing: -0.02em;
        }
        .welcome-text p { 
            font-size: 1.1rem; 
            margin: 0; 
            opacity: 0.9; 
            font-weight: 400;
        }
        .welcome-actions {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }
        .welcome-btn {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(10px);
            border: 2px solid rgba(255, 255, 255, 0.2);
            color: white;
            padding: 12px 24px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.95rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .welcome-btn:hover {
            background: rgba(255, 255, 255, 0.25);
            border-color: rgba(255, 255, 255, 0.4);
            transform: translateY(-2px);
            color: white;
            text-decoration: none;
        }
        .welcome-btn-primary {
            background: rgba(255, 255, 255, 0.9);
            color: #667eea;
            border-color: transparent;
        }
        .welcome-btn-primary:hover {
            background: white;
            color: #5a6fd8;
        }
        
        /* Stats Grid */
        .stats-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); 
            gap: 20px; 
            margin-bottom: 30px; 
        }
        .stat-card { 
            background: white; 
            padding: 24px; 
            border-radius: 16px; 
            text-align: center; 
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(0, 0, 0, 0.05);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.12);
        }
        .stat-number { 
            font-size: 2.25rem; 
            font-weight: 700; 
            color: #667eea; 
            margin-bottom: 8px;
            line-height: 1;
        }
        .stat-label { 
            color: #6c757d; 
            font-size: 0.875rem; 
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        /* Content Grid */
        .content-grid { 
            display: grid; 
            grid-template-columns: 2fr 1fr; 
            gap: 30px; 
        }
        .main-content { 
            display: flex; 
            flex-direction: column; 
            gap: 25px; 
        }
        .sidebar { 
            display: flex; 
            flex-direction: column; 
            gap: 25px; 
        }
        
        /* Section Cards */
        .section { 
            background: white; 
            padding: 30px; 
            border-radius: 16px; 
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(0, 0, 0, 0.05);
        }
        .section h2 { 
            margin: 0 0 24px 0; 
            color: #212529; 
            font-size: 1.5rem;
            font-weight: 600;
            letter-spacing: -0.01em;
        }
        .section h3 { 
            margin: 0 0 20px 0; 
            color: #212529; 
            font-size: 1.25rem;
            font-weight: 600;
        }
        
        /* Activity Items */
        .contribution-item { 
            display: flex; 
            justify-content: space-between; 
            align-items: start; 
            padding: 20px 0; 
            border-bottom: 1px solid #e9ecef; 
        }
        .contribution-item:last-child { border-bottom: none; }
        .contribution-details { flex: 1; }
        .topic-title { 
            font-weight: 600; 
            color: #212529; 
            margin-bottom: 8px; 
            font-size: 1rem;
            line-height: 1.4;
        }
        .topic-title a {
            color: inherit;
            text-decoration: none;
            transition: color 0.2s ease;
        }
        .topic-title a:hover {
            color: #667eea;
        }
        .topic-meta { 
            color: #6c757d; 
            font-size: 0.875rem;
            margin-bottom: 12px;
        }
        .contribution-amount { 
            font-weight: 600; 
            color: #28a745; 
            font-size: 1.125rem;
        }
        
        /* Topic Cards */
        .topic-card { 
            border: 1px solid #e9ecef; 
            padding: 20px; 
            border-radius: 12px; 
            margin-bottom: 16px;
            transition: all 0.2s ease;
        }
        .topic-card:hover {
            border-color: #667eea;
            box-shadow: 0 4px 20px rgba(102, 126, 234, 0.1);
        }
        .topic-status { 
            padding: 6px 12px; 
            border-radius: 20px; 
            font-size: 0.75rem; 
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .status-active { background: #fff3cd; color: #856404; }
        .status-funded { background: #d4edda; color: #155724; }
        .status-completed { background: #cce5ff; color: #004085; }
        .status-pending-approval { background: #f8d7da; color: #721c24; }
        
        /* Funding Progress */
        .funding-bar { 
            background: #e9ecef; 
            height: 6px; 
            border-radius: 3px; 
            margin: 12px 0;
            overflow: hidden;
        }
        .funding-progress { 
            background: linear-gradient(90deg, #28a745, #20c997); 
            height: 100%; 
            border-radius: 3px; 
            transition: width 0.5s ease; 
        }
        
        /* Empty States */
        .empty-state { 
            text-align: center; 
            color: #6c757d; 
            padding: 40px 20px;
            background: #f8f9fa;
            border-radius: 12px;
            border: 2px dashed #dee2e6;
        }
        .empty-state h4 {
            color: #495057;
            margin-bottom: 12px;
            font-weight: 600;
        }
        .empty-state p {
            margin-bottom: 20px;
            font-size: 0.95rem;
        }
        
        /* Buttons */
        .btn { 
            background: #667eea; 
            color: white; 
            padding: 12px 20px; 
            text-decoration: none; 
            border-radius: 8px; 
            display: inline-block; 
            font-weight: 500;
            font-size: 0.875rem;
            transition: all 0.2s ease;
            border: none;
            cursor: pointer;
        }
        .btn:hover { 
            background: #5a6fd8; 
            color: white; 
            text-decoration: none;
            transform: translateY(-1px);
        }
        .btn-success { 
            background: #28a745; 
        }
        .btn-success:hover { 
            background: #218838; 
        }
        
        /* Milestone Items */
        .milestone-item { 
            padding: 16px 0; 
            border-bottom: 1px solid #e9ecef; 
        }
        .milestone-item:last-child { border-bottom: none; }
        .milestone-title {
            font-weight: 600;
            color: #212529;
            margin-bottom: 6px;
            font-size: 0.95rem;
        }
        .milestone-meta {
            color: #6c757d;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .milestone-badge {
            background: #28a745;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        
        /* Activity Feed */
        .activity-feed { 
            max-height: 500px; 
            overflow-y: auto; 
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .content-grid { grid-template-columns: 1fr; }
            .welcome-content { 
                flex-direction: column; 
                text-align: center; 
                gap: 20px;
            }
            .welcome-text h1 { font-size: 2rem; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .welcome-actions {
                justify-content: center;
                width: 100%;
            }
            .welcome-btn {
                flex: 1;
                justify-content: center;
                min-width: 140px;
            }
        }
        
        @media (max-width: 480px) {
            .container { padding: 15px; }
            .stats-grid { grid-template-columns: 1fr; }
            .welcome-actions {
                flex-direction: column;
            }
            .welcome-btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <?php renderNavigation('dashboard'); ?>

    <div class="container">
        <!-- Welcome Header -->
        <div class="welcome-header">
            <div class="welcome-content">
                <div class="welcome-text">
                    <h1>Welcome back, <?php echo htmlspecialchars($_SESSION['username']); ?>! 👋</h1>
                    <p>Fund topics and track your impact on the creator community</p>
                </div>
                <div class="welcome-actions">
                    <a href="../topics/create.php" class="welcome-btn welcome-btn-primary">
                        💡 Propose New Topic
                    </a>
                    <a href="../creators/index.php" class="welcome-btn">
                        👥 Browse Creators
                    </a>
                </div>
            </div>
        </div>

        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number">$<?php echo number_format($user_stats->total_contributed, 0); ?></div>
                <div class="stat-label">Total Contributed</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $user_stats->total_contributions; ?></div>
                <div class="stat-label">Contributions Made</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $user_stats->topics_funded; ?></div>
                <div class="stat-label">Topics Supported</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo count($funded_topics); ?></div>
                <div class="stat-label">Topics Completed</div>
            </div>
        </div>

        <!-- Main Content Grid -->
        <div class="content-grid">
            <div class="main-content">
                <!-- Recent Contributions -->
                <div class="section">
                    <h2>Recent Activity</h2>
                    <?php if (empty($recent_contributions)): ?>
                        <div class="empty-state">
                            <h4>No contributions yet</h4>
                            <p>Start supporting creators by funding topics you're interested in!</p>
                            <a href="../topics/index.php" class="btn">Browse Active Topics</a>
                        </div>
                    <?php else: ?>
                        <div class="activity-feed">
                            <?php foreach ($recent_contributions as $contribution): ?>
                                <div class="contribution-item">
                                    <div class="contribution-details">
                                        <div class="topic-title">
                                            <a href="../topics/view.php?id=<?php echo $contribution->topic_id; ?>">
                                                <?php echo htmlspecialchars($contribution->topic_title); ?>
                                            </a>
                                        </div>
                                        <div class="topic-meta">
                                            By <?php echo htmlspecialchars($contribution->creator_name); ?> • 
                                            <?php echo date('M j, Y', strtotime($contribution->contributed_at)); ?> •
                                            <span class="topic-status status-<?php echo $contribution->topic_status; ?>">
                                                <?php echo ucfirst($contribution->topic_status); ?>
                                            </span>
                                        </div>
                                        <?php if ($contribution->topic_status === 'active'): ?>
                                            <div class="funding-bar" style="width: 200px;">
                                                <?php 
                                                $progress = ($contribution->current_funding / $contribution->funding_threshold) * 100;
                                                $progress = min($progress, 100);
                                                ?>
                                                <div class="funding-progress" style="width: <?php echo $progress; ?>%"></div>
                                            </div>
                                            <div style="font-size: 0.8rem; color: #6c757d; margin-top: 4px;">
                                                $<?php echo number_format($contribution->current_funding, 0); ?> / 
                                                $<?php echo number_format($contribution->funding_threshold, 0); ?> 
                                                (<?php echo round($progress); ?>%)
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="contribution-amount">
                                        $<?php echo number_format($contribution->amount, 2); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if (count($recent_contributions) >= 10): ?>
                            <div style="text-align: center; margin-top: 20px;">
                                <a href="contributions.php" class="btn">View All Contributions</a>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <!-- Topics You Created -->
                <div class="section">
                    <h2>Topics You Proposed</h2>
                    <?php if (empty($user_topics)): ?>
                        <div class="empty-state">
                            <h4>No topics proposed yet</h4>
                            <p>Have an idea for your favorite creator? Propose it and fund it to make it happen!</p>
                            <a href="../topics/create.php" class="btn btn-success">Propose Your First Topic</a>
                        </div>
                    <?php else: ?>
                        <?php foreach ($user_topics as $topic): ?>
                            <div class="topic-card">
                                <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 12px;">
                                    <h4 style="margin: 0; font-size: 1.1rem; font-weight: 600;">
                                        <?php echo htmlspecialchars($topic->title); ?>
                                    </h4>
                                    <span class="topic-status status-<?php echo $topic->status; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $topic->status)); ?>
                                    </span>
                                </div>
                                <div style="color: #6c757d; font-size: 0.875rem; margin-bottom: 12px;">
                                    For <?php echo htmlspecialchars($topic->creator_name); ?> • 
                                    Created <?php echo date('M j, Y', strtotime($topic->created_at)); ?>
                                </div>
                                <?php if ($topic->status === 'active'): ?>
                                    <?php 
                                    $progress = ($topic->current_funding / $topic->funding_threshold) * 100;
                                    $progress = min($progress, 100);
                                    ?>
                                    <div class="funding-bar">
                                        <div class="funding-progress" style="width: <?php echo $progress; ?>%"></div>
                                    </div>
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 12px;">
                                        <span style="font-size: 0.875rem; color: #6c757d;">
                                            $<?php echo number_format($topic->current_funding, 0); ?> / 
                                            $<?php echo number_format($topic->funding_threshold, 0); ?> 
                                            (<?php echo round($progress); ?>%)
                                        </span>
                                        <a href="../topics/view.php?id=<?php echo $topic->id; ?>" class="btn">View Details</a>
                                    </div>
                                <?php else: ?>
                                    <div style="margin-top: 12px;">
                                        <a href="../topics/view.php?id=<?php echo $topic->id; ?>" class="btn">View Details</a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="sidebar">
                <!-- Milestones -->
                <div class="section">
                    <h3>Recent Milestones</h3>
                    <?php if (empty($funded_topics)): ?>
                        <div class="empty-state">
                            <p style="font-size: 0.9rem;">No funded topics yet. Keep contributing to see your impact!</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($funded_topics as $milestone): ?>
                            <div class="milestone-item">
                                <div class="milestone-title">
                                    <?php echo htmlspecialchars($milestone->title); ?>
                                </div>
                                <div class="milestone-meta">
                                    <span><?php echo htmlspecialchars($milestone->creator_name); ?></span>
                                    <span class="milestone-badge">Funded!</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
