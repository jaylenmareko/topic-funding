<?php
// creators/apply.php - Exact match for your database structure
session_start();
require_once '../config/database.php';

// Check if user has pending creator registration
if (!isset($_SESSION['pending_creator_registration'])) {
    header('Location: ../auth/register.php?type=creator');
    exit;
}

$errors = [];
$success = '';
$pending_registration = $_SESSION['pending_creator_registration'];

if ($_POST) {
    // Simple validation
    $platform_url = trim($_POST['platform_url'] ?? '');
    $subscriber_count = (int)($_POST['subscriber_count'] ?? 0);
    
    // Basic validation
    if (empty($platform_url) || !filter_var($platform_url, FILTER_VALIDATE_URL)) {
        $errors[] = "Valid YouTube channel URL required";
    }
    
    if ($subscriber_count < 100) {
        $errors[] = "Minimum 100 subscribers required";
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
            
            // Create creator profile
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
                    application_status
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
                    :application_status
                )
            ');
            
            $db->bind(':username', $username);
            $db->bind(':display_name', $pending_registration['username']); // Use username as display name
            $db->bind(':email', $pending_registration['email']);
            $db->bind(':bio', 'YouTube Creator');
            $db->bind(':platform_type', 'youtube');
            $db->bind(':platform_url', $platform_url);
            $db->bind(':subscriber_count', $subscriber_count);
            $db->bind(':default_funding_threshold', 50.00);
            $db->bind(':commission_rate', 5.00);
            $db->bind(':is_verified', 1);
            $db->bind(':is_active', 1);
            $db->bind(':applicant_user_id', $user_id);
            $db->bind(':application_status', 'approved');
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
            
            header("refresh:3;url=../dashboard/index.php");
            
        } catch (Exception $e) {
            $db->cancelTransaction();
            $errors[] = "Registration failed: " . $e->getMessage();
            error_log("Creator application error: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Join as Creator - TopicLaunch</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
        .container { max-width: 500px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .header { text-align: center; margin-bottom: 30px; }
        .header h1 { margin: 0 0 10px 0; color: #333; }
        .header p { margin: 0; color: #666; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: bold; color: #333; }
        input { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 6px; box-sizing: border-box; font-size: 16px; }
        .btn { background: #ff0000; color: white; padding: 15px 30px; border: none; border-radius: 6px; cursor: pointer; font-size: 16px; width: 100%; font-weight: bold; }
        .btn:hover { background: #cc0000; }
        .btn:disabled { background: #6c757d; cursor: not-allowed; }
        .error { color: red; margin-bottom: 15px; padding: 12px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 6px; }
        .success { color: green; margin-bottom: 20px; padding: 15px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 6px; text-align: center; }
        .back-link { color: #007bff; text-decoration: none; margin-bottom: 20px; display: inline-block; }
        .back-link:hover { text-decoration: underline; }
        small { color: #666; font-size: 14px; }
        .requirements { background: #e3f2fd; padding: 15px; border-radius: 6px; margin-bottom: 20px; font-size: 14px; }
        .requirements h4 { margin-top: 0; color: #1976d2; }
        
        @media (max-width: 600px) {
            .container { margin: 10px; padding: 20px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📺 YouTuber Profile Setup</h1>
            <p>Step 2 of 2: Complete your YouTuber profile</p>
            <div style="background: #e3f2fd; padding: 10px; border-radius: 6px; font-size: 14px; margin-bottom: 20px;">
                <strong>Username:</strong> <?php echo htmlspecialchars($pending_registration['username']); ?><br>
                <strong>Email:</strong> <?php echo htmlspecialchars($pending_registration['email']); ?>
            </div>
        </div>

        <div class="requirements">
            <h4>🚀 What you get as a TopicLaunch Creator:</h4>
            • Fans propose and fund topics for you<br>
            • Get paid 90% of the funding (we keep 10%)<br>
            • 48-hour delivery window<br>
            • Automatic payment processing<br>
            • No upfront costs or monthly fees
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
        <?php endif; ?>

        <?php if (!$success): ?>
            <form method="POST" id="creatorForm">
                <div class="form-group">
                    <label>YouTube Channel URL:</label>
                    <input type="url" name="platform_url" required 
                           placeholder="https://youtube.com/@yourchannel"
                           value="<?php echo isset($_POST['platform_url']) ? htmlspecialchars($_POST['platform_url']) : ''; ?>">
                    <small>Your main YouTube channel URL</small>
                </div>

                <div class="form-group">
                    <label>Subscriber Count:</label>
                    <input type="number" name="subscriber_count" min="100" max="100000000" required
                           placeholder="1000"
                           value="<?php echo isset($_POST['subscriber_count']) ? htmlspecialchars($_POST['subscriber_count']) : ''; ?>">
                    <small>Must have at least 100 subscribers</small>
                </div>

                <button type="submit" class="btn" id="submitBtn">
                    📺 Complete YouTuber Setup
                </button>
                
                <div style="text-align: center; margin-top: 15px; font-size: 12px; color: #666;">
                    By completing setup, you agree to create content within 48 hours of funding
                </div>
            </form>
        <?php endif; ?>
    </div>

    <script>
    document.getElementById('creatorForm').addEventListener('submit', function(e) {
        const youtubeUrl = document.querySelector('input[name="platform_url"]').value.trim();
        const subscribers = parseInt(document.querySelector('input[name="subscriber_count"]').value);
        
        // Validation
        if (!youtubeUrl || !youtubeUrl.includes('youtube.com')) {
            e.preventDefault();
            alert('Please enter a valid YouTube channel URL');
            return;
        }
        
        if (subscribers < 100) {
            e.preventDefault();
            alert('Minimum 100 subscribers required');
            return;
        }
        
        // Show loading state
        const submitBtn = document.getElementById('submitBtn');
        submitBtn.innerHTML = '⏳ Completing Setup...';
        submitBtn.disabled = true;
    });
    </script>
</body>
</html>
