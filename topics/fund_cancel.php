<?php
// topics/fund_cancel.php - Handle cancelled payments
session_start();
require_once '../config/database.php';

$topic_id = isset($_GET['topic_id']) ? (int)$_GET['topic_id'] : 0;

if (!$topic_id) {
    header('Location: ../creators/index.php');
    exit;
}

$helper = new DatabaseHelper();
$topic = $helper->getTopicById($topic_id);

if (!$topic) {
    header('Location: ../creators/index.php');
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Payment Cancelled - TopicLaunch</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
        .container { max-width: 600px; margin: 50px auto; }
        .cancel-card { background: white; padding: 40px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); text-align: center; }
        .cancel-icon { font-size: 48px; color: #6c757d; margin-bottom: 20px; }
        .cancel-title { font-size: 24px; font-weight: bold; color: #333; margin-bottom: 15px; }
        .cancel-message { color: #666; margin-bottom: 30px; line-height: 1.6; }
        .topic-info { background: #f8f9fa; padding: 20px; border-radius: 6px; margin: 20px 0; text-align: left; }
        .topic-title { font-weight: bold; color: #333; margin-bottom: 10px; }
        .btn { background: #007bff; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px; display: inline-block; margin: 10px; }
        .btn:hover { background: #0056b3; }
        .btn-primary { background: #28a745; }
        .btn-primary:hover { background: #218838; }
    </style>
</head>
<body>
    <div class="container">
        <div class="cancel-card">
            <div class="cancel-icon">❌</div>
            <h1 class="cancel-title">Payment Cancelled</h1>
            <div class="cancel-message">
                No worries! Your payment was cancelled and no charge was made to your account.
                You can try again anytime to support this topic.
            </div>

            <div class="topic-info">
                <div class="topic-title"><?php echo htmlspecialchars($topic->title); ?></div>
                <div style="color: #666;">By <?php echo htmlspecialchars($topic->creator_name); ?></div>
            </div>

            <div style="margin-top: 30px;">
                <a href="fund.php?id=<?php echo $topic->id; ?>" class="btn btn-primary">Try Payment Again</a>
                <a href="view.php?id=<?php echo $topic->id; ?>" class="btn">View Topic Details</a>
                <a href="../creators/index.php" class="btn">Browse YouTubers</a>
            </div>

            <div style="margin-top: 20px; color: #666; font-size: 14px;">
                <p>Need help? <a href="mailto:support@topiclaunch.com" style="color: #007bff;">Contact our support team</a></p>
            </div>
        </div>
    </div>
</body>
</html>
