<?php
// topics/index.php - Browse all topics with simplified navigation
session_start();
require_once '../config/database.php';
require_once '../config/navigation.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$helper = new DatabaseHelper();

// Get filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build query based on filters
$db = new Database();
$where_conditions = [];
$params = [];

if ($status_filter !== 'all') {
    $where_conditions[] = 't.status = :status';
    $params[':status'] = $status_filter;
} else {
    // For "all", show active, funded, and completed topics
    $where_conditions[] = 't.status IN ("active", "funded", "completed")';
}

if ($search) {
    $where_conditions[] = '(t.title LIKE :search OR t.description LIKE :search OR c.display_name LIKE :search)';
    $params[':search'] = '%' . $search . '%';
}

$where_clause = empty($where_conditions) ? '' : 'WHERE ' . implode(' AND ', $where_conditions);

$query = "
    SELECT t.*, c.display_name as creator_name, c.profile_image as creator_image,
           (SELECT COUNT(*) FROM contributions WHERE topic_id = t.id AND payment_status = 'completed') as contributor_count
    FROM topics t
    JOIN creators c ON t.creator_id = c.id
    {$where_clause}
    ORDER BY 
        CASE WHEN t.status = 'active' THEN 1
             WHEN t.status = 'funded' THEN 2
             WHEN t.status = 'completed' THEN 3
             ELSE 4 END,
        t.created_at DESC
";

$db->query($query);
foreach ($params as $param => $value) {
    $db->bind($param, $value);
}
$topics = $db->resultSet();

