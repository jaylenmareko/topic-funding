<?php
// creators/setup_paypal.php - PayPal email setup for payouts
session_start();
require_once '../config/database.php';
require_once '../config/navigation.php';

// Check if user is logged in and is a creator
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
    header('Location: ../dashboard/index.php');
    exit;
}

// Calculate available earnings
$db->query('
    SELECT 
        COALESCE(SUM(CASE WHEN t.status = "completed" THEN t.current_funding * 0.9 END), 0) as total_earned
    FROM topics t 
    WHERE t.creator_id = :creator_id
');
$db->bind(':creator_id', $creator->id);
$earnings = $db->single();

$minimum_threshold = $creator->manual_payout_threshold ?? 100;
$can_setup_paypal = $earnings->total_earned >= $minimum_threshold;

$errors = [];
$success = '';

// Handle PayPal email setup
if ($_POST && isset($_POST['setup_paypal'])) {
    $paypal_email = trim($_POST['paypal_email']);
    
    // Enhanced PayPal email validation
    if (empty($paypal_email)) {
        $errors[] = "PayPal email is required for payouts";
    } elseif (!filter_var($paypal_email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid PayPal email address";
    } elseif (!preg_match('/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $paypal_email)) {
        $errors[] = "Please enter a properly formatted email address";
    } elseif (strlen($paypal_email) < 6) {
        $errors[] = "PayPal email is too short";
    }
    
    if (empty($errors)) {
        try {
            // Update creator with PayPal email
            $db->query('
                UPDATE creators 
                SET paypal_email = :paypal_email
                WHERE id = :creator_id
            ');
            $db->bind(':paypal_email', $paypal_email);
            $db->bind(':creator_id', $creator->id);
            $db->execute();
            
            $success = "PayPal email saved successfully! You can now request payouts.";
            
            // Refresh creator data
            $db->query('SELECT * FROM creators WHERE id = :creator_id');
            $db->bind(':creator_id', $creator->id);
            $creator = $db->single();
            
        } catch (Exception $e) {
            $errors[] = "Failed to save PayPal email. Please try again.";
            error_log("PayPal setup error: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Setup PayPal for Payouts - TopicLaunch</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; background: #f5f5f5; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: white; padding: 30px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .nav { margin-bottom: 20px; }
        .nav a { color: #007bff; text-decoration: none; margin-right: 15px; }
        .setup-form { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .earnings-info { background: #e8f5e8; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
        .earnings-amount { font-size: 32px; font-weight: bold; color: #28a745; margin-bottom: 10px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: bold; color: #333; }
        input[type="email"] { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 6px; box-sizing: border-box; font-size: 16px; }
        .btn { background: #28a745; color: white; padding: 15px 30px; border: none; border-radius: 6px; cursor: pointer; font-size: 16px; font-weight: bold; }
        .btn:hover { background: #218838; }
        .btn:disabled { background: #6c757d; cursor: not-allowed; }
        .error { color: red; margin-bottom: 15px; padding: 12px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 6px; }
        .success { color: green; margin-bottom: 20px; padding: 15px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 6px; }
        .info-box { background: #e3f2fd; padding: 15px; border-radius: 6px; margin-bottom: 20px; font-size: 14px; }
        .threshold-warning { background: #fff3cd; padding: 15px; border-radius: 6px; margin-bottom: 20px; color: #856404; }
        small { color: #666; font-size: 14px; }
        .completed-setup { background: #d4edda; padding: 20px; border-radius: 8px; text-align: center; }
        
        @media (max-width: 600px) {
            .container { padding: 15px; }
            .setup-form { padding: 20px; }
        }
    </style>
</head>
<body>
    <?php renderNavigation('dashboard'); ?>

    <div class="container">
        <div class="nav">
            <a href="../creators/dashboard.php">← Back to Dashboard</a>
            <a href="../creators/edit.php?id=<?php echo $creator->id; ?>">Edit Profile</a>
        </div>

        <div class="header">
            <h1>💰 Setup PayPal for Payouts</h1>
            <p>Enter your PayPal email to receive payments for completed topics</p>
        </div>

        <?php if (!empty($errors)): ?>
            <?php foreach ($errors as $error): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <div class="earnings-info">
            <div class="earnings-amount">$<?php echo number_format($earnings->total_earned, 2); ?></div>
            <div style="color: #666;">Total Earnings Available</div>
            <div style="margin-top: 10px; font-size: 14px;">
                <strong>Minimum for Payout:</strong> $<?php echo number_format($minimum_threshold, 2); ?>
            </div>
        </div>

        <?php if ($creator->paypal_email): ?>
        <div class="completed-setup">
            <h3>✅ PayPal Setup Complete!</h3>
            <p><strong>PayPal Email:</strong> <?php echo htmlspecialchars($creator->paypal_email); ?></p>
            <p>You can now request payouts from your dashboard.</p>
            <div style="margin-top: 20px;">
                <a href="../creators/request_payout.php" class="btn">💸 Request Payout</a>
                <a href="../creators/dashboard.php" class="btn" style="background: #007bff; margin-left: 15px;">📊 Dashboard</a>
            </div>
        </div>
        
        <div class="setup-form">
            <h3>Update PayPal Email</h3>
            <form method="POST">
                <div class="form-group">
                    <label for="paypal_email">PayPal Email:</label>
                    <input type="email" id="paypal_email" name="paypal_email" 
                           value="<?php echo htmlspecialchars($creator->paypal_email); ?>" 
                           required>
                    <small>This must be a valid PayPal email address</small>
                </div>

                <button type="submit" name="setup_paypal" class="btn">
                    💾 Update PayPal Email
                </button>
            </form>
        </div>
        
        <?php elseif (!$can_setup_paypal): ?>
        <div class="threshold-warning">
            <h3>💡 PayPal Setup Available at $<?php echo number_format($minimum_threshold, 2); ?></h3>
            <p>You need to earn at least $<?php echo number_format($minimum_threshold, 2); ?> before you can setup PayPal for payouts.</p>
            <p><strong>Current earnings:</strong> $<?php echo number_format($earnings->total_earned, 2); ?></p>
            <p><strong>Still needed:</strong> $<?php echo number_format($minimum_threshold - $earnings->total_earned, 2); ?></p>
            
            <div style="margin-top: 15px;">
                <a href="../creators/dashboard.php" class="btn">📊 Back to Dashboard</a>
            </div>
        </div>
        
        <?php else: ?>
        <div class="setup-form">
            <h3>💸 Setup PayPal Payouts</h3>
            
            <div class="info-box">
                <strong>📋 How it works:</strong><br>
                • Enter your PayPal email below<br>
                • Request payouts when you want to withdraw earnings<br>
                • We'll send payments within 3-5 business days<br>
                • Minimum payout: $<?php echo number_format($minimum_threshold, 2); ?>
            </div>

            <form method="POST" id="paypalForm">
                <div class="form-group">
                    <label for="paypal_email">PayPal Email: *</label>
                    <input type="email" id="paypal_email" name="paypal_email" 
                           placeholder="your-paypal@email.com"
                           pattern="[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}"
                           title="Must be a valid email address"
                           required>
                    <small>Must be a valid PayPal email address where you can receive payments</small>
                </div>

                <button type="submit" name="setup_paypal" class="btn" id="submitBtn">
                    💰 Setup PayPal Payouts
                </button>
            </form>
        </div>
        <?php endif; ?>

        <div class="info-box">
            <strong>🛡️ Security & Privacy:</strong><br>
            Your PayPal email is encrypted and only used for sending you payments. We never share your payment information with third parties.
        </div>
    </div>

    <script>
    // Enhanced email validation
    document.getElementById('paypalForm')?.addEventListener('submit', function(e) {
        const email = document.getElementById('paypal_email').value.trim();
        
        // Enhanced email format check
        const emailRegex = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;
        if (!emailRegex.test(email)) {
            e.preventDefault();
            alert('Please enter a valid PayPal email address');
            return;
        }
        
        if (email.length < 6) {
            e.preventDefault();
            alert('PayPal email is too short');
            return;
        }
        
        if (!confirm('Setup PayPal payouts with: ' + email + '?\n\nMake sure this email can receive PayPal payments.')) {
            e.preventDefault();
            return;
        }
        
        document.getElementById('submitBtn').innerHTML = '⏳ Setting up PayPal...';
        document.getElementById('submitBtn').disabled = true;
    });

    // Real-time email validation
    document.getElementById('paypal_email')?.addEventListener('input', function() {
        const value = this.value;
        const emailRegex = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;
        const isValid = emailRegex.test(value);
        this.style.borderColor = value ? (isValid ? '#28a745' : '#dc3545') : '#ddd';
    });
    </script>
</body>
</html>
