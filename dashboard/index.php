<?php
// dashboard/index.php - Complete fixed file without broken HTML
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
    // Redirect to creator dashboard
    header('Location: ../creators/dashboard.php');
    exit;
}

// ==============================================================================
// FAN DASHBOARD - For non-creators
// ==============================================================================

// Get user's contribution statistics
$db->query('
    SELECT 
        COUNT(*) as total_contributions,
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
    LIMIT 5
');
$db->bind(':user_id', $user_id);
$recent_contributions = $db->resultSet();
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
            text-align: center;
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
        
        /* Main Content */
        .main-content { 
            background: white; 
            padding: 30px; 
            border-radius: 16px; 
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(0, 0, 0, 0.05);
        }
        .main-content h2 { 
            margin: 0 0 24px 0; 
            color: #212529; 
            font-size: 1.5rem;
            font-weight: 600;
            letter-spacing: -0.01em;
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
        
        /* Topic Status */
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
        
        /* Activity Feed */
        .activity-feed { 
            max-height: 500px; 
            overflow-y: auto; 
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
        
        @media (max-width: 480px) {
            .container { padding: 15px; }
            .stats-grid { grid-template-columns: 1fr; }
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
                    <h1>Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?>! 👋</h1>
                    <p>Fund topics and support your favorite creators</p>
                </div>
            </div>
        </div>

        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $user_stats->total_contributions; ?></div>
                <div class="stat-label">Contributions Made</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $user_stats->topics_funded; ?></div>
                <div class="stat-label">Topics Supported</div>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="main-content">
            <h2>Recent Activity</h2>
            <?php if (empty($recent_contributions)): ?>
                <div class="empty-state">
                    <h4>Your activity will appear here</h4>
                    <p>Once you start funding topics, you'll see your recent activity here!</p>
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
                
                <div style="text-align: center; margin-top: 30px;">
                    <a href="contributions.php" class="btn" style="margin-left: 15px;">View All Contributions</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
