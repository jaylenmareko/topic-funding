<?php
// creators/apply.php - Debug version with detailed error logging
session_start();
require_once '../config/database.php';
require_once '../config/csrf.php';
require_once '../config/sanitizer.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Simple YouTube channel verification
function verifyYouTubeChannel($url) {
    // For now, just do basic URL validation to avoid external API issues
    return ['valid' => true];
}

function extractChannelInfo($url) {
    // Parse different YouTube URL formats
    $patterns = [
        '/youtube\.com\/@([a-zA-Z0-9_.-]+)/',
        '/youtube\.com\/channel\/([a-zA-Z0-9_-]+)/',
        '/youtube\.com\/c\/([a-zA-Z0-9_-]+)/',
        '/youtube\.com\/user\/([a-zA-Z0-9_-]+)/'
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $url, $matches)) {
            return $matches[1];
        }
    }
    return false;
}

function generateVerificationCode() {
    return 'TL-' . strtoupper(substr(md5(uniqid()), 0, 8));
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$errors = [];
$success = '';
$debug_info = [];

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
    $debug_info[] = "Form submitted by user: " . $_SESSION['user_id'];
    
    // CSRF Protection
    try {
        CSRFProtection::requireValidToken();
        $debug_info[] = "CSRF token validated";
    } catch (Exception $e) {
        $errors[] = "CSRF validation failed: " . $e->getMessage();
        $debug_info[] = "CSRF error: " . $e->getMessage();
    }
    
    if (empty($errors)) {
        // Debug: Show what we received
        $debug_info[] = "POST data received: " . json_encode($_POST);
        
        // Sanitize inputs with error checking
        try {
            $display_name = isset($_POST['display_name']) ? InputSanitizer::sanitizeString($_POST['display_name']) : '';
            $platform_url = isset($_POST['platform_url']) ? InputSanitizer::sanitizeUrl($_POST['platform_url']) : '';
            $subscriber_count = isset($_POST['subscriber_count']) ? (int)InputSanitizer::sanitizeInt($_POST['subscriber_count']) : 0;
            
            $debug_info[] = "Sanitized data - Name: $display_name, URL: $platform_url, Subs: $subscriber_count";
            
            // Generate username from display name
            $base_username = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $display_name));
            $username = $base_username . '_' . $_SESSION['user_id'];
            
            // Set default values
            $bio = "YouTube Creator";
            $platform_type = "youtube";
            $default_funding_threshold = 50;
            $email = '';
            
            $debug_info[] = "Generated username: $username";
            
        } catch (Exception $e) {
            $errors[] = "Data sanitization error: " . $e->getMessage();
            $debug_info[] = "Sanitization error: " . $e->getMessage();
        }
    }
    
    if (empty($errors)) {
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
        
        $debug_info[] = "Validation completed. Errors: " . count($errors);
    }
    
    // Create creator application if no errors
    if (empty($errors)) {
        try {
            $debug_info[] = "Starting database transaction";
            $db->beginTransaction();
            
            // Get user email
            $db->query('SELECT email FROM users WHERE id = :user_id');
            $db->bind(':user_id', $_SESSION['user_id']);
            $user_data = $db->single();
            $email = $user_data ? $user_data->email : '';
            
            $debug_info[] = "User email retrieved: " . ($email ? "Yes" : "No");
            
            // Check if creator_verification table exists, create if not
            try {
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
                $debug_info[] = "Verification table checked/created";
            } catch (Exception $e) {
                $debug_info[] = "Table creation error: " . $e->getMessage();
            }
            
            // Insert creator profile
            $sql = '
                INSERT INTO creators (
                    username, display_name, email, bio, platform_type, platform_url, 
                    subscriber_count, default_funding_threshold, applicant_user_id, 
                    is_active, application_status, commission_rate, is_verified, verification_status
                ) VALUES (
                    :username, :display_name, :email, :bio, :platform_type, :platform_url, 
                    :subscriber_count, :default_funding_threshold, :user_id, 
                    0, "pending_verification", 5.00, 0, "pending"
                )
            ';
            
            $debug_info[] = "Preparing SQL query";
            $db->query($sql);
            $db->bind(':username', $username);
            $db->bind(':display_name', $display_name);
            $db->bind(':email', $email);
            $db->bind(':bio', $bio);
            $db->bind(':platform_type', $platform_type);
            $db->bind(':platform_url', $platform_url);
            $db->bind(':subscriber_count', $subscriber_count);
            $db->bind(':default_funding_threshold', $default_funding_threshold);
            $db->bind(':user_id', $_SESSION['user_id']);
            
            $debug_info[] = "Parameters bound";
            
            if ($db->execute()) {
                $creator_id = $db->lastInsertId();
                $debug_info[] = "Creator inserted with ID: " . $creator_id;
                
                // Generate and store verification code
                $verification_code = generateVerificationCode();
                
                $db->query('INSERT INTO creator_verification (creator_id, verification_code, created_at) VALUES (:creator_id, :code, NOW())');
                $db->bind(':creator_id', $creator_id);
                $db->bind(':code', $verification_code);
                
                if ($db->execute()) {
                    $debug_info[] = "Verification code stored";
                    $db->endTransaction();
                    
                    $success = "Profile created! Please complete verification to start receiving topic requests.";
                    header("refresh:3;url=../creators/verify.php?creator_id=" . $creator_id);
                } else {
                    throw new Exception("Failed to store verification code");
                }
            } else {
                throw new Exception("Failed to insert creator record");
            }
            
        } catch (Exception $e) {
            $db->cancelTransaction();
            $errors[] = "Database error occurred. Please try again.";
            $debug_info[] = "Database error: " . $e->getMessage();
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
        input, select { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 6px; box-sizing: border-box; font-size: 16px; }
        .btn { background: #ff0000; color: white; padding: 15px 30px; border: none; border-radius: 6px; cursor: pointer; font-size: 16px; width: 100%; font-weight: bold; }
        .btn:hover { background: #cc0000; }
        .btn:disabled { background: #6c757d; cursor: not-allowed; }
        .error { color: red; margin-bottom: 15px; padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 6px; }
        .success { color: green; margin-bottom: 20px; padding: 15px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 6px; }
        .debug { background: #e3f2fd; padding: 15px; border-radius: 6px; margin-bottom: 20px; font-size: 12px; }
        .debug h4 { margin-top: 0; color: #1976d2; }
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

        <?php if (!empty($debug_info)): ?>
        <div class="debug">
            <h4>🔧 Debug Information:</h4>
            <?php foreach ($debug_info as $info): ?>
                <div><?php echo htmlspecialchars($info); ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

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

        <!-- Debug: Session Info -->
        <div class="debug">
            <h4>📊 Session Info:</h4>
            <div>User ID: <?php echo $_SESSION['user_id'] ?? 'Not set'; ?></div>
            <div>Username: <?php echo $_SESSION['username'] ?? 'Not set'; ?></div>
            <div>Email: <?php echo $_SESSION['email'] ?? 'Not set'; ?></div>
        </div>
    </div>

    <script>
    // Simple form validation
    document.getElementById('creatorForm').addEventListener('submit', function(e) {
        const displayName = document.querySelector('input[name="display_name"]').value.trim();
        const youtubeUrl = document.querySelector('input[name="platform_url"]').value.trim();
        const subscribers = parseInt(document.querySelector('input[name="subscriber_count"]').value);
        
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
        const submitBtn = this.querySelector('button[type="submit"]');
        submitBtn.innerHTML = '⏳ Submitting...';
        submitBtn.disabled = true;
    });
    </script>
</body>
</html>
