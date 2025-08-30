<?php
// creators/dashboard.php - Improved creator dashboard with topic management controls
session_start();
require_once '../config/database.php';
require_once '../config/navigation.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$db = new Database();

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
    // If no creator found, redirect to browse creators instead of dashboard
    header('Location: ../creators/index.php');
    exit;
}

// Calculate earnings
$db->query('
    SELECT 
        COALESCE(SUM(CASE WHEN t.status = "completed" THEN t.current_funding * 0.9 END), 0) as total_earned
    FROM topics t 
    WHERE t.creator_id = :creator_id
');
$db->bind(':creator_id', $creator->id);
$earnings = $db->single();

// Check if PayPal email is set
$has_paypal = !empty($creator->paypal_email);
$needs_paypal_setup = $earnings->total_earned >= ($creator->manual_payout_threshold ?? 100) && !$has_paypal;

// Get creator's topics for sharing dropdown (all topics, not just recent)
$db->query('
    SELECT id, title, status, created_at, current_funding, funding_threshold
    FROM topics 
    WHERE creator_id = :creator_id 
    AND status IN ("active", "funded", "completed", "on_hold")
    ORDER BY created_at DESC
');
$db->bind(':creator_id', $creator->id);
$shareable_topics = $db->resultSet();

// Default share URL (fallback)
$default_share_url = 'https://topiclaunch.com/';
if (!empty($shareable_topics)) {
    $default_share_url = 'https://topiclaunch.com/topics/view.php?id=' . $shareable_topics[0]->id;
}

// Get urgent funded topics awaiting content (48-hour deadline)
$db->query('
    SELECT t.*, 
           (SELECT COUNT(*) FROM contributions WHERE topic_id = t.id AND payment_status = "completed") as contributor_count,
           TIMESTAMPDIFF(HOUR, t.funded_at, NOW()) as hours_since_funded,
           (48 - TIMESTAMPDIFF(HOUR, t.funded_at, NOW())) as hours_remaining
    FROM topics t 
    WHERE t.creator_id = :creator_id 
    AND t.status = "funded" 
    AND t.content_url IS NULL
    AND TIMESTAMPDIFF(HOUR, t.funded_at, NOW()) < 48
    ORDER BY t.funded_at ASC
');
$db->bind(':creator_id', $creator->id);
$urgent_topics = $db->resultSet();

// Get topics on hold
$db->query('
    SELECT t.*, 
           (SELECT COUNT(*) FROM contributions WHERE topic_id = t.id AND payment_status = "completed") as contributor_count
    FROM topics t 
    WHERE t.creator_id = :creator_id 
    AND t.status = "on_hold"
    ORDER BY t.held_at DESC
');
$db->bind(':creator_id', $creator->id);
$held_topics = $db->resultSet();

// Get live topics being funded
$db->query('
    SELECT t.*, 
           (t.current_funding / t.funding_threshold * 100) as funding_percentage,
           (SELECT COUNT(*) FROM contributions WHERE topic_id = t.id AND payment_status = "completed") as contributor_count
    FROM topics t 
    WHERE t.creator_id = :creator_id 
    AND t.status = "active"
    ORDER BY funding_percentage DESC, t.created_at DESC
    LIMIT 5
');
$db->bind(':creator_id', $creator->id);
$live_topics = $db->resultSet();

// Get completed topics (last 5)
$db->query('
    SELECT t.*, t.content_url, t.completed_at
    FROM topics t 
    WHERE t.creator_id = :creator_id 
    AND t.status = "completed" 
    ORDER BY t.completed_at DESC 
    LIMIT 5
');
$db->bind(':creator_id', $creator->id);
$completed_topics = $db->resultSet();
?>
<!DOCTYPE html>
<html>
<head>
    <title>YouTuber Dashboard - TopicLaunch</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; background: #f8f9fa; }
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        
        /* Header */
        .dashboard-header { 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
            color: white; 
            padding: 30px 20px; 
            margin-bottom: 30px;
            border-radius: 12px;
        }
        .header-content { max-width: 1200px; margin: 0 auto; }
        .header-title { font-size: 32px; margin: 0 0 10px 0; font-weight: bold; }
        .header-subtitle { font-size: 18px; margin: 0 0 20px 0; opacity: 0.9; }
        
        /* Share Section */
        .share-section { 
            background: rgba(255,255,255,0.1); 
            padding: 20px; 
            border-radius: 10px; 
            margin-top: 20px;
            backdrop-filter: blur(10px);
        }
        .share-controls { display: flex; gap: 15px; align-items: center; flex-wrap: wrap; }
        .topic-selector {
            background: rgba(255,255,255,0.2); 
            border: 1px solid rgba(255,255,255,0.3);
            padding: 8px 12px; 
            border-radius: 8px; 
            color: white;
            font-size: 14px;
            min-width: 200px;
        }
        .topic-selector option {
            background: #333;
            color: white;
        }
        .share-url { 
            background: rgba(255,255,255,0.2); 
            padding: 12px 15px; 
            border-radius: 8px; 
            font-family: monospace; 
            font-size: 14px; 
            flex: 1; 
            min-width: 300px;
            word-break: break-all;
        }
        .share-btn { 
            background: rgba(255,255,255,0.2); 
            color: white; 
            padding: 12px 20px; 
            border: none; 
            border-radius: 8px; 
            cursor: pointer; 
            font-weight: bold;
            transition: background 0.3s;
            text-decoration: none;
            display: inline-block;
        }
        .share-btn:hover { background: rgba(255,255,255,0.3); color: white; text-decoration: none; }
        .share-info { font-size: 12px; opacity: 0.8; margin-bottom: 10px; }
        
        /* PayPal Setup Alert */
        .paypal-alert { 
            background: #fff3cd; 
            border: 2px solid #ffc107; 
            color: #856404;
            padding: 20px; 
            border-radius: 12px; 
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .paypal-alert-content h3 { margin: 0 0 10px 0; }
        .paypal-alert-btn { 
            background: #ffc107; 
            color: #212529; 
            padding: 12px 20px; 
            text-decoration: none; 
            border-radius: 6px; 
            font-weight: bold;
            white-space: nowrap;
        }
        
        /* Alert Sections */
        .urgent-section { 
            background: #fff3cd; 
            border: 2px solid #ffc107; 
            border-radius: 12px; 
            padding: 25px; 
            margin-bottom: 30px;
        }
        .urgent-header { 
            display: flex; 
            align-items: center; 
            margin-bottom: 20px; 
        }
        .urgent-icon { 
            font-size: 28px; 
            margin-right: 15px; 
        }
        .urgent-title { 
            font-size: 24px; 
            color: #856404; 
            margin: 0; 
            font-weight: bold;
        }
        
        /* Main Grid */
        .dashboard-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 30px; margin-bottom: 30px; }
        .dashboard-card { 
            background: white; 
            border-radius: 12px; 
            padding: 25px; 
            box-shadow: 0 4px 12px rgba(0,0,0,0.1); 
        }
        .card-header { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            margin-bottom: 20px; 
            border-bottom: 2px solid #f1f3f4;
            padding-bottom: 15px;
        }
        .card-title { 
            font-size: 20px; 
            font-weight: bold; 
            color: #333; 
            margin: 0; 
        }
        .card-icon { font-size: 24px; }
        
        /* Topic Items */
        .topic-item { 
            background: #f8f9fa; 
            border-radius: 8px; 
            padding: 20px; 
            margin-bottom: 15px; 
            border-left: 4px solid #007bff;
        }
        .urgent-topic { 
            background: #fff3cd; 
            border-left-color: #ffc107; 
        }
        .topic-title { 
            font-size: 18px; 
            font-weight: bold; 
            color: #333; 
            margin: 0 0 10px 0; 
        }
        .topic-meta { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            font-size: 14px; 
            color: #666; 
            margin-bottom: 15px;
        }
        .countdown { 
            background: #dc3545; 
            color: white; 
            padding: 5px 12px; 
            border-radius: 15px; 
            font-weight: bold; 
            font-size: 12px;
        }
        .progress-bar { 
            background: #e9ecef; 
            border-radius: 10px; 
            height: 8px; 
            overflow: hidden; 
            margin: 10px 0;
        }
        .progress-fill { 
            background: #28a745; 
            height: 100%; 
            transition: width 0.3s;
        }
        
        /* Buttons */
        .btn { 
            padding: 10px 20px; 
            border: none; 
            border-radius: 6px; 
            cursor: pointer; 
            text-decoration: none; 
            font-weight: bold; 
            display: inline-block;
            text-align: center;
        }
        .btn-primary { background: #007bff; color: white; }
        .btn-primary:hover { background: #0056b3; color: white; text-decoration: none; }
        .btn-success { background: #28a745; color: white; }
        .btn-success:hover { background: #218838; color: white; text-decoration: none; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-danger:hover { background: #c82333; color: white; text-decoration: none; }
        
        /* Stats */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-item { text-align: center; background: white; padding: 20px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .stat-value { font-size: 32px; font-weight: bold; color: #007bff; margin: 0; }
        .stat-label { font-size: 14px; color: #666; margin: 5px 0 0 0; }
        
        .empty-state { 
            text-align: center; 
            color: #666; 
            padding: 40px 20px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        .payout-message { 
            background: #e3f2fd; 
            padding: 20px; 
            border-radius: 8px; 
            margin-top: 15px;
            font-size: 14px;
        }

        /* Modal styles */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; }
        .modal-content { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 30px; border-radius: 8px; max-width: 500px; width: 90%; }
        .modal-actions { margin-top: 20px; text-align: right; }
        .modal-actions button { margin-left: 10px; }
        
        @media (max-width: 768px) {
            .dashboard-grid { grid-template-columns: 1fr; }
            .container { padding: 10px; }
            .dashboard-header { margin: 10px; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .share-controls { flex-direction: column; align-items: stretch; }
            .share-url { min-width: auto; }
            .paypal-alert { flex-direction: column; gap: 15px; text-align: center; }
            .topic-selector { min-width: auto; }
        }
    </style>
</head>
<body>
    <?php renderNavigation('dashboard'); ?>
    
    <div class="container">
        <!-- Header with Share Section -->
        <div class="dashboard-header">
            <div class="header-content">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px;">
                    <div>
                        <h1 class="header-title">📺 YouTuber Dashboard</h1>
                        <p class="header-subtitle">Welcome, <?php echo htmlspecialchars($creator->display_name); ?>!</p>
                    </div>
                    <a href="../creators/edit.php?id=<?php echo $creator->id; ?>" class="share-btn" style="background: rgba(255,255,255,0.3);">⚙️ Edit Profile</a>
                </div>
                
                <!-- Share Your Topics -->
                <div class="share-section">
                    <?php if (!empty($shareable_topics)): ?>
                        <p style="margin: 0 0 15px 0; opacity: 0.9;">Share your topics with fans so they can fund them!</p>
                        <div class="share-info">Select a topic to share:</div>
                        <div class="share-controls">
                            <select class="topic-selector" id="topicSelector" onchange="updateShareUrl()">
                                <?php foreach ($shareable_topics as $topic): ?>
                                    <option value="<?php echo $topic->id; ?>" data-title="<?php echo htmlspecialchars($topic->title); ?>">
                                        <?php echo htmlspecialchars($topic->title); ?> 
                                        (<?php echo ucfirst($topic->status); ?> - 
                                        $<?php echo number_format($topic->current_funding, 0); ?>/$<?php echo number_format($topic->funding_threshold, 0); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="share-url" id="shareUrl"><?php echo $default_share_url; ?></div>
                            <button class="share-btn" onclick="copyShareLink()">📋 Copy Link</button>
                        </div>
                    <?php else: ?>
                        <p style="margin: 0 0 15px 0; opacity: 0.9;">Create topics to share with your fans!</p>
                        <div class="share-controls">
                            <div class="share-url">No topics available to share yet</div>
                            <a href="../topics/create.php?creator_id=<?php echo $creator->id; ?>" class="share-btn">Create Topic</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Display messages -->
        <?php 
        if (isset($_GET['success'])) {
            echo '<div style="background: #d4edda; color: #155724; padding: 15px; border-radius: 6px; margin-bottom: 20px;">' . htmlspecialchars($_GET['success']) . '</div>';
        }
        if (isset($_GET['error'])) {
            echo '<div style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 6px; margin-bottom: 20px;">' . htmlspecialchars($_GET['error']) . '</div>';
        }
        ?>

        <!-- PayPal Setup Alert -->
        <?php if ($needs_paypal_setup): ?>
        <div class="paypal-alert">
            <div class="paypal-alert-content">
                <h3>💰 Ready to Withdraw $<?php echo number_format($earnings->total_earned, 2); ?>!</h3>
                <p>You've reached the $<?php echo number_format($creator->manual_payout_threshold ?? 100, 0); ?> minimum. Enter your PayPal email to receive payments.</p>
            </div>
            <a href="../creators/setup_paypal.php" class="paypal-alert-btn">Enter PayPal Email</a>
        </div>
        <?php endif; ?>

        <!-- Urgent: Funded Topics Awaiting Content -->
        <?php if (!empty($urgent_topics)): ?>
        <div class="urgent-section">
            <div class="urgent-header">
                <span class="urgent-icon">🔥</span>
                <h2 class="urgent-title">Action Required - Deliver Content</h2>
            </div>
            
            <?php foreach ($urgent_topics as $topic): ?>
            <div class="topic-item urgent-topic">
                <h3 class="topic-title"><?php echo htmlspecialchars($topic->title); ?></h3>
                <div class="topic-meta">
                    <span><strong>$<?php echo number_format($topic->current_funding, 2); ?></strong> funded</span>
                    <span class="countdown">
                        ⏰ <?php echo max(0, $topic->hours_remaining); ?> hours left
                    </span>
                </div>
                <p style="margin: 10px 0; color: #666;">
                    <?php echo htmlspecialchars(substr($topic->description, 0, 150)); ?>...
                </p>
                <div style="margin-top: 15px;">
                    <a href="../creators/upload_content.php?topic=<?php echo $topic->id; ?>" class="btn btn-success">
                        🎬 Upload Content Now
                    </a>
                    <button onclick="showTopicActions(<?php echo $topic->id; ?>, 'funded')" class="btn btn-danger" style="margin-left: 10px;">
                        ⏸️ Manage Topic
                    </button>
                    <a href="../topics/view.php?id=<?php echo $topic->id; ?>" class="btn btn-primary" style="margin-left: 10px;">
                        👁️ View Details
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Topics on Hold -->
        <?php if (!empty($held_topics)): ?>
        <div class="urgent-section" style="background: #e3f2fd; border-color: #2196f3;">
            <div class="urgent-header">
                <span class="urgent-icon">⏸️</span>
                <h2 class="urgent-title" style="color: #1565c0;">Topics on Hold (<?php echo count($held_topics); ?>)</h2>
            </div>
            
            <?php foreach ($held_topics as $topic): ?>
            <div class="topic-item" style="border-left-color: #2196f3; background: #f3f9ff;">
                <h3 class="topic-title"><?php echo htmlspecialchars($topic->title); ?></h3>
                <div class="topic-meta">
                    <span><strong>$<?php echo number_format($topic->current_funding, 2); ?></strong> funded</span>
                    <span style="background: #2196f3; color: white; padding: 5px 12px; border-radius: 15px; font-weight: bold; font-size: 12px;">
                        ⏸️ On Hold
                    </span>
                </div>
                <?php if ($topic->hold_reason): ?>
                    <p style="margin: 10px 0; color: #666; font-style: italic;">
                        <strong>Reason:</strong> <?php echo htmlspecialchars($topic->hold_reason); ?>
                    </p>
                <?php endif; ?>
                <div style="margin-top: 15px;">
                    <button onclick="showTopicActions(<?php echo $topic->id; ?>, 'on_hold')" class="btn btn-primary">
                        ▶️ Resume Topic
                    </button>
                    <a href="../topics/view.php?id=<?php echo $topic->id; ?>" class="btn" style="margin-left: 10px;">
                        👁️ View Details
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-item">
                <div class="stat-value">$<?php echo number_format($earnings->total_earned ?? 0, 0); ?></div>
                <div class="stat-label">Total Earnings</div>
            </div>
        </div>

        <div class="dashboard-grid">
            <!-- Topics Being Funded -->
            <div class="dashboard-card">
                <div class="card-header">
                    <h2 class="card-title">📈 Topics Being Funded</h2>
                    <span class="card-icon">💰</span>
                </div>
                
                <?php if (!empty($live_topics)): ?>
                    <?php foreach ($live_topics as $topic): ?>
                    <div class="topic-item">
                        <h3 class="topic-title"><?php echo htmlspecialchars($topic->title); ?></h3>
                        <div class="topic-meta">
                            <span>$<?php echo number_format($topic->current_funding, 2); ?> of $<?php echo number_format($topic->funding_threshold, 2); ?></span>
                            <span><?php echo $topic->contributor_count; ?> contributors</span>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo min(100, $topic->funding_percentage); ?>%"></div>
                        </div>
                        <a href="../topics/view.php?id=<?php echo $topic->id; ?>" class="btn btn-primary">
                            View Topic
                        </a>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <p>No topics currently being funded.</p>
                        <div class="payout-message">
                            <strong>💡 How it works:</strong><br>
                            Fans create topics for you automatically. Once you earn $100, we will send your earnings to your PayPal email.
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Recent Completed Topics -->
            <div class="dashboard-card">
                <div class="card-header">
                    <h2 class="card-title">✅ Recent Completed</h2>
                    <span class="card-icon">🎬</span>
                </div>
                
                <?php if (!empty($completed_topics)): ?>
                    <?php foreach ($completed_topics as $topic): ?>
                    <div class="topic-item" style="border-left-color: #28a745;">
                        <h3 class="topic-title"><?php echo htmlspecialchars($topic->title); ?></h3>
                        <div class="topic-meta">
                            <span>$<?php echo number_format($topic->current_funding * 0.9, 2); ?> earned</span>
                            <span><?php echo date('M j', strtotime($topic->completed_at)); ?></span>
                        </div>
                        <div style="margin-top: 10px;">
                            <a href="../topics/view.php?id=<?php echo $topic->id; ?>" class="btn btn-primary">View</a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <p>No completed topics yet.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Topic Actions Modal -->
    <div id="topicActionsModal" class="modal">
        <div class="modal-content">
            <h3 id="modalTitle">Manage Topic</h3>
            <div id="modalContent"></div>
            <div class="modal-actions">
                <button onclick="closeModal()" class="btn" style="background: #6c757d;">Cancel</button>
                <div id="modalActions" style="display: inline;"></div>
            </div>
        </div>
    </div>

    <script>
    let currentTopicId = null;
    let currentStatus = null;

    function showTopicActions(topicId, status) {
        currentTopicId = topicId;
        currentStatus = status;
        
        const modal = document.getElementById('topicActionsModal');
        const title = document.getElementById('modalTitle');
        const content = document.getElementById('modalContent');
        const actions = document.getElementById('modalActions');
        
        if (status === 'funded') {
            title.textContent = 'Manage Funded Topic';
            content.innerHTML = `
                <p>This topic is fully funded. What would you like to do?</p>
                <div style="margin: 15px 0;">
                    <label for="holdReason">Reason for putting on hold (optional):</label><br>
                    <input type="text" id="holdReason" placeholder="e.g., Working on higher priority content" 
                           style="width: 100%; padding: 8px; margin-top: 5px; border: 1px solid #ddd; border-radius: 4px;">
                </div>
                <div style="background: #fff3cd; padding: 10px; border-radius: 4px; font-size: 14px;">
                    <strong>Options:</strong><br>
                    • <strong>Put on Hold:</strong> Pause the 48-hour deadline<br>
                    • <strong>Decline:</strong> Refund all contributors and cancel topic
                </div>
            `;
            actions.innerHTML = `
                <button onclick="performAction('hold')" class="btn" style="background: #ffc107; color: #000; margin-right: 10px;">⏸️ Put on Hold</button>
                <button onclick="performAction('decline')" class="btn btn-danger">❌ Decline & Refund</button>
            `;
        } else if (status === 'on_hold') {
            title.textContent = 'Resume Topic';
            content.innerHTML = `
                <p>This topic is currently on hold. Resume to get a new 48-hour deadline.</p>
                <div style="background: #e3f2fd; padding: 10px; border-radius: 4px; font-size: 14px;">
                    <strong>Resume:</strong> Topic gets a fresh 48-hour deadline starting now
                </div>
            `;
            actions.innerHTML = `
                <button onclick="performAction('resume')" class="btn btn-success">▶️ Resume Topic</button>
                <button onclick="performAction('decline')" class="btn btn-danger" style="margin-left: 10px;">❌ Decline & Refund</button>
            `;
        }
        
        modal.style.display = 'block';
    }

    function closeModal() {
        document.getElementById('topicActionsModal').style.display = 'none';
        currentTopicId = null;
        currentStatus = null;
    }

    function performAction(action) {
        if (!currentTopicId) return;
        
        let confirmMessage = '';
        if (action === 'decline') {
            confirmMessage = 'Are you sure you want to decline this topic? All contributors will be refunded and the topic will be cancelled. This cannot be undone.';
        } else if (action === 'hold') {
            confirmMessage = 'Put this topic on hold? The 48-hour deadline will be paused.';
        } else if (action === 'resume') {
            confirmMessage = 'Resume this topic? You will get a fresh 48-hour deadline starting now.';
        }
        
        if (!confirm(confirmMessage)) {
            return;
        }
        
        // Create and submit form
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'topic_actions.php';
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = action;
        form.appendChild(actionInput);
        
        const topicInput = document.createElement('input');
        topicInput.type = 'hidden';
        topicInput.name = 'topic_id';
        topicInput.value = currentTopicId;
        form.appendChild(topicInput);
        
        if (action === 'hold') {
            const reasonInput = document.createElement('input');
            reasonInput.type = 'hidden';
            reasonInput.name = 'hold_reason';
            reasonInput.value = document.getElementById('holdReason').value || 'Working on other content first';
            form.appendChild(reasonInput);
        }
        
        document.body.appendChild(form);
        form.submit();
    }

    function updateShareUrl() {
        const selector = document.getElementById('topicSelector');
        const shareUrl = document.getElementById('shareUrl');
        const selectedTopicId = selector.value;
        
        if (selectedTopicId) {
            shareUrl.textContent = `https://topiclaunch.com/topics/view.php?id=${selectedTopicId}`;
        }
    }

    function copyShareLink() {
        const shareUrl = document.getElementById('shareUrl').textContent;
        
        // Don't copy if there's no valid URL
        if (!shareUrl.includes('topics/view.php')) {
            alert('Please create a topic first to generate a shareable link!');
            return;
        }
        
        navigator.clipboard.writeText(shareUrl).then(function() {
            // Show success feedback
            const button = event.target;
            const originalText = button.textContent;
            button.textContent = '✅ Copied!';
            button.style.background = 'rgba(40, 167, 69, 0.3)';
            
            setTimeout(() => {
                button.textContent = originalText;
                button.style.background = 'rgba(255,255,255,0.2)';
            }, 2000);
        }).catch(function() {
            // Fallback for older browsers
            const textArea = document.createElement('textarea');
            textArea.value = shareUrl;
            document.body.appendChild(textArea);
            textArea.select();
            document.execCommand('copy');
            document.body.removeChild(textArea);
            
            alert('Link copied to clipboard!');
        });
    }

    // Close modal when clicking outside
    document.getElementById('topicActionsModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeModal();
        }
    });

    // Initialize the share URL on page load
    document.addEventListener('DOMContentLoaded', function() {
        <?php if (!empty($shareable_topics)): ?>
        updateShareUrl();
        <?php endif; ?>
    });
    </script>
</body>
</html>
