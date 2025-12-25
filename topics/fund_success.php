<?php
// topics/fund_success.php - Handle successful payments with notification integration
session_start();
require_once '../config/database.php';
require_once '../config/stripe.php';
require_once '../config/notification_system.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$topic_id = isset($_GET['topic_id']) ? (int)$_GET['topic_id'] : 0;
$amount = isset($_GET['amount']) ? (float)$_GET['amount'] : 0;
$session_id = isset($_GET['session_id']) ? $_GET['session_id'] : '';

if (!$topic_id || !$amount) {
    header('Location: ../creators/index.php');
    exit;
}

$helper = new DatabaseHelper();
$topic = $helper->getTopicById($topic_id);

if (!$topic) {
    header('Location: ../creators/index.php');
    exit;
}

// Add the contribution to database with payment ID from Stripe
$contribution_result = $helper->addContributionWithTracking($topic_id, $_SESSION['user_id'], $amount);

if ($contribution_result['success']) {
    // Store Stripe session ID as payment_id for refund capability
    if ($session_id) {
        try {
            // Retrieve the Stripe session to get payment intent ID
            $session = \Stripe\Checkout\Session::retrieve($session_id);
            $payment_intent_id = $session->payment_intent;
            
            // Update contribution with payment_intent_id for refunds
            $db = new Database();
            $db->query('UPDATE contributions SET payment_id = :payment_id WHERE id = :id');
            $db->bind(':payment_id', $payment_intent_id);
            $db->bind(':id', $contribution_result['contribution_id']);
            $db->execute();
            
        } catch (Exception $e) {
            error_log("Failed to store payment ID: " . $e->getMessage());
        }
    }
    
    // Refresh topic data
    $topic = $helper->getTopicById($topic_id);
    $success_message = "Thank you for your contribution of $" . number_format($amount, 2) . "!";
    
    // Check if topic just became fully funded
    if ($contribution_result['fully_funded']) {
        $success_message .= " 🎉 This topic is now fully funded!";
        
        // Trigger notification system
        $notificationSystem = new NotificationSystem();
        $notification_result = $notificationSystem->handleTopicFunded($topic_id);
        
        if ($notification_result['success']) {
            $deadline = date('M j, Y g:i A', strtotime($notification_result['deadline']));
            $success_message .= " The creator has been notified and has until {$deadline} to create the content.";
        }
    }
} else {
    $error_message = "Payment was successful, but there was an issue recording your contribution. Please contact support.";
}

