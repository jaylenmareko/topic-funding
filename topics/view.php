<?php
// topics/view.php - Individual topic detail page with simplified view for creators
session_start();
require_once '../config/database.php';
require_once '../config/navigation.php';

$helper = new DatabaseHelper();
$topic_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$topic_id) {
    header('Location: ../creators/index.php');
    exit;
}

$topic = $helper->getTopicById($topic_id);
if (!$topic) {
    header('Location: ../creators/index.php');
    exit;
}

// Check if current user is a creator
$is_creator = false;
if (isset($_SESSION['user_id'])) {
    $db = new Database();
    $db->query('SELECT id FROM creators WHERE applicant_user_id = :user_id AND is_active = 1');
    $db->bind(':user_id', $_SESSION['user_id']);
    $is_creator = $db->single() ? true : false;
}

// Get topic contributions (only for non-creators)
$contributions = [];
$analytics = null;
if (!$is_creator) {
    $contributions = $helper->getTopicContributions($topic_id);
    $analytics = $helper->getTopicFundingAnalytics($topic_id);
}

// Calculate funding progress
$progress_percent = ($topic->current_funding / $topic->funding_threshold) * 100;
$progress_percent = min($progress_percent, 100);
$remaining = max(0, $topic->funding_threshold - $topic->current_funding);
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo htmlspecialchars($topic->title); ?> - TopicLaunch</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; background: #f5f5f5; }
        .container { max-width: 1000px; margin: 0 auto; padding: 20px; }
        .back-link { color: #007bff; text-decoration: none; margin-bottom: 20px; display: inline-block; }
        .back-link:hover { text-decoration: underline; }
        .topic-header { background: white; padding: 30px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .topic-title { font-size: 28px; font-weight: bold; margin: 0 0 15px 0; color: #333; }
        .topic-meta { color: #666; margin-bottom: 20px; }
        .creator-info { display: flex; align-items: center; gap: 15px; margin-bottom: 20px; }
        .creator-avatar { width: 60px; height: 60px; background: linear-gradient(135deg, #667eea, #764ba2); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 24px; color: white; font-weight: bold; }
        .creator-details h3 { margin: 0; color: #333; }
        .creator-details p { margin: 5px 0; color: #666; }
        .status-badge { padding: 8px 16px; border-radius: 15px; font-size: 14px; font-weight: bold; }
        .status-active { background: #fff3cd; color: #856404; }
        .status-funded { background: #d4edda; color: #155724; }
        .status-completed { background: #cce5ff; color: #004085; }
        .status-pending-approval { background: #f8d7da; color: #721c24; }
        .content-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; }
        .main-content { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .sidebar { display: flex; flex-direction: column; gap: 20px; }
        .contributions-section { margin-top: 30px; }
        .contribution-item { display: flex; justify-content: space-between; align-items: center; padding: 12px 0; border-bottom: 1px solid #eee; }
        .contribution-item:last-child { border-bottom: none; }
        .contributor-info { display: flex; align-items: center; gap: 10px; }
        .contributor-avatar { width: 30px; height: 30px; background: #667eea; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; color: white; font-weight: bold; }
        .contributor-name { font-weight: bold; }
        .contribution-amount { color: #28a745; font-weight: bold; }
        .contribution-date { font-size: 12px; color: #666; }
        .empty-contributions { text-align: center; color: #666; padding: 20px; }
        .share-section { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .share-buttons { display: flex; gap: 10px; margin-top: 10px; flex-wrap: wrap; }
        .share-btn { padding: 10px 15px; border-radius: 6px; text-decoration: none; color: white; font-size: 14px; font-weight: 500; transition: transform 0.3s; }
        .share-btn:hover { transform: translateY(-1px); text-decoration: none; color: white; }
        .share-twitter { background: #1da1f2; }
        .share-facebook { background: #4267b2; }
        .share-copy { background: #6c757d; border: none; cursor: pointer; }
        .deadline-warning { background: #fff3cd; border: 1px solid #ffeaa7; padding: 20px; border-radius: 8px; margin: 20px 0; }
        .completed-content { background: #d4edda; border: 1px solid #c3e6cb; padding: 20px; border-radius: 8px; margin: 20px 0; }
        .analytics-section { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .analytics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(100px, 1fr)); gap: 15px; }
        .analytics-stat { text-align: center; padding: 15px; background: #f8f9fa; border-radius: 8px; }
        .analytics-number { font-size: 16px; font-weight: bold; color: #667eea; }
        .analytics-label { font-size: 11px; color: #666; margin-top: 5px; }
        .funding-widget { background: white; padding: 25px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .funding-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .funding-progress-bar { background: #e9ecef; height: 20px; border-radius: 10px; margin: 20px 0; overflow: hidden; position: relative; }
        .funding-progress { background: linear-gradient(90deg, #28a745, #20c997); height: 100%; border-radius: 10px; transition: width 0.5s ease; }
        .funding-stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 15px; margin: 20px 0; }
        .funding-stat { text-align: center; padding: 15px; background: #f8f9fa; border-radius: 8px; }
        .funding-stat-number { font-size: 18px; font-weight: bold; color: #28a745; }
        .funding-stat-label { font-size: 12px; color: #666; }
        .action-button { background: #28a745; color: white; padding: 15px 25px; text-decoration: none; border-radius: 6px; display: block; text-align: center; font-weight: bold; margin-top: 20px; transition: background 0.3s; }
        .action-button:hover { background: #218838; color: white; text-decoration: none; }
        .action-button.login { background: #667eea; }
        .action-button.login:hover { background: #5a6fd8; }
        .action-button.completed { background: #6c757d; cursor: default; }
        .pending-approval { background: #fff3cd; border: 1px solid #ffeaa7; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
        
        /* Creator-only styles for simplified view */
        .creator-simple-view .container { max-width: 800px; }
        .creator-simple-view .content-grid { grid-template-columns: 1fr; }
        .creator-simple-view .main-content { margin-bottom: 20px; }
        
        @media (max-width: 768px) {
            .container { padding: 15px; }
            .content-grid { grid-template-columns: 1fr; }
            .creator-info { flex-direction: column; text-align: center; }
            .funding-stats-grid { grid-template-columns: repeat(2, 1fr); }
            .share-buttons { flex-direction: column; }
        }
    </style>
</head>
<body <?php echo $is_creator ? 'class="creator-simple-view"' : ''; ?>>
    <?php renderNavigation('browse_creators'); ?>

    <div class="container">
        <?php if ($is_creator): ?>
            <!-- No back link for creators - they can use navigation -->
        <?php else: ?>
            <!-- Original back link for fans -->
            <a href="../creators/profile.php?id=<?php echo $topic->creator_id; ?>" class="back-link">← Back to Creator Profile</a>
        <?php endif; ?>

        <div class="topic-header">
            <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
                <span class="status-badge status-<?php echo str_replace('_', '-', $topic->status); ?>">
                    <?php echo ucfirst(str_replace('_', ' ', $topic->status)); ?>
                </span>
                <div class="topic-meta">
                    Created <?php echo date('M j, Y g:i A', strtotime($topic->created_at)); ?>
                </div>
            </div>

            <h1 class="topic-title"><?php echo htmlspecialchars($topic->title); ?></h1>

            <?php if (!$is_creator): ?>
            <div class="creator-info">
                <div class="creator-avatar">
                    <?php if ($topic->creator_image): ?>
                        <img src="../uploads/creators/<?php echo htmlspecialchars($topic->creator_image); ?>" 
                             alt="Creator" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                    <?php else: ?>
                        <?php echo strtoupper(substr($topic->creator_name, 0, 1)); ?>
                    <?php endif; ?>
                </div>
                <div class="creator-details">
                    <h3><?php echo htmlspecialchars($topic->creator_name); ?></h3>
                    <p>Content Creator</p>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($topic->status === 'pending_approval'): ?>
        <div class="pending-approval">
            <h3>⏳ Awaiting Creator Approval</h3>
            <p>This topic has been proposed and is waiting for the creator to review and approve it. Once approved, it will go live for community funding.</p>
        </div>
        <?php endif; ?>

        <?php if ($is_creator): ?>
            <!-- SIMPLIFIED VIEW FOR CREATORS - Only topic description -->
            <div class="main-content">
                <h2>Topic Description</h2>
                <div style="line-height: 1.6; color: #666;">
                    <?php echo nl2br(htmlspecialchars($topic->description)); ?>
                </div>

                <?php if ($topic->status === 'funded' && $topic->content_deadline): ?>
                <div class="deadline-warning">
                    <h4 style="margin-top: 0;">⏰ Content Deadline</h4>
                    <p><strong><?php echo date('l, M j, Y \a\t g:i A', strtotime($topic->content_deadline)); ?></strong></p>
                    <p>You have committed to delivering this content by the deadline above. If content isn't delivered on time, all contributors will be automatically refunded.</p>
                    <div style="margin-top: 15px;">
                        <a href="../creators/upload_content.php?topic=<?php echo $topic->id; ?>" class="action-button" style="display: inline-block; margin-top: 0;">
                            🎬 Upload Content Now
                        </a>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($topic->status === 'completed' && $topic->content_url): ?>
                <div class="completed-content">
                    <h3>✅ Content Delivered!</h3>
                    <p>You have completed this topic. Here's the content you delivered:</p>
                    <a href="<?php echo htmlspecialchars($topic->content_url); ?>" target="_blank" 
                       style="background: #28a745; color: white; padding: 15px 25px; text-decoration: none; border-radius: 6px; display: inline-block; font-weight: bold; margin-top: 10px;">
                        🎬 View Your Content
                    </a>
                    <?php if ($topic->completion_notes): ?>
                        <div style="margin-top: 15px; padding: 15px; background: rgba(255,255,255,0.7); border-radius: 6px;">
                            <strong>Your Notes:</strong><br>
                            <?php echo nl2br(htmlspecialchars($topic->completion_notes)); ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

        <?php else: ?>
            <!-- FULL VIEW FOR FANS - All sections -->
            <div class="content-grid">
                <div class="main-content">
                    <h2>Topic Description</h2>
                    <div style="line-height: 1.6; color: #666;">
                        <?php echo nl2br(htmlspecialchars($topic->description)); ?>
                    </div>

                    <?php if ($topic->status === 'funded' && $topic->content_deadline): ?>
                    <div class="deadline-warning">
                        <h4 style="margin-top: 0;">⏰ Content Deadline</h4>
                        <p><strong><?php echo date('l, M j, Y \a\t g:i A', strtotime($topic->content_deadline)); ?></strong></p>
                        <p>The creator has committed to delivering this content by the deadline above. If content isn't delivered on time, all contributors will be automatically refunded.</p>
                    </div>
                    <?php endif; ?>

                    <?php if ($topic->status === 'completed' && $topic->content_url): ?>
                    <div class="completed-content">
                        <h3>✅ Content Delivered!</h3>
                        <p>The creator has completed this topic. Check out the content below:</p>
                        <a href="<?php echo htmlspecialchars($topic->content_url); ?>" target="_blank" 
                           style="background: #28a745; color: white; padding: 15px 25px; text-decoration: none; border-radius: 6px; display: inline-block; font-weight: bold; margin-top: 10px;">
                            🎬 View Content
                        </a>
                        <?php if ($topic->completion_notes): ?>
                            <div style="margin-top: 15px; padding: 15px; background: rgba(255,255,255,0.7); border-radius: 6px;">
                                <strong>Creator's Notes:</strong><br>
                                <?php echo nl2br(htmlspecialchars($topic->completion_notes)); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <div class="contributions-section">
                        <h3>Contributors (<?php echo count($contributions); ?>)</h3>
                        <?php if (empty($contributions)): ?>
                            <div class="empty-contributions">
                                <p>No contributions yet. Be the first to fund this topic!</p>
                            </div>
                        <?php else: ?>
                            <div style="max-height: 400px; overflow-y: auto;">
                                <?php foreach ($contributions as $contribution): ?>
                                    <div class="contribution-item">
                                        <div class="contributor-info">
                                            <div class="contributor-avatar">
                                                <?php echo strtoupper(substr($contribution->username, 0, 1)); ?>
                                            </div>
                                            <div>
                                                <div class="contributor-name"><?php echo htmlspecialchars($contribution->username); ?></div>
                                                <div class="contribution-date"><?php echo date('M j, Y g:i A', strtotime($contribution->contributed_at)); ?></div>
                                            </div>
                                        </div>
                                        <div class="contribution-amount">$<?php echo number_format($contribution->amount, 2); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="sidebar">
                    <!-- Enhanced Funding Widget -->
                    <div class="funding-widget">
                        <div class="funding-header">
                            <h3 style="margin: 0; color: #333;">Funding Progress</h3>
                            <?php if ($topic->status === 'active' && count($contributions) > 0): ?>
                                <div style="background: #e8f5e8; color: #2d5f2d; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: bold;">
                                    🔥 Active
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="funding-progress-bar">
                            <div class="funding-progress" style="width: <?php echo $progress_percent; ?>%"></div>
                        </div>

                        <div class="funding-stats-grid">
                            <div class="funding-stat">
                                <div class="funding-stat-number">$<?php echo number_format($topic->current_funding, 0); ?></div>
                                <div class="funding-stat-label">Raised</div>
                            </div>
                            <div class="funding-stat">
                                <div class="funding-stat-number" style="color: #dc3545;">$<?php echo number_format($remaining, 0); ?></div>
                                <div class="funding-stat-label">Remaining</div>
                            </div>
                            <div class="funding-stat">
                                <div class="funding-stat-number" style="color: #667eea;"><?php echo count($contributions); ?></div>
                                <div class="funding-stat-label">Backers</div>
                            </div>
                            <div class="funding-stat">
                                <div class="funding-stat-number" style="color: #6f42c1;"><?php echo round($progress_percent); ?>%</div>
                                <div class="funding-stat-label">Complete</div>
                            </div>
                        </div>

                        <?php if ($topic->status === 'active'): ?>
                            <?php if (isset($_SESSION['user_id'])): ?>
                                <a href="fund.php?id=<?php echo $topic->id; ?>" class="action-button">
                                    💰 Fund This Topic
                                </a>
                            <?php else: ?>
                                <a href="../auth/login.php" class="action-button login">
                                    🔑 Login to Fund
                                </a>
                            <?php endif; ?>
                        <?php elseif ($topic->status === 'funded'): ?>
                            <div class="action-button completed">
                                ✅ Fully Funded! Content coming soon...
                            </div>
                        <?php elseif ($topic->status === 'completed'): ?>
                            <div class="action-button completed">
                                ✅ Completed!
                            </div>
                        <?php elseif ($topic->status === 'pending_approval'): ?>
                            <div class="action-button completed">
                                ⏳ Awaiting Creator Approval
                            </div>
                        <?php endif; ?>
                    </div>


                </div>
            </div>


        <?php endif; ?>
    </div>

    <script>
    function copyToClipboard() {
        navigator.clipboard.writeText(window.location.href).then(function() {
            // Show success feedback
            const button = event.target;
            const originalText = button.textContent;
            button.textContent = '✅ Copied!';
            button.style.background = '#28a745';
            
            setTimeout(() => {
                button.textContent = originalText;
                button.style.background = '#6c757d';
            }, 2000);
        }).catch(function() {
            // Fallback for older browsers
            const textArea = document.createElement('textarea');
            textArea.value = window.location.href;
            document.body.appendChild(textArea);
            textArea.select();
            document.execCommand('copy');
            document.body.removeChild(textArea);
            
            alert('Link copied to clipboard!');
        });
    }

    // Auto-refresh for funded topics to show real-time updates
    <?php if ($topic->status === 'funded'): ?>
    // Check for content updates every 5 minutes
    setInterval(function() {
        fetch(window.location.href)
            .then(response => response.text())
            .then(html => {
                // Check if status changed to completed
                if (html.includes('status-completed') && !document.querySelector('.status-completed')) {
                    window.location.reload();
                }
            })
            .catch(error => console.log('Status check failed:', error));
    }, 300000); // 5 minutes
    <?php endif; ?>
    </script>
</body>
</html>
