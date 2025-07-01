<?php
// creators/apply.php - Simplified creator application
session_start();
require_once '../config/database.php';
require_once '../config/csrf.php';
require_once '../config/sanitizer.php';

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
    // Redirect to homepage if already a creator
    header('Location: ../index.php');
    exit;
}

if ($_POST && !$existing_application) {
    // CSRF Protection
    CSRFProtection::requireValidToken();
    
    // Sanitize inputs
    $display_name = InputSanitizer::sanitizeString($_POST['display_name']);
    $platform_url = InputSanitizer::sanitizeUrl($_POST['platform_url']);
    $subscriber_count = InputSanitizer::sanitizeInt($_POST['subscriber_count']);
    
    // Generate username
    $base_username = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $display_name));
    $username = $base_username . '_' . $_SESSION['user_id'];
    
    // Validation
    if (empty($display_name) || strlen($display_name) < 2) {
        $errors[] = "Creator name is required (2+ characters)";
    }
    
    if (empty($platform_url) || !filter_var($platform_url, FILTER_VALIDATE_URL)) {
        $errors[] = "Valid YouTube channel URL required";
    } elseif (strpos($platform_url, 'youtube.com') === false && strpos($platform_url, 'youtu.be') === false) {
        $errors[] = "Must be a YouTube channel URL";
    }
    
    if ($subscriber_count < 100) {
        $errors[] = "Minimum 100 subscribers required";
    }
    
    // Create creator application if no errors
    if (empty($errors)) {
        try {
            // Get user email
            $db->query('SELECT email FROM users WHERE id = :user_id');
            $db->bind(':user_id', $_SESSION['user_id']);
            $user_data = $db->single();
            $user_email = $user_data ? $user_data->email : '';
            
            // Insert creator profile (ACTIVE immediately - no approval needed)
            $db->query('
                INSERT INTO creators (
                    username, display_name, email, bio, platform_type, platform_url, 
                    subscriber_count, default_funding_threshold, applicant_user_id, 
                    is_active, application_status, commission_rate, is_verified
                ) VALUES (
                    :username, :display_name, :email, "YouTube Creator", "youtube", :platform_url, 
                    :subscriber_count, 50, :user_id, 1, "approved", 5.00, 0
                )
            ');
            $db->bind(':username', $username);
            $db->bind(':display_name', $display_name);
            $db->bind(':email', $user_email);
            $db->bind(':platform_url', $platform_url);
            $db->bind(':subscriber_count', $subscriber_count);
            $db->bind(':user_id', $_SESSION['user_id']);
            
            if ($db->execute()) {
                $success = "Welcome to TopicLaunch! You're now a creator.";
                // Redirect to dashboard after 2 seconds
                header("refresh:2;url=../dashboard/index.php");
            } else {
                $errors[] = "Failed to submit application. Please try again.";
            }
        } catch (Exception $e) {
            $errors[] = "Error submitting application.";
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
        input, select { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 6px; box-sizing: border-box; font-size: 16px; }
        .btn { background: #ff0000; color: white; padding: 15px 30px; border: none; border-radius: 6px; cursor: pointer; font-size: 16px; width: 100%; font-weight: bold; }
        .btn:hover { background: #cc0000; }
        .btn:disabled { background: #6c757d; cursor: not-allowed; }
        .error { color: red; margin-bottom: 15px; padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 6px; }
        .success { color: green; margin-bottom: 20px; padding: 15px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 6px; }
        .requirements { background: #e3f2fd; padding: 15px; border-radius: 6px; margin-bottom: 20px; font-size: 14px; }
        .requirements h4 { margin-top: 0; color: #1976d2; }
        .back-link { color: #007bff; text-decoration: none; margin-bottom: 20px; display: inline-block; }
        .back-link:hover { text-decoration: underline; }
        small { color: #666; font-size: 14px; }
        
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

        <?php if (!empty($errors)): ?>
            <?php foreach ($errors as $error): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <?php if (!$success): ?>
            <form method="POST" id="creatorForm">
                <?php echo CSRFProtection::getTokenField(); ?>
                
                <div class="form-group">
                    <label>Your Creator Name:</label>
                    <input type="text" name="display_name" required minlength="2" 
                           placeholder="How you want to appear on TopicLaunch"
                           value="<?php echo isset($_POST['display_name']) ? htmlspecialchars($_POST['display_name']) : ''; ?>">
                </div>

                <div class="form-group">
                    <label>YouTube Channel URL:</label>
                    <input type="url" name="platform_url" required 
                           placeholder="https://youtube.com/@yourchannel"
                           value="<?php echo isset($_POST['platform_url']) ? htmlspecialchars($_POST['platform_url']) : ''; ?>">
                </div>

                <div class="form-group">
                    <label>Subscriber Count:</label>
                    <input type="number" name="subscriber_count" min="100" required
                           placeholder="100"
                           value="<?php echo isset($_POST['subscriber_count']) ? htmlspecialchars($_POST['subscriber_count']) : ''; ?>">
                    <small>Must have at least 100 subscribers</small>
                </div>

                <button type="submit" class="btn">
                    📺 Complete Setup
                </button>
            </form>
            

        <?php endif; ?>
    </div>

    <script>
    // Simple form validation with YouTube URL verification
    document.getElementById('creatorForm').addEventListener('submit', function(e) {
        const displayName = document.querySelector('input[name="display_name"]').value.trim();
        const youtubeUrl = document.querySelector('input[name="platform_url"]').value.trim();
        const subscribers = parseInt(document.querySelector('input[name="subscriber_count"]').value);
        
        if (displayName.length < 2) {
            e.preventDefault();
            alert('Creator name must be at least 2 characters');
            return;
        }
        
        // Validate YouTube URL format
        if (!youtubeUrl || !isValidYouTubeUrl(youtubeUrl)) {
            e.preventDefault();
            alert('Please enter a valid YouTube channel URL (e.g., https://youtube.com/@yourchannel)');
            return;
        }
        
        if (subscribers < 100) {
            e.preventDefault();
            alert('Minimum 100 subscribers required');
            return;
        }
        
        // Show loading state
        const submitBtn = this.querySelector('button[type="submit"]');
        submitBtn.innerHTML = '⏳ Submitting...';
        submitBtn.disabled = true;
    });
    
    // YouTube URL validation function
    function isValidYouTubeUrl(url) {
        try {
            const urlObj = new URL(url);
            const hostname = urlObj.hostname.toLowerCase();
            
            // Check if it's a YouTube domain
            if (hostname !== 'youtube.com' && hostname !== 'www.youtube.com' && hostname !== 'youtu.be') {
                return false;
            }
            
            // Check for common YouTube URL patterns
            const pathname = urlObj.pathname;
            
            // Valid patterns: /channel/, /c/, /@username, /user/
            const validPatterns = [
                /^\/channel\/[a-zA-Z0-9_-]+/,
                /^\/c\/[a-zA-Z0-9_-]+/,
                /^\/user\/[a-zA-Z0-9_-]+/,
                /^\/@[a-zA-Z0-9_.-]+/
            ];
            
            return validPatterns.some(pattern => pattern.test(pathname));
        } catch (e) {
            return false;
        }
    }
    
    // Real-time YouTube URL validation
    document.querySelector('input[name="platform_url"]').addEventListener('input', function() {
        const submitBtn = document.querySelector('button[type="submit"]');
        const url = this.value.trim();
        
        if (url && !isValidYouTubeUrl(url)) {
            this.style.borderColor = '#dc3545';
            submitBtn.disabled = true;
            submitBtn.style.opacity = '0.6';
        } else {
            this.style.borderColor = '#ddd';
            submitBtn.disabled = false;
            submitBtn.style.opacity = '1';
        }
    });
    </script>
</body>
</html>
