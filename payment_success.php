<?php
// payment_success.php - Simple success page (root directory)
session_start();

$session_id = $_GET['session_id'] ?? '';
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
    <title>Payment Processing - TopicLaunch</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; text-align: center; }
        .container { max-width: 600px; margin: 50px auto; background: white; padding: 40px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .processing-icon { font-size: 64px; margin-bottom: 20px; }
        .title { font-size: 24px; font-weight: bold; color: #333; margin-bottom: 15px; }
        .message { color: #666; margin-bottom: 30px; line-height: 1.6; }
        .status-check { background: #e3f2fd; padding: 20px; border-radius: 6px; margin: 20px 0; }
        .btn { background: #007bff; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; display: inline-block; margin: 10px; }
        .btn:hover { background: #0056b3; color: white; text-decoration: none; }
        .loading { font-size: 18px; color: #007bff; }
    </style>
</head>
<body>
    <div class="container">
        <div class="processing-icon">⏳</div>
        <h1 class="title">Payment Processing...</h1>
        <div class="message">
            Your payment was successful! We're now processing your 
            <?php echo $type === 'topic_creation' ? 'topic creation' : 'contribution'; ?> 
            in the background.
        </div>
        
        <div class="status-check">
            <div class="loading" id="statusMessage">
                Processing payment via secure webhook system...
            </div>
        </div>
        
        <div style="margin-top: 30px;">
            <a href="dashboard/index.php" class="btn">Go to Dashboard</a>
            <a href="creators/index.php" class="btn">Browse YouTubers</a>
        </div>
        
        <div style="margin-top: 20px; color: #666; font-size: 14px;">
            <p>This may take a few moments. You'll be automatically redirected once processing is complete.</p>
        </div>
    </div>

    <script>
    let checkCount = 0;
    const maxChecks = 20; // Check for 2 minutes max
    
    function checkPaymentStatus() {
        checkCount++;
        
        // Update message based on check count
        const statusEl = document.getElementById('statusMessage');
        if (checkCount <= 5) {
            statusEl.textContent = 'Processing payment via secure webhook system...';
        } else if (checkCount <= 10) {
            statusEl.textContent = 'Creating your topic...';
        } else if (checkCount <= 15) {
            statusEl.textContent = 'Notifying creator...';
        } else {
            statusEl.textContent = 'Finalizing...';
        }
        
        // Try to check if payment was processed by looking for new topics/contributions
        <?php if ($type === 'topic_creation'): ?>
        // For topic creation, redirect to dashboard after delay
        if (checkCount >= 10) {
            window.location.href = 'dashboard/index.php?created=1';
            return;
        }
        <?php else: ?>
        // For contributions, redirect to creators page
        if (checkCount >= 8) {
            window.location.href = 'creators/index.php?contributed=1';
            return;
        }
        <?php endif; ?>
        
        if (checkCount < maxChecks) {
            setTimeout(checkPaymentStatus, 6000); // Check every 6 seconds
        } else {
            // Max checks reached, redirect anyway
            statusEl.textContent = 'Processing complete! Redirecting...';
            setTimeout(() => {
                window.location.href = 'dashboard/index.php';
            }, 2000);
        }
    }
    
    // Start checking after 3 seconds
    setTimeout(checkPaymentStatus, 3000);
    </script>
</body>
</html>
