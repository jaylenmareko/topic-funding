<?php
// creators/index.php - Fixed version with error handling
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Check if database file exists
if (!file_exists('../config/database.php')) {
    die('Database configuration file not found. Check if config/database.php exists.');
}

try {
    require_once '../config/database.php';
} catch (Exception $e) {
    die('Error loading database configuration: ' . $e->getMessage());
}

try {
    $helper = new DatabaseHelper();
    $creators = $helper->getAllCreators();
} catch (Exception $e) {
    die('Database error: ' . $e->getMessage());
}

// Simple search functionality
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
if ($search) {
    // Filter creators by search term
    $creators = array_filter($creators, function($creator) use ($search) {
        return stripos($creator->display_name, $search) !== false || 
               stripos($creator->bio, $search) !== false ||
               stripos($creator->platform_type, $search) !== false;
    });
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>All Creators - Topic Funding</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; }
        .header { background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .nav { margin-bottom: 20px; }
        .nav a { color: #007bff; text-decoration: none; margin-right: 15px; }
        .nav a:hover { text-decoration: underline; }
        .search-box { margin-bottom: 20px; }
        .search-box input { padding: 10px; width: 300px; border: 1px solid #ddd; border-radius: 4px; }
        .search-box button { padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; }
        .creator-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 20px; }
        .creator-card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .creator-header { display: flex; gap: 15px; align-items: start; margin-bottom: 15px; }
        .creator-image { width: 80px; height: 80px; background: #ddd; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 32px; color: #666; }
        .creator-info h3 { margin: 0 0 5px 0; color: #333; }
        .creator-stats { display: flex; gap: 15px; margin: 10px 0; }
        .stat { text-align: center; }
        .stat-number { font-weight: bold; color: #007bff; }
        .stat-label { font-size: 12px; color: #666; }
        .platform-badge { background: #e9ecef; padding: 3px 8px; border-radius: 12px; font-size: 12px; color: #495057; }
        .btn { background: #007bff; color: white; padding: 8px 16px; text-decoration: none; border-radius: 4px; display: inline-block; }
        .btn:hover { background: #0056b3; }
        .empty-state { text-align: center; color: #666; padding: 40px; background: white; border-radius: 8px; }
        .debug-info { background: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; margin-bottom: 20px; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="nav">
            <a href="../index.php">← Back to Home</a>
            <?php if (isset($_SESSION['user_id'])): ?>
                <a href="../dashboard/index.php">My Dashboard</a>
            <?php endif; ?>
        </div>

        <div class="header">
            <h1>All Creators</h1>
            <p>Browse and fund topics from your favorite content creators</p>
            
            <form method="GET" class="search-box">
                <input type="text" name="search" placeholder="Search creators..." value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit">Search</button>
                <?php if ($search): ?>
                    <a href="index.php" style="margin-left: 10px;">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <?php if ($search): ?>
            <p>Search results for "<?php echo htmlspecialchars($search); ?>" (<?php echo count($creators); ?> found)</p>
        <?php endif; ?>

        <?php if (empty($creators)): ?>
            <div class="empty-state">
                <?php if ($search): ?>
                    <h3>No creators found</h3>
                    <p>No creators match your search term "<?php echo htmlspecialchars($search); ?>"</p>
                    <a href="index.php">View all creators</a>
                <?php else: ?>
                    <h3>No creators yet</h3>
                    <p>The database appears to be empty. Make sure you've run the database setup script.</p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="creator-grid">
                <?php foreach ($creators as $creator): ?>
                    <div class="creator-card">
                        <div class="creator-header">
                            <div class="creator-image">
                                <?php if ($creator->profile_image && file_exists('../uploads/creators/' . $creator->profile_image)): ?>
                                    <img src="../uploads/creators/<?php echo htmlspecialchars($creator->profile_image); ?>" alt="Profile" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                                <?php else: ?>
                                    <?php echo strtoupper(substr($creator->display_name, 0, 1)); ?>
                                <?php endif; ?>
                            </div>
                            <div class="creator-info">
                                <h3><?php echo htmlspecialchars($creator->display_name); ?></h3>
                                <span class="platform-badge"><?php echo ucfirst($creator->platform_type); ?></span>
                                <?php if ($creator->is_verified): ?>
                                    <span style="color: #28a745;">✓ Verified</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="creator-stats">
                            <div class="stat">
                                <div class="stat-number"><?php echo number_format($creator->subscriber_count); ?></div>
                                <div class="stat-label">Subscribers</div>
                            </div>
                            <div class="stat">
                                <div class="stat-number">$<?php echo number_format($creator->default_funding_threshold); ?></div>
                                <div class="stat-label">Default Funding</div>
                            </div>
                        </div>
                        
                        <p><?php echo htmlspecialchars(substr($creator->bio, 0, 120)) . (strlen($creator->bio) > 120 ? '...' : ''); ?></p>
                        
                        <?php if (isset($_SESSION['user_id'])): ?>
                            <a href="profile.php?id=<?php echo $creator->id; ?>" class="btn">View Topics & Fund</a>
                        <?php else: ?>
                            <a href="../auth/login.php" class="btn">Login to Fund Topics</a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
