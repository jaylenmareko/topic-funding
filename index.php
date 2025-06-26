<?php
// index.php - Homepage with simplified navigation
session_start();
require_once 'config/database.php';
require_once 'config/navigation.php';

$helper = new DatabaseHelper();
$creators = $helper->getAllCreators();
?>
<!DOCTYPE html>
<html>
<head>
    <title>TopicLaunch - Fund Topics from Your Favorite Creators</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; background: #f5f5f5; }
        .hero { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 60px 20px; text-align: center; }
        .hero h1 { font-size: 48px; margin: 0 0 20px 0; font-weight: bold; }
        .hero p { font-size: 20px; margin: 0 0 30px 0; opacity: 0.9; }
        .hero-buttons { display: flex; gap: 20px; justify-content: center; flex-wrap: wrap; }
        .btn { padding: 15px 30px; border-radius: 8px; text-decoration: none; font-weight: bold; font-size: 16px; transition: all 0.3s; }
        .btn-primary { background: white; color: #667eea; }
        .btn-primary:hover { background: #f0f0f0; }
        .btn-secondary { background: rgba(255,255,255,0.2); color: white; border: 2px solid white; }
        .btn-secondary:hover { background: rgba(255,255,255,0.3); }
        .container { max-width: 1200px; margin: 0 auto; padding: 40px 20px; }
        .section-title { font-size: 32px; text-align: center; margin-bottom: 40px; color: #333; }
        .creator-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 25px; }
        .creator-card { background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); transition: transform 0.3s; }
        .creator-card:hover { transform: translateY(-5px); }
        .creator-header { display: flex; gap: 15px; align-items: start; margin-bottom: 15px; }
        .creator-image { width: 70px; height: 70px; background: linear-gradient(135deg, #667eea, #764ba2); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 28px; color: white; }
        .creator-info h3 { margin: 0 0 8px 0; color: #333; font-size: 20px; }
        .creator-stats { display: flex; gap: 20px; margin: 15px 0; }
        .stat { text-align: center; }
        .stat-number { font-weight: bold; color: #667eea; font-size: 18px; }
        .stat-label { font-size: 12px; color: #666; }
        .platform-badge { background: #f0f0f0; padding: 4px 12px; border-radius: 15px; font-size: 12px; color: #666; }
        .btn-card { background: #667eea; color: white; padding: 12px 20px; border-radius: 6px; text-decoration: none; display: inline-block; margin-top: 15px; }
        .btn-card:hover { background: #5a6fd8; color: white; text-decoration: none; }
        .empty-state { text-align: center; color: #666; padding: 60px 20px; background: white; border-radius: 12px; }
        .features { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 30px; margin: 60px 0; }
        .feature { background: white; padding: 30px; border-radius: 12px; text-align: center; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .feature-icon { font-size: 48px; margin-bottom: 20px; }
        .feature h3 { color: #333; margin-bottom: 15px; }
        
        @media (max-width: 768px) {
            .hero h1 { font-size: 36px; }
            .hero p { font-size: 18px; }
            .hero-buttons { flex-direction: column; align-items: center; }
            .creator-grid { grid-template-columns: 1fr; }
            .features { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <?php renderNavigation('home'); ?>

    <!-- Hero Section -->
    <div class="hero">
        <h1>Fund Topics from Your Favorite Creators</h1>
        <p>Propose specific topics you want to see covered, fund them with the community, and creators deliver in 48 hours</p>
        
        <div class="hero-buttons">
            <?php if (isset($_SESSION['user_id'])): ?>
                <a href="topics/create.php" class="btn btn-primary">💡 Propose Topic</a>
                <a href="topics/index.php" class="btn btn-secondary">Browse Active Topics</a>
            <?php else: ?>
                <a href="auth/register.php" class="btn btn-primary">Get Started Free</a>
                <a href="creators/index.php" class="btn btn-secondary">Browse Creators</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="container">
        <!-- How It Works -->
        <div class="features">
            <div class="feature">
                <div class="feature-icon">💡</div>
                <h3>Propose Topics</h3>
                <p>Have an idea for your favorite creator? Propose specific topics you want to see covered with a funding goal.</p>
            </div>
            <div class="feature">
                <div class="feature-icon">🤝</div>
                <h3>Creator Approval</h3>
                <p>Creators review and approve topics they're excited to create content about, ensuring quality output.</p>
            </div>
            <div class="feature">
                <div class="feature-icon">💰</div>
                <h3>Community Funding</h3>
                <p>Once approved, the community funds topics together. When the goal is reached, creation begins!</p>
            </div>
            <div class="feature">
                <div class="feature-icon">⚡</div>
                <h3>48-Hour Delivery</h3>
                <p>Creators have 48 hours to deliver your requested content, or everyone gets automatically refunded.</p>
            </div>
        </div>

        <!-- Active Creators -->
        <?php if (!empty($creators)): ?>
            <h2 class="section-title">Active Creators</h2>
            <div class="creator-grid">
                <?php foreach (array_slice($creators, 0, 6) as $creator): ?>
                    <div class="creator-card">
                        <div class="creator-header">
                            <div class="creator-image">
                                <?php if ($creator->profile_image && file_exists('uploads/creators/' . $creator->profile_image)): ?>
                                    <img src="uploads/creators/<?php echo htmlspecialchars($creator->profile_image); ?>" alt="Profile" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                                <?php else: ?>
                                    <?php echo strtoupper(substr($creator->display_name, 0, 1)); ?>
                                <?php endif; ?>
                            </div>
                            <div style="flex: 1;">
                                <h3>
                                    <?php echo htmlspecialchars($creator->display_name); ?>
                                    <?php if ($creator->is_verified): ?>
                                        <span style="color: #28a745;">✓</span>
                                    <?php endif; ?>
                                </h3>
                                <span class="platform-badge"><?php echo ucfirst($creator->platform_type); ?></span>
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
                        
                        <p style="color: #666; line-height: 1.6; margin: 15px 0;">
                            <?php echo htmlspecialchars(substr($creator->bio, 0, 120)) . (strlen($creator->bio) > 120 ? '...' : ''); ?>
                        </p>
                        
                        <?php if (isset($_SESSION['user_id'])): ?>
                            <a href="creators/profile.php?id=<?php echo $creator->id; ?>" class="btn-card">View Topics & Fund</a>
                        <?php else: ?>
                            <a href="auth/login.php" class="btn-card">Login to Fund Topics</a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <?php if (count($creators) > 6): ?>
                <div style="text-align: center; margin-top: 30px;">
                    <a href="creators/index.php" class="btn btn-primary">View All <?php echo count($creators); ?> Creators</a>
                </div>
            <?php endif; ?>
            
        <?php else: ?>
            <div class="empty-state">
                <h3>🚀 Be the First Creator!</h3>
                <p>TopicLaunch is just getting started. Join as a creator and start earning from your content ideas.</p>
                <?php if (isset($_SESSION['user_id'])): ?>
                    <a href="creators/apply.php" class="btn btn-primary">Apply to be a Creator</a>
                <?php else: ?>
                    <a href="auth/register.php" class="btn btn-primary">Register & Apply</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
