<?php
// creators/apply.php - Fixed CSRF and simplified with @username format
session_start();
require_once '../config/database.php';

// If user is already logged in, check if they're already a creator
if (isset($_SESSION['user_id'])) {
    $db = new Database();
    $db->query('SELECT id FROM creators WHERE applicant_user_id = :user_id AND is_active = 1');
    $db->bind(':user_id', $_SESSION['user_id']);
    $existing_creator = $db->single();
    
    if ($existing_creator) {
        // User is already a creator, redirect to their dashboard
        header('Location: ../dashboard/index.php');
        exit;
    } else {
        // User is logged in but not a creator, redirect to dashboard
        header('Location: ../dashboard/index.php');
        exit;
    }
}

// Check if user has pending creator registration
if (!isset($_SESSION['pending_creator_registration'])) {
    header('Location: ../auth/register.php?type=creator');
    exit;
}

$errors = [];
$success = '';
$pending_registration = $_SESSION['pending_creator_registration'];

// Generate CSRF token if not exists
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_POST) {
    // CSRF Protection - fixed validation
    $token = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        $errors[] = "Security token expired. Please refresh the page and try again.";
        // Regenerate token
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } else {
        $youtube_handle = trim($_POST['youtube_handle'] ?? '');
        $paypal_email = trim($_POST['paypal_email'] ?? '');
        
        // Remove @ if user included it
        if (strpos($youtube_handle, '@') === 0) {
            $youtube_handle = substr($youtube_handle, 1);
        }
        
        // Validation
        if (empty($youtube_handle)) {
            $errors[] = "YouTube handle is required";
        } elseif (strlen($youtube_handle) < 3) {
            $errors[] = "YouTube handle must be at least 3 characters";
        } elseif (!preg_match('/^[a-zA-Z0-9_.-]+$/', $youtube_handle)) {
            $errors[] = "YouTube handle can only contain letters, numbers, dots, dashes, and underscores";
        }
        
        if (empty($paypal_email)) {
            $errors[] = "PayPal email is required for payouts";
        } elseif (!filter_var($paypal_email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Please enter a valid PayPal email address";
        }
        
        // Create both user account and creator profile atomically
        if (empty($errors)) {
            try {
                $db = new Database();
                $db->beginTransaction();
                
                // Create user account from pending registration data
                $db->query('
                    INSERT INTO users (username, email, password_hash, full_name) 
                    VALUES (:username, :email, :password_hash, :full_name)
                ');
                $db->bind(':username', $pending_registration['username']);
                $db->bind(':email', $pending_registration['email']);
                $db->bind(':password_hash', $pending_registration['password_hash']);
                $db->bind(':full_name', $pending_registration['full_name']);
                $db->execute();
                
                $user_id = $db->lastInsertId();
                
                // Generate creator username from user username
                $username = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $pending_registration['username'])) . '_' . $user_id;
                
                // Construct YouTube URL with @username format
                $platform_url = 'https://youtube.com/@' . $youtube_handle;
                
                // Create creator profile with manual payout system
                $db->query('
                    INSERT INTO creators (
                        username, 
                        display_name, 
                        email, 
                        bio, 
                        platform_type, 
                        platform_url, 
                        subscriber_count, 
                        default_funding_threshold, 
                        commission_rate, 
                        is_verified, 
                        is_active, 
                        applicant_user_id, 
                        application_status,
                        paypal_email,
                        manual_payout_threshold
                    ) VALUES (
                        :username, 
                        :display_name, 
                        :email, 
                        :bio, 
                        :platform_type, 
                        :platform_url, 
                        :subscriber_count, 
                        :default_funding_threshold, 
                        :commission_rate, 
                        :is_verified, 
                        :is_active, 
                        :applicant_user_id, 
                        :application_status,
                        :paypal_email,
                        :manual_payout_threshold
                    )
                ');
                
                $db->bind(':username', $username);
                $db->bind(':display_name', $pending_registration['username']); // Use username as display name
                $db->bind(':email', $pending_registration['email']);
                $db->bind(':bio', 'YouTube Creator on TopicLaunch');
                $db->bind(':platform_type', 'youtube');
                $db->bind(':platform_url', $platform_url);
                $db->bind(':subscriber_count', 1000); // Default subscriber count
                $db->bind(':default_funding_threshold', 50.00);
                $db->bind(':commission_rate', 5.00);
                $db->bind(':is_verified', 1);
                $db->bind(':is_active', 1);
                $db->bind(':applicant_user_id', $user_id);
                $db->bind(':application_status', 'approved');
                $db->bind(':paypal_email', $paypal_email);
                $db->bind(':manual_payout_threshold', 50.00); // $50 minimum payout
                $db->execute();
                
                $creator_id = $db->lastInsertId();
                
                // Commit transaction
                $db->endTransaction();
                
                // Clear pending registration and log user in
                unset($_SESSION['pending_creator_registration']);
                $_SESSION['user_id'] = $user_id;
                $_SESSION['username'] = $pending_registration['username'];
                $_SESSION['full_name'] = $pending_registration['full_name'];
                $_SESSION['email'] = $pending_registration['email'];
                
                // Regenerate session ID for security
                session_regenerate_id(true);
                
                $success = "🎉 YouTuber profile created successfully! Welcome to TopicLaunch.";
                
                error_log("Creator profile created successfully for new user " . $user_id . " with creator ID " . $creator_id);
                
                // Redirect to dashboard instead of showing success message
                header('Location: ../dashboard/index.php');
                exit;
                
            } catch (Exception $e) {
                $db->cancelTransaction();
                $errors[] = "Registration failed: " . $e->getMessage();
                error_log("Creator application error: " . $e->getMessage());
            }
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Join as YouTuber - TopicLaunch</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
        .container { max-width: 500px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .header { text-align: center; margin-bottom: 30px; }
        .header h1 { margin: 0 0 10px 0; color: #333; }
        .header p { margin: 0; color: #666; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: bold; color: #333; }
        .input-group { position: relative; display: flex; align-items: center; }
        .input-prefix { background: #e9ecef; border: 1px solid #ddd; border-right: none; padding: 12px 15px; border-radius: 6px 0 0 6px; color: #666; font-weight: bold; }
        input { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 6px; box-sizing: border-box; font-size: 16px; }
        .input-group input { border-left: none; border-radius: 0 6px 6px 0; }
        .btn { background: #ff0000; color: white; padding: 15px 30px; border: none; border-radius: 6px; cursor: pointer; font-size: 16px; width: 100%; font-weight: bold; }
        .btn:hover { background: #cc0000; }
        .btn:disabled { background: #6c757d; cursor: not-allowed; }
        .error { color: red; margin-bottom: 15px; padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 6px; }
        .success { color: green; margin-bottom: 20px; padding: 15px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 6px; text-align: center; }
        small { color: #666; font-size: 14px; }
        .info-box { background: #e3f2fd; padding: 15px; border-radius: 6px; margin-bottom: 20px; }
        .payout-info { background: #e8f5e8; padding: 15px; border-radius: 6px; margin-bottom: 20px; }
        .account-info { background: #f8f9fa; padding: 15px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #dee2e6; }
        
        @media (max-width: 600px) {
            .container { margin: 10px; padding: 20px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📺 YouTuber Profile Setup</h1>
            <p>Complete your YouTuber profile to start earning</p>
        </div>
        
        <div class="account-info">
            <strong>Account Details:</strong><br>
            <strong>Username:</strong> <?php echo htmlspecialchars($pending_registration['username']); ?><br>
            <strong>Email:</strong> <?php echo htmlspecialchars($pending_registration['email']); ?>
        </div>

        <?php if (!empty($errors)): ?>
            <?php foreach ($errors as $error): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="success">
                <?php echo htmlspecialchars($success); ?><br><br>
                <strong>Redirecting to your dashboard...</strong>
            </div>
        <?php else: ?>
            <div class="info-box">
                <h4 style="margin-top: 0;">📋 What you need:</h4>
                • Your YouTube channel handle (@username)<br>
                • PayPal email for receiving payments<br>
                • Takes less than 2 minutes to complete
            </div>

            <div class="payout-info">
                <h4 style="margin-top: 0;">💰 Payout System:</h4>
                • Earn 90% of funded topics (10% platform fee)<br>
                • $50 minimum payout threshold<br>
                • Manual PayPal payouts within 3-5 business days<br>
                • Request payouts from your dashboard
            </div>

            <form method="POST" id="creatorForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                
                <div class="form-group">
                    <label for="youtube_handle">YouTube Handle: *</label>
                    <div class="input-group">
                        <span class="input-prefix">@</span>
                        <input type="text" id="youtube_handle" name="youtube_handle" required 
                               placeholder="MrBeast"
                               pattern="[a-zA-Z0-9_.-]{3,}"
                               value="<?php echo isset($_POST['youtube_handle']) ? htmlspecialchars($_POST['youtube_handle']) : ''; ?>">
                    </div>
                    <small>Example: MrBeast, PewDiePie, etc. (Just your @username without the @)</small>
                </div>

                <div class="form-group">
                    <label for="paypal_email">PayPal Email for Payouts: *</label>
                    <input type="email" id="paypal_email" name="paypal_email" required 
                           placeholder="your-paypal@email.com"
                           value="<?php echo isset($_POST['paypal_email']) ? htmlspecialchars($_POST['paypal_email']) : ''; ?>">
                    <small>This is where you'll receive your earnings via PayPal (90% of topic funding)</small>
                </div>

                <button type="submit" class="btn" id="submitBtn">
                    📺 Complete YouTuber Setup
                </button>
                
                <div style="text-align: center; margin-top: 15px; font-size: 12px; color: #666;">
                    Your YouTube URL will be: youtube.com/@<span id="urlPreview">yourhandle</span>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <script>
    document.getElementById('creatorForm').addEventListener('submit', function(e) {
        const youtubeHandle = document.querySelector('input[name="youtube_handle"]').value.trim();
        const paypalEmail = document.querySelector('input[name="paypal_email"]').value.trim();
        
        // Validation
        if (!youtubeHandle || youtubeHandle.length < 3) {
            e.preventDefault();
            alert('Please enter a valid YouTube handle (3+ characters)');
            return;
        }
        
        if (!paypalEmail || !paypalEmail.includes('@')) {
            e.preventDefault();
            alert('Please enter a valid PayPal email address');
            return;
        }
        
        // Show loading state
        const submitBtn = document.getElementById('submitBtn');
        submitBtn.innerHTML = '⏳ Creating Your Profile...';
        submitBtn.disabled = true;
    });

    // Auto-format YouTube handle and show URL preview
    document.getElementById('youtube_handle').addEventListener('input', function() {
        let value = this.value;
        
        // Remove @ if user types it
        if (value.startsWith('@')) {
            this.value = value.substring(1);
            value = this.value;
        }
        
        // Remove youtube.com/ if user pastes full URL
        if (value.includes('youtube.com/')) {
            const match = value.match(/youtube\.com\/@?([a-zA-Z0-9_.-]+)/);
            if (match) {
                this.value = match[1];
                value = this.value;
            }
        }
        
        // Update URL preview
        document.getElementById('urlPreview').textContent = value || 'yourhandle';
    });
    </script>
</body>
</html>
