<?php
// dashboard/index.php - User Dashboard
session_start();
require_once '../config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$helper = new DatabaseHelper();
$user_id = $_SESSION['user_id'];

// Get user's contribution statistics
$db = new Database();

// Get user's total contributions
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

// Calculate user level based on contributions
$total_contributed = $user_stats->total_contributed;
if ($total_contributed >= 500) {
    $user_level = "Platinum Supporter";
    $level_color = "#e5e4e2";
} elseif ($total_contributed >= 200) {
    $user_level = "Gold Supporter";
    $level_color = "#ffd700";
} elseif ($total_contributed >= 50) {
    $user_level = "Silver Supporter";
    $level_color = "#c0c0c0";
} else {
    $user_level = "Bronze Supporter";
    $level_color = "#cd7f32";
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>My Dashboard - Topic Funding</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; }
        .nav { margin-bottom: 20px; }
        .nav a { color: #007bff; text-decoration: none; margin-right: 15px; }
        .nav a:hover { text-decoration: underline; }
        .header { background: white; padding: 30px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .user-info { display: flex; justify-content: space-between; align-items: center; }
        .user-details h1 { margin: 0 0 10px 0; color: #333; }
        .user-level { padding: 8px 16px; border-radius: 20px; font-weight: bold; color: white; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; padding: 25px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); text-align: center; }
        .stat-number { font-size: 32px; font-weight: bold; color: #007bff; margin-bottom: 5px; }
        .stat-label { color: #666; font-size: 14px; }
        .content-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; }
        .main-content { display: flex; flex-direction: column; gap: 20px; }
        .sidebar { display: flex; flex-direction: column; gap: 20px; }
        .section { background: white; padding: 25px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .section h2 { margin-top: 0; color: #333; }
        .contribution-item { display: flex; justify-content: space-between; align-items: start; padding: 15px 0; border-bottom: 1px solid #eee; }
        .contribution-item:last-child { border-bottom: none; }
        .contribution-details { flex: 1; }
        .topic-title { font-weight: bold; color: #333; margin-bottom: 5px; }
        .topic-meta { color: #666; font-size: 12px; }
        .contribution-amount { font-weight: bold; color: #28a745; font-size: 18px; }
        .topic-card { border: 1px solid #e9ecef; padding: 15px; border-radius: 5px; margin-bottom: 15px; }
        .topic-status { padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: bold; }
        .status-active { background: #fff3cd; color: #856404; }
        .status-funded { background: #d4edda; color: #155724; }
        .status-completed { background: #cce5ff; color: #004085; }
        .funding-bar { background: #e9ecef; height: 6px; border-radius: 3px; margin: 10px 0; }
        .funding-progress { background: #28a745; height: 100%; border-radius: 3px; transition: width 0.3s; }
        .btn { background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; display: inline-block; font-size: 14px; }
        .btn:hover { background: #0056b3; }
        .btn-success { background: #28a745; }
        .btn-success:hover { background: #218838; }
        .empty-state { text-align: center; color: #666; padding: 30px; }
        .milestone-item { padding: 10px 0; border-bottom: 1px solid #eee; }
        .milestone-item:last-child { border-bottom: none; }
        .achievement-badge { background: #28a745; color: white; padding: 4px 8px; border-radius: 4px; font-size: 12px; }
        .quick-actions { display: flex; gap: 10px; margin-top: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="nav">
            <a href="../index.php">← Back to Home</a>
            <a href="../topics/index.php">Browse Topics</a>
            <a href="../creators/index.php">Browse Creators</a>
        </div>

        <div class="header">
            <div class="user-info">
                <div class="user-details">
                    <h1>Welcome back, <?php echo htmlspecialchars($_SESSION['username']); ?>!</h1>
                    <p style="margin: 0; color: #666;">Track your contributions and discover new topics to fund</p>
                </div>
                <div>
                    <span class="user-level" style="background-color: <?php echo $level_color; ?>;">
                        <?php echo $user_level; ?>
                    </span>
                </div>
            </div>
        </div>

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
                <div class="stat-label">Topics Funded</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo count($funded_topics); ?></div>
                <div class="stat-label">Topics Completed</div>
            </div>
        </div>

        <div class="content-grid">
            <div class="main-content">
                <!-- Recent Contributions -->
                <div class="section">
                    <h2>Recent Contributions</h2>
                    <?php if (empty($recent_contributions)): ?>
                        <div class="empty-state">
                            <p>You haven't made any contributions yet.</p>
                            <a href="../topics/index.php" class="btn">Browse Topics</a>
                        </div>
                    <?php else: ?>
                        <?php foreach ($recent_contributions as $contribution): ?>
                            <div class="contribution-item">
                                <div class="contribution-details">
                                    <div class="topic-title"><?php echo htmlspecialchars($contribution->topic_title); ?></div>
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
                                    <?php endif; ?>
                                </div>
                                <div class="contribution-amount">
                                    $<?php echo number_format($contribution->amount, 2); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (count($recent_contributions) >= 10): ?>
                            <div style="text-align: center; margin-top: 15px;">
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
                            <p>You haven't proposed any topics yet.</p>
                            <a href="../topics/create.php" class="btn btn-success">Propose Your First Topic</a>
                        </div>
                    <?php else: ?>
                        <?php foreach ($user_topics as $topic): ?>
                            <div class="topic-card">
                                <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 10px;">
                                    <h4 style="margin: 0;"><?php echo htmlspecialchars($topic->title); ?></h4>
                                    <span class="topic-status status-<?php echo $topic->status; ?>">
                                        <?php echo ucfirst($topic->status); ?>
                                    </span>
                                </div>
                                <p style="margin: 5px 0; color: #666; font-size: 14px;">
                                    For <?php echo htmlspecialchars($topic->creator_name); ?> • 
                                    Created <?php echo date('M j, Y', strtotime($topic->created_at)); ?>
                                </p>
                                <?php if ($topic->status === 'active'): ?>
                                    <?php 
                                    $progress = ($topic->current_funding / $topic->funding_threshold) * 100;
                                    $progress = min($progress, 100);
                                    ?>
                                    <div class="funding-bar">
                                        <div class="funding-progress" style="width: <?php echo $progress; ?>%"></div>
                                    </div>
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 10px;">
                                        <span style="font-size: 14px;">
                                            $<?php echo number_format($topic->current_funding, 0); ?> / 
                                            $<?php echo number_format($topic->funding_threshold, 0); ?> 
                                            (<?php echo round($progress); ?>%)
                                        </span>
                                        <a href="../topics/view.php?id=<?php echo $topic->id; ?>" class="btn">View Details</a>
                                    </div>
                                <?php else: ?>
                                    <div style="margin-top: 10px;">
                                        <a href="../topics/view.php?id=<?php echo $topic->id; ?>" class="btn">View Details</a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="sidebar">
                <!-- Quick Actions -->
                <div class="section">
                    <h3 style="margin-top: 0;">Quick Actions</h3>
                    <div class="quick-actions" style="flex-direction: column;">
                        <a href="../topics/create.php" class="btn btn-success">Propose New Topic</a>
                        <a href="../topics/index.php" class="btn">Browse Active Topics</a>
                        <a href="../creators/apply.php" class="btn">Become a Creator</a>
                    </div>
                </div>

                <!-- Achievements/Milestones -->
                <div class="section">
                    <h3 style="margin-top: 0;">Recent Milestones</h3>
                    <?php if (empty($funded_topics)): ?>
                        <div class="empty-state">
                            <p style="font-size: 14px;">No funded topics yet. Keep contributing!</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($funded_topics as $milestone): ?>
                            <div class="milestone-item">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <div>
                                        <div style="font-weight: bold; font-size: 14px;">
                                            <?php echo htmlspecialchars($milestone->title); ?>
                                        </div>
                                        <div style="color: #666; font-size: 12px;">
                                            <?php echo htmlspecialchars($milestone->creator_name); ?>
                                        </div>
                                    </div>
                                    <span class="achievement-badge">Funded!</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Contribution Tips -->
                <div class="section">
                    <h3 style="margin-top: 0;">💡 Tips</h3>
                    <ul style="margin: 0; padding-left: 20px; color: #666; font-size: 14px;">
                        <li>Contribute early to show creators there's interest</li>
                        <li>Share topics with friends to reach funding goals faster</li>
                        <li>Follow your favorite creators for new topic updates</li>
                        <li>Propose topics you're passionate about</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
