<?php
// topics/creation_success.php - Handle successful topic creation payments
session_start();
require_once '../config/database.php';
require_once '../config/stripe.php';
require_once '../config/notification_system.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

// Check if this is a topic creation success
if (!isset($_GET['topic_creation']) || !isset($_GET['session_id'])) {
    header('Location: index.php');
    exit;
}

$session_id = $_GET['session_id'];
$creator_id = isset($_GET['creator_id']) ? (int)$_GET['creator_id'] : 0;
$amount = isset($_GET['amount']) ? (float)$_GET['amount'] : 0;

// Get pending topic data from session
if (!isset($_SESSION['pending_topic'])) {
    header('Location: index.php');
    exit;
}

$topic_data = $_SESSION['pending_topic'];
$errors = [];
$success = false;

try {
    // Retrieve Stripe session to verify payment
    $stripe_session = \Stripe\Checkout\Session::retrieve($session_id);
    $payment_intent_id = $stripe_session->payment_intent;
    
    if ($stripe_session->payment_status === 'paid') {
        $db = new Database();
        $db->beginTransaction();
        
        // Create the topic
        $db->query('
            INSERT INTO topics (creator_id, initiator_user_id, title, description, funding_threshold, status, approval_status, current_funding) 
            VALUES (:creator_id, :user_id, :title, :description, :funding_threshold, "pending_approval", "pending", :initial_funding)
        ');
        $db->bind(':creator_id', $topic_data['creator_id']);
        $db->bind(':user_id', $_SESSION['user_id']);
        $db->bind(':title', $topic_data['title']);
        $db->bind(':description', $topic_data['description']);
        $db->bind(':funding_threshold', $topic_data['funding_threshold']);
        $db->bind(':initial_funding', $topic_data['initial_contribution']);
        $db->execute();
        
        $topic_id = $db->lastInsertId();
        
        // Create the initial contribution record
        $db->query('
            INSERT INTO contributions (topic_id, user_id, amount, payment_status, payment_id, contributed_at) 
            VALUES (:topic_id, :user_id, :amount, "completed", :payment_id, NOW())
        ');
        $db->bind(':topic_id', $topic_id);
        $db->bind(':user_id', $_SESSION['user_id']);
        $db->bind(':amount', $topic_data['initial_contribution']);
        $db->bind(':payment_id', $payment_intent_id);
        $db->execute();
        
        // Send notification to creator
        $notificationSystem = new NotificationSystem();
        $notificationSystem->sendTopicProposalNotification($topic_id);
        
        $db->endTransaction();
        $success = true;
        
        // Clear pending topic from session
        unset($_SESSION['pending_topic']);
        
        // Get creator info for display
        $db->query('SELECT display_name FROM creators WHERE id = :creator_id');
        $db->bind(':creator_id', $topic_data['creator_id']);
        $creator = $db->single();
        
    } else {
        $errors[] = "Payment was not completed successfully.";
    }
    
} catch (Exception $e) {
    if (isset($db)) {
        $db->cancelTransaction();
    }
    $errors[] = "Failed to create topic. Please contact support.";
    error_log("Topic creation error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo $success ? 'Topic Created Successfully!' : 'Topic Creation Failed'; ?> - TopicLaunch</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
        .container { max-width: 600px; margin: 50px auto; }
        .result-card { background: white; padding: 40px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); text-align: center; }
        .success-icon { font-size: 64px; color: #28a745; margin-bottom: 20px; }
        .error-icon { font-size: 64px; color: #dc3545; margin-bottom: 20px; }
        .result-title { font-size: 28px; font-weight: bold; color: #333; margin-bottom: 15px; }
        .result-message { color: #666; margin-bottom: 30px; line-height: 1.6; font-size: 16px; }
        .topic-info { background: #f8f9fa; padding: 20px; border-radius: 6px; margin: 20px 0; text-align: left; }
        .topic-title { font-weight: bold; color: #333; margin-bottom: 10px; font-size: 18px; }
        .topic-details { color: #666; font-size: 14px; }
        .btn { background: #28a745; color: white; padding: 15px 25px; text-decoration: none; border-radius: 6px; display: inline-block; margin: 10px; font-weight: bold; transition: background 0.3s; }
        .btn:hover { background: #218838; color: white; text-decoration: none; }
        .btn-primary { background: #667eea; }
        .btn-primary:hover { background: #5a6fd8; }
        .error { color: red; background: #f8d7da; padding: 20px; border-radius: 6px; margin-bottom: 20px; }
        .next-steps { background: #e3f2fd; padding: 20px; border-radius: 6px; margin: 20px 0; }
        .next-steps h4 { margin-top: 0; color: #1976d2; }
        .funding-breakdown { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 15px; margin: 15px 0; }
        .breakdown-item { text-align: center; padding: 15px; background: white; border-radius: 6px; }
        .breakdown-number { font-size: 18px; font-weight: bold; color: #28a745; }
        .breakdown-label { font-size: 12px; color: #666; }
        
        @media (max-width: 600px) {
            .container { margin: 20px; }
            .result-card { padding: 30px 20px; }
            .funding-breakdown { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="result-card">
            <?php if ($success): ?>
                <div class="success-icon">🎉</div>
                <h1 class="result-title">Topic Created Successfully!</h1>
                <div class="result-message">
                    Your topic proposal has been created and your initial payment of $<?php echo number_format($topic_data['initial_contribution'], 2); ?> has been processed.
                    The creator has been notified and will review your proposal.
                </div>

                <div class="topic-info">
                    <div class="topic-title"><?php echo htmlspecialchars($topic_data['title']); ?></div>
                    <div class="topic-details">
                        <strong>Creator:</strong> <?php echo htmlspecialchars($creator->display_name ?? 'YouTube Creator'); ?><br>
                        <strong>Funding Goal:</strong> $<?php echo number_format($topic_data['funding_threshold'], 2); ?><br>
                        <strong>Your Contribution:</strong> $<?php echo number_format($topic_data['initial_contribution'], 2); ?>
                    </div>
                    
                    <div class="funding-breakdown">
                        <div class="breakdown-item">
                            <div class="breakdown-number">$<?php echo number_format($topic_data['funding_threshold'], 0); ?></div>
                            <div class="breakdown-label">Total Goal</div>
                        </div>
                        <div class="breakdown-item">
                            <div class="breakdown-number">$<?php echo number_format($topic_data['initial_contribution'], 2); ?></div>
                            <div class="breakdown-label">Your Payment</div>
                        </div>
                        <div class="breakdown-item">
                            <div class="breakdown-number">$<?php echo number_format($topic_data['funding_threshold'] - $topic_data['initial_contribution'], 2); ?></div>
                            <div class="breakdown-label">Still Needed</div>
                        </div>
                        <div class="breakdown-item">
                            <div class="breakdown-number"><?php echo round(($topic_data['initial_contribution'] / $topic_data['funding_threshold']) * 100); ?>%</div>
                            <div class="breakdown-label">Funded</div>
                        </div>
                    </div>
                </div>

                <div class="next-steps">
                    <h4>📋 What happens next:</h4>
                    <ol style="text-align: left; margin: 10px 0;">
                        <li><strong>Creator Review:</strong> The YouTube creator will review your proposal</li>
                        <li><strong>Approval Decision:</strong> They'll approve or decline within 48 hours</li>
                        <li><strong>If Approved:</strong> Topic goes live for community funding</li>
                        <li><strong>If Declined:</strong> You'll receive a full refund automatically</li>
                        <li><strong>Once Funded:</strong> Creator has 48 hours to create the content</li>
                    </ol>
                </div>

                <div style="margin-top: 30px;">
                    <a href="../dashboard/index.php" class="btn">📊 View My Dashboard</a>
                    <a href="create.php" class="btn btn-primary">💡 Create Another Topic</a>
                    <a href="../creators/index.php" class="btn btn-primary">🔍 Browse More Creators</a>
                </div>

                <div style="margin-top: 25px; color: #666; font-size: 14px;">
                    <p><strong>📧 Email confirmation sent!</strong> Check your email for payment receipt and updates.</p>
                    <p><strong>🔔 Stay Updated:</strong> You'll be notified when the creator makes their decision.</p>
                </div>

            <?php else: ?>
                <div class="error-icon">❌</div>
                <h1 class="result-title">Topic Creation Failed</h1>
                <div class="result-message">
                    There was an issue creating your topic. Please try again or contact support if the problem persists.
                </div>

                <?php if (!empty($errors)): ?>
                    <?php foreach ($errors as $error): ?>
                        <div class="error"><?php echo htmlspecialchars($error); ?></div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <div style="margin-top: 30px;">
                    <a href="create.php" class="btn">🔄 Try Again</a>
                    <a href="../index.php" class="btn btn-primary">🏠 Back to Home</a>
                </div>

                <div style="margin-top: 25px; color: #666; font-size: 14px;">
                    <p>Need help? <a href="mailto:support@topiclaunch.com" style="color: #667eea;">Contact our support team</a></p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    // Auto-redirect to dashboard after successful creation
    <?php if ($success): ?>
    setTimeout(function() {
        if (confirm('🎉 Topic created successfully! Would you like to view your dashboard to track its progress?')) {
            window.location.href = '../dashboard/index.php';
        }
    }, 5000);
    <?php endif; ?>
    
    // Celebration effect for successful creation
    <?php if ($success): ?>
    document.addEventListener('DOMContentLoaded', function() {
        // Add some celebration effects
        setTimeout(function() {
            if (confirm('💡 Want to create another topic while you wait for approval?')) {
                window.location.href = 'create.php';
            }
        }, 8000);
    });
    <?php endif; ?>
    </script>
</body>
</html>
