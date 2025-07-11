<?php
// creators/profile.php - Creator profile with simplified navigation
session_start();
require_once '../config/database.php';
require_once '../config/navigation.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$helper = new DatabaseHelper();
$creator_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$creator_id) {
    header('Location: ../index.php');
    exit;
}

$creator = $helper->getCreatorById($creator_id);
if (!$creator) {
    header('Location: ../index.php');
    exit;
}

// Get creator's topics by status
$db = new Database();

// Active topics
$db->query('SELECT * FROM topics WHERE creator_id = :creator_id AND status = "active" ORDER BY created_at DESC');
$db->bind(':creator_id', $creator_id);
$active_topics = $db->resultSet();

// Funded topics
$db->query('SELECT * FROM topics WHERE creator_id = :creator_id AND status = "funded" ORDER BY funded_at DESC');
$db->bind(':creator_id', $creator_id);
$funded_topics = $db->resultSet();

// Completed topics
$db->query('SELECT * FROM topics WHERE creator_id = :creator_id AND status = "completed" ORDER BY completed_at DESC');
$db->bind(':creator_id', $creator_id);
$completed_topics = $db->resultSet();
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo htmlspecialchars($creator->display_name); ?> - TopicLaunch</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .creator-header { background: white; padding: 30px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .creator-info { display: flex; gap: 25px; align-items: start; flex-wrap: wrap; }
        .creator-avatar { width: 120px; height: 120px; background: linear-gradient(135deg, #667eea, #764ba2); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 48px; color: white; font-weight: bold; flex-shrink: 0; }
        .creator-details { flex: 1; min-width: 300px; }
        .creator-details h1 { margin: 0 0 20px 0; color: #333; font-size: 28px; }
        .creator-actions { display: flex; gap: 15px; margin-top: 20px; flex-wrap: wrap; }
        .btn { background: #667eea; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; display: inline-block; font-weight: 500; transition: background 0.3s; }
        .btn:hover { background: #5a6fd8; color: white; text-decoration: none; }
        .btn-success { background: #28a745; }
        .btn-success:hover { background: #218838; }
        .btn-outline { background: transparent; color: #667eea; border: 2px solid #667eea; }
        .btn-outline:hover { background: #667eea; color: white; }
        .content-tabs { display: flex; gap: 0; margin-bottom: 20px; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .tab { padding: 15px 25px; border: none; background: transparent; cursor: pointer; font-size: 16px; font-weight: 500; color: #666; transition: all 0.3s; }
        .tab.active { background: #667eea; color: white; }
        .tab:hover:not(.active) { background: #f8f9fa; }
        .tab-badge { background: rgba(255,255,255,0.3); color: white; padding: 2px 8px; border-radius: 10px; font-size: 12px; margin-left: 8px; }
        .tab-content { background: white; padding: 25px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .topic-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 20px; }
        .topic-card { border: 1px solid #e9ecef; padding: 20px; border-radius: 8px; transition: transform 0.3s, box-shadow 0.3s; }
        .topic-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .topic-title { font-weight: bold; font-size: 18px; margin-bottom: 10px; color: #333; }
        .topic-meta { color: #666; font-size: 14px; margin-bottom: 15px; }
        .topic-description { color: #666; line-height: 1.5; margin-bottom: 15px; }
        .funding-bar { background: #e9ecef; height: 8px; border-radius: 4px; margin: 15px 0; }
        .funding-progress { background: linear-gradient(90deg, #28a745, #20c997); height: 100%; border-radius: 4px; transition: width 0.3s; }
        .funding-info { display: flex; justify-content: space-between; align-items: center; margin-top: 15px; }
        .funding-stats { font-size: 14px; color: #666; }
        .funding-amount { font-weight: bold; color: #28a745; }
        .status-badge { padding: 6px 12px; border-radius: 12px; font-size: 12px; font-weight: bold; }
        .status-active { background: #fff3cd; color: #856404; }
        .status-funded { background: #d4edda; color: #155724; }
        .status-completed { background: #cce5ff; color: #004085; }
        .empty-state { text-align: center; color: #666; padding: 40px; }
        .completion-info { background: #d4edda; padding: 15px; border-radius: 6px; margin-top: 15px; }
        .deadline-warning { background: #fff3cd; padding: 15px; border-radius: 6px; margin-top: 15px; }
        .content-link { background: #28a745; color: white; padding: 8px 16px; text-decoration: none; border-radius: 4px; display: inline-block; margin-top: 10px; }
        .content-link:hover { background: #218838; color: white; text-decoration: none; }
        
        @media (max-width: 768px) {
            .container { padding: 15px; }
            .creator-info { flex-direction: column; text-align: center; }
            .content-tabs { flex-direction: column; }
            .topic-grid { grid-template-columns: 1fr; }
            .creator-actions { justify-content: center; }
        }
    </style>
</head>
<body>
    <?php renderNavigation('browse_creators'); ?>

    <div class="container">
        <div class="creator-header">
            <div class="creator-info">
                <div class="creator-avatar">
                    <?php if ($creator->profile_image && file_exists('../uploads/creators/' . $creator->profile_image)): ?>
                        <img src="../uploads/creators/<?php echo htmlspecialchars($creator->profile_image); ?>" 
                             alt="Profile" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                    <?php else: ?>
                        <?php echo strtoupper(substr($creator->display_name, 0, 1)); ?>
                    <?php endif; ?>
                </div>
                <div class="creator-details">
                    <h1><?php echo htmlspecialchars($creator->display_name); ?></h1>
                    

                    
                    <div class="creator-actions">
                        <a href="../topics/create.php?creator_id=<?php echo $creator->id; ?>" class="btn btn-success">💡 Propose New Topic</a>
                        <?php if ($creator->platform_url): ?>
                            <a href="<?php echo htmlspecialchars($creator->platform_url); ?>" target="_blank" class="btn btn-outline">Visit Channel</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Topic Tabs -->
        <div class="content-tabs">
            <button class="tab active" onclick="showTab('active')">
                Active Topics
                <?php if (count($active_topics) > 0): ?>
                    <span class="tab-badge"><?php echo count($active_topics); ?></span>
                <?php endif; ?>
            </button>
            <button class="tab" onclick="showTab('funded')">
                Funded Topics
                <?php if (count($funded_topics) > 0): ?>
                    <span class="tab-badge"><?php echo count($funded_topics); ?></span>
                <?php endif; ?>
            </button>
            <button class="tab" onclick="showTab('completed')">
                Completed Topics
                <?php if (count($completed_topics) > 0): ?>
                    <span class="tab-badge"><?php echo count($completed_topics); ?></span>
                <?php endif; ?>
            </button>
        </div>

        <!-- Active Topics Tab -->
        <div id="active-tab" class="tab-content">
            <h2>Active Topics Seeking Funding</h2>
            <?php if (empty($active_topics)): ?>
                <div class="empty-state">
                    <h3>No active topics</h3>
                    <p>This creator doesn't have any topics seeking funding right now.</p>
                </div>
            <?php else: ?>
                <div class="topic-grid">
                    <?php foreach ($active_topics as $topic): ?>
                    <div class="topic-card">
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 15px;">
                            <span class="status-badge status-active">Active</span>
                            <div style="font-size: 12px; color: #666;">
                                Created <?php echo date('M j, Y', strtotime($topic->created_at)); ?>
                            </div>
                        </div>
                        
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
                            <div class="funding-stats">
                                <span class="funding-amount">$<?php echo number_format($topic->current_funding, 2); ?></span>
                                of $<?php echo number_format($topic->funding_threshold, 2); ?>
                                (<?php echo round($progress_percent, 1); ?>%)
                            </div>
                            <a href="../topics/fund.php?id=<?php echo $topic->id; ?>" class="btn">Fund This Topic</a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Funded Topics Tab -->
        <div id="funded-tab" class="tab-content" style="display: none;">
            <h2>Funded Topics - Content Coming Soon!</h2>
            <?php if (empty($funded_topics)): ?>
                <div class="empty-state">
                    <h3>No funded topics</h3>
                    <p>This creator doesn't have any topics that are currently funded and awaiting content creation.</p>
                </div>
            <?php else: ?>
                <div class="topic-grid">
                    <?php foreach ($funded_topics as $topic): ?>
                    <div class="topic-card">
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 15px;">
                            <span class="status-badge status-funded">Funded</span>
                            <div style="font-size: 12px; color: #666;">
                                Funded <?php echo date('M j, Y', strtotime($topic->funded_at)); ?>
                            </div>
                        </div>
                        
                        <h3 class="topic-title"><?php echo htmlspecialchars($topic->title); ?></h3>
                        <p class="topic-description"><?php echo htmlspecialchars(substr($topic->description, 0, 150)) . (strlen($topic->description) > 150 ? '...' : ''); ?></p>
                        
                        <div class="completion-info">
                            <strong>✅ Fully Funded!</strong><br>
                            Raised: $<?php echo number_format($topic->current_funding, 2); ?>
                        </div>
                        
                        <?php if ($topic->content_deadline): ?>
                            <div class="deadline-warning">
                                <strong>⏰ Content Due:</strong><br>
                                <?php echo date('M j, Y g:i A', strtotime($topic->content_deadline)); ?>
                            </div>
                        <?php endif; ?>
                        
                        <div style="margin-top: 15px;">
                            <a href="../topics/view.php?id=<?php echo $topic->id; ?>" class="btn">View Details</a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Completed Topics Tab -->
        <div id="completed-tab" class="tab-content" style="display: none;">
            <h2>Completed Topics</h2>
            <?php if (empty($completed_topics)): ?>
                <div class="empty-state">
                    <h3>No completed topics yet</h3>
                    <p>This creator hasn't completed any topics yet, but they're working on it!</p>
                </div>
            <?php else: ?>
                <div class="topic-grid">
                    <?php foreach ($completed_topics as $topic): ?>
                    <div class="topic-card">
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 15px;">
                            <span class="status-badge status-completed">Completed</span>
                            <div style="font-size: 12px; color: #666;">
                                Completed <?php echo date('M j, Y', strtotime($topic->completed_at)); ?>
                            </div>
                        </div>
                        
                        <h3 class="topic-title"><?php echo htmlspecialchars($topic->title); ?></h3>
                        <p class="topic-description"><?php echo htmlspecialchars(substr($topic->description, 0, 150)) . (strlen($topic->description) > 150 ? '...' : ''); ?></p>
                        
                        <div class="completion-info">
                            <strong>✅ Content Delivered!</strong><br>
                            Raised: $<?php echo number_format($topic->current_funding, 2); ?>
                        </div>
                        
                        <div style="margin-top: 15px;">
                            <?php if ($topic->content_url): ?>
                                <a href="<?php echo htmlspecialchars($topic->content_url); ?>" target="_blank" class="content-link">View Content</a>
                            <?php endif; ?>
                            <a href="../topics/view.php?id=<?php echo $topic->id; ?>" class="btn" style="margin-left: 10px;">View Details</a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    function showTab(tabName) {
        // Hide all tab contents
        document.querySelectorAll('.tab-content').forEach(content => {
            content.style.display = 'none';
        });
        
        // Remove active class from all tabs
        document.querySelectorAll('.tab').forEach(tab => {
            tab.classList.remove('active');
        });
        
        // Show selected tab content
        document.getElementById(tabName + '-tab').style.display = 'block';
        
        // Add active class to clicked tab
        event.target.classList.add('active');
    }
    </script>
</body>
</html>
