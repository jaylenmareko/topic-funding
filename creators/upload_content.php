<?php
session_start();
// creators/upload_content.php - Allow creators to upload content URLs for funded topics
require_once '../config/database.php';
require_once '../config/notification_system.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$topic_id = isset($_GET['topic']) ? (int)$_GET['topic'] : 0;
if (!$topic_id) {
    header('Location: ../index.php');
    exit;
}

$db = new Database();
$errors = [];
$success = '';

// Get topic and verify creator ownership
$db->query('
    SELECT t.*, c.display_name as creator_name, c.applicant_user_id, c.id as creator_id
    FROM topics t 
    JOIN creators c ON t.creator_id = c.id 
    WHERE t.id = :topic_id AND t.status = "funded"
');
$db->bind(':topic_id', $topic_id);
$topic = $db->single();

if (!$topic) {
    header('Location: ../index.php');
    exit;
}

// Verify user owns this topic (either as the creator or as the user who applied to be this creator)
if ($topic->applicant_user_id != $_SESSION['user_id']) {
    header('Location: ../index.php');
    exit;
}

// Check if deadline has passed
$deadline_passed = strtotime($topic->content_deadline) < time();
$time_remaining = strtotime($topic->content_deadline) - time();
$hours_remaining = max(0, floor($time_remaining / 3600));
$minutes_remaining = max(0, floor(($time_remaining % 3600) / 60));

// Handle content upload
if ($_POST && isset($_POST['content_url'])) {
    $content_url = trim($_POST['content_url']);
    
    // Validation
    if (empty($content_url)) {
        $errors[] = "Content URL is required";
    } elseif (!filter_var($content_url, FILTER_VALIDATE_URL)) {
        $errors[] = "Please enter a valid URL";
    }
    
    if ($deadline_passed) {
        $errors[] = "Sorry, the 48-hour deadline has passed. Auto-refunds may have been processed.";
    }
    
    // Update topic with content
    if (empty($errors)) {
        try {
            $db->beginTransaction();
            
            // Update topic
            $db->query('
                UPDATE topics 
                SET content_url = :content_url, 
                    status = "completed", 
                    completed_at = NOW()
                WHERE id = :topic_id
            ');
            $db->bind(':content_url', $content_url);
            $db->bind(':topic_id', $topic_id);
            $db->execute();
            
            // Notify all contributors
            $notificationSystem = new NotificationSystem();
            $notificationSystem->sendContentDeliveredNotifications($topic_id, $content_url);
            
            $db->endTransaction();
            
            // Redirect to dashboard after successful upload
            header('Location: ../creators/dashboard.php?uploaded=1');
            exit;
            
        } catch (Exception $e) {
            $db->cancelTransaction();
            $errors[] = "Failed to upload content. Please try again.";
            error_log("Content upload error: " . $e->getMessage());
        }
    }
}

// Get contributors for display
$db->query('
    SELECT COUNT(*) as contributor_count, SUM(amount) as total_funding
    FROM contributions 
    WHERE topic_id = :topic_id AND payment_status = "completed"
');
$db->bind(':topic_id', $topic_id);
$funding_stats = $db->single();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Upload Content - <?php echo htmlspecialchars($topic->title); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; }
        .header { background: white; padding: 30px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .nav { margin-bottom: 20px; }
        .nav a { color: #007bff; text-decoration: none; margin-right: 15px; }
        .topic-title { font-size: 24px; font-weight: bold; color: #333; margin-bottom: 10px; }
        .deadline-info { padding: 20px; border-radius: 8px; margin-bottom: 20px; }
        .deadline-urgent { background: #fff3cd; border: 2px solid #ffc107; }
        .deadline-expired { background: #f8d7da; border: 2px solid #dc3545; }
        .deadline-normal { background: #d4edda; border: 2px solid #28a745; }
        .upload-form { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: bold; color: #333; }
        input[type="url"] { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 6px; box-sizing: border-box; font-size: 16px; }
        .btn { background: #28a745; color: white; padding: 15px 30px; border: none; border-radius: 6px; cursor: pointer; font-size: 16px; font-weight: bold; }
        .btn:hover { background: #218838; }
        .btn:disabled { background: #6c757d; cursor: not-allowed; }
        .error { color: red; margin-bottom: 15px; padding: 12px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 6px; }
        .success { color: green; margin-bottom: 20px; padding: 15px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 6px; }
        .completed-status { background: #d4edda; padding: 20px; border-radius: 8px; text-align: center; }
        .timer { font-size: 20px; font-weight: bold; }
        .timer.urgent { color: #dc3545; }
        .timer.normal { color: #28a745; }
        
        @media (max-width: 600px) {
            .container { margin: 10px; }
            .header, .upload-form { padding: 20px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="nav">
            <a href="../creators/dashboard.php">← Dashboard</a>
        </div>

        <div class="header">
            <h1 class="topic-title"><?php echo htmlspecialchars($topic->title); ?></h1>
            <p style="color: #666; margin: 0 0 20px 0;">Upload your content to complete this funded topic</p>
            
            <!-- Earnings Breakdown -->
            <div style="background: #e8f5e8; padding: 15px; border-radius: 6px; border-left: 4px solid #28a745;">
                <h4 style="margin: 0 0 10px 0; color: #155724;">💰 Payment Breakdown</h4>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; font-size: 14px;">
                    <div>
                        <strong>Total Funded:</strong> $<?php echo number_format($funding_stats->total_funding, 2); ?>
                    </div>
                    <div>
                        <strong>TopicLaunch Fee (10%):</strong> $<?php echo number_format($funding_stats->total_funding * 0.10, 2); ?>
                    </div>
                    <div style="color: #28a745; font-weight: bold; font-size: 16px;">
                        <strong>Your Earnings (90%):</strong> $<?php echo number_format($funding_stats->total_funding * 0.90, 2); ?>
                    </div>
                    <div style="color: #666; font-size: 12px;">
                        Payment processed after content upload
                    </div>
                </div>
            </div>
        </div>

        <?php if ($topic->status === 'completed'): ?>
            <div class="completed-status">
                <h2>✅ Content Successfully Uploaded!</h2>
                <p>Your content has been delivered to all contributors.</p>
                <p><strong>Content URL:</strong> <a href="<?php echo htmlspecialchars($topic->content_url); ?>" target="_blank"><?php echo htmlspecialchars($topic->content_url); ?></a></p>
                <p><em>Completed on <?php echo date('M j, Y g:i A', strtotime($topic->completed_at)); ?></em></p>
            </div>
        <?php else: ?>
            <!-- Deadline Status -->
            <?php if ($deadline_passed): ?>
                <div class="deadline-info deadline-expired">
                    <h3>⚠️ Deadline Expired</h3>
                    <p>The 48-hour deadline has passed. Auto-refunds may have been processed. Please contact support if you believe this is an error.</p>
                    <p><strong>Deadline was:</strong> <?php echo date('M j, Y g:i A', strtotime($topic->content_deadline)); ?></p>
                </div>
            <?php elseif ($hours_remaining <= 6): ?>
                <div class="deadline-info deadline-urgent">
                    <h3>🚨 Urgent: Deadline Approaching!</h3>
                    <p class="timer urgent"><?php echo $hours_remaining; ?> hours, <?php echo $minutes_remaining; ?> minutes remaining</p>
                    <p><strong>Deadline:</strong> <?php echo date('M j, Y g:i A', strtotime($topic->content_deadline)); ?></p>
                    <p><strong>⚠️ Important:</strong> If content isn't uploaded by the deadline, all contributors will be automatically refunded.</p>
                </div>
            <?php else: ?>
                <div class="deadline-info deadline-normal">
                    <h3>📅 Content Deadline</h3>
                    <p class="timer normal"><?php echo $hours_remaining; ?> hours, <?php echo $minutes_remaining; ?> minutes remaining</p>
                    <p><strong>Deadline:</strong> <?php echo date('M j, Y g:i A', strtotime($topic->content_deadline)); ?></p>
                </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <?php foreach ($errors as $error): ?>
                    <div class="error"><?php echo htmlspecialchars($error); ?></div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <div class="upload-form">
                <h3>Upload Your Content</h3>
                
                <form method="POST" id="uploadForm">
                    <div class="form-group">
                        <label for="content_url">Content URL: *</label>
                        <input type="url" id="content_url" name="content_url" required 
                               placeholder="https://youtube.com/watch?v=... or https://twitch.tv/videos/..."
                               value="<?php echo isset($_POST['content_url']) ? htmlspecialchars($_POST['content_url']) : ''; ?>">
                        <small style="color: #666;">Direct link to your uploaded video or live stream</small>
                    </div>

                    <button type="submit" class="btn" id="submitBtn" <?php echo $deadline_passed ? 'disabled' : ''; ?>>
                        <?php echo $deadline_passed ? 'Deadline Expired' : 'Upload Content & Complete Topic'; ?>
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <script>
    // Real-time countdown timer
    let deadline = new Date('<?php echo date('c', strtotime($topic->content_deadline)); ?>').getTime();
    
    function updateCountdown() {
        let now = new Date().getTime();
        let timeLeft = deadline - now;
        
        if (timeLeft > 0) {
            let hours = Math.floor(timeLeft / (1000 * 60 * 60));
            let minutes = Math.floor((timeLeft % (1000 * 60 * 60)) / (1000 * 60));
            
            document.querySelectorAll('.timer').forEach(timer => {
                timer.textContent = hours + ' hours, ' + minutes + ' minutes remaining';
                
                if (hours <= 1) {
                    timer.className = 'timer urgent';
                }
            });
        } else {
            document.querySelectorAll('.timer').forEach(timer => {
                timer.textContent = 'DEADLINE EXPIRED';
                timer.className = 'timer urgent';
            });
            
            // Disable form if deadline passed
            document.getElementById('submitBtn').disabled = true;
            document.getElementById('submitBtn').textContent = 'Deadline Expired';
        }
    }
    
    // Update countdown every minute
    setInterval(updateCountdown, 60000);
    updateCountdown(); // Initial call

    // Form validation
    document.getElementById('uploadForm').addEventListener('submit', function(e) {
        const url = document.getElementById('content_url').value.trim();
        
        if (!url) {
            e.preventDefault();
            alert('Please enter your content URL');
            return;
        }
        
        if (!url.startsWith('http://') && !url.startsWith('https://')) {
            e.preventDefault();
            alert('Please enter a valid URL starting with http:// or https://');
            return;
        }
        
        if (!confirm('Are you sure you want to upload this content? This will notify all contributors and mark the topic as completed.')) {
            e.preventDefault();
            return;
        }
        
        // Show loading state
        document.getElementById('submitBtn').innerHTML = 'Uploading...';
        document.getElementById('submitBtn').disabled = true;
    });
    </script>
</body>
</html>
