<?php
// creators/profile.php - Creator profile with live countdown timer for waiting upload topics
session_start();
require_once '../config/database.php';
require_once '../config/navigation.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$helper = new DatabaseHelper();
$creator_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$creator_id) {
    header('Location: ../index.php');
    exit;
}

$creator = $helper->getCreatorById($creator_id);
if (!$creator) {
    header('Location: ../index.php');
    exit;
}

// Get creator's topics by status
$db = new Database();

// Check if current user is this creator
$is_this_creator = false;
if (isset($_SESSION['user_id'])) {
    $db->query('SELECT id FROM creators WHERE applicant_user_id = :user_id AND id = :creator_id AND is_active = 1');
    $db->bind(':user_id', $_SESSION['user_id']);
    $db->bind(':creator_id', $creator_id);
    $is_this_creator = $db->single() ? true : false;
}

// Active topics
$db->query('SELECT * FROM topics WHERE creator_id = :creator_id AND status = "active" ORDER BY created_at DESC');
$db->bind(':creator_id', $creator_id);
$active_topics = $db->resultSet();

// Waiting Upload topics (funded but no content uploaded yet) - with deadline timestamp
// Exclude topics that are more than 2 hours past deadline (refunds processed, topic failed)
$db->query('
    SELECT t.*, 
           UNIX_TIMESTAMP(t.content_deadline) as deadline_timestamp,
           TIMESTAMPDIFF(HOUR, t.funded_at, NOW()) as hours_since_funded,
           (48 - TIMESTAMPDIFF(HOUR, t.funded_at, NOW())) as hours_remaining,
           TIMESTAMPDIFF(HOUR, t.content_deadline, NOW()) as hours_past_deadline
    FROM topics t 
    WHERE t.creator_id = :creator_id 
    AND t.status = "funded" 
    AND (t.content_url IS NULL OR t.content_url = "")
    AND TIMESTAMPDIFF(HOUR, t.content_deadline, NOW()) <= 2
    ORDER BY t.funded_at ASC
');
$db->bind(':creator_id', $creator_id);
$waiting_upload_topics = $db->resultSet();

// Completed topics
$db->query('SELECT * FROM topics WHERE creator_id = :creator_id AND status = "completed" ORDER BY completed_at DESC');
$db->bind(':creator_id', $creator_id);
$completed_topics = $db->resultSet();
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo htmlspecialchars($creator->display_name); ?> - TopicLaunch</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .creator-header { background: white; padding: 30px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .creator-info { display: flex; gap: 25px; align-items: start; flex-wrap: wrap; }
        .creator-avatar { width: 120px; height: 120px; background: linear-gradient(135deg, #667eea, #764ba2); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 48px; color: white; font-weight: bold; flex-shrink: 0; }
        .creator-details { flex: 1; min-width: 300px; }
        .creator-details h1 { margin: 0 0 20px 0; color: #333; font-size: 28px; }
        .creator-actions { display: flex; gap: 15px; margin-top: 20px; flex-wrap: wrap; }
        .btn { background: #667eea; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; display: inline-block; font-weight: 500; transition: background 0.3s; }
        .btn:hover { background: #5a6fd8; color: white; text-decoration: none; }
        .btn-success { background: #28a745; }
        .btn-success:hover { background: #218838; }
        .content-tabs { display: flex; gap: 0; margin-bottom: 20px; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .tab { padding: 15px 25px; border: none; background: transparent; cursor: pointer; font-size: 16px; font-weight: 500; color: #666; transition: all 0.3s; }
        .tab.active { background: #667eea; color: white; }
        .tab:hover:not(.active) { background: #f8f9fa; }
        .tab-badge { background: rgba(255,255,255,0.3); color: white; padding: 2px 8px; border-radius: 10px; font-size: 12px; margin-left: 8px; }
        .tab-content { background: white; padding: 25px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .topic-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 20px; }
        .topic-card { border: 1px solid #e9ecef; padding: 20px; border-radius: 8px; transition: transform 0.3s, box-shadow 0.3s; cursor: pointer; }
        .topic-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); text-decoration: none; color: inherit; }
        .topic-title { font-weight: bold; font-size: 18px; margin-bottom: 10px; color: #333; }
        .topic-meta { color: #666; font-size: 14px; margin-bottom: 15px; }
        .topic-description { color: #666; line-height: 1.5; margin-bottom: 15px; }
        .funding-bar { background: #e9ecef; height: 8px; border-radius: 4px; margin: 15px 0; }
        .funding-progress { background: linear-gradient(90deg, #28a745, #20c997); height: 100%; border-radius: 4px; transition: width 0.3s; }
        .funding-info { display: flex; justify-content: space-between; align-items: center; margin-top: 15px; }
        .funding-stats { font-size: 14px; color: #666; }
        .funding-amount { font-weight: bold; color: #28a745; }
        .status-badge { padding: 6px 12px; border-radius: 12px; font-size: 12px; font-weight: bold; }
        .status-active { background: #fff3cd; color: #856404; }
        .status-funded { background: #d4edda; color: #155724; }
        .status-completed { background: #cce5ff; color: #004085; }
        .empty-state { text-align: center; color: #666; padding: 40px; }
        .completion-info { background: #d4edda; padding: 15px; border-radius: 6px; margin-top: 15px; }
        .deadline-warning { background: #fff3cd; padding: 15px; border-radius: 6px; margin-top: 15px; }
        .content-link { background: #28a745; color: white; padding: 8px 16px; text-decoration: none; border-radius: 4px; display: inline-block; margin-top: 10px; }
        .btn-urgent { background: #dc3545; animation: pulse 2s infinite; }
        .btn-urgent:hover { background: #c82333; }
        
        /* Live countdown timer styles */
        .countdown-timer { 
            background: #dc3545; 
            color: white; 
            padding: 8px 16px; 
            border-radius: 15px; 
            font-weight: bold; 
            font-size: 14px;
            font-family: monospace;
            display: inline-block;
        }
        .countdown-timer.warning { 
            background: #ffc107; 
            color: #000; 
        }
        .countdown-timer.safe { 
            background: #28a745; 
        }
        .countdown-timer.expired {
            background: #6c757d;
            animation: none;
            font-family: Arial, sans-serif;
        }
        .refund-message {
            background: #f8d7da;
            color: #721c24;
            padding: 10px 15px;
            border-radius: 6px;
            margin-top: 10px;
            font-size: 14px;
            border-left: 4px solid #dc3545;
        }
        .topic-fading {
            opacity: 0.6;
            transition: opacity 2s ease-out;
        }
        
        /* Pulsing animation for urgent topics */
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.7); }
            70% { box-shadow: 0 0 0 10px rgba(220, 53, 69, 0); }
            100% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0); }
        }
        
        .topic-card.urgent { border-left: 4px solid #dc3545; background: #fff5f5; }
        .topic-card.warning { border-left: 4px solid #ffc107; background: #fffbf0; }
        .topic-card.normal { border-left: 4px solid #28a745; background: #f8fff8; }
        .topic-actions { margin-top: 15px; }
        .section-header { margin: 40px 0 20px 0; }
        .section-title { font-size: 20px; color: #333; margin: 0; }
        
        @media (max-width: 768px) {
            .container { padding: 15px; }
            .creator-info { flex-direction: column; text-align: center; }
            .content-tabs { flex-direction: column; }
            .topic-grid { grid-template-columns: 1fr; }
            .creator-actions { justify-content: center; }
        }
    </style>
</head>
<body>
    <?php renderNavigation('browse_creators'); ?>

    <div class="container">
        <!-- Create a Topic Button -->
        <div style="text-align: center; margin: 80px 0 50px 0;">
            <a href="../topics/create.php?creator_id=<?php echo $creator->id; ?>" class="btn btn-success" style="font-size: 18px; padding: 15px 30px;">Create a Topic</a>
        </div>

        <!-- Active Topics -->
        <div style="background: white; padding: 25px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <?php if (empty($active_topics)): ?>
                <div class="empty-state" style="padding: 80px 20px;">
                    <h3>No active topics</h3>
                    <p>This creator doesn't have any topics seeking funding right now.</p>
                </div>
            <?php else: ?>
                <div class="topic-grid">
                    <?php foreach ($active_topics as $topic): ?>
                    <a href="../topics/view.php?id=<?php echo $topic->id; ?>" class="topic-card" style="text-decoration: none; color: inherit; display: block;">
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 15px;">
                            <span class="status-badge status-active">Active</span>
                            <div style="font-size: 12px; color: #666;">
                                Created <?php echo date('M j, Y', strtotime($topic->created_at)); ?>
                            </div>
                        </div>
                        
                        <h3 class="topic-title"><?php echo htmlspecialchars($topic->title); ?></h3>
                        <p class="topic-description"><?php echo htmlspecialchars(substr($topic->description, 0, 150)) . (strlen($topic->description) > 150 ? '...' : ''); ?></p>
                        
                        <?php 
                        $progress_percent = ($topic->current_funding / $topic->funding_threshold) * 100;
                        $progress_percent = min($progress_percent, 100);
                        ?>
                        
                        <div class="funding-bar">
                            <div class="funding-progress" style="width: <?php echo $progress_percent; ?>%"></div>
                        </div>
                        
                        <div class="funding-info">
                            <div class="funding-stats">
                                <span class="funding-amount">$<?php echo number_format($topic->current_funding, 2); ?></span>
                                of $<?php echo number_format($topic->funding_threshold, 2); ?>
                                (<?php echo round($progress_percent, 1); ?>%)
                            </div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Waiting Upload Topics -->
        <?php if (!empty($waiting_upload_topics)): ?>
        <div class="section-header">
            <h2 class="section-title">⏰ Waiting Upload (<?php echo count($waiting_upload_topics); ?>)</h2>
        </div>
        
        <div style="background: white; padding: 25px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <div class="topic-grid">
                <?php foreach ($waiting_upload_topics as $topic): ?>
                    <?php 
                    $urgency_class = 'normal';
                    $countdown_class = 'safe';
                    ?>
                    <div class="topic-card <?php echo $urgency_class; ?>">
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 15px;">
                            <div class="countdown-timer <?php echo $countdown_class; ?>" 
                                 data-deadline="<?php echo $topic->deadline_timestamp; ?>"
                                 id="countdown-<?php echo $topic->id; ?>">
                                Creator has 00:00:00 to create content
                            </div>
                            <div style="font-size: 12px; color: #666;">
                                Funded <?php echo date('M j, g:i A', strtotime($topic->funded_at)); ?>
                            </div>
                        </div>
                        
                        <div class="refund-message" id="refund-message-<?php echo $topic->id; ?>" style="display: none;">
                            💰 <strong>Deadline expired.</strong> 90% refunds are being processed automatically. Contributors will receive their refunds within 5-10 business days.
                        </div>
                        
                        <h3 class="topic-title"><?php echo htmlspecialchars($topic->title); ?></h3>
                        <p class="topic-description"><?php echo htmlspecialchars(substr($topic->description, 0, 150)) . (strlen($topic->description) > 150 ? '...' : ''); ?></p>
                        
                        <?php if ($is_this_creator): ?>
                        <div style="background: #f8f9fa; padding: 10px; border-radius: 4px; margin: 10px 0;">
                            <strong>💰 Earnings:</strong> $<?php echo number_format($topic->current_funding * 0.9, 2); ?> (after 10% platform fee)
                        </div>
                        
                        <div class="topic-actions">
                            <a href="../creators/upload_content.php?topic=<?php echo $topic->id; ?>" 
                               class="btn btn-urgent">
                                🎬 Upload Content Now
                            </a>
                            <a href="../topics/view.php?id=<?php echo $topic->id; ?>" class="btn" style="margin-left: 10px;">
                                👁️ View Details
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
    // Live countdown timer functionality
    function updateCountdowns() {
        const countdownElements = document.querySelectorAll('.countdown-timer[data-deadline]');
        
        countdownElements.forEach(element => {
            const deadline = parseInt(element.getAttribute('data-deadline')) * 1000; // Convert to milliseconds
            const now = new Date().getTime();
            const timeLeft = deadline - now;
            const topicCard = element.closest('.topic-card');
            const topicId = element.id.replace('countdown-', '');
            const refundMessage = document.getElementById(`refund-message-${topicId}`);
            
            if (timeLeft > 0) {
                // Calculate time remaining
                const hours = Math.floor(timeLeft / (1000 * 60 * 60));
                const minutes = Math.floor((timeLeft % (1000 * 60 * 60)) / (1000 * 60));
                const seconds = Math.floor((timeLeft % (1000 * 60)) / 1000);
                
                // Format time
                const formattedTime = 
                    String(hours).padStart(2, '0') + ':' + 
                    String(minutes).padStart(2, '0') + ':' + 
                    String(seconds).padStart(2, '0');
                
                element.textContent = `Creator has ${formattedTime} to create content`;
                
                // Hide refund message while countdown is active
                if (refundMessage) {
                    refundMessage.style.display = 'none';
                }
                
                // Update styling based on time remaining
                element.classList.remove('expired', 'warning', 'safe');
                
                if (hours <= 1) {
                    element.classList.add('expired');
                    element.style.animation = 'pulse 1s infinite';
                } else if (hours <= 6) {
                    element.classList.add('warning');
                } else {
                    element.classList.add('safe');
                }
                
                // Update topic card styling
                if (topicCard) {
                    topicCard.classList.remove('urgent', 'warning', 'normal', 'topic-fading');
                    if (hours <= 1) {
                        topicCard.classList.add('urgent');
                    } else if (hours <= 6) {
                        topicCard.classList.add('warning');
                    } else {
                        topicCard.classList.add('normal');
                    }
                }
                
            } else {
                // Time expired - calculate hours past deadline
                const hoursPastDeadline = Math.abs(timeLeft) / (1000 * 60 * 60);
                
                if (hoursPastDeadline >= 2) {
                    // Topic should fade out and be removed after 2 hours
                    if (topicCard) {
                        topicCard.style.display = 'none';
                    }
                } else {
                    // Show expired state with refund message
                    element.textContent = 'Deadline expired';
                    element.classList.remove('warning', 'safe');
                    element.classList.add('expired');
                    element.style.animation = 'none';
                    
                    // Show refund message
                    if (refundMessage) {
                        refundMessage.style.display = 'block';
                    }
                    
                    // Update topic card styling - make it fade as it approaches removal
                    if (topicCard) {
                        topicCard.classList.remove('warning', 'normal');
                        topicCard.classList.add('urgent', 'topic-fading');
                    }
                }
            }
        });
        
        // Check if all topics in waiting upload section are hidden
        const waitingSection = document.querySelector('.section-title');
        if (waitingSection && waitingSection.textContent.includes('Waiting Upload')) {
            const allTopicCards = document.querySelectorAll('.topic-card');
            const visibleCards = Array.from(allTopicCards).filter(card => 
                card.style.display !== 'none' && 
                card.closest('.topic-grid')?.previousElementSibling?.textContent?.includes('Waiting Upload')
            );
            
            if (visibleCards.length === 0) {
                // Hide the entire waiting upload section if no topics are visible
                const waitingUploadSection = waitingSection.closest('.section-header')?.nextElementSibling;
                if (waitingUploadSection) {
                    waitingUploadSection.style.display = 'none';
                    waitingSection.closest('.section-header').style.display = 'none';
                }
            }
        }
    }
    
    // Update countdown immediately and then every second
    updateCountdowns();
    setInterval(updateCountdowns, 1000);
    
    // Auto-refresh page every 10 minutes to sync with server state
    setInterval(function() {
        window.location.reload();
    }, 600000); // 10 minutes
    </script>
</body>
</html>
