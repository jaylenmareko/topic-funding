<?php
// creators/profile.php
session_start();
require_once '../config/database.php';

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

// Get creator's topics
$active_topics = $helper->getCreatorTopics($creator_id, 'active');
$funded_topics = $helper->getCreatorTopics($creator_id, 'funded');
$completed_topics = $helper->getCreatorTopics($creator_id, 'completed');
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo htmlspecialchars($creator->display_name); ?> - Topic Funding</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; }
        .header { background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .creator-info { display: flex; gap: 20px; align-items: start; }
        .creator-image { width: 120px; height: 120px; background: #ddd; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 48px; color: #666; }
        .creator-details h1 { margin: 0 0 10px 0; color: #333; }
        .creator-stats { display: flex; gap: 20px; margin: 15px 0; }
        .stat { background: #f8f9fa; padding: 10px 15px; border-radius: 5px; text-align: center; }
        .stat-number { font-size: 20px; font-weight: bold; color: #007bff; }
        .stat-label { font-size: 12px; color: #666; }
        .nav { margin-bottom: 20px; }
        .nav a { color: #007bff; text-decoration: none; margin-right: 15px; }
        .nav a:hover { text-decoration: underline; }
        .section { background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .section h2 { margin-top: 0; color: #333; }
        .topic-card { border: 1px solid #e9ecef; padding: 15px; border-radius: 5px; margin-bottom: 15px; }
        .topic-title { font-weight: bold; font-size: 18px; margin-bottom: 8px; }
        .topic-meta { color: #666; font-size: 14px; margin-bottom: 10px; }
        .funding-bar { background: #e9ecef; height: 8px; border-radius: 4px; margin: 10px 0; }
        .funding-progress { background: #28a745; height: 100%; border-radius: 4px; transition: width 0.3s; }
        .funding-info { display: flex; justify-content: space-between; align-items: center; margin-top: 10px; }
        .btn { background: #007bff; color: white; padding: 8px 16px; text-decoration: none; border-radius: 4px; display: inline-block; border: none; cursor: pointer; }
        .btn:hover { background: #0056b3; }
        .btn-success { background: #28a745; }
        .btn-success:hover { background: #218838; }
        .btn-secondary { background: #6c757d; }
        .empty-state { text-align: center; color: #666; padding: 40px; }
        .create-topic-btn { background: #28a745; font-size: 16px; padding: 12px 24px; }
        .create-topic-btn:hover { background: #218838; }
    </style>
</head>
<body>
    <div class="container">
        <div class="nav">
            <a href="../index.php">← Back to Creators</a>
            <a href="../dashboard/index.php">My Dashboard</a>
        </div>

        <div class="header">
            <div class="creator-info">
                <div class="creator-image">
                    <?php if ($creator->profile_image): ?>
                        <img src="../uploads/creators/<?php echo htmlspecialchars($creator->profile_image); ?>" alt="Profile" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                    <?php else: ?>
                        <?php echo strtoupper(substr($creator->display_name, 0, 1)); ?>
                    <?php endif; ?>
                </div>
                <div class="creator-details">
                    <h1><?php echo htmlspecialchars($creator->display_name); ?></h1>
                    <p><?php echo htmlspecialchars($creator->bio); ?></p>
                    
                    <div class="creator-stats">
                        <div class="stat">
                            <div class="stat-number"><?php echo number_format($creator->subscriber_count); ?></div>
                            <div class="stat-label">Subscribers</div>
                        </div>
                        <div class="stat">
                            <div class="stat-number"><?php echo ucfirst($creator->platform_type); ?></div>
                            <div class="stat-label">Platform</div>
                        </div>
                        <div class="stat">
                            <div class="stat-number">$<?php echo number_format($creator->default_funding_threshold); ?></div>
                            <div class="stat-label">Default Funding</div>
                        </div>
                    </div>
                    
                    <?php if ($creator->platform_url): ?>
                        <a href="<?php echo htmlspecialchars($creator->platform_url); ?>" target="_blank" class="btn">Visit Channel</a>
                    <?php endif; ?>
                    
                    <a href="../topics/create.php?creator_id=<?php echo $creator->id; ?>" class="btn create-topic-btn">Propose New Topic</a>
                </div>
            </div>
        </div>

        <!-- Active Topics -->
        <div class="section">
            <h2>Active Topics (<?php echo count($active_topics); ?>)</h2>
            <?php if (empty($active_topics)): ?>
                <div class="empty-state">
                    <p>No active topics yet. Be the first to propose a topic!</p>
                    <a href="../topics/create.php?creator_id=<?php echo $creator->id; ?>" class="btn">Propose Topic</a>
                </div>
            <?php else: ?>
                <?php foreach ($active_topics as $topic): ?>
                    <div class="topic-card">
                        <div class="topic-title"><?php echo htmlspecialchars($topic->title); ?></div>
                        <div class="topic-meta">
                            Created <?php echo date('M j, Y', strtotime($topic->created_at)); ?>
                        </div>
                        <p><?php echo htmlspecialchars($topic->description); ?></p>
                        
                        <?php 
                        $progress_percent = ($topic->current_funding / $topic->funding_threshold) * 100;
                        $progress_percent = min($progress_percent, 100);
                        ?>
                        
                        <div class="funding-bar">
                            <div class="funding-progress" style="width: <?php echo $progress_percent; ?>%"></div>
                        </div>
                        
                        <div class="funding-info">
                            <span>
                                <strong>$<?php echo number_format($topic->current_funding, 2); ?></strong> 
                                of $<?php echo number_format($topic->funding_threshold, 2); ?> 
                                (<?php echo round($progress_percent, 1); ?>%)
                            </span>
                            <a href="../topics/fund.php?id=<?php echo $topic->id; ?>" class="btn">Fund This Topic</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Funded Topics -->
        <?php if (!empty($funded_topics)): ?>
        <div class="section">
            <h2>Funded Topics - Content Coming Soon! (<?php echo count($funded_topics); ?>)</h2>
            <?php foreach ($funded_topics as $topic): ?>
                <div class="topic-card">
                    <div class="topic-title"><?php echo htmlspecialchars($topic->title); ?></div>
                    <div class="topic-meta">
                        Funded on <?php echo date('M j, Y', strtotime($topic->funded_at)); ?>
                        <?php if ($topic->content_deadline): ?>
                            | Content due: <?php echo date('M j, Y g:i A', strtotime($topic->content_deadline)); ?>
                        <?php endif; ?>
                    </div>
                    <p><?php echo htmlspecialchars($topic->description); ?></p>
                    
                    <div class="funding-info">
                        <span style="color: #28a745;">
                            <strong>✅ Fully Funded!</strong> 
                            $<?php echo number_format($topic->current_funding, 2); ?> raised
                        </span>
                        <span class="btn btn-success">Waiting for Content</span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Completed Topics -->
        <?php if (!empty($completed_topics)): ?>
        <div class="section">
            <h2>Completed Topics (<?php echo count($completed_topics); ?>)</h2>
            <?php foreach ($completed_topics as $topic): ?>
                <div class="topic-card">
                    <div class="topic-title"><?php echo htmlspecialchars($topic->title); ?></div>
                    <div class="topic-meta">
                        Completed on <?php echo date('M j, Y', strtotime($topic->completed_at)); ?>
                    </div>
                    <p><?php echo htmlspecialchars($topic->description); ?></p>
                    
                    <div class="funding-info">
                        <span style="color: #28a745;">
                            <strong>✅ Completed!</strong> 
                            $<?php echo number_format($topic->current_funding, 2); ?> raised
                        </span>
                        <?php if ($topic->content_url): ?>
                            <a href="<?php echo htmlspecialchars($topic->content_url); ?>" target="_blank" class="btn">View Content</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
