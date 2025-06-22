<?php
// index.php - Main homepage with admin navigation
session_start();
require_once 'config/database.php';
require_once 'config/security_headers.php';

$helper = new DatabaseHelper();
$creators = $helper->getAllCreators();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Topic Funding Platform</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; }
        .header { background: #f8f9fa; padding: 15px; margin-bottom: 30px; border-radius: 5px; }
        .nav { float: right; }
        .nav a { margin-left: 15px; text-decoration: none; color: #007bff; }
        .nav a:hover { text-decoration: underline; }
        .nav .admin-link { background: #dc3545; color: white; padding: 5px 10px; border-radius: 4px; }
        .nav .admin-link:hover { background: #c82333; text-decoration: none; }
        .welcome { margin-bottom: 10px; }
        .creator-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-top: 20px; }
        .creator-card { border: 1px solid #ddd; padding: 20px; border-radius: 8px; background: white; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .creator-card h3 { margin-top: 0; color: #333; }
        .creator-info { color: #666; margin-bottom: 10px; }
        .creator-image { width: 60px; height: 60px; background: #ddd; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 24px; color: #666; float: left; margin-right: 15px; }
        .creator-details { overflow: hidden; }
        .btn { background: #007bff; color: white; padding: 8px 16px; text-decoration: none; border-radius: 4px; display: inline-block; }
        .btn:hover { background: #0056b3; }
        .btn-success { background: #28a745; }
        .btn-success:hover { background: #218838; }
        .clearfix::after { content: ""; display: table; clear: both; }
        .empty-state { text-align: center; color: #666; padding: 40px; background: white; border-radius: 8px; }
        .quick-actions { margin-top: 20px; text-align: center; }
        .platform-badge { background: #e9ecef; padding: 3px 8px; border-radius: 12px; font-size: 12px; color: #495057; margin-left: 10px; }
        
        @media (max-width: 768px) {
            .nav { float: none; text-align: center; margin-top: 10px; }
            .header h1 { text-align: center; }
            .creator-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="header clearfix">
        <h1 style="float: left; margin: 0;">Topic Funding Platform</h1>
        <div class="nav">
            <?php if (isset($_SESSION['user_id'])): ?>
                <span>Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?>!</span>
                <a href="dashboard/index.php">Dashboard</a>
                <a href="creators/index.php">Browse Creators</a>
                <a href="topics/index.php">Browse Topics</a>
                <?php if ($_SESSION['user_id'] == 9 || $_SESSION['user_id'] == 1 || $_SESSION['user_id'] == 2): ?>
                    <a href="admin/creators.php" class="admin-link">Admin Panel</a>
                <?php endif; ?>
                <a href="auth/logout.php">Logout</a>
            <?php else: ?>
                <a href="auth/login.php">Login</a>
                <a href="auth/register.php">Register</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="content">
        <?php if (isset($_SESSION['user_id'])): ?>
            <div class="welcome">
                <h2>Fund Topics from Your Favorite Creators</h2>
                <p>Browse creators below and fund the topics you want to see covered!</p>
                
                <div class="quick-actions">
                    <a href="topics/create.php" class="btn btn-success">Propose New Topic</a>
                    <a href="creators/apply.php" class="btn">Become a Creator</a>
                </div>
            </div>
        <?php else: ?>
            <div class="welcome">
                <h2>Welcome to Topic Funding</h2>
                <p>Fund specific topics you want your favorite creators to cover. <a href="auth/register.php">Register</a> to get started!</p>
                
                <div class="quick-actions">
                    <a href="auth/register.php" class="btn btn-success">Get Started</a>
                    <a href="creators/index.php" class="btn">Browse Creators</a>
                </div>
            </div>
        <?php endif; ?>

        <?php if (empty($creators)): ?>
            <div class="empty-state">
                <h3>No creators yet</h3>
                <p>Be the first to join as a creator!</p>
                <?php if (isset($_SESSION['user_id'])): ?>
                    <a href="creators/apply.php" class="btn btn-success">Apply to be a Creator</a>
                <?php else: ?>
                    <a href="auth/register.php" class="btn">Register to Apply</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="creator-grid">
                <?php foreach ($creators as $creator): ?>
                    <div class="creator-card clearfix">
                        <div class="creator-image">
                            <?php if ($creator->profile_image && file_exists('uploads/creators/' . $creator->profile_image)): ?>
                                <img src="uploads/creators/<?php echo htmlspecialchars($creator->profile_image); ?>" alt="Profile" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                            <?php else: ?>
                                <?php echo strtoupper(substr($creator->display_name, 0, 1)); ?>
                            <?php endif; ?>
                        </div>
                        
                        <div class="creator-details">
                            <h3>
                                <?php echo htmlspecialchars($creator->display_name); ?>
                                <span class="platform-badge"><?php echo ucfirst($creator->platform_type); ?></span>
                                <?php if ($creator->is_verified): ?>
                                    <span style="color: #28a745;">✓</span>
                                <?php endif; ?>
                            </h3>
                            
                            <div class="creator-info">
                                <strong>Subscribers:</strong> <?php echo number_format($creator->subscriber_count); ?><br>
                                <strong>Default Funding:</strong> $<?php echo number_format($creator->default_funding_threshold, 2); ?>
                            </div>
                            
                            <p><?php echo htmlspecialchars(substr($creator->bio, 0, 120)) . (strlen($creator->bio) > 120 ? '...' : ''); ?></p>
                            
                            <?php if (isset($_SESSION['user_id'])): ?>
                                <a href="creators/profile.php?id=<?php echo $creator->id; ?>" class="btn">View Topics & Fund</a>
                            <?php else: ?>
                                <a href="auth/login.php" class="btn">Login to Fund Topics</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
