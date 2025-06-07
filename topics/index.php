<?php
// topics/index.php - Topics listing with filtering
session_start();
require_once '../config/database.php';

$helper = new DatabaseHelper();

// Get filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'active';
$creator_filter = isset($_GET['creator_id']) ? (int)$_GET['creator_id'] : 0;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build query based on filters
$db = new Database();
$where_conditions = [];
$params = [];

// Status filter
if ($status_filter && $status_filter !== 'all') {
    $where_conditions[] = "t.status = :status";
    $params[':status'] = $status_filter;
}

// Creator filter
if ($creator_filter) {
    $where_conditions[] = "t.creator_id = :creator_id";
    $params[':creator_id'] = $creator_filter;
}

// Search filter
if ($search) {
    $where_conditions[] = "(t.title LIKE :search OR t.description LIKE :search OR c.display_name LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

$where_clause = empty($where_conditions) ? '' : 'WHERE ' . implode(' AND ', $where_conditions);

$query = "
    SELECT t.*, c.display_name as creator_name, c.profile_image as creator_image, c.platform_type
    FROM topics t 
    JOIN creators c ON t.creator_id = c.id 
    $where_clause
    ORDER BY t.created_at DESC
";

$db->query($query);
foreach ($params as $param => $value) {
    $db->bind($param, $value);
}
$topics = $db->resultSet();

// Get all creators for filter dropdown
$all_creators = $helper->getAllCreators();

// Count topics by status
$db->query("SELECT status, COUNT(*) as count FROM topics GROUP BY status");
$status_counts = $db->resultSet();
$counts = [];
foreach ($status_counts as $sc) {
    $counts[$sc->status] = $sc->count;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>All Topics - Topic Funding</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; }
        .header { background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .nav { margin-bottom: 20px; }
        .nav a { color: #007bff; text-decoration: none; margin-right: 15px; }
        .nav a:hover { text-decoration: underline; }
        .filters { display: flex; gap: 15px; margin-bottom: 20px; flex-wrap: wrap; align-items: center; }
        .filter-group { display: flex; align-items: center; gap: 5px; }
        .filter-group label { font-weight: bold; }
        .filter-group select, .filter-group input { padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
        .btn { background: #007bff; color: white; padding: 8px 16px; text-decoration: none; border-radius: 4px; display: inline-block; border: none; cursor: pointer; }
        .btn:hover { background: #0056b3; }
        .btn-success { background: #28a745; }
        .btn-success:hover { background: #218838; }
        .status-tabs { display: flex; gap: 10px; margin-bottom: 20px; }
        .status-tab { padding: 10px 15px; background: white; border: 1px solid #ddd; border-radius: 4px; text-decoration: none; color: #333; }
        .status-tab.active { background: #007bff; color: white; }
        .status-tab:hover { background: #f8f9fa; }
        .status-tab.active:hover { background: #0056b3; }
        .topic-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 20px; }
        .topic-card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .topic-header { display: flex; justify-content: space-between; align-items: start; margin-bottom: 15px; }
        .topic-title { font-size: 18px; font-weight: bold; margin: 0 0 10px 0; color: #333; }
        .topic-meta { color: #666; font-size: 14px; margin-bottom: 15px; }
        .creator-info { display: flex; align-items: center; gap: 10px; margin-bottom: 15px; }
        .creator-avatar { width: 40px; height: 40px; background: #ddd; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 16px; color: #666; }
        .status-badge { padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: bold; }
        .status-active { background: #fff3cd; color: #856404; }
        .status-funded { background: #d4edda; color: #155724; }
        .status-completed { background: #cce5ff; color: #004085; }
        .funding-bar { background: #e9ecef; height: 8px; border-radius: 4px; margin: 15px 0; }
        .funding-progress { background: #28a745; height: 100%; border-radius: 4px; transition: width 0.3s; }
        .funding-info { display: flex; justify-content: space-between; align-items: center; margin-top: 15px; }
        .empty-state { text-align: center; color: #666; padding: 40px; background: white; border-radius: 8px; }
        .results-info { margin-bottom: 20px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="nav">
            <a href="../index.php">← Back to Home</a>
            <a href="../creators/index.php">Browse Creators</a>
            <?php if (isset($_SESSION['user_id'])): ?>
                <a href="../dashboard/index.php">My Dashboard</a>
                <a href="create.php" class="btn btn-success">Propose New Topic</a>
            <?php endif; ?>
        </div>

        <div class="header">
            <h1>All Topics</h1>
            <p>Browse and fund topics proposed by the community</p>
        </div>

        <!-- Status Tabs -->
        <div class="status-tabs">
            <a href="?status=all<?php echo $creator_filter ? '&creator_id=' . $creator_filter : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>" 
               class="status-tab <?php echo $status_filter === 'all' ? 'active' : ''; ?>">
                All Topics (<?php echo array_sum($counts); ?>)
            </a>
            <a href="?status=active<?php echo $creator_filter ? '&creator_id=' . $creator_filter : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>" 
               class="status-tab <?php echo $status_filter === 'active' ? 'active' : ''; ?>">
                Active (<?php echo $counts['active'] ?? 0; ?>)
            </a>
            <a href="?status=funded<?php echo $creator_filter ? '&creator_id=' . $creator_filter : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>" 
               class="status-tab <?php echo $status_filter === 'funded' ? 'active' : ''; ?>">
                Funded (<?php echo $counts['funded'] ?? 0; ?>)
            </a>
            <a href="?status=completed<?php echo $creator_filter ? '&creator_id=' . $creator_filter : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>" 
               class="status-tab <?php echo $status_filter === 'completed' ? 'active' : ''; ?>">
                Completed (<?php echo $counts['completed'] ?? 0; ?>)
            </a>
        </div>

        <!-- Filters -->
        <form method="GET" class="filters">
            <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
            
            <div class="filter-group">
                <label>Creator:</label>
                <select name="creator_id" onchange="this.form.submit()">
                    <option value="">All Creators</option>
                    <?php foreach ($all_creators as $creator): ?>
                        <option value="<?php echo $creator->id; ?>" <?php echo $creator_filter == $creator->id ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($creator->display_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label>Search:</label>
                <input type="text" name="search" placeholder="Search topics..." value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="btn">Search</button>
            </div>

            <?php if ($creator_filter || $search): ?>
                <a href="?status=<?php echo htmlspecialchars($status_filter); ?>" class="btn">Clear Filters</a>
            <?php endif; ?>
        </form>

        <!-- Results Info -->
        <?php if ($search || $creator_filter): ?>
        <div class="results-info">
            Showing <?php echo count($topics); ?> results
            <?php if ($search): ?> for "<?php echo htmlspecialchars($search); ?>"<?php endif; ?>
            <?php if ($creator_filter): ?> 
                from <?php 
                $filtered_creator = array_filter($all_creators, function($c) use ($creator_filter) { return $c->id == $creator_filter; });
                echo $filtered_creator ? htmlspecialchars(current($filtered_creator)->display_name) : 'Unknown Creator';
                ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Topics Grid -->
        <?php if (empty($topics)): ?>
            <div class="empty-state">
                <?php if ($search || $creator_filter): ?>
                    <h3>No topics found</h3>
                    <p>No topics match your current filters.</p>
                    <a href="index.php">View all topics</a>
                <?php else: ?>
                    <h3>No topics yet</h3>
                    <p>Be the first to propose a topic!</p>
                    <?php if (isset($_SESSION['user_id'])): ?>
                        <a href="create.php" class="btn btn-success">Propose First Topic</a>
                    <?php else: ?>
                        <a href="../auth/login.php" class="btn">Login to Propose Topic</a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="topic-grid">
                <?php foreach ($topics as $topic): ?>
                    <div class="topic-card">
                        <div class="topic-header">
                            <div>
                                <span class="status-badge status-<?php echo $topic->status; ?>">
                                    <?php echo ucfirst($topic->status); ?>
                                </span>
                            </div>
                            <div class="topic-meta">
                                Created <?php echo date('M j, Y', strtotime($topic->created_at)); ?>
                            </div>
                        </div>

                        <h3 class="topic-title"><?php echo htmlspecialchars($topic->title); ?></h3>

                        <div class="creator-info">
                            <div class="creator-avatar">
                                <?php if ($topic->creator_image): ?>
                                    <img src="../uploads/creators/<?php echo htmlspecialchars($topic->creator_image); ?>" alt="Creator" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                                <?php else: ?>
                                    <?php echo strtoupper(substr($topic->creator_name, 0, 1)); ?>
                                <?php endif; ?>
                            </div>
                            <div>
                                <strong><?php echo htmlspecialchars($topic->creator_name); ?></strong>
                                <div style="font-size: 12px; color: #666;"><?php echo ucfirst($topic->platform_type); ?></div>
                            </div>
                        </div>

                        <p><?php echo htmlspecialchars(substr($topic->description, 0, 150)) . (strlen($topic->description) > 150 ? '...' : ''); ?></p>

                        <?php if ($topic->status === 'active'): ?>
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
                                <?php if (isset($_SESSION['user_id'])): ?>
                                    <a href="fund.php?id=<?php echo $topic->id; ?>" class="btn">Fund Now</a>
                                <?php else: ?>
                                    <a href="../auth/login.php" class="btn">Login to Fund</a>
                                <?php endif; ?>
                            </div>
                        <?php elseif ($topic->status === 'funded'): ?>
                            <div class="funding-info">
                                <span style="color: #28a745;">
                                    <strong>✅ Fully Funded!</strong> 
                                    $<?php echo number_format($topic->current_funding, 2); ?> raised
                                </span>
                                <span class="btn" style="background: #28a745;">Content Coming Soon</span>
                            </div>
                        <?php elseif ($topic->status === 'completed'): ?>
                            <div class="funding-info">
                                <span style="color: #007bff;">
                                    <strong>✅ Completed!</strong> 
                                    $<?php echo number_format($topic->current_funding, 2); ?> raised
                                </span>
                                <?php if ($topic->content_url): ?>
                                    <a href="<?php echo htmlspecialchars($topic->content_url); ?>" target="_blank" class="btn">View Content</a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div style="margin-top: 15px;">
                            <a href="view.php?id=<?php echo $topic->id; ?>" style="color: #007bff; text-decoration: none; font-size: 14px;">View Details →</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
