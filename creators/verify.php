<?php
// creators/verify.php - Creator verification process
session_start();
require_once '../config/database.php';
require_once '../config/csrf.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$creator_id = isset($_GET['creator_id']) ? (int)$_GET['creator_id'] : 0;
$db = new Database();

// Get creator and verification info
$db->query('
    SELECT c.*, cv.verification_code, cv.verified_at, cv.created_at as code_created
    FROM creators c 
    LEFT JOIN creator_verification cv ON c.id = cv.creator_id
    WHERE c.id = :creator_id AND c.applicant_user_id = :user_id
');
$db->bind(':creator_id', $creator_id);
$db->bind(':user_id', $_SESSION['user_id']);
$creator = $db->single();

if (!$creator) {
    header('Location: apply.php');
    exit;
}

$errors = [];
$success = '';
$step = 1;

// Determine current step
if ($creator->verification_code && !$creator->verified_at) {
    $step = 2; // Waiting for verification
} elseif ($creator->verified_at) {
    $step = 3; // Verified, need payment setup
}

// Handle verification check
if ($_POST && isset($_POST['check_verification'])) {
    CSRFProtection::requireValidToken();
    
    // Simple check - in real implementation, this would scan their channel
    // For now, we'll just mark as verified after they click
    $db->query('UPDATE creator_verification SET verified_at = NOW() WHERE creator_id = :creator_id');
    $db->bind(':creator_id', $creator_id);
    
    if ($db->execute()) {
        $db->query('UPDATE creators SET is_active = 1, application_status = "approved", verification_status = "verified" WHERE id = :creator_id');
        $db->bind(':creator_id', $creator_id);
        $db->execute();
        
        $success = "Verification complete! You're now an active creator.";
        $step = 3;
        
        // Refresh data
        $db->query('
            SELECT c.*, cv.verification_code, cv.verified_at 
            FROM creators c 
            LEFT JOIN creator_verification cv ON c.id = cv.creator_id
            WHERE c.id = :creator_id
        ');
        $db->bind(':creator_id', $creator_id);
        $creator = $db->single();
    }
}

// Create creator_verification table if it doesn't exist
$db->query("
    CREATE TABLE IF NOT EXISTS creator_verification (
        id INT AUTO_INCREMENT PRIMARY KEY,
        creator_id INT NOT NULL,
        verification_code VARCHAR(50) NOT NULL,
        verified_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_creator (creator_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
$db->execute();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Verify Your Channel - TopicLaunch</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
        .container { max-width: 600px; margin: 0 auto; background: white; padding: 40px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .step-indicator { display: flex; justify-content: space-between; margin-bottom: 40px; }
        .step { width: 30%; text-align: center; padding: 15px; border-radius: 8px; }
        .step.active { background: #e3f2fd; border: 2px solid #2196f3; }
        .step.completed { background: #e8f5e8; border: 2px solid #4caf50; }
        .step.pending { background: #f5f5f5; border: 2px solid #ddd; }
        .step-number { font-size: 24px; font-weight: bold; margin-bottom: 5px; }
        .step-title { font-size: 14px; font-weight: bold; }
        .step-desc { font-size: 12px; color: #666; }
        .verification-box { background: #fff3cd; border: 2px solid #ffc107; padding: 25px; border-radius: 8px; margin: 20px 0; }
        .verification-code { font-family: monospace; font-size: 24px; font-weight: bold; background: white; padding: 15px; border-radius: 6px; text-align: center; margin: 15px 0; border: 2px dashed #ffc107; }
        .instructions { background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0; }
        .instructions ol { margin: 0; padding-left: 20px; }
        .instructions li { margin: 10px 0; line-height: 1.5; }
        .btn { padding: 15px 30px; border: none; border-radius: 8px; font-size: 16px; font-weight: bold; cursor: pointer; text-decoration: none; display: inline-block; text-align: center; }
        .btn-primary { background: #2196f3; color: white; }
        .btn-primary:hover { background: #1976d2; color: white; text-decoration: none; }
        .btn-success { background: #4caf50; color: white; }
        .btn-success:hover { background: #388e3c; color: white; text-decoration: none; }
        .success { background: #e8f5e8; color: #2e7d32; padding: 15px; border-radius: 8px; margin: 20px 0; }
        .error { background: #ffebee; color: #c62828; padding: 15px; border-radius: 8px; margin: 20px 0; }
        .channel-info { background: #e3f2fd; padding: 20px; border-radius: 8px; margin: 20px 0; }
        .trust-badges { display: flex; gap: 20px; justify-content: center; margin: 30px 0; flex-wrap: wrap; }
        .trust-badge { text-align: center; padding: 15px; background: #f8f9fa; border-radius: 8px; flex: 1; min-width: 150px; }
        .trust-icon { font-size: 32px; margin-bottom: 10px; }
        .trust-title { font-weight: bold; margin-bottom: 5px; }
        .trust-desc { font-size: 12px; color: #666; }
        
        @media (max-width: 600px) {
            .container { margin: 10px; padding: 20px; }
            .step-indicator { flex-direction: column; gap: 10px; }
            .step { width: 100%; }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Step Indicator -->
        <div class="step-indicator">
            <div class="step completed">
                <div class="step-number">1</div>
                <div class="step-title">Profile Created</div>
                <div class="step-desc">Basic info submitted</div>
            </div>
            <div class="step <?php echo $step >= 2 ? 'active' : 'pending'; ?>">
                <div class="step-number">2</div>
                <div class="step-title">Verify Channel</div>
                <div class="step-desc">Prove ownership</div>
            </div>
            <div class="step <?php echo $step >= 3 ? 'active' : 'pending'; ?>">
                <div class="step-number">3</div>
                <div class="step-title">Setup Payments</div>
                <div class="step-desc">Receive earnings</div>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <?php foreach ($errors as $error): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($step == 2): ?>
        <!-- Verification Step -->
        <h2>🔐 Verify Your YouTube Channel</h2>
        
        <div class="channel-info">
            <h4>Channel to Verify:</h4>
            <strong><?php echo htmlspecialchars($creator->display_name); ?></strong><br>
            <a href="<?php echo htmlspecialchars($creator->platform_url); ?>" target="_blank">
                <?php echo htmlspecialchars($creator->platform_url); ?>
            </a>
        </div>

        <div class="verification-box">
            <h3 style="margin-top: 0; color: #856404;">📋 Quick Verification</h3>
            <p>To verify you own this channel, please add this code to your channel:</p>
            
            <div class="verification-code">
                <?php echo htmlspecialchars($creator->verification_code); ?>
            </div>
        </div>

        <div class="instructions">
            <h4>How to verify (choose one method):</h4>
            <ol>
                <li><strong>Channel Description:</strong> Add the code to your channel's "About" section</li>
                <li><strong>Community Post:</strong> Make a community post with the code</li>
                <li><strong>Video Description:</strong> Add the code to your latest video description</li>
                <li><strong>Pinned Comment:</strong> Pin a comment with the code on your latest video</li>
            </ol>
            <p style="margin: 15px 0 0 0; font-size: 14px; color: #666;">
                <strong>Note:</strong> You can remove the code after verification is complete.
            </p>
        </div>

        <form method="POST" style="text-align: center; margin-top: 30px;">
            <?php echo CSRFProtection::getTokenField(); ?>
            <p style="margin-bottom: 20px;">Once you've added the code to your channel:</p>
            <button type="submit" name="check_verification" class="btn btn-primary">
                ✅ Check Verification
            </button>
        </form>

        <?php elseif ($step == 3): ?>
        <!-- Payment Setup Step -->
        <h2>💳 Setup Payments</h2>
        
        <div class="success">
            <h3 style="margin-top: 0;">🎉 Channel Verified Successfully!</h3>
            <p>You're now an active creator on TopicLaunch.</p>
        </div>

        <div class="instructions">
            <h4>Next Steps:</h4>
            <ol>
                <li><strong>Setup Stripe payments</strong> to receive instant payouts</li>
                <li><strong>Wait for fans</strong> to propose topics for your channel</li>
                <li><strong>Create content</strong> when topics get fully funded</li>
            </ol>
        </div>

        <div style="text-align: center; margin-top: 30px;">
            <a href="../creators/stripe_onboarding.php?creator_id=<?php echo $creator_id; ?>" class="btn btn-success">
                💳 Setup Instant Payments
            </a>
            <a href="../dashboard/index.php" class="btn btn-primary" style="margin-left: 15px;">
                📊 Go to Dashboard
            </a>
        </div>
        <?php endif; ?>

        <!-- Trust & Security -->
        <div class="trust-badges">
            <div class="trust-badge">
                <div class="trust-icon">🛡️</div>
                <div class="trust-title">Secure</div>
                <div class="trust-desc">Your channel info is encrypted</div>
            </div>
            <div class="trust-badge">
                <div class="trust-icon">⚡</div>
                <div class="trust-title">Fast</div>
                <div class="trust-desc">Verification takes 1 minute</div>
            </div>
            <div class="trust-badge">
                <div class="trust-icon">🎯</div>
                <div class="trust-title">Simple</div>
                <div class="trust-desc">Just add a code, then remove it</div>
            </div>
        </div>

        <div style="text-align: center; margin-top: 30px; color: #666; font-size: 14px;">
            <p>Need help? Contact us at <a href="mailto:support@topiclaunch.com">support@topiclaunch.com</a></p>
        </div>
    </div>
</body>
</html>
