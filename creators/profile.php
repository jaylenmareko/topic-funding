<?php
// creators/profile.php - Creators can only access dashboard/edit, not other profiles
session_start();
require_once '../config/database.php';

// If creator is logged in, redirect to dashboard (they shouldn't view any profiles)
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

// Only guests (fans) can view creator profiles
$helper = new DatabaseHelper();
$creator_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$creator_id) {
    header('Location: index.php');
    exit;
}

$creator = $helper->getCreatorById($creator_id);
if (!$creator) {
    header('Location: index.php');
    exit;
}

// Get creator's topics by status
$db = new Database();

// Active topics
$db->query('SELECT * FROM topics WHERE creator_id = :creator_id AND status = "active" ORDER BY created_at DESC');
$db->bind(':creator_id', $creator_id);
$active_topics = $db->resultSet();

// Waiting Upload topics (funded but no content uploaded yet) - with deadline timestamp
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
        
        /* Navigation */
        .nav {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 15px 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .nav-container {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 20px;
        }
        .nav-logo {
            font-size: 24px;
            font-weight: bold;
            color: white;
            text-decoration: none;
            transition: opacity 0.3s;
            cursor: pointer;
        }
        .nav-logo:hover { 
            opacity: 0.9;
            color: white; 
            text-decoration: none;
        }
        .nav-user {
            color: white;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .nav-user a {
            color: white;
            text-decoration: none;
            padding: 8px 16px;
            border-radius: 6px;
            transition: background 0.3s;
        }
        .nav-user a:hover {
            background: rgba(255,255,255,0.2);
        }
        
        /* Clean profile styling */
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .creator-header { background: white; padding: 30px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .creator-info { display: flex; gap: 25px; align-items: start; flex-wrap: wrap; }
        .creator-avatar { width: 120px; height: 120px; background: linear-gradient(135deg, #667eea, #764ba2); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 48px; color: white; font-weight: bold; flex-shrink: 0; }
        .creator-details { flex: 1; min-width: 300px; }
        .creator-details h1 { margin: 0 0 20px 0; color: #333; font-size: 28px; }
        
        /* Create Topic Button */
        .create-topic-btn { 
            background: #28a745; 
            color: white; 
            padding: 15px 30px; 
            text-decoration: none; 
            border-radius: 8px; 
            font-size: 18px; 
            font-weight: bold; 
            display: inline-block;
            transition: all 0.3s;
        }
        .create-topic-btn:hover { 
            background: #218838; 
            color: white; 
            text-decoration: none;
            transform: translateY(-2px);
        }
        
        /* Topics sections */
        .section { background: white; padding: 25px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .section h2 { margin-top: 0; color: #333; }
        .topic-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 20px; }
        .topic-card { border: 1px solid #e9ecef; padding: 20px; border-radius: 8px; transition: transform 0.3s, box-shadow 0.3s; cursor: pointer; }
        .topic-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .topic-title { font-weight: bold; font-size: 18px; margin-bottom: 10px; color: #333; }
        .topic-description { color: #666; line-height: 1.5; margin-bottom: 15px; }
        .funding-bar { background: #e9ecef; height: 8px; border-radius: 4px; margin: 15px 0; }
        .funding-progress { background: linear-gradient(90deg, #28a745, #20c997); height: 100%; border-radius: 4px; transition: width 0.3s; }
        .funding-info { display: flex; justify-content: space-between; align-items: center; margin-top: 15px; }
        .funding-amount { font-weight: bold; color: #28a745; }
        .status-badge { padding: 6px 12px; border-radius: 12px; font-size: 12px; font-weight: bold; }
        .status-active { background: #fff3cd; color: #856404; }
        .status-funded { background: #d4edda; color: #155724; }
        .status-completed { background: #cce5ff; color: #004085; }
        .empty-state { text-align: center; color: #666; padding: 40px; }
        
        /* Countdown timer styles */
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
        .countdown-timer.warning { background: #ffc107; color: #000; }
        .countdown-timer.safe { background: #28a745; }
        .countdown-timer.expired { background: #6c757d; animation: none; font-family: Arial, sans-serif; }
        
        .refund-message {
            background: #f8d7da;
            color: #721c24;
            padding: 10px 15px;
            border-radius: 6px;
            margin-top: 10px;
            font-size: 14px;
            border-left: 4px solid #dc3545;
        }
        
        @media (max-width: 768px) {
            .container { padding: 15px; }
            .creator-info { flex-direction: column; text-align: center; }
            .topic-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <!-- Navigation - Only guests see this page -->
    <nav class="nav">
        <div class="nav-container">
            <a href="../index.php" class="nav-logo">TopicLaunch</a>
        </div>
    </nav>

    <div class="container">
        <!-- Create a Topic Button with Profile Picture aligned -->
        <div style="text-align: center; margin: 80px 0 50px 0; position: relative;">
            <a href="../topics/create.php?creator_id=<?php echo $creator->id; ?>" class="create-topic-btn">Create a Topic</a>
            
            <!-- Creator Profile Picture - Same horizontal level as button, aligned right -->
            <div style="position: absolute; top: 50%; right: 0; transform: translateY(-50%); text-align: center;">
                <div style="width: 120px; height: 120px; background: linear-gradient(135deg, #667eea, #764ba2); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 48px; color: white; font-weight: bold; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
                    <?php if ($creator->profile_image && file_exists('../uploads/creators/' . $creator->profile_image)): ?>
                        <img src="../uploads/creators/<?php echo htmlspecialchars($creator->profile_image); ?>" 
                             alt="<?php echo htmlspecialchars($creator->display_name); ?>" 
                             style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                    <?php else: ?>
                        <?php echo strtoupper(substr($creator->display_name, 0, 1)); ?>
                    <?php endif; ?>
                </div>
                <div style="margin-top: 8px; font-size: 14px; color: #666;">
                    @<?php echo htmlspecialchars($creator->display_name); ?>
                </div>
            </div>
        </div>

        <!-- Active Topics -->
        <div class="section">
            <h2>Active Topics</h2>
            <?php if (empty($active_topics)): ?>
                <div class="empty-state">
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
                            <div>
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
        <div class="section">
            <h2>⏰ Waiting Upload (<?php echo count($waiting_upload_topics); ?>)</h2>
            <div class="topic-grid">
                <?php foreach ($waiting_upload_topics as $topic): ?>
                    <a href="../topics/view.php?id=<?php echo $topic->id; ?>" class="topic-card" style="text-decoration: none; color: inherit; display: block;">
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 15px;">
                            <div class="countdown-timer safe" 
                                 data-deadline="<?php echo $topic->deadline_timestamp; ?>"
                                 id="countdown-<?php echo $topic->id; ?>">
                                Creator has 00:00:00 to create content
                            </div>
                            <div style="font-size: 12px; color: #666;">
                                Funded <?php echo date('M j, g:i A', strtotime($topic->funded_at)); ?>
                            </div>
                        </div>
                        
                        <div class="refund-message" id="refund-message-<?php echo $topic->id; ?>" style="display: none;">
                            💰 <strong>Deadline expired.</strong> 90% refunds are being processed automatically.
                        </div>
                        
                        <h3 class="topic-title"><?php echo htmlspecialchars($topic->title); ?></h3>
                        <p class="topic-description"><?php echo htmlspecialchars(substr($topic->description, 0, 150)) . (strlen($topic->description) > 150 ? '...' : ''); ?></p>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Completed Topics -->
        <?php if (!empty($completed_topics)): ?>
        <div class="section">
            <h2>✅ Completed Topics (<?php echo count($completed_topics); ?>)</h2>
            <div class="topic-grid">
                <?php foreach (array_slice($completed_topics, 0, 6) as $topic): ?>
                    <a href="../topics/view.php?id=<?php echo $topic->id; ?>" class="topic-card" style="text-decoration: none; color: inherit; display: block;">
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 15px;">
                            <span class="status-badge status-completed">Completed</span>
                            <div style="font-size: 12px; color: #666;">
                                <?php echo date('M j, Y', strtotime($topic->completed_at)); ?>
                            </div>
                        </div>
                        
                        <h3 class="topic-title"><?php echo htmlspecialchars($topic->title); ?></h3>
                        <p class="topic-description"><?php echo htmlspecialchars(substr($topic->description, 0, 150)) . (strlen($topic->description) > 150 ? '...' : ''); ?></p>
                        
                        <?php if ($topic->content_url): ?>
                        <div style="margin-top: 15px;">
                            <span style="background: #28a745; color: white; padding: 6px 12px; border-radius: 12px; font-size: 12px; font-weight: bold;">
                                🎬 Content Available
                            </span>
                        </div>
                        <?php endif; ?>
                    </a>
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
            const deadline = parseInt(element.getAttribute('data-deadline')) * 1000;
            const now = new Date().getTime();
            const timeLeft = deadline - now;
            const topicId = element.id.replace('countdown-', '');
            const refundMessage = document.getElementById(`refund-message-${topicId}`);
            
            if (timeLeft > 0) {
                const hours = Math.floor(timeLeft / (1000 * 60 * 60));
                const minutes = Math.floor((timeLeft % (1000 * 60 * 60)) / (1000 * 60));
                const seconds = Math.floor((timeLeft % (1000 * 60)) / 1000);
                
                const formattedTime = 
                    String(hours).padStart(2, '0') + ':' + 
                    String(minutes).padStart(2, '0') + ':' + 
                    String(seconds).padStart(2, '0');
                
                element.textContent = `Creator has ${formattedTime} to create content`;
                
                if (refundMessage) {
                    refundMessage.style.display = 'none';
                }
                
                element.classList.remove('expired', 'warning', 'safe');
                
                if (hours <= 1) {
                    element.classList.add('expired');
                } else if (hours <= 6) {
                    element.classList.add('warning');
                } else {
                    element.classList.add('safe');
                }
            } else {
                element.textContent = 'Deadline expired';
                element.classList.remove('warning', 'safe');
                element.classList.add('expired');
                
                if (refundMessage) {
                    refundMessage.style.display = 'block';
                }
            }
        });
    }
    
    updateCountdowns();
    setInterval(updateCountdowns, 1000);
    </script>
</body>
</html>
