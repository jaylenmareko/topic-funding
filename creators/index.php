<?php
// creators/index.php - Browse creators with simplified navigation
session_start();
require_once '../config/database.php';
require_once '../config/navigation.php';

try {
    $helper = new DatabaseHelper();
    $creators = $helper->getAllCreators();
} catch (Exception $e) {
    die('Database error: ' . $e->getMessage());
}

// Simple search functionality
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$platform_filter = isset($_GET['platform']) ? $_GET['platform'] : 'all';

if ($search || $platform_filter !== 'all') {
    $creators = array_filter($creators, function($creator) use ($search, $platform_filter) {
        $matches_search = empty($search) || 
                         stripos($creator->display_name, $search) !== false || 
                         stripos($creator->bio, $search) !== false;
        
        $matches_platform = $platform_filter === 'all' || 
                           $creator->platform_type === $platform_filter;
        
        return $matches_search && $matches_platform;
    });
}

// Get unique platforms for filter
$platforms = array_unique(array_column($creators, 'platform_type'));
?>
<!DOCTYPE html>
<html>
<head>
    <title>Browse Creators - TopicLaunch</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .header { background: white; padding: 30px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .header h1 { margin: 0 0 15px 0; color: #333; }
        .filters { display: flex; gap: 20px; align-items: center; flex-wrap: wrap; margin-bottom: 20px; }
        .filter-section { display: flex; gap: 10px; align-items: center; }
        .filter-label { font-weight: bold; color: #666; }
        .filter-select { padding: 10px; border: 1px solid #ddd; border-radius: 6px; background: white; }
        .search-box { flex: 1; max-width: 300px; }
        .search-box input { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 25px; font-size: 16px; }
        .search-box button { background: #667eea; color: white; padding: 12px 20px; border: none; border-radius: 25px; margin-left: 10px; cursor: pointer; }
        .creator-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(380px, 1fr)); gap: 25px; }
        .creator-card { background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); transition: transform 0.3s, box-shadow 0.3s; }
        .creator-card:hover { transform: translateY(-3px); box-shadow: 0 8px 20px rgba(0,0,0,0.15); }
        .creator-header { display: flex; gap: 20px; align-items: start; margin-bottom: 20px; }
        .creator-image { width: 80px; height: 80px; background: linear-gradient(135deg, #667eea, #764ba2); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 32px; color: white; font-weight: bold; flex-shrink: 0; }
        .creator-info h3 { margin: 0 0 8px 0; color: #333; font-size: 20px; }
        .creator-badges { display: flex; gap: 8px; align-items: center; margin-bottom: 10px; }
        .platform-badge { background: #e9ecef; padding: 4px 12px; border-radius: 15px; font-size: 12px; color: #495057; font-weight: 500; }
        .verified-badge { color: #28a745; font-weight: bold; }
        .creator-stats { display: flex; gap: 20px; margin: 15px 0; }
        .stat { text-align: center; }
        .stat-number { font-weight: bold; color: #667eea; font-size: 18px; }
        .stat-label { font-size: 12px; color: #666; }
        .creator-bio { color: #666; line-height: 1.6; margin: 15px 0; }
        .creator-actions { display: flex; gap: 10px; margin-top: 20px; }
        .btn { background: #667eea; color: white; padding: 12px 20px; text-decoration: none; border-radius: 6px; display: inline-block; font-weight: 500; transition: background 0.3s; text-align: center; }
        .btn:hover { background: #5a6fd8; color: white; text-decoration: none; }
        .btn-outline { background: transparent; color: #667eea; border: 2px solid #667eea; }
        .btn-outline:hover { background: #667eea; color: white; }
        .empty-state { text-align: center; color: #666; padding: 60px 20px; background: white; border-radius: 12px; }
        .stats-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .summary-card { background: white; padding: 20px; border-radius: 8px; text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .summary-number { font-size: 24px; font-weight: bold; color: #667eea; }
        .summary-label { color: #666; font-size: 14px; }
        .featured-badge { background: linear-gradient(45deg, #feca57, #ff9ff3); color: white; padding: 4px 8px; border-radius: 12px; font-size: 11px; margin-left: 10px; }
        
        @media (max-width: 768px) {
            .container { padding: 15px; }
            .creator-grid { grid-template-columns: 1fr; }
            .creator-header { flex-direction: column; text-align: center; }
            .filters { flex-direction: column; align-items: stretch; }
            .search-box { max-width: none; }
        }
    </style>
</head>
<body>
    <?php renderNavigation('browse_creators'); ?>

    <div class="container">
        <div class="header">
            <h1>Browse Content Creators</h1>
            <p>Discover talented creators and fund the topics you want to see covered</p>
            
            <!-- Summary Stats -->
            <div class="stats-summary">
                <div class="summary-card">
                    <div class="summary-number"><?php echo count($creators); ?></div>
                    <div class="summary-label">Active Creators</div>
                </div>
                <div class="summary-card">
                    <div class="summary-number"><?php echo count($platforms); ?></div>
                    <div class="summary-label">Platforms</div>
                </div>
                <div class="summary-card">
                    <div class="summary-number"><?php echo number_format(array_sum(array_column($creators, 'subscriber_count'))); ?></div>
                    <div class="summary-label">Total Subscribers</div>
                </div>
                <div class="summary-card">
                    <div class="summary-number">$<?php echo number_format(array_sum(array_column($creators, 'default_funding_threshold')), 0); ?></div>
                    <div class="summary-label">Combined Goals</div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters">
                <div class="filter-section">
                    <span class="filter-label">Platform:</span>
                    <select class="filter-select" onchange="updateFilters()">
                        <option value="all" <?php echo $platform_filter === 'all' ? 'selected' : ''; ?>>All Platforms</option>
                        <?php foreach ($platforms as $platform): ?>
                            <option value="<?php echo htmlspecialchars($platform); ?>" 
                                    <?php echo $platform_filter === $platform ? 'selected' : ''; ?>>
                                <?php echo ucfirst($platform); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <form method="GET" class="search-box">
                    <input type="hidden" name="platform" value="<?php echo htmlspecialchars($platform_filter); ?>">
                    <input type="text" name="search" placeholder="Search creators..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit">Search</button>
                </form>

                <?php if ($search || $platform_filter !== 'all'): ?>
                    <a href="index.php" class="btn btn-outline">Clear Filters</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($search || $platform_filter !== 'all'): ?>
            <div style="margin-bottom: 20px; color: #666;">
                <?php if ($search): ?>
                    Search results for "<strong><?php echo htmlspecialchars($search); ?></strong>"
                <?php endif; ?>
                <?php if ($platform_filter !== 'all'): ?>
                    • Filtered by <strong><?php echo ucfirst($platform_filter); ?></strong>
                <?php endif; ?>
                • <strong><?php echo count($creators); ?></strong> creators found
            </div>
        <?php endif; ?>

        <?php if (empty($creators)): ?>
            <div class="empty-state">
                <?php if ($search || $platform_filter !== 'all'): ?>
                    <h3>No creators found</h3>
                    <p>No creators match your search criteria</p>
                    <a href="index.php" class="btn">View All Creators</a>
                <?php else: ?>
                    <h3>No creators yet</h3>
                    <p>Be the first to join as a creator!</p>
                    <a href="apply.php" class="btn">Apply to be a Creator</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="creator-grid">
                <?php foreach ($creators as $creator): ?>
                    <div class="creator-card">
                        <div class="creator-header">
                            <div class="creator-image">
                                <?php if ($creator->profile_image && file_exists('../uploads/creators/' . $creator->profile_image)): ?>
                                    <img src="../uploads/creators/<?php echo htmlspecialchars($creator->profile_image); ?>" 
                                         alt="Profile" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                                <?php else: ?>
                                    <?php echo strtoupper(substr($creator->display_name, 0, 1)); ?>
                                <?php endif; ?>
                            </div>
                            <div class="creator-info">
                                <h3>
                                    <?php echo htmlspecialchars($creator->display_name); ?>
                                    <?php if ($creator->subscriber_count >= 10000): ?>
                                        <span class="featured-badge">⭐ Featured</span>
                                    <?php endif; ?>
                                </h3>
                                <div class="creator-badges">
                                    <span class="platform-badge"><?php echo ucfirst($creator->platform_type); ?></span>
                                    <?php if ($creator->is_verified): ?>
                                        <span class="verified-badge">✓ Verified</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="creator-stats">
                            <div class="stat">
                                <div class="stat-number"><?php echo number_format($creator->subscriber_count); ?></div>
                                <div class="stat-label">Subscribers</div>
                            </div>
                            <div class="stat">
                                <div class="stat-number">$<?php echo number_format($creator->default_funding_threshold); ?></div>
                                <div class="stat-label">Default Goal</div>
                            </div>
                        </div>
                        
                        <div class="creator-bio">
                            <?php echo htmlspecialchars(substr($creator->bio, 0, 150)) . (strlen($creator->bio) > 150 ? '...' : ''); ?>
                        </div>
                        
                        <div class="creator-actions">
                            <?php if (isset($_SESSION['user_id'])): ?>
                                <a href="profile.php?id=<?php echo $creator->id; ?>" class="btn">View Topics & Fund</a>
                                <?php if ($creator->platform_url): ?>
                                    <a href="<?php echo htmlspecialchars($creator->platform_url); ?>" target="_blank" class="btn btn-outline">Visit Channel</a>
                                <?php endif; ?>
                            <?php else: ?>
                                <a href="../auth/login.php" class="btn">Login to Fund Topics</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Call to Action -->
            <div style="text-align: center; margin-top: 40px; padding: 30px; background: white; border-radius: 12px;">
                <h3>Want to become a creator?</h3>
                <p>Join TopicLaunch and let your audience fund the content they want to see!</p>
                <?php if (isset($_SESSION['user_id'])): ?>
                    <a href="apply.php" class="btn">Apply to be a Creator</a>
                <?php else: ?>
                    <a href="../auth/register.php" class="btn">Get Started</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
    function updateFilters() {
        const platform = document.querySelector('.filter-select').value;
        const search = new URLSearchParams(window.location.search).get('search') || '';
        
        const url = new URL(window.location);
        url.searchParams.set('platform', platform);
        if (search) url.searchParams.set('search', search);
        
        window.location.href = url.toString();
    }
    </script>
</body>
</html>
