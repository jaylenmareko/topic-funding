<?php
// dashboard/contributions.php - Detailed contributions history
session_start();
require_once '../config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$helper = new DatabaseHelper();
$user_id = $_SESSION['user_id'];

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Filter options
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$date_filter = isset($_GET['date']) ? $_GET['date'] : 'all';

// Build query with filters
$db = new Database();
$where_conditions = ['c.user_id = :user_id', 'c.payment_status = "completed"'];
$params = [':user_id' => $user_id];

if ($status_filter !== 'all') {
    $where_conditions[] = 't.status = :status';
    $params[':status'] = $status_filter;
}

if ($date_filter !== 'all') {
    switch ($date_filter) {
        case 'week':
            $where_conditions[] = 'c.contributed_at >= DATE_SUB(NOW(), INTERVAL 1 WEEK)';
            break;
        case 'month':
            $where_conditions[] = 'c.contributed_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)';
            break;
        case 'year':
            $where_conditions[] = 'c.contributed_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR)';
            break;
    }
}

$where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

// Get total count for pagination
$count_query = "
    SELECT COUNT(*) as total
    FROM contributions c
    JOIN topics t ON c.topic_id = t.id
    JOIN creators cr ON t.creator_id = cr.id
    $where_clause
";
$db->query($count_query);
foreach ($params as $param => $value) {
    $db->bind($param, $value);
}
$total_count = $db->single()->total;

// Get contributions with pagination
$query = "
    SELECT c.*, t.title as topic_title, t.status as topic_status, t.funding_threshold,
           t.current_funding, cr.display_name as creator_name, cr.profile_image as creator_image
    FROM contributions c
    JOIN topics t ON c.topic_id = t.id
    JOIN creators cr ON t.creator_id = cr.id
    $where_clause
    ORDER BY c.contributed_at DESC
    LIMIT :limit OFFSET :offset
";

$db->query($query);
foreach ($params as $param => $value) {
    $db->bind($param, $value);
}
$db->bind(':limit', $per_page);
$db->bind(':offset', $offset);
$contributions = $db->resultSet();

// Calculate pagination
$total_pages = ceil($total_count / $per_page);