// Get funding analytics for enhanced display
$analytics = $helper->getTopicFundingAnalytics($topic_id);
$contributions = $helper->getTopicContributions($topic_id);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Payment Successful - TopicLaunch</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
        .container { max-width: 700px; margin: 50px auto; }
        .success-card { background: white; padding: 40px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); text-align: center; }
        .success-icon { font-size: 64px; color: #28a745; margin-bottom: 20px; }
        .success-title { font-size: 28px; font-weight: bold; color: #333; margin-bottom: 15px; }
        .success-message { color: #666; margin-bottom: 30px; line-height: 1.6; font-size: 16px; }
        .topic-info { background: #f8f9fa; padding: 25px; border-radius: 6px; margin: 25px 0; text-align: left; }
        .topic-title { font-weight: bold; color: #333; margin-bottom: 15px; font-size: 20px; }
        .funding-progress { background: #e9ecef; height: 12px; border-radius: 6px; margin: 20px 0; }
        .funding-bar { background: linear-gradient(90deg, #28a745, #20c997); height: 100%; border-radius: 6px; transition: width 0.5s ease; }
        .funding-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 15px; margin: 20px 0; }
        .stat { text-align: center; padding: 15px; background: white; border-radius: 6px; }
        .stat-number { font-size: 20px; font-weight: bold; color: #007bff; }
        .stat-label { font-size: 12px; color: #666; }
        .btn { background: #007bff; color: white; padding: 15px 25px; text-decoration: none; border-radius: 6px; display: inline-block; margin: 10px; font-weight: bold; }
        .btn:hover { background: #0056b3; }
        .btn-success { background: #28a745; }
        .btn-success:hover { background: #218838; }
        .error { color: red; background: #f8d7da; padding: 20px; border-radius: 6px; margin-bottom: 20px; }
        .milestone-celebration { background: linear-gradient(135deg, #FF0000 0%, #CC0000 100%); color: white; padding: 25px; border-radius: 8px; margin: 25px 0; }
        .milestone-title { font-size: 22px; font-weight: bold; margin-bottom: 15px; }
        .countdown-box { background: rgba(255,255,255,0.1); padding: 15px; border-radius: 6px; margin-top: 15px; }
        .next-steps { background: #e3f2fd; padding: 20px; border-radius: 6px; margin: 20px 0; }
        .next-steps h4 { margin-top: 0; color: #1976d2; }
        .contributor-impact { margin-top: 25px; }
        .impact-stat { display: inline-block; background: #e8f5e8; color: #2d5f2d; padding: 8px 16px; border-radius: 12px; margin: 5px; font-size: 14px; font-weight: bold; }
        
        @media (max-width: 600px) {
            .container { margin: 20px; }
            .success-card { padding: 30px 20px; }
            .funding-stats { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="success-card">
            <?php if (isset($error_message)): ?>
                <div class="error"><?php echo htmlspecialchars($error_message); ?></div>
            <?php else: ?>
                <div class="success-icon">✅</div>
                <h1 class="success-title">Payment Successful!</h1>
                <div class="success-message">
                    <?php echo htmlspecialchars($success_message); ?>
                </div>

                <?php if ($contribution_result['fully_funded']): ?>
                <div class="milestone-celebration">
                    <div class="milestone-title">🎉 GOAL REACHED!</div>
                    <p>This topic is now fully funded! The creator has been automatically notified and has 48 hours to create your requested content.</p>
                    
                    <div class="countdown-box">
                        <strong>⏱️ Content Deadline:</strong><br>
                        <?php echo date('l, M j, Y \a\t g:i A', strtotime($topic->content_deadline)); ?><br>
                        <small>You'll be automatically refunded if content isn't delivered on time</small>
                    </div>
                </div>
                <?php endif; ?>

                <div class="topic-info">
                    <div class="topic-title"><?php echo htmlspecialchars($topic->title); ?></div>
                    <div style="color: #666; margin-bottom: 15px;">By <?php echo htmlspecialchars($topic->creator_name); ?></div>
                    
                    <?php 
                    $progress_percent = ($topic->current_funding / $topic->funding_threshold) * 100;
                    $progress_percent = min($progress_percent, 100);
                    ?>
                    
                    <div class="funding-progress">
                        <div class="funding-bar" style="width: <?php echo $progress_percent; ?>%"></div>
                    </div>
                    
                    <div class="funding-stats">
                        <div class="stat">
                            <div class="stat-number">$<?php echo number_format($topic->current_funding, 0); ?></div>
                            <div class="stat-label">Total Raised</div>
                        </div>
                        <div class="stat">
                            <div class="stat-number"><?php echo round($progress_percent); ?>%</div>
                            <div class="stat-label">Complete</div>
                        </div>
                        <div class="stat">
                            <div class="stat-number"><?php echo count($contributions); ?></div>
                            <div class="stat-label">Contributors</div>
                        </div>
                        <div class="stat">
                            <div class="stat-number">$<?php echo number_format($topic->funding_threshold, 0); ?></div>
                            <div class="stat-label">Goal</div>
                        </div>
                    </div>
                </div>

                <?php if (!empty($contribution_result['milestones'])): ?>
                <div class="contributor-impact">
                    <h4>🎯 Your Impact:</h4>
                    <?php foreach ($contribution_result['milestones'] as $milestone): ?>
                        <span class="impact-stat">Helped reach <?php echo $milestone; ?>% funding!</span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div class="next-steps">
                    <h4>📋 What happens next:</h4>
                    <ul style="text-align: left; margin: 10px 0;">
                        <?php if ($topic->status === 'funded'): ?>
                            <li><strong>Creator notified:</strong> They have 48 hours to create content</li>
                            <li><strong>Content delivery:</strong> You'll be notified when it's ready</li>
                            <li><strong>Automatic protection:</strong> Full refund if content isn't delivered on time</li>
                        <?php else: ?>
                            <li><strong>Track progress:</strong> Watch as more people contribute</li>
                            <li><strong>Share topic:</strong> Help it reach the funding goal faster</li>
                            <li><strong>Get notified:</strong> We'll email you when it's fully funded</li>
                        <?php endif; ?>
                    </ul>
                </div>

                <div style="margin-top: 30px;">
                    <a href="view.php?id=<?php echo $topic->id; ?>" class="btn btn-success">View Topic Details</a>
                    <a href="../dashboard/index.php" class="btn">My Dashboard</a>
                    <a href="../creators/index.php" class="btn">Browse YouTubers</a>
                </div>

                <div style="margin-top: 25px; color: #666; font-size: 14px;">
                    <p><strong>📧 Email confirmations sent!</strong> Check your email for payment confirmation and topic updates.</p>
                    <p>Questions? Contact our support team anytime.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    // Celebration animation for funded topics
    <?php if (isset($contribution_result['fully_funded']) && $contribution_result['fully_funded']): ?>
    document.addEventListener('DOMContentLoaded', function() {
        // Add some celebration effects
        setTimeout(function() {
            if (window.confirm('🎉 This topic is now fully funded! Would you like to share the good news?')) {
                const shareText = 'Just helped fund "<?php echo addslashes($topic->title); ?>" on TopicLaunch! 🎉';
                const shareUrl = 'https://topiclaunch.com/topics/view.php?id=<?php echo $topic->id; ?>';
                
                if (navigator.share) {
                    navigator.share({
                        title: 'Topic Funded!',
                        text: shareText,
                        url: shareUrl
                    });
                } else {
                    const twitterUrl = `https://twitter.com/intent/tweet?text=${encodeURIComponent(shareText)}&url=${encodeURIComponent(shareUrl)}`;
                    window.open(twitterUrl, '_blank');
                }
            }
        }, 2000);
    });
    <?php endif; ?>
    
    // Auto-redirect after a delay for non-funded topics
    <?php if (!isset($contribution_result['fully_funded']) || !$contribution_result['fully_funded']): ?>
    setTimeout(function() {
        const redirectChoice = confirm('Would you like to browse more YouTubers to support?');
        if (redirectChoice) {
            window.location.href = '../creators/index.php';
        }
    }, 8000);
    <?php endif; ?>
    </script>
</body>
</html>
