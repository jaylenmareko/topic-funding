<?php
// creators/dashboard.php - Enhanced with Stripe Connect payment tracking
session_start();
require_once '../config/database.php';
require_once '../config/navigation.php';
require_once '../config/stripe_connect.php';
require_once '../config/auto_payout.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$db = new Database();
$stripeConnect = new StripeConnectManager();
$autoPayoutManager = new AutoPayoutManager();

// Get creator info
$db->query('
    SELECT c.*, u.email 
    FROM creators c 
    LEFT JOIN users u ON c.applicant_user_id = u.id 
    WHERE c.applicant_user_id = :user_id AND c.is_active = 1
');
$db->bind(':user_id', $_SESSION['user_id']);
$creator = $db->single();

if (!$creator) {
    header('Location: apply.php');
    exit;
}

// Check Stripe Connect status
$stripe_ready = $stripeConnect->isAccountReady($creator->id);

// Get creator statistics
$db->query('
    SELECT 
        COUNT(*) as total_topics,
        COUNT(CASE WHEN status = "pending_approval" THEN 1 END) as pending_approval,
        COUNT(CASE WHEN status = "active" THEN 1 END) as active_topics,
        COUNT(CASE WHEN status = "funded" THEN 1 END) as funded_topics,
        COUNT(CASE WHEN status = "completed" THEN 1 END) as completed_topics,
        COALESCE(SUM(CASE WHEN status IN ("completed") THEN current_funding ELSE 0 END), 0) as total_earned_gross,
        COALESCE(SUM(CASE WHEN status IN ("completed") THEN current_funding * 0.9 ELSE 0 END), 0) as total_earned_net,
        COALESCE(SUM(CASE WHEN status = "funded" THEN current_funding * 0.9 ELSE 0 END), 0) as pending_earnings
    FROM topics 
    WHERE creator_id = :creator_id
');
$db->bind(':creator_id', $creator->id);
$stats = $db->single();

// Get topics by status
$db->query('
    SELECT t.*, u.username as proposer_name
    FROM topics t
    LEFT JOIN users u ON t.initiator_user_id = u.id
    WHERE t.creator_id = :creator_id AND t.status = "pending_approval"
    ORDER BY t.created_at DESC
');
$db->bind(':creator_id', $creator->id);
$pending_topics = $db->resultSet();

$db->query('
    SELECT t.*, u.username as proposer_name,
           (SELECT COUNT(*) FROM contributions WHERE topic_id = t.id AND payment_status = "completed") as contributor_count
    FROM topics t
    LEFT JOIN users u ON t.initiator_user_id = u.id
    WHERE t.creator_id = :creator_id AND t.status = "active"
    ORDER BY t.created_at DESC
');
$db->bind(':creator_id', $creator->id);
$active_topics = $db->resultSet();

$db->query('
    SELECT t.*, u.username as proposer_name,
           (SELECT COUNT(*) FROM contributions WHERE topic_id = t.id AND payment_status = "completed") as contributor_count
    FROM topics t
    LEFT JOIN users u ON t.initiator_user_id = u.id
    WHERE t.creator_id = :creator_id AND t.status = "funded"
    ORDER BY t.funded_at DESC
');
$db->bind(':creator_id', $creator->id);
$funded_topics = $db->resultSet();

// Get recent completed topics with payment info
$db->query('
    SELECT t.*, u.username as proposer_name,
           cp.status as payout_status, cp.processed_at as payout_date,
           (t.current_funding * 0.9) as net_earnings
    FROM topics t
    LEFT JOIN users u ON t.initiator_user_id = u.id
    LEFT JOIN creator_payouts cp ON t.id = cp.topic_id
    WHERE t.creator_id = :creator_id AND t.status = "completed"
    ORDER BY t.completed_at DESC
    LIMIT 10
');
$db->bind(':creator_id', $creator->id);
$completed_topics = $db->resultSet();

// Get payout history
$payout_history = $autoPayoutManager->getCreatorPayoutHistory($creator->id);

// Handle topic approval/rejection
$message = '';
$error = '';

if ($_POST && isset($_POST['topic_action'])) {
    $topic_id = (int)$_POST['topic_id'];
    $action = $_POST['topic_action'];
    
    // Verify topic belongs to this creator
    $db->query('SELECT id FROM topics WHERE id = :topic_id AND creator_id = :creator_id');
    $db->bind(':topic_id', $topic_id);
    $db->bind(':creator_id', $creator->id);
    
    if ($db->single()) {
        if ($action === 'approve') {
            $db->query('UPDATE topics SET status = "active", approval_status = "approved" WHERE id = :topic_id');
            $db->bind(':topic_id', $topic_id);
            if ($db->execute()) {
                $message = "Topic approved and is now live for funding!";
            }
        } elseif ($action === 'reject') {
            $db->query('UPDATE topics SET status = "rejected", approval_status = "rejected" WHERE id = :topic_id');
            $db->bind(':topic_id', $topic_id);
            if ($db->execute()) {
                $message = "Topic rejected.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Creator Dashboard - <?php echo htmlspecialchars($creator->display_name); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; background: #f5f5f5; }
        .container { max-width: 1400px; margin: 0 auto; padding: 20px; }
        .header { background: white; padding: 30px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .creator-info { display: flex; align-items: center; gap: 20px; margin-bottom: 20px; }
        .creator-avatar { width: 80px; height: 80px; background: linear-gradient(135deg, #667eea, #764ba2); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 32px; color: white; font-weight: bold; }
        .creator-details h1 { margin: 0 0 5px 0; color: #333; }
        .creator-details p { margin: 0; color: #666; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; padding: 25px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); text-align: center; }
        .stat-number { font-size: 28px; font-weight: bold; margin-bottom: 5px; }
        .stat-label { color: #666; font-size: 14px; }
        .earnings-stat { color: #28a745; }
        .pending-stat { color: #ffc107; }
        .topics-stat { color: #667eea; }
        .payment-status { padding: 20px; border-radius: 8px; margin-bottom: 20px; }
        .payment-ready { background: #d4edda; border: 2px solid #28a745; color: #155724; }
        .payment-pending { background: #fff3cd; border: 2px solid #ffc107; color: #856404; }
        .payment-needed { background: #f8d7da; border: 2px solid #dc3545; color: #721c24; }
        .section { background: white; padding: 25px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .section h2 { margin-top: 0; color: #333; }
        .topic-card { border: 1px solid #e9ecef; padding: 20px; border-radius: 8px; margin-bottom: 15px; }
        .topic-header { display: flex; justify-content: space-between; align-items: start; margin-bottom: 15px; }
        .topic-title { font-weight: bold; color: #333; font-size: 16px; margin-bottom: 8px; }
        .topic-meta { color: #666; font-size: 14px; margin-bottom: 10px; }
        .topic-description { color: #666; line-height: 1.5; margin: 10px 0; }
        .status-badge { padding: 6px 12px; border-radius: 12px; font-size: 12px; font-weight: bold; }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-active { background: #cce5ff; color: #004085; }
        .status-funded { background: #d4edda; color: #155724; }
        .status-completed { background: #e2e3e5; color: #495057; }
        .btn { background: #667eea; color: white; padding: 10px 20px; border: none; border-radius: 6px; cursor: pointer; text-decoration: none; display: inline-block; font-size: 14px; margin: 5px; }
        .btn:hover { background: #5a6fd8; color: white; text-decoration: none; }
        .btn-success { background: #28a745; }
        .btn-success:hover { background: #218838; }
        .btn-danger { background: #dc3545; }
        .btn-danger:hover { background: #c82333; }
        .btn-warning { background: #ffc107; color: #212529; }
        .btn-warning:hover { background: #e0a800; }
        .funding-bar { background: #e9ecef; height: 8px; border-radius: 4px; margin: 10px 0; }
        .funding-progress { background: #28a745; height: 100%; border-radius: 4px; transition: width 0.3s; }
        .deadline-warning { background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 6px; margin: 10px 0; }
        .payout-item { display: flex; justify-content: space-between; align-items: center; padding: 15px 0; border-bottom: 1px solid #eee; }
        .payout-item:last-child { border-bottom: none; }
        .payout-details { flex: 1; }
        .payout-amount { font-weight: bold; color: #28a745; font-size: 18px; }
        .payout-date { color: #666; font-size: 14px; }
        .payout-type { padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; }
        .payout-stripe { background: #e8f5e8; color: #2d5f2d; }
        .payout-manual { background: #fff3cd; color: #856404; }
        .message { color: green; margin-bottom: 20px; padding: 15px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 6px; }
        .error { color: red; margin-bottom: 20px; padding: 15px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 6px; }
        .empty-state { text-align: center; color: #666; padding: 40px; }
        .content-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; }
        
        @media (max-width: 1200px) {
            .content-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px) {
            .container { padding: 15px; }
            .creator-info { flex-direction: column; text-align: center; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>
    <?php renderNavigation('creator_dashboard'); ?>

    <div class="container">
        <div class="header">
            <div class="creator-info">
                <div class="creator-avatar">
                    <?php if ($creator->profile_image): ?>
                        <img src="../uploads/creators/<?php echo htmlspecialchars($creator->profile_image); ?>" 
                             alt="Profile" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                    <?php else: ?>
                        <?php echo strtoupper(substr($creator->display_name, 0, 1)); ?>
                    <?php endif; ?>
                </div>
                <div class="creator-details">
                    <h1><?php echo htmlspecialchars($creator->display_name); ?> Creator Dashboard</h1>
                    <p><?php echo number_format($creator->subscriber_count); ?> subscribers • <?php echo ucfirst($creator->platform_type); ?></p>
                </div>
            </div>

            <!-- Payment Status -->
            <?php if ($stripe_ready): ?>
                <div class="payment-status payment-ready">
                    <strong>✅ Instant Payments Active</strong> - You'll receive automatic payouts when you complete topics
                </div>
            <?php elseif ($creator->stripe_account_id): ?>
                <div class="payment-status payment-pending">
                    <strong>⏳ Payment Setup In Progress</strong> - Stripe verification pending
                    <a href="stripe_onboarding.php?creator_id=<?php echo $creator->id; ?>" style="margin-left: 15px;" class="btn btn-warning">Check Status</a>
                </div>
            <?php else: ?>
                <div class="payment-status payment-needed">
                    <strong>💳 Setup Instant Payments</strong> - Get paid automatically when you complete topics
                    <a href="stripe_onboarding.php?creator_id=<?php echo $creator->id; ?>" style="margin-left: 15px;" class="btn btn-success">Setup Now</a>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($message): ?>
            <div class="message"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number earnings-stat">$<?php echo number_format($stats->total_earned_net, 0); ?></div>
                <div class="stat-label">Total Earnings</div>
            </div>
            <div class="stat-card">
                <div class="stat-number pending-stat">$<?php echo number_format($stats->pending_earnings, 0); ?></div>
                <div class="stat-label">Pending Earnings</div>
            </div>
            <div class="stat-card">
                <div class="stat-number topics-stat"><?php echo $stats->completed_topics; ?></div>
                <div class="stat-label">Completed Topics</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" style="color: #dc3545;"><?php echo $stats->pending_approval; ?></div>
                <div class="stat-label">Awaiting Approval</div>
            </div>
        </div>

        <div class="content-grid">
            <div>
                <!-- Pending Approval Topics -->
                <?php if (!empty($pending_topics)): ?>
                <div class="section">
                    <h2>📋 Topics Awaiting Your Approval (<?php echo count($pending_topics); ?>)</h2>
                    <?php foreach ($pending_topics as $topic): ?>
                        <div class="topic-card">
                            <div class="topic-header">
                                <div>
                                    <div class="topic-title"><?php echo htmlspecialchars($topic->title); ?></div>
                                    <div class="topic-meta">
                                        Proposed by <?php echo htmlspecialchars($topic->proposer_name); ?> • 
                                        <?php echo date('M j, Y g:i A', strtotime($topic->created_at)); ?> •
                                        Goal: $<?php echo number_format($topic->funding_threshold, 0); ?>
                    </div>
                </div>
                <span class="status-badge status-pending">Pending Approval</span>
            </div>
            <div class="topic-description">
                <?php echo htmlspecialchars(substr($topic->description, 0, 200)) . (strlen($topic->description) > 200 ? '...' : ''); ?>
            </div>
            <div style="display: flex; gap: 10px; margin-top: 15px;">
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="topic_id" value="<?php echo $topic->id; ?>">
                    <input type="hidden" name="topic_action" value="approve">
                    <button type="submit" class="btn btn-success" onclick="return confirm('Approve this topic for community funding?')">
                        ✅ Approve
                    </button>
                </form>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="topic_id" value="<?php echo $topic->id; ?>">
                    <input type="hidden" name="topic_action" value="reject">
                    <button type="submit" class="btn btn-danger" onclick="return confirm('Reject this topic proposal?')">
                        ❌ Reject
                    </button>
                </form>
                <a href="../topics/view.php?id=<?php echo $topic->id; ?>" class="btn">👀 View Details</a>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Funded Topics (Need Content) -->
<?php if (!empty($funded_topics)): ?>
<div class="section" id="funded-topics">
    <h2>⚡ Funded Topics - Create Content! (<?php echo count($funded_topics); ?>)</h2>
    <?php foreach ($funded_topics as $topic): ?>
        <div class="topic-card" style="border-left: 4px solid #28a745;">
            <div class="topic-header">
                <div>
                    <div class="topic-title"><?php echo htmlspecialchars($topic->title); ?></div>
                    <div class="topic-meta">
                        Funded: $<?php echo number_format($topic->current_funding, 0); ?> • 
                        <?php echo $topic->contributor_count; ?> contributors • 
                        Your earnings: $<?php echo number_format($topic->current_funding * 0.9, 0); ?>
                    </div>
                </div>
                <span class="status-badge status-funded">Funded!</span>
            </div>
            
            <?php if ($topic->content_deadline): ?>
                <?php 
                $time_remaining = strtotime($topic->content_deadline) - time();
                $hours_remaining = max(0, floor($time_remaining / 3600));
                $deadline_passed = $time_remaining <= 0;
                ?>
                <?php if (!$deadline_passed): ?>
                    <div class="deadline-warning">
                        <strong>⏰ Deadline: <?php echo date('M j, Y g:i A', strtotime($topic->content_deadline)); ?></strong><br>
                        <?php echo $hours_remaining; ?> hours remaining to create content
                    </div>
                <?php else: ?>
                    <div style="background: #f8d7da; border: 1px solid #f5c6cb; padding: 15px; border-radius: 6px; margin: 10px 0; color: #721c24;">
                        <strong>⚠️ DEADLINE PASSED</strong> - Auto-refunds may have been processed
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            
            <div style="display: flex; gap: 10px; margin-top: 15px;">
                <a href="upload_content.php?topic=<?php echo $topic->id; ?>" class="btn btn-success">
                    📤 Upload Content
                </a>
                <a href="../topics/view.php?id=<?php echo $topic->id; ?>" class="btn">👀 View Details</a>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Active Topics -->
<?php if (!empty($active_topics)): ?>
<div class="section">
    <h2>🔥 Active Topics Seeking Funding (<?php echo count($active_topics); ?>)</h2>
    <?php foreach ($active_topics as $topic): ?>
        <div class="topic-card">
            <div class="topic-header">
                <div>
                    <div class="topic-title"><?php echo htmlspecialchars($topic->title); ?></div>
                    <div class="topic-meta">
                        <?php echo $topic->contributor_count; ?> contributors • 
                        <?php echo date('M j, Y', strtotime($topic->created_at)); ?>
                    </div>
                </div>
                <span class="status-badge status-active">Active</span>
            </div>
            
            <?php 
            $progress = ($topic->current_funding / $topic->funding_threshold) * 100;
            $progress = min($progress, 100);
            ?>
            <div class="funding-bar">
                <div class="funding-progress" style="width: <?php echo $progress; ?>%"></div>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 10px;">
                <span>$<?php echo number_format($topic->current_funding, 0); ?> / $<?php echo number_format($topic->funding_threshold, 0); ?> (<?php echo round($progress); ?>%)</span>
                <a href="../topics/view.php?id=<?php echo $topic->id; ?>" class="btn">👀 View</a>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Recent Completed Topics -->
<?php if (!empty($completed_topics)): ?>
<div class="section">
    <h2>✅ Recent Completed Topics</h2>
    <?php foreach (array_slice($completed_topics, 0, 5) as $topic): ?>
        <div class="topic-card">
            <div class="topic-header">
                <div>
                    <div class="topic-title"><?php echo htmlspecialchars($topic->title); ?></div>
                    <div class="topic-meta">
                        Completed <?php echo date('M j, Y', strtotime($topic->completed_at)); ?> • 
                        Earned: $<?php echo number_format($topic->net_earnings, 2); ?>
                    </div>
                </div>
                <div>
                    <span class="status-badge status-completed">Completed</span>
                    <?php if ($topic->payout_status === 'completed'): ?>
                        <span style="background: #d4edda; color: #155724; padding: 4px 8px; border-radius: 12px; font-size: 11px; margin-left: 5px;">💰 Paid</span>
                    <?php else: ?>
                        <span style="background: #fff3cd; color: #856404; padding: 4px 8px; border-radius: 12px; font-size: 11px; margin-left: 5px;">⏳ Processing</span>
                    <?php endif; ?>
                </div>
            </div>
            
            <div style="display: flex; gap: 10px; margin-top: 10px;">
                <?php if ($topic->content_url): ?>
                    <a href="<?php echo htmlspecialchars($topic->content_url); ?>" target="_blank" class="btn">🎬 View Content</a>
                <?php endif; ?>
                <a href="../topics/view.php?id=<?php echo $topic->id; ?>" class="btn">👀 Details</a>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
</div>

<div>
<!-- Quick Actions -->
<div class="section">
    <h3>⚡ Quick Actions</h3>
    <div style="display: flex; flex-direction: column; gap: 10px;">
        <a href="../topics/create.php?creator_id=<?php echo $creator->id; ?>" class="btn btn-success">💡 Propose New Topic</a>
        <a href="../topics/index.php" class="btn">🔍 Browse All Topics</a>
        <a href="edit.php?id=<?php echo $creator->id; ?>" class="btn">✏️ Edit Profile</a>
        <?php if (!$stripe_ready): ?>
            <a href="stripe_onboarding.php?creator_id=<?php echo $creator->id; ?>" class="btn btn-warning">💳 Setup Payments</a>
        <?php endif; ?>
    </div>
</div>

<!-- Payment History -->
<div class="section">
    <h3>💰 Payment History</h3>
    <?php if (empty($payout_history)): ?>
        <div class="empty-state">
            <p>No payments yet. Complete funded topics to start earning!</p>
        </div>
    <?php else: ?>
        <div style="max-height: 400px; overflow-y: auto;">
            <?php foreach (array_slice($payout_history, 0, 10) as $payout): ?>
                <div class="payout-item">
                    <div class="payout-details">
                        <div style="font-weight: bold; margin-bottom: 5px;">
                            <?php echo htmlspecialchars($payout->topic_title); ?>
                        </div>
                        <div class="payout-date">
                            <?php echo date('M j, Y g:i A', strtotime($payout->processed_at)); ?>
                            <span class="payout-type payout-<?php echo $payout->type; ?>">
                                <?php echo $payout->type === 'stripe' ? '⚡ Instant' : '📋 Manual'; ?>
                            </span>
                        </div>
                        <?php if ($payout->reference): ?>
                            <div style="font-size: 12px; color: #666;">
                                Ref: <?php echo htmlspecialchars($payout->reference); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="payout-amount">
                        $<?php echo number_format($payout->amount, 2); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <?php if (count($payout_history) > 10): ?>
            <div style="text-align: center; margin-top: 15px;">
                <span style="color: #666;">Showing 10 most recent payments</span>
            </div>
        <?php endif; ?>
        
        <div style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 6px;">
            <strong>💡 Payment Summary:</strong><br>
            <span style="color: #666;">Total Earned: $<?php echo number_format($stats->total_earned_net, 2); ?> • 
            Pending: $<?php echo number_format($stats->pending_earnings, 2); ?></span>
        </div>
    <?php endif; ?>
</div>

<!-- Performance Stats -->
<div class="section">
    <h3>📊 Performance</h3>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 15px;">
        <div style="text-align: center; padding: 15px; background: #f8f9fa; border-radius: 6px;">
            <div style="font-size: 20px; font-weight: bold; color: #667eea;"><?php echo $stats->total_topics; ?></div>
            <div style="font-size: 12px; color: #666;">Total Topics</div>
        </div>
        <div style="text-align: center; padding: 15px; background: #f8f9fa; border-radius: 6px;">
            <div style="font-size: 20px; font-weight: bold; color: #28a745;"><?php echo $stats->completed_topics; ?></div>
            <div style="font-size: 12px; color: #666;">Completed</div>
        </div>
        <div style="text-align: center; padding: 15px; background: #f8f9fa; border-radius: 6px;">
            <div style="font-size: 20px; font-weight: bold; color: #ffc107;"><?php echo $stats->active_topics; ?></div>
            <div style="font-size: 12px; color: #666;">Active</div>
        </div>
        <div style="text-align: center; padding: 15px; background: #f8f9fa; border-radius: 6px;">
            <div style="font-size: 20px; font-weight: bold; color: #dc3545;"><?php echo number_format(($stats->completed_topics / max($stats->total_topics, 1)) * 100, 0); ?>%</div>
            <div style="font-size: 12px; color: #666;">Success Rate</div>
        </div>
    </div>
</div>
</div>
</div>

<!-- No Topics State -->
<?php if ($stats->total_topics == 0): ?>
<div style="text-align: center; margin-top: 40px; padding: 40px; background: white; border-radius: 12px;">
    <h3>🚀 Ready to Start Creating?</h3>
    <p>You don't have any topics yet. Wait for proposals or create your own!</p>
    <div style="margin-top: 20px;">
        <a href="../topics/create.php?creator_id=<?php echo $creator->id; ?>" class="btn btn-success">💡 Create First Topic</a>
        <a href="../topics/index.php" class="btn">🔍 Browse Community Topics</a>
    </div>
</div>
<?php endif; ?>
</div>

<script>
// Auto-refresh funding progress
setInterval(function() {
    // Refresh active topics funding bars
    document.querySelectorAll('.funding-progress').forEach(function(bar) {
        // This would need AJAX to update in real-time
        // For now, just add a subtle animation
        bar.style.opacity = '0.8';
        setTimeout(() => bar.style.opacity = '1', 200);
    });
}, 30000); // Every 30 seconds

// Highlight urgent funded topics
document.addEventListener('DOMContentLoaded', function() {
    const fundedSection = document.getElementById('funded-topics');
    if (fundedSection && <?php echo count($funded_topics); ?> > 0) {
        // Add pulsing effect to urgent topics
        fundedSection.querySelectorAll('.deadline-warning').forEach(function(warning) {
            if (warning.textContent.includes('hours remaining')) {
                const hours = parseInt(warning.textContent.match(/(\d+) hours/)[1]);
                if (hours <= 6) {
                    warning.style.animation = 'pulse 2s infinite';
                }
            }
        });
    }
});

// Add CSS for pulse animation
const style = document.createElement('style');
style.textContent = `
@keyframes pulse {
    0% { box-shadow: 0 0 0 0 rgba(255, 193, 7, 0.7); }
    70% { box-shadow: 0 0 0 10px rgba(255, 193, 7, 0); }
    100% { box-shadow: 0 0 0 0 rgba(255, 193, 7, 0); }
}
`;
document.head.appendChild(style);
</script>
</body>
</html>
