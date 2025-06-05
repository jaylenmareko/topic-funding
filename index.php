<?php
// index.php
session_start();
require_once 'config/database.php';

$helper = new DatabaseHelper();
$creators = $helper->getAllCreators();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Topic Funding Platform</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; }
        .header { background: #f8f9fa; padding: 15px; margin-bottom: 30px; border-radius: 5px; }
        .nav { float: right; }
        .nav a { margin-left: 15px; text-decoration: none; color: #007bff; }
        .nav a:hover { text-decoration: underline; }
        .welcome { margin-bottom: 10px; }
        .creator-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-top: 20px; }
        .creator-card { border: 1px solid #ddd; padding: 20px; border-radius: 8px; background: white; }
        .creator-card h3 { margin-top: 0; color: #333; }
        .creator-info { color: #666; margin-bottom: 10px; }
        .btn { background: #007bff; color: white; padding: 8px 16px; text-decoration: none; border-radius: 4px; display: inline-block; }
        .btn:hover { background: #0056b3; }
        .clearfix::after { content: ""; display: table; clear: both; }
    </style>
</head>
<body>
    <div class="header clearfix">
        <h1 style="float: left; margin: 0;">Topic Funding Platform</h1>
        <div class="nav">
            <?php if (isset($_SESSION['user_id'])): ?>
                <span>Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?>!</span>
                <a href="dashboard/index.php">Dashboard</a>
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
            </div>
        <?php else: ?>
            <div class="welcome">
                <h2>Welcome to Topic Funding</h2>
                <p>Fund specific topics you want your favorite creators to cover. <a href="auth/register.php">Register</a> to get started!</p>
            </div>
        <?php endif; ?>

        <div class="creator-grid">
            <?php foreach ($creators as $creator): ?>
                <div class="creator-card">
                    <h3><?php echo htmlspecialchars($creator->display_name); ?></h3>
                    <div class="creator-info">
                        <strong>Platform:</strong> <?php echo ucfirst($creator->platform_type); ?><br>
                        <strong>Subscribers:</strong> <?php echo number_format($creator->subscriber_count); ?><br>
                        <strong>Default Funding:</strong> $<?php echo number_format($creator->default_funding_threshold, 2); ?>
                    </div>
                    <p><?php echo htmlspecialchars(substr($creator->bio, 0, 100)) . '...'; ?></p>
                    
                    <?php if (isset($_SESSION['user_id'])): ?>
                        <a href="creators/profile.php?id=<?php echo $creator->id; ?>" class="btn">View Topics & Fund</a>
                    <?php else: ?>
                        <a href="auth/login.php" class="btn">Login to Fund Topics</a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if (empty($creators)): ?>
            <p>No creators found. Make sure your database has sample data.</p>
        <?php endif; ?>
    </div>
</body>
</html>
