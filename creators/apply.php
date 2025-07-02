<?php
// creators/apply.php - Exact match for your database structure
session_start();
require_once '../config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$errors = [];
$success = '';

// Check if user already has a creator application
$db = new Database();
$db->query('SELECT * FROM creators WHERE applicant_user_id = :user_id');
$db->bind(':user_id', $_SESSION['user_id']);
$existing_application = $db->single();

if ($existing_application) {
    header('Location: ../index.php');
    exit;
}

if ($_POST) {
    // Simple validation
    $display_name = trim($_POST['display_name'] ?? '');
    $platform_url = trim($_POST['platform_url'] ?? '');
    $subscriber_count = (int)($_POST['subscriber_count'] ?? 0);
    
    // Basic validation
    if (empty($display_name) || strlen($display_name) < 2) {
        $errors[] = "Creator name is required (2+ characters)";
    }
    
    if (empty($platform_url) || !filter_var($platform_url, FILTER_VALIDATE_URL)) {
        $errors[] = "Valid YouTube channel URL required";
    }
    
    if ($subscriber_count < 100) {
        $errors[] = "Minimum 100 subscribers required";
    }
    
    // Create application if no errors
    if (empty($errors)) {
        try {
            // Generate simple username
            $username = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $display_name)) . '_' . $_SESSION['user_id'];
            
            // Get user email
            $db->query('SELECT email FROM users WHERE id = :user_id');
            $db->bind(':user_id', $_SESSION['user_id']);
            $user_data = $db->single();
            $email = $user_data ? $user_data->email : '';
            
            // Insert creator record using YOUR EXACT table structure
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
            $db->bind(':display_name', $display_name);
            $db->bind(':email', $email);
            $db->bind(':bio', 'YouTube Creator');
            $db->bind(':platform_type', 'youtube');
            $db->bind(':platform_url', $platform_url);
            $db->bind(':subscriber_count', $subscriber_count);
            $db->bind(':default_funding_threshold', 50.00);
            $db->bind(':commission_rate', 5.00);
            $db->bind(':is_verified', 1);
            $db->bind(':is_active', 1);
            $db->bind(':applicant_user_id', $_SESSION['user_id']);
            $db->bind(':application_status', 'approved');
            
            if ($db->execute()) {
                $creator_id = $db->lastInsertId();
                $success = "🎉 Creator profile created successfully! You can now receive topic requests.";
                
                // Log successful creation
                error_log("Creator profile created successfully for user " . $_SESSION['user_id'] . " with creator ID " . $creator_id);
                
                header("refresh:3;url=../dashboard/index.php");
            } else {
                throw new Exception("Failed to insert creator record");
            }
            
        } catch (Exception $e) {
            $errors[] = "Registration failed: " . $e->getMessage();
            error_log("Creator application error for user " . $_SESSION['user_id'] . ": " . $e->getMessage());
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
        <a href="../dashboard/index.php" class="back-link">← Back to Dashboard</a>

        <div class="header">
            <h1>📺 Join as YouTuber</h1>
            <p>Set up your creator profile to start receiving topic requests!</p>
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
                    <label>Your Creator Name:</label>
                    <input type="text" name="display_name" required minlength="2" maxlength="50"
                           placeholder="How you want to appear on TopicLaunch"
                           value="<?php echo isset($_POST['display_name']) ? htmlspecialchars($_POST['display_name']) : ''; ?>">
                    <small>This is how fans will see your name</small>
                </div>

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
                    📺 Create Creator Profile
                </button>
                
                <div style="text-align: center; margin-top: 15px; font-size: 12px; color: #666;">
                    By joining, you agree to create content within 48 hours of funding
                </div>
            </form>
        <?php endif; ?>
    </div>

    <script>
    document.getElementById('creatorForm').addEventListener('submit', function(e) {
        const displayName = document.querySelector('input[name="display_name"]').value.trim();
        const youtubeUrl = document.querySelector('input[name="platform_url"]').value.trim();
        const subscribers = parseInt(document.querySelector('input[name="subscriber_count"]').value);
        
        // Validation
        if (displayName.length < 2) {
            e.preventDefault();
            alert('Creator name must be at least 2 characters');
            return;
        }
        
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
        submitBtn.innerHTML = '⏳ Creating Profile...';
        submitBtn.disabled = true;
    });
    </script>
</body>
</html>