// Get summary statistics
$db->query('
    SELECT 
        COUNT(*) as total_contributions,
        COALESCE(SUM(amount), 0) as total_amount,
        COUNT(DISTINCT topic_id) as unique_topics,
        AVG(amount) as average_contribution
    FROM contributions 
    WHERE user_id = :user_id AND payment_status = "completed"
');
$db->bind(':user_id', $user_id);
$summary = $db->single();
?>
<!DOCTYPE html>
<html>
<head>
    <title>My Contributions - Topic Funding</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; }
        .nav { margin-bottom: 20px; }
        .nav a { color: #007bff; text-decoration: none; margin-right: 15px; }
        .nav a:hover { text-decoration: underline; }
        .header { background: white; padding: 25px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .summary-card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); text-align: center; }
        .summary-number { font-size: 24px; font-weight: bold; color: #007bff; }
        .summary-label { color: #666; font-size: 14px; }
        .filters { background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .filter-row { display: flex; gap: 15px; align-items: center; flex-wrap: wrap; }
        .filter-group { display: flex; align-items: center; gap: 5px; }
        .filter-group label { font-weight: bold; }
        .filter-group select { padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
        .btn { background: #007bff; color: white; padding: 8px 16px; text-decoration: none; border-radius: 4px; display: inline-block; border: none; cursor: pointer; }
        .btn:hover { background: #0056b3; }
        .contributions-list { background: white; padding: 25px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .contribution-item { display: grid; grid-template-columns: auto 1fr auto auto; gap: 20px; align-items: center; padding: 20px 0; border-bottom: 1px solid #eee; }
        .contribution-item:last-child { border-bottom: none; }
        .creator-avatar { width: 50px; height: 50px; background: #ddd; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 20px; color: #666; }
        .contribution-details { }
        .topic-title { font-weight: bold; color: #333; margin-bottom: 5px; }
        .topic-meta { color: #666; font-size: 14px; margin-bottom: 8px; }
        .funding-info { font-size: 12px; color: #666; }
        .contribution-amount { font-size: 20px; font-weight: bold; color: #28a745; }
        .contribution-date { color: #666; font-size: 14px; text-align: right; }
        .status-badge { padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: bold; }
        .status-active { background: #fff3cd; color: #856404; }
        .status-funded { background: #d4edda; color: #155724; }
        .status-completed { background: #cce5ff; color: #004085; }
        .pagination { display: flex; justify-content: center; gap: 10px; margin-top: 30px; }
        .pagination a, .pagination span { padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; text-decoration: none; color: #007bff; }
        .pagination .current { background: #007bff; color: white; }
        .pagination a:hover { background: #f8f9fa; }
        .empty-state { text-align: center; color: #666; padding: 40px; }
        .funding-bar { background: #e9ecef; height: 4px; border-radius: 2px; margin: 5px 0; }
        .funding-progress { background: #28a745; height: 100%; border-radius: 2px; transition: width 0.3s; }
    </style>
</head>
<body>
    <div class="container">
        <div class="nav">
            <a href="index.php">← Back to Dashboard</a>
            <a href="../topics/index.php">Browse Topics</a>
            <a href="../index.php">Home</a>
        </div>

        <div class="header">
            <h1>My Contribution History</h1>
            <p>Track all your contributions and see how they're making an impact</p>
        </div>

        <div class="summary-grid">
            <div class="summary-card">
                <div class="summary-number">$<?php echo number_format($summary->total_amount, 2); ?></div>
                <div class="summary-label">Total Contributed</div>
            </div>
            <div class="summary-card">
                <div class="summary-number"><?php echo $summary->total_contributions; ?></div>
                <div class="summary-label">Total Contributions</div>
            </div>
            <div class="summary-card">
                <div class="summary-number"><?php echo $summary->unique_topics; ?></div>
                <div class="summary-label">Topics Supported</div>
            </div>
            <div class="summary-card">
                <div class="summary-number">$<?php echo number_format($summary->average_contribution, 2); ?></div>
                <div class="summary-label">Average Contribution</div>
            </div>
        </div>

        <div class="filters">
            <form method="GET" class="filter-row">
                <div class="filter-group">
                    <label>Status:</label>
                    <select name="status" onchange="this.form.submit()">
                        <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Topics</option>
                        <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Still Active</option>
                        <option value="funded" <?php echo $status_filter === 'funded' ? 'selected' : ''; ?>>Funded</option>
                        <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label>Time Period:</label>
                    <select name="date" onchange="this.form.submit()">
                        <option value="all" <?php echo $date_filter === 'all' ? 'selected' : ''; ?>>All Time</option>
                        <option value="week" <?php echo $date_filter === 'week' ? 'selected' : ''; ?>>This Week</option>
                        <option value="month" <?php echo $date_filter === 'month' ? 'selected' : ''; ?>>This Month</option>
                        <option value="year" <?php echo $date_filter === 'year' ? 'selected' : ''; ?>>This Year</option>
                    </select>
                </div>

                <?php if ($status_filter !== 'all' || $date_filter !== 'all'): ?>
                    <a href="contributions.php" class="btn">Clear Filters</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="contributions-list">
            <h2>Contributions (<?php echo number_format($total_count); ?>)</h2>
            
            <?php if (empty($contributions)): ?>
                <div class="empty-state">
                    <h3>No contributions found</h3>
                    <p>No contributions match your current filters.</p>
                    <a href="../topics/index.php" class="btn">Browse Topics to Fund</a>
                </div>
            <?php else: ?>
                <?php foreach ($contributions as $contribution): ?>
                    <div class="contribution-item">
                        <div class="creator-avatar">
                            <?php if ($contribution->creator_image): ?>
                                <img src="../uploads/creators/<?php echo htmlspecialchars($contribution->creator_image); ?>" alt="Creator" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                            <?php else: ?>
                                <?php echo strtoupper(substr($contribution->creator_name, 0, 1)); ?>
                            <?php endif; ?>
                        </div>
                        
                        <div class="contribution-details">
                            <div class="topic-title">
                                <a href="../topics/view.php?id=<?php echo $contribution->topic_id; ?>" style="color: #333; text-decoration: none;">
                                    <?php echo htmlspecialchars($contribution->topic_title); ?>
                                </a>
                            </div>
                            <div class="topic-meta">
                                By <?php echo htmlspecialchars($contribution->creator_name); ?> • 
                                <span class="status-badge status-<?php echo $contribution->topic_status; ?>">
                                    <?php echo ucfirst($contribution->topic_status); ?>
                                </span>
                            </div>
                            <?php if ($contribution->topic_status === 'active'): ?>
                                <?php 
                                $progress = ($contribution->current_funding / $contribution->funding_threshold) * 100;
                                $progress = min($progress, 100);
                                ?>
                                <div class="funding-info">
                                    $<?php echo number_format($contribution->current_funding, 0); ?> / 
                                    $<?php echo number_format($contribution->funding_threshold, 0); ?> 
                                    (<?php echo round($progress); ?>% funded)
                                </div>
                                <div class="funding-bar" style="width: 200px;">
                                    <div class="funding-progress" style="width: <?php echo $progress; ?>%"></div>
                                </div>
                            <?php else: ?>
                                <div class="funding-info">
                                    Final total: $<?php echo number_format($contribution->current_funding, 0); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="contribution-amount">
                            $<?php echo number_format($contribution->amount, 2); ?>
                        </div>
                        
                        <div class="contribution-date">
                            <?php echo date('M j, Y', strtotime($contribution->contributed_at)); ?><br>
                            <small><?php echo date('g:i A', strtotime($contribution->contributed_at)); ?></small>
                        </div>
                    </div>
                <?php endforeach; ?>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?php echo $page - 1; ?>&status=<?php echo $status_filter; ?>&date=<?php echo $date_filter; ?>">← Previous</a>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <?php if ($i == $page): ?>
                                <span class="current"><?php echo $i; ?></span>
                            <?php else: ?>
                                <a href="?page=<?php echo $i; ?>&status=<?php echo $status_filter; ?>&date=<?php echo $date_filter; ?>"><?php echo $i; ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                            <a href="?page=<?php echo $page + 1; ?>&status=<?php echo $status_filter; ?>&date=<?php echo $date_filter; ?>">Next →</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
