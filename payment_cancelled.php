<?php
// payment_cancelled.php - Simple cancelled page (root directory)
session_start();

$type = $_GET['type'] ?? '';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Payment Cancelled - TopicLaunch</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; text-align: center; }
        .container { max-width: 600px; margin: 50px auto; background: white; padding: 40px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .cancel-icon { font-size: 64px; color: #6c757d; margin-bottom: 20px; }
        .title { font-size: 24px; font-weight: bold; color: #333; margin-bottom: 15px; }
        .message { color: #666; margin-bottom: 30px; line-height: 1.6; }
        .btn { background: #007bff; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; display: inline-block; margin: 10px; }
        .btn:hover { background: #0056b3; color: white; text-decoration: none; }
        .btn-primary { background: #28a745; }
        .btn-primary:hover { background: #218838; }
    </style>
</head>
<body>
    <div class="container">
        <div class="cancel-icon">❌</div>
        <h1 class="title">Payment Cancelled</h1>
        <div class="message">
            No worries! Your payment was cancelled and no charge was made to your account.
            You can try again anytime to 
            <?php echo $type === 'topic_creation' ? 'create your topic' : 'fund this topic'; ?>.
        </div>

        <div style="margin-top: 30px;">
            <?php if ($type === 'topic_creation'): ?>
                <a href="topics/create.php" class="btn btn-primary">Try Again</a>
                <a href="creators/index.php" class="btn">Browse Creators</a>
            <?php else: ?>
                <a href="topics/index.php" class="btn btn-primary">Browse Topics</a>
                <a href="dashboard/index.php" class="btn">My Dashboard</a>
            <?php endif; ?>
            <a href="index.php" class="btn">Home</a>
        </div>

        <div style="margin-top: 20px; color: #666; font-size: 14px;">
            <p>Need help? <a href="mailto:support@topiclaunch.com" style="color: #007bff;">Contact our support team</a></p>
        </div>
    </div>
</body>
</html>
