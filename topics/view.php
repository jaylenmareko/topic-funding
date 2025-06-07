<?php
// topics/view.php - Individual topic detail page
session_start();
require_once '../config/database.php';

$helper = new DatabaseHelper();
$topic_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$topic_id) {
    header('Location: index.php');
    exit;
}

$topic = $helper->getTopicById($topic_id);
if (!$topic) {
    header('Location: index.php');
    exit;
}

// Get topic contributions
$contributions = $helper->getTopicContributions($topic_id);

// Calculate funding progress
$progress_percent = ($topic->current_funding / $topic->funding_threshold) * 100;
$progress_percent = min($progress_percent, 100);
$remaining = max(0, $topic->funding_threshold - $topic->current_funding);
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo htmlspecialchars($topic->title); ?> - Topic Funding</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
        .container { max-width: 1000px; margin: 0 auto; }
        .nav { margin-bottom: 20px; }
        .nav a { color: #007bff; text-decoration: none; margin-right: 15px; }
        .nav a:hover { text-decoration: underline; }
        .topic-header { background: white; padding: 30px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .topic-title { font-size: 28px; font-weight: bold; margin: 0 0 15px 0; color: #333; }
        .topic-meta { color: #666; margin-bottom: 20px; }
        .creator-info { display: flex; align-items: center; gap: 15px; margin-bottom: 20px; }
        .creator-avatar { width: 60px; height: 60px; background: #ddd; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 24px; color: #666; }
        .creator-details h3 { margin: 0; color: #333; }
        .creator-details p { margin: 5px 0; color: #666; }
        .status-badge { padding: 6px 16px; border-radius: 12px; font-size: 14px; font-weight: bold; }
        .status-active { background: #fff3cd; color: #856404; }
        .status-funded { background: #d4edda; color: #155724; }
        .status-completed { background: #cce5ff; color: #004085; }
        .content-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; }
        .main-content { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .sidebar { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .funding-progress { background: #e9ecef; height: 12px; border-radius: 6px; margin: 20px 0; }
        .funding-bar { background: #28a745; height: 100%; border-radius: 6px; transition: width 0.3s; }
        .funding-stats { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin: 20px 0; }
        .stat-box { text-align: center; padding: 15px; background: #f8f9fa; border-radius: 5px; }
        .stat-number { font-size: 24px; font-weight: bold; color: #007bff; }
        .stat-label { font-size: 12px; color: #666; }
        .btn { background: #007bff; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px; display: inline-block; font-size: 16px; text-align: center; border: none; cursor: pointer; }
        .btn:hover { background: #0056b3; }
        .btn-success { background: #28a745; }
        .btn-success:hover { background: #218838; }
        .btn-large { padding: 15px 30px; font-size: 18px; width: 100%; }
        .contributions-section { margin-top: 30px; }
        .contribution-item { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid #eee; }
        .contribution-item:last-child { border-bottom: none; }
        .contributor-name { font-weight: bold; }
        .contribution-amount { color: #28a745; font-weight: bold; }
        .contribution-date { font-size: 12px; color: #666; }
        .empty-contributions { text-align: center; color: #666; padding: 20px; }
        .share-section { margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee; }
        .share-buttons { display: flex; gap: 10px; margin-top: 10px; }
        .share-btn { padding: 8px 16px; border-radius: 4px; text-decoration: none; color: white; font-size: 14px; }
        .share-twitter { background: #1da1f2; }
        .share-facebook { background: #4267b2; }
        .share-copy { background: #6c757d; }
        .deadline-warning { background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 4px; margin: 20px 0; }
        .completed-content { background: #d4edda; border: 1px solid #c3e6cb; padding: 20px; border-radius: 4px; margin: 20px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="nav">
            <a href="index.php">← Back to All Topics</a>
            <a href="../creators/profile.php?id=<?php echo $topic->creator_id; ?>">View Creator Profile</a>
            <a href="../index.php">Home</a>
        </div>

        <div class="topic-header">
            <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 20px;">
                <span class="status-badge status-<?php echo $topic->status; ?>">
                    <?php echo ucfirst($topic->status); ?>
                </span>
                <div class="topic-meta">
                    Created <?php echo date('M j, Y g:i A', strtotime($topic->created_at)); ?>
                </div>
            </div>

            <h1 class="topic-title"><?php echo htmlspecialchars($topic->title); ?></h1>

            <div class="creator-info">
                <div class="creator-avatar">
                    <?php if ($topic->creator_image): ?>
                        <img src="../uploads/creators/<?php echo htmlspecialchars($topic->creator_image); ?>" alt="Creator" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                    <?php else: ?>
                        <?php echo strtoupper(substr($topic->creator_name, 0, 1)); ?>
                    <?php endif; ?>
                </div>
                <div class="creator-details">
                    <h3><?php echo htmlspecialchars($topic->creator_name); ?></h3>
                    <p>Content Creator</p>
                </div>
            </div>
        </div>

        <div class="content-grid">
            <div class="main-content">
                <h2>Topic Description</h2>
                <p><?php echo nl2br(htmlspecialchars($topic->description)); ?></p>

                <?php if ($topic->status === 'funded' && $topic->content_deadline): ?>
                <div class="deadline-warning">
                    <strong>⏰ Content Deadline:</strong> 
                    <?php echo date('M j, Y g:i A', strtotime($topic->content_deadline)); ?>
                    <br><small>The creator has committed to delivering this content by the deadline above.</small>
                </div>
                <?php endif; ?>

                <?php if ($topic->status === 'completed' && $topic->content_url): ?>
                <div class="completed-content">
                    <h3>✅ Content Delivered!</h3>
                    <p>The creator has completed this topic. Check out the content below:</p>
                    <a href="<?php echo htmlspecialchars($topic->content_url); ?>" target="_blank" class="btn btn-success">View Content</a>
                </div>
                <?php endif; ?>

                <div class="contributions-section">
                    <h3>Recent Contributors (<?php echo count($contributions); ?>)</h3>
                    <?php if (empty($contributions)): ?>
                        <div class="empty-contributions">
                            <p>No contributions yet. Be the first to fund this topic!</p>
                        </div>
                    <?php else: ?>
                        <?php foreach (array_slice($contributions, 0, 10) as $contribution): ?>
                            <div class="contribution-item">
                                <div>
                                    <div class="contributor-name"><?php echo htmlspecialchars($contribution->username); ?></div>
                                    <div class="contribution-date"><?php echo date('M j, Y g:i A', strtotime($contribution->contributed_at)); ?></div>
                                </div>
                                <div class="contribution-amount">$<?php echo number_format($contribution->amount, 2); ?></div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (count($contributions) > 10): ?>
                            <div style="text-align: center; margin-top: 15px; color: #666;">
                                And <?php echo count($contributions) - 10; ?> more contributors...
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="sidebar">
                <?php if ($topic->status === 'active'): ?>
                    <h3>Funding Progress</h3>
                    <div class="funding-progress">
                        <div class="funding-bar" style="width: <?php echo $progress_percent; ?>%"></div>
                    </div>

                    <div class="funding-stats">
                        <div class="stat-box">
                            <div class="stat-number">$<?php echo number_format($topic->current_funding, 0); ?></div>
                            <div class="stat-label">Raised</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-number">$<?php echo number_format($remaining, 0); ?></div>
                            <div class="stat-label">Remaining</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-number"><?php echo round($progress_percent); ?>%</div>
                            <div class="stat-label">Complete</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-number"><?php echo count($contributions); ?></div>
                            <div class="stat-label">Backers</div>
                        </div>
                    </div>

                    <?php if (isset($_SESSION['user_id'])): ?>
                        <a href="fund.php?id=<?php echo $topic->id; ?>" class="btn btn-success btn-large">Fund This Topic</a>
                    <?php else: ?>
                        <a href="../auth/login.php" class="btn btn-large">Login to Fund</a>
                    <?php endif; ?>

                <?php elseif ($topic->status === 'funded'): ?>
                    <h3>✅ Fully Funded!</h3>
                    <div class="stat-box">
                        <div class="stat-number">$<?php echo number_format($topic->current_funding, 0); ?></div>
                        <div class="stat-label">Total Raised</div>
                    </div>
                    <p style="color: #28a745; font-weight: bold; text-align: center; margin: 20px 0;">
                        Content is being created!
                    </p>

                <?php elseif ($topic->status === 'completed'): ?>
                    <h3>✅ Completed!</h3>
                    <div class="stat-box">
                        <div class="stat-number">$<?php echo number_format($topic->current_funding, 0); ?></div>
                        <div class="stat-label">Total Raised</div>
                    </div>
                    <?php if ($topic->content_url): ?>
                        <a href="<?php echo htmlspecialchars($topic->content_url); ?>" target="_blank" class="btn btn-success btn-large">View Content</a>
                    <?php endif; ?>
                <?php endif; ?>

                <div class="share-section">
                    <h4>Share This Topic</h4>
                    <p>Help this topic reach its funding goal!</p>
                    <div class="share-buttons">
                        <a href="https://twitter.com/intent/tweet?text=<?php echo urlencode('Check out this topic: ' . $topic->title); ?>&url=<?php echo urlencode('http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']); ?>" 
                           target="_blank" class="share-btn share-twitter">Twitter</a>
                        <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode('http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']); ?>" 
                           target="_blank" class="share-btn share-facebook">Facebook</a>
                        <button onclick="copyToClipboard()" class="share-btn share-copy">Copy Link</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    function copyToClipboard() {
        navigator.clipboard.writeText(window.location.href).then(function() {
            alert('Link copied to clipboard!');
        });
    }
    </script>
</body>
</html>
