<?php
// creators/request_payout.php - Simple manual payout request system
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/navigation.php';

// Check if user is logged in and is a creator
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$db = new Database();

// Get creator info
$db->query("
    SELECT c.*, u.email 
    FROM creators c 
    LEFT JOIN users u ON c.applicant_user_id = u.id 
    WHERE c.applicant_user_id = :user_id AND c.is_active = 1
");
$db->bind(':user_id', $_SESSION['user_id']);
$creator = $db->single();

if (!$creator) {
    header('Location: dashboard.php');
    exit;
}

// Calculate available earnings
$db->query("
    SELECT 
        COALESCE(SUM(CASE WHEN t.status = 'completed' THEN t.current_funding * 0.9 END), 0) as total_earned,
        COALESCE(SUM(CASE WHEN pr.status = 'completed' THEN pr.amount END), 0) as total_paid_out
    FROM topics t 
    LEFT JOIN payout_requests pr ON pr.creator_id = :creator_id
    WHERE t.creator_id = :creator_id
");
$db->bind(':creator_id', $creator->id);
$earnings = $db->single();

$available_balance = $earnings->total_earned - $earnings->total_paid_out;
$minimum_threshold = $creator->manual_payout_threshold ?? 50;

$errors = [];
$success = '';

// Handle payout request
if ($_POST && isset($_POST['request_payout'])) {
    $requested_amount = (float)$_POST['amount'];

    $has_paypal = !empty(trim($creator->paypal_email ?? ''));
    $has_venmo  = !empty(trim($creator->venmo_handle ?? ''));

    // Validation
    if (!$has_paypal && !$has_venmo) {
        $errors[] = "Please add either a PayPal email or Venmo handle to your profile before requesting a payout.";
    } elseif ($requested_amount < $minimum_threshold) {
        $errors[] = "Minimum payout amount is $" . number_format($minimum_threshold, 2);
    } elseif ($requested_amount > $available_balance) {
        $errors[] = "Requested amount exceeds available balance";
    } elseif ($requested_amount <= 0) {
        $errors[] = "Please enter a valid amount";
    }
    
    // Check for pending requests
    $db->query("SELECT COUNT(*) as count FROM payout_requests WHERE creator_id = :creator_id AND status = 'pending'");
    $db->bind(':creator_id', $creator->id);
    $pending_count = $db->single()->count;
    
    if ($pending_count > 0) {
        $errors[] = "You already have a pending payout request. Please wait for it to be processed.";
    }
    
    if (empty($errors)) {
        try {
            // Prefer PayPal when both are present
            $payout_method_label = $has_paypal ? 'PayPal' : 'Venmo';

            // Create payout request
            $db->query("
                INSERT INTO payout_requests (creator_id, amount, paypal_email, venmo_handle, payout_method, status, requested_at)
                VALUES (:creator_id, :amount, :paypal_email, :venmo_handle, :payout_method, 'pending', NOW())
            ");
            $db->bind(':creator_id', $creator->id);
            $db->bind(':amount', $requested_amount);
            $db->bind(':paypal_email', $has_paypal ? $creator->paypal_email : null);
            $db->bind(':venmo_handle', $has_venmo  ? $creator->venmo_handle : null);
            $db->bind(':payout_method', $payout_method_label);
            $db->execute();
            $success = "Payout request submitted successfully! You'll receive $" . number_format($requested_amount, 2) . " via {$payout_method_label} within 3-5 business days.";

            // Send notification email to admin
            $admin_subject = "New Payout Request - " . $creator->display_name;
            $admin_message = "
                New payout request submitted:

                Creator: " . $creator->display_name . "
                Amount: $" . number_format($requested_amount, 2) . "
                PayPal: " . ($has_paypal ? $creator->paypal_email : '(not set)') . "
                Venmo:  " . ($has_venmo  ? '@' . $creator->venmo_handle : '(not set)') . "

                Process this payout manually and mark as completed in admin.
            ";
            
            if ($_SERVER['HTTP_HOST'] === 'localhost' || strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false) {
                error_log("ADMIN PAYOUT REQUEST: " . $admin_message);
            } else {
                mail('admin@topiclaunch.com', $admin_subject, $admin_message, 'From: noreply@topiclaunch.com');
            }
            
        } catch (Exception $e) {
            $errors[] = "Failed to submit payout request. Please try again.";
            error_log("Payout request error: " . $e->getMessage());
        }
    }
}

// Get payout history
$db->query("
    SELECT * FROM payout_requests 
    WHERE creator_id = :creator_id 
    ORDER BY requested_at DESC 
    LIMIT 10
");
$db->bind(':creator_id', $creator->id);
$payout_history = $db->resultSet();

// Create payout_requests table if it doesn't exist
$db->query("
    CREATE TABLE IF NOT EXISTS payout_requests (
        id SERIAL PRIMARY KEY,
        creator_id INT NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        paypal_email VARCHAR(255),
        venmo_handle VARCHAR(255),
        payout_method VARCHAR(20),
        status VARCHAR(50) DEFAULT 'pending',
        requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        processed_at TIMESTAMP NULL,
        admin_notes TEXT,
        transaction_id VARCHAR(255)
    )
");
$db->execute();

// Backfill columns on existing tables
$db->query("ALTER TABLE payout_requests ADD COLUMN IF NOT EXISTS venmo_handle VARCHAR(255)");
$db->execute();
$db->query("ALTER TABLE payout_requests ADD COLUMN IF NOT EXISTS payout_method VARCHAR(20)");
$db->execute();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Request Payout - TopicLaunch</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; padding: 20px; }
        .header { background: white; padding: 30px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .nav { margin-bottom: 20px; }
        .nav a { color: #007bff; text-decoration: none; margin-right: 15px; }
        .payout-form { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .balance-info { background: #e8f5e8; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
        .balance-amount { font-size: 32px; font-weight: bold; color: #28a745; margin-bottom: 10px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: bold; color: #333; }
        input[type="number"], input[type="email"] { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 6px; box-sizing: border-box; font-size: 16px; }
        .btn { background: #28a745; color: white; padding: 15px 30px; border: none; border-radius: 6px; cursor: pointer; font-size: 16px; font-weight: bold; }
        .btn:hover { background: #218838; }
        .btn:disabled { background: #6c757d; cursor: not-allowed; }
        .error { color: red; margin-bottom: 15px; padding: 12px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 6px; }
        .success { color: green; margin-bottom: 20px; padding: 15px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 6px; }
        .history-section { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .history-item { display: flex; justify-content: space-between; align-items: center; padding: 15px 0; border-bottom: 1px solid #eee; }
        .history-item:last-child { border-bottom: none; }
        .status-badge { padding: 6px 12px; border-radius: 15px; font-size: 12px; font-weight: bold; }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-processing { background: #cce5ff; color: #004085; }
        .status-completed { background: #d4edda; color: #155724; }
        .status-failed { background: #f8d7da; color: #721c24; }
        .info-box { background: #e3f2fd; padding: 15px; border-radius: 6px; margin-bottom: 20px; font-size: 14px; }
        small { color: #666; font-size: 14px; }
        
        @media (max-width: 600px) {
            .container { padding: 15px; }
            .payout-form, .history-section { padding: 20px; }
        }
    </style>
</head>
<body>
    <?php renderNavigation('dashboard'); ?>

    <div class="container">
        <div class="nav">
            <a href="dashboard.php">← Back to Dashboard</a>
            <a href="edit.php?id=<?php echo $creator->id; ?>">Edit Profile</a>
        </div>

        <div class="header">
            <h1>💰 Request Payout</h1>
            <p>Request manual payouts (PayPal or Venmo) for your earnings</p>
        </div>

        <?php if (!empty($errors)): ?>
            <?php foreach ($errors as $error): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <?php
            $has_paypal_view = !empty(trim($creator->paypal_email ?? ''));
            $has_venmo_view  = !empty(trim($creator->venmo_handle ?? ''));
        ?>
        <div class="balance-info">
            <div class="balance-amount">$<?php echo number_format($available_balance, 2); ?></div>
            <div style="color: #666;">Available for Payout</div>
            <div style="margin-top: 10px; font-size: 14px;">
                <?php if ($has_paypal_view): ?>
                    <strong>PayPal Email:</strong> <?php echo htmlspecialchars($creator->paypal_email); ?><br>
                <?php endif; ?>
                <?php if ($has_venmo_view): ?>
                    <strong>Venmo Handle:</strong> @<?php echo htmlspecialchars($creator->venmo_handle); ?><br>
                <?php endif; ?>
                <?php if (!$has_paypal_view && !$has_venmo_view): ?>
                    <span style="color:#B91C1C;"><strong>No payout method set.</strong> <a href="edit.php?id=<?php echo $creator->id; ?>">Add PayPal or Venmo</a> to enable payouts.</span><br>
                <?php endif; ?>
                <strong>Minimum Payout:</strong> $<?php echo number_format($minimum_threshold, 2); ?>
            </div>
        </div>

        <?php if ($available_balance >= $minimum_threshold): ?>
        <div class="payout-form">
            <h3>💸 Request New Payout</h3>
            
            <div class="info-box">
                <strong>📋 How it works:</strong><br>
                • Enter the amount you want to withdraw<br>
                • We'll send your payment via <?php echo $has_paypal_view ? 'PayPal' : 'Venmo'; ?> within 3-5 business days<br>
                • You'll receive an email confirmation when payment is sent
            </div>

            <form method="POST" id="payoutForm">
                <div class="form-group">
                    <label for="amount">Payout Amount ($):</label>
                    <input type="number" id="amount" name="amount" 
                           min="<?php echo $minimum_threshold; ?>" 
                           max="<?php echo $available_balance; ?>" 
                           step="0.01" 
                           value="<?php echo number_format($available_balance, 2); ?>"
                           required>
                    <small>Minimum: $<?php echo number_format($minimum_threshold, 2); ?> • Maximum: $<?php echo number_format($available_balance, 2); ?></small>
                </div>

                <div class="form-group">
                    <label>Payout Method:</label>
                    <div style="padding:10px 12px; background:#f8f9fa; border:1px solid #e5e5e5; border-radius:6px; font-size:14px;">
                        <?php if ($has_paypal_view): ?>
                            PayPal: <strong><?php echo htmlspecialchars($creator->paypal_email); ?></strong><br>
                        <?php endif; ?>
                        <?php if ($has_venmo_view): ?>
                            Venmo: <strong>@<?php echo htmlspecialchars($creator->venmo_handle); ?></strong>
                        <?php endif; ?>
                    </div>
                    <small>To update your payout details, <a href="edit.php?id=<?php echo $creator->id; ?>">edit your profile</a></small>
                </div>

                <button type="submit" name="request_payout" class="btn" id="submitBtn">
                    💰 Request Payout
                </button>
            </form>
        </div>
        <?php else: ?>
        <div class="payout-form">
            <h3>💸 Payout Not Available</h3>
            <div style="text-align: center; color: #666; padding: 20px;">
                <p>You need at least $<?php echo number_format($minimum_threshold, 2); ?> to request a payout.</p>
                <p>Current balance: $<?php echo number_format($available_balance, 2); ?></p>
                <p>Keep completing topics to reach the minimum threshold!</p>
            </div>
        </div>
        <?php endif; ?>

        <div class="history-section">
            <h3>📋 Payout History</h3>
            
            <?php if (empty($payout_history)): ?>
                <div style="text-align: center; color: #666; padding: 20px;">
                    <p>No payout requests yet.</p>
                </div>
            <?php else: ?>
                <?php foreach ($payout_history as $payout): ?>
                    <div class="history-item">
                        <div>
                            <div style="font-weight: bold;">$<?php echo number_format($payout->amount, 2); ?></div>
                            <div style="font-size: 12px; color: #666;">
                                Requested <?php echo date('M j, Y g:i A', strtotime($payout->requested_at)); ?>
                                <?php if ($payout->processed_at): ?>
                                    • Processed <?php echo date('M j, Y', strtotime($payout->processed_at)); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div style="text-align: right;">
                            <span class="status-badge status-<?php echo $payout->status; ?>">
                                <?php echo ucfirst($payout->status); ?>
                            </span>
                            <?php if ($payout->transaction_id): ?>
                                <div style="font-size: 12px; color: #666; margin-top: 5px;">
                                    ID: <?php echo htmlspecialchars($payout->transaction_id); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
    document.getElementById('payoutForm').addEventListener('submit', function(e) {
        const amount = parseFloat(document.getElementById('amount').value);
        const available = <?php echo $available_balance; ?>;
        const minimum = <?php echo $minimum_threshold; ?>;
        
        if (amount < minimum) {
            e.preventDefault();
            alert('Minimum payout amount is  + minimum.toFixed(2));
            return;
        }
        
        if (amount > available) {
            e.preventDefault();
            alert('Amount exceeds available balance of $' + available.toFixed(2));
            return;
        }
        
        if (!confirm('Request payout of $' + amount.toFixed(2) + ' via <?php echo $has_paypal_view ? "PayPal" : "Venmo"; ?>?\n\nThis will be processed within 3-5 business days.')) {
            e.preventDefault();
            return;
        }
        
        document.getElementById('submitBtn').innerHTML = '⏳ Submitting Request...';
        document.getElementById('submitBtn').disabled = true;
    });
    </script>
</body>
</html>