// Get topic counts for filter badges
$db->query('SELECT status, COUNT(*) as count FROM topics WHERE status IN ("active", "funded", "completed") GROUP BY status');
$status_counts = [];
foreach ($db->resultSet() as $row) {
    $status_counts[$row->status] = $row->count;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Browse Topics - TopicLaunch</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .header { background: white; padding: 30px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .header h1 { margin: 0 0 15px 0; color: #333; }
        .filters { display: flex; gap: 20px; align-items: center; flex-wrap: wrap; margin-bottom: 20px; }
        .filter-tabs { display: flex; gap: 10px; }
        .filter-tab { padding: 10px 20px; border-radius: 20px; text-decoration: none; color: #666; background: white; border: 2px solid #e9ecef; font-weight: 500; transition: all 0.3s; }
        .filter-tab:hover, .filter-tab.active { background: #667eea; color: white; border-color: #667eea; text-decoration: none; }
        .filter-badge { background: #ff6b6b; color: white; padding: 2px 8px; border-radius: 10px; font-size: 12px; margin-left: 5px; }
        .search-box { flex: 1; max-width: 300px; }
        .search-box input { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 25px; font-size: 16px; }
        .search-box button { background: #667eea; color: white; padding: 12px 20px; border: none; border-radius: 25px; margin-left: 10px; cursor: pointer; }
        .topics-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(400px, 1fr)); gap: 25px; }
        .topic-card { background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); transition: transform 0.3s, box-shadow 0.3s; }
        .topic-card:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,0.12); }
        .topic-header { display: flex; justify-content: space-between; align-items: start; margin-bottom: 15px; }
        .topic-title { font-size: 18px; font-weight: bold; color: #333; margin: 0 0 10px 0; line-height: 1.3; }
        .topic-meta { color: #666; font-size: 14px; margin-bottom: 15px; }
        .creator-info { display: flex; align-items: center; gap: 10px; margin-bottom: 15px; }
        .creator-avatar { width: 40px; height: 40px; background: linear-gradient(135deg, #667eea, #764ba2); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; }
        .status-badge { padding: 6px 12px; border-radius: 15px; font-size: 12px; font-weight: bold; }
        .status-active { background: #fff3cd; color: #856404; }
        .status-funded { background: #d4edda; color: #155724; }
        .status-completed { background: #cce5ff; color: #004085; }
        .funding-bar { background: #e9ecef; height: 8px; border-radius: 4px; margin: 15px 0; }
        .funding-progress { background: linear-gradient(90deg, #28a745, #20c997); height: 100%; border-radius: 4px; transition: width 0.5s ease; }
        .funding-info { display: flex; justify-content: space-between; align-items: center; margin: 10px 0; }
        .funding-stats { font-size: 14px; color: #666; }
        .funding-amount { font-weight: bold; color: #28a745; }
        .btn { background: #667eea; color: white; padding: 10px 20px; text-decoration: none; border-radius: 6px; display: inline-block; font-weight: 500; transition: background 0.3s; }
        .btn:hover { background: #5a6fd8; color: white; text-decoration: none; }
        .btn-success { background: #28a745; }
        .btn-success:hover { background: #218838; }
        .empty-state { text-align: center; color: #666; padding: 60px 20px; background: white; border-radius: 12px; }
        .quick-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; padding: 20px; border-radius: 8px; text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .stat-number { font-size: 24px; font-weight: bold; color: #667eea; }
        .stat-label { color: #666; font-size: 14px; }
        .trending-badge { background: linear-gradient(45deg, #ff6b6b, #feca57); color: white; padding: 4px 8px; border-radius: 12px; font-size: 11px; margin-left: 10px; }
        
        @media (max-width: 768px) {
            .container { padding: 15px; }
            .filters { flex-direction: column; align-items: stretch; }
            .filter-tabs { justify-content: center; }
            .topics-grid { grid-template-columns: 1fr; }
            .search-box { max-width: none; }
        }
    </style>
</head>
<body>
    <?php renderNavigation('browse_topics'); ?>

    <div class="container">
        <div class="header">
            <h1>Browse Active Topics</h1>
            <p>Fund the topics you want to see covered by your favorite creators</p>
            
            <!-- Quick Stats -->
            <div class="quick-stats">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $status_counts['active'] ?? 0; ?></div>
                    <div class="stat-label">Active Topics</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $status_counts['funded'] ?? 0; ?></div>
                    <div class="stat-label">Funded Topics</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $status_counts['completed'] ?? 0; ?></div>
                    <div class="stat-label">Completed Topics</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo count($topics); ?></div>
                    <div class="stat-label">Total Showing</div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters">
                <div class="filter-tabs">
                    <a href="?status=all&search=<?php echo urlencode($search); ?>" 
                       class="filter-tab <?php echo $status_filter === 'all' ? 'active' : ''; ?>">
                        All Topics
                        <span class="filter-badge"><?php echo array_sum($status_counts); ?></span>
                    </a>
                    <a href="?status=active&search=<?php echo urlencode($search); ?>" 
                       class="filter-tab <?php echo $status_filter === 'active' ? 'active' : ''; ?>">
                        Active
                        <?php if (isset($status_counts['active']) && $status_counts['active'] > 0): ?>
                            <span class="filter-badge"><?php echo $status_counts['active']; ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="?status=funded&search=<?php echo urlencode($search); ?>" 
                       class="filter-tab <?php echo $status_filter === 'funded' ? 'active' : ''; ?>">
                        Funded
                        <?php if (isset($status_counts['funded']) && $status_counts['funded'] > 0): ?>
                            <span class="filter-badge"><?php echo $status_counts['funded']; ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="?status=completed&search=<?php echo urlencode($search); ?>" 
                       class="filter-tab <?php echo $status_filter === 'completed' ? 'active' : ''; ?>">
                        Completed
                        <?php if (isset($status_counts['completed']) && $status_counts['completed'] > 0): ?>
                            <span class="filter-badge"><?php echo $status_counts['completed']; ?></span>
                        <?php endif; ?>
                    </a>
                </div>
                
                <form method="GET" class="search-box">
                    <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
                    <input type="text" name="search" placeholder="Search topics or creators..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit">Search</button>
                </form>
            </div>
        </div>

        <!-- Topics Grid -->
        <?php if (empty($topics)): ?>
            <div class="empty-state">
                <h3>No topics found</h3>
                <?php if ($search): ?>
                    <p>No topics match "<?php echo htmlspecialchars($search); ?>"</p>
                    <a href="?status=<?php echo $status_filter; ?>" class="btn">Clear Search</a>
                <?php else: ?>
                    <p>No topics in this category yet.</p>
                    <a href="create.php" class="btn btn-success">Propose the First Topic</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="topics-grid">
                <?php foreach ($topics as $topic): ?>
                    <div class="topic-card">
                        <div class="topic-header">
                            <span class="status-badge status-<?php echo $topic->status; ?>">
                                <?php echo ucfirst($topic->status); ?>
                            </span>
                            <?php if ($topic->status === 'active' && $topic->contributor_count >= 5): ?>
                                <span class="trending-badge">🔥 Trending</span>
                            <?php endif; ?>
                        </div>

                        <h3 class="topic-title"><?php echo htmlspecialchars($topic->title); ?></h3>
                        
                        <div class="creator-info">
                            <div class="creator-avatar">
                                <?php if ($topic->creator_image): ?>
                                    <img src="../uploads/creators/<?php echo htmlspecialchars($topic->creator_image); ?>" 
                                         alt="Creator" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                                <?php else: ?>
                                    <?php echo strtoupper(substr($topic->creator_name, 0, 1)); ?>
                                <?php endif; ?>
                            </div>
                            <div>
                                <strong><?php echo htmlspecialchars($topic->creator_name); ?></strong>
                                <div style="font-size: 12px; color: #666;">
                                    <?php echo $topic->contributor_count; ?> contributors
                                </div>
                            </div>
                        </div>

                        <p style="color: #666; line-height: 1.5; margin: 15px 0;">
                            <?php echo htmlspecialchars(substr($topic->description, 0, 100)) . (strlen($topic->description) > 100 ? '...' : ''); ?>
                        </p>

                        <?php if ($topic->status === 'active'): ?>
                            <?php 
                            $progress = ($topic->current_funding / $topic->funding_threshold) * 100;
                            $progress = min($progress, 100);
                            ?>
                            <div class="funding-bar">
                                <div class="funding-progress" style="width: <?php echo $progress; ?>%"></div>
                            </div>
                            
                            <div class="funding-info">
                                <div class="funding-stats">
                                    <span class="funding-amount">$<?php echo number_format($topic->current_funding, 0); ?></span>
                                    / $<?php echo number_format($topic->funding_threshold, 0); ?>
                                    (<?php echo round($progress); ?>%)
                                </div>
                                <a href="fund.php?id=<?php echo $topic->id; ?>" class="btn">Fund Now</a>
                            </div>
                        <?php elseif ($topic->status === 'funded'): ?>
                            <div style="background: #d4edda; color: #155724; padding: 12px; border-radius: 6px; text-align: center; margin: 15px 0;">
                                ✅ Fully Funded! Content coming soon...
                            </div>
                            <div style="text-align: center;">
                                <a href="view.php?id=<?php echo $topic->id; ?>" class="btn">View Details</a>
                            </div>
                        <?php elseif ($topic->status === 'completed'): ?>
                            <div style="background: #cce5ff; color: #004085; padding: 12px; border-radius: 6px; text-align: center; margin: 15px 0;">
                                ✅ Completed! $<?php echo number_format($topic->current_funding, 0); ?> raised
                            </div>
                            <div style="text-align: center;">
                                <a href="view.php?id=<?php echo $topic->id; ?>" class="btn">View Content</a>
                            </div>
                        <?php endif; ?>

                        <div class="topic-meta" style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #eee;">
                            Created <?php echo date('M j, Y', strtotime($topic->created_at)); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Call to Action -->
        <?php if (count($topics) > 0): ?>
            <div style="text-align: center; margin-top: 40px; padding: 30px; background: white; border-radius: 12px;">
                <h3>Don't see what you're looking for?</h3>
                <p>Propose a new topic and get your favorite creators to cover it!</p>
                <a href="create.php" class="btn btn-success">💡 Propose New Topic</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
