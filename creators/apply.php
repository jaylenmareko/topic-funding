<?php
// creators/apply.php - Updated to redirect existing logged-in users appropriately
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

if ($_POST) {
    // URL validation
    $platform_url = trim($_POST['platform_url'] ?? '');
    
    // Validate URL format and check if it's a real YouTube channel
    if (empty($platform_url)) {
        $errors[] = "YouTube channel URL is required";
    } elseif (!filter_var($platform_url, FILTER_VALIDATE_URL)) {
        $errors[] = "Please enter a valid URL";
    } elseif (!preg_match('/youtube\.com\/(c\/|channel\/|user\/|@)/', $platform_url)) {
        $errors[] = "Please enter a valid YouTube channel URL (youtube.com/@channel or youtube.com/c/channel)";
    } else {
        // Check if the URL actually exists (real channel check)
        $headers = @get_headers($platform_url);
        if (!$headers || strpos($headers[0], '200') === false) {
            $errors[] = "This YouTube channel URL doesn't seem to exist. Please check the URL and try again.";
        }
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
            $db->bind(':subscriber_count', 1000); // Default subscriber count
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
        .error { color: red; margin-bottom: 15px; padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 6px; }
        .success { color: green; margin-bottom: 20px; padding: 15px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 6px; text-align: center; }
        small { color: #666; font-size: 14px; }
        
        @media (max-width: 600px) {
            .container { margin: 10px; padding: 20px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📺 YouTuber Profile Setup</h1>
            <p>Complete your YouTuber profile</p>
            <div style="background: #e3f2fd; padding: 10px; border-radius: 6px; font-size: 14px; margin-bottom: 20px;">
                <strong>Username:</strong> <?php echo htmlspecialchars($pending_registration['username']); ?><br>
                <strong>Email:</strong> <?php echo htmlspecialchars($pending_registration['email']); ?>
            </div>
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
            <form method="POST" id="creatorForm">
                <div class="form-group">
                    <label>YouTube Channel URL:</label>
                    <input type="url" name="platform_url" required 
                           placeholder="https://youtube.com/@yourchannel"
                           value="<?php echo isset($_POST['platform_url']) ? htmlspecialchars($_POST['platform_url']) : ''; ?>">
                    <small>Your main YouTube channel URL - we'll verify it's a real, working channel</small>
                </div>

                <button type="submit" class="btn" id="submitBtn">
                    📺 Complete YouTuber Setup
                </button>
                
                <div style="text-align: center; margin-top: 15px; font-size: 12px; color: #666;">
                    We'll verify your channel exists before completing setup
                </div>
            </form>
        <?php endif; ?>
    </div>

    <script>
    document.getElementById('creatorForm').addEventListener('submit', function(e) {
        const youtubeUrl = document.querySelector('input[name="platform_url"]').value.trim();
        
        // Validation
        if (!youtubeUrl || (!youtubeUrl.includes('youtube.com/@') && !youtubeUrl.includes('youtube.com/c/') && !youtubeUrl.includes('youtube.com/channel/') && !youtubeUrl.includes('youtube.com/user/'))) {
            e.preventDefault();
            alert('Please enter a valid YouTube channel URL (youtube.com/@channel, youtube.com/c/channel, etc.)');
            return;
        }
        
        // Show loading state
        const submitBtn = document.getElementById('submitBtn');
        submitBtn.innerHTML = '⏳ Verifying Channel & Completing Setup...';
        submitBtn.disabled = true;
    });
    </script>
</body>
</html>
