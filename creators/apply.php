<?php
// creators/apply.php - Fixed creator application form with security
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
    $success = "You already have a creator application. Status: " . ucfirst($existing_application->application_status);
}

if ($_POST && !$existing_application) {
    // CSRF Protection
    CSRFProtection::requireValidToken();
    
    // Sanitize inputs
    $display_name = InputSanitizer::sanitizeString($_POST['display_name']);
    $bio = InputSanitizer::sanitizeString($_POST['bio']);
    $platform_type = InputSanitizer::sanitizeString($_POST['platform_type']);
    $platform_url = InputSanitizer::sanitizeUrl($_POST['platform_url']);
    $subscriber_count = InputSanitizer::sanitizeInt($_POST['subscriber_count']);
    $default_funding_threshold = InputSanitizer::sanitizeFloat($_POST['default_funding_threshold']);
    
    // Generate a unique username based on display name and user ID
    $base_username = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $display_name));
    $username = $base_username . '_' . $_SESSION['user_id'];
    
    // Make sure username is unique
    $counter = 1;
    $original_username = $username;
    while (true) {
        $db->query('SELECT id FROM creators WHERE username = :username');
        $db->bind(':username', $username);
        $db->execute();
        if ($db->rowCount() == 0) {
            break; // Username is unique
        }
        $username = $original_username . '_' . $counter;
        $counter++;
    }
    
    // Validation
    if (empty($display_name)) {
        $errors[] = "Display name is required";
    } elseif (strlen($display_name) < 2) {
        $errors[] = "Display name must be at least 2 characters";
    }
    
    if (empty($bio)) {
        $errors[] = "Bio is required";
    } elseif (strlen($bio) < 50) {
        $errors[] = "Bio must be at least 50 characters";
    }
    
    if (empty($platform_type)) {
        $errors[] = "Platform type is required";
    }
    
    if (empty($platform_url)) {
        $errors[] = "Platform URL is required";
    } elseif (!filter_var($platform_url, FILTER_VALIDATE_URL)) {
        $errors[] = "Please enter a valid URL";
    }
    
    if ($subscriber_count < 100) {
        $errors[] = "Minimum 100 subscribers required";
    }
    
    if ($default_funding_threshold < 10) {
        $errors[] = "Minimum funding threshold is $10";
    }
    
    // Initialize profile_image variable
    $profile_image = null;
    
    // Enhanced file upload security
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/creators/';
        
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
        
        $file_info = finfo_open(FILEINFO_MIME_TYPE);
        $detected_type = finfo_file($file_info, $_FILES['profile_image']['tmp_name']);
        finfo_close($file_info);
        
        $file_extension = strtolower(pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION));
        $file_size = $_FILES['profile_image']['size'];
        
        if (!in_array($detected_type, $allowed_types)) {
            $errors[] = "Only JPG, PNG, and GIF images are allowed";
        } elseif (!in_array($file_extension, $allowed_extensions)) {
            $errors[] = "Invalid file extension";
        } elseif ($file_size > 2 * 1024 * 1024) {
            $errors[] = "Image must be less than 2MB";
        } else {
            $safe_filename = 'creator_' . $_SESSION['user_id'] . '_' . time() . '.' . $file_extension;
            $upload_path = $upload_dir . $safe_filename;
            
            if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $upload_path)) {
                $profile_image = $safe_filename;
            } else {
                $errors[] = "Failed to upload image";
            }
        }
    }
    
    // Create creator application if no errors
    if (empty($errors)) {
        try {
            // Get user email for the application
            $db->query('SELECT email FROM users WHERE id = :user_id');
            $db->bind(':user_id', $_SESSION['user_id']);
            $user_data = $db->single();
            $user_email = $user_data ? $user_data->email : '';
            
            // Insert with all required fields including username and email
            $db->query('
                INSERT INTO creators (
                    username, display_name, email, bio, platform_type, platform_url, 
                    subscriber_count, default_funding_threshold, profile_image,
                    applicant_user_id, is_active, application_status, commission_rate,
                    is_verified
                ) VALUES (
                    :username, :display_name, :email, :bio, :platform_type, :platform_url, 
                    :subscriber_count, :threshold, :profile_image, :user_id, 0, "pending", 5.00, 0
                )
            ');
            $db->bind(':username', $username);
            $db->bind(':display_name', $display_name);
            $db->bind(':email', $user_email);
            $db->bind(':bio', $bio);
            $db->bind(':platform_type', $platform_type);
            $db->bind(':platform_url', $platform_url);
            $db->bind(':subscriber_count', $subscriber_count);
            $db->bind(':threshold', $default_funding_threshold);
            $db->bind(':profile_image', $profile_image);
            $db->bind(':user_id', $_SESSION['user_id']);
            
            if ($db->execute()) {
                $success = "Creator application submitted successfully! We'll review it and get back to you. Your creator username will be: " . $username;
            } else {
                $errors[] = "Failed to submit application. Please try again.";
            }
        } catch (Exception $e) {
            $errors[] = "Database error: " . $e->getMessage();
            error_log("Creator application error for user " . $_SESSION['user_id'] . ": " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Apply to be a Creator - Topic Funding</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
        .container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .nav { margin-bottom: 20px; }
        .nav a { color: #007bff; text-decoration: none; margin-right: 15px; }
        .header { text-align: center; margin-bottom: 30px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input[type="text"], input[type="url"], input[type="number"], select, textarea { 
            width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box;
        }
        textarea { height: 120px; resize: vertical; }
        .btn { background: #007bff; color: white; padding: 12px 24px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; width: 100%; }
        .btn:hover { background: #0056b3; }
        .btn:disabled { background: #6c757d; cursor: not-allowed; }
        .error { color: red; margin-bottom: 10px; }
        .success { color: green; margin-bottom: 10px; padding: 15px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; }
        .requirements { background: #f8f9fa; padding: 15px; border-radius: 4px; margin-bottom: 20px; }
        .requirements h3 { margin-top: 0; }
        .requirements ul { margin-bottom: 0; }
        .security-note { background: #e3f2fd; padding: 10px; border-radius: 4px; margin-bottom: 20px; font-size: 14px; }
        .file-requirements { font-size: 12px; color: #666; margin-top: 5px; }
        .char-counter { font-size: 12px; color: #666; margin-top: 5px; }
        
        @media (max-width: 768px) {
            .container { margin: 10px; padding: 20px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="nav">
            <a href="../index.php">← Back to Home</a>
            <a href="../creators/index.php">Browse Creators</a>
        </div>

        <div class="header">
            <h1>Apply to be a Creator</h1>
            <p>Join our platform and let your audience fund the content they want to see!</p>
        </div>

        <div class="security-note">
            🔒 Your application is protected with advanced security measures.
        </div>

        <div class="requirements">
            <h3>Requirements:</h3>
            <ul>
                <li>Minimum 100 subscribers/followers</li>
                <li>Active content creation on your platform</li>
                <li>Commitment to creating funded content within 48 hours</li>
                <li>Valid platform URL for verification</li>
            </ul>
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
            <form method="POST" enctype="multipart/form-data" id="creatorForm">
                <?php echo CSRFProtection::getTokenField(); ?>
                
                <div class="form-group">
                    <label>Creator Display Name: *</label>
                    <input type="text" name="display_name" id="display_name" value="<?php echo isset($_POST['display_name']) ? htmlspecialchars($_POST['display_name']) : ''; ?>" required>
                    <small>The name your audience will see (username will be auto-generated)</small>
                </div>

                <div class="form-group">
                    <label>Bio: *</label>
                    <textarea name="bio" id="bio" required placeholder="Tell us about yourself and your content (minimum 50 characters)"><?php echo isset($_POST['bio']) ? htmlspecialchars($_POST['bio']) : ''; ?></textarea>
                    <div class="char-counter" id="bioCounter">0 / 50 characters minimum</div>
                </div>

                <div class="form-group">
                    <label>Platform Type: *</label>
                    <select name="platform_type" required>
                        <option value="">Select Platform</option>
                        <option value="youtube" <?php echo (isset($_POST['platform_type']) && $_POST['platform_type'] == 'youtube') ? 'selected' : ''; ?>>YouTube</option>
                        <option value="twitch" <?php echo (isset($_POST['platform_type']) && $_POST['platform_type'] == 'twitch') ? 'selected' : ''; ?>>Twitch</option>
                        <option value="tiktok" <?php echo (isset($_POST['platform_type']) && $_POST['platform_type'] == 'tiktok') ? 'selected' : ''; ?>>TikTok</option>
                        <option value="other" <?php echo (isset($_POST['platform_type']) && $_POST['platform_type'] == 'other') ? 'selected' : ''; ?>>Other</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Platform URL: *</label>
                    <input type="url" name="platform_url" value="<?php echo isset($_POST['platform_url']) ? htmlspecialchars($_POST['platform_url']) : ''; ?>" required placeholder="https://youtube.com/channel/...">
                    <small>Link to your main channel/profile for verification</small>
                </div>

                <div class="form-group">
                    <label>Subscriber/Follower Count: *</label>
                    <input type="number" name="subscriber_count" value="<?php echo isset($_POST['subscriber_count']) ? htmlspecialchars($_POST['subscriber_count']) : ''; ?>" min="100" required>
                    <small>Current number of subscribers/followers</small>
                </div>

                <div class="form-group">
                    <label>Default Funding Threshold: *</label>
                    <input type="number" name="default_funding_threshold" value="<?php echo isset($_POST['default_funding_threshold']) ? htmlspecialchars($_POST['default_funding_threshold']) : '50'; ?>" min="10" step="0.01" required>
                    <small>Default amount needed to fund a topic (minimum $10)</small>
                </div>

                <div class="form-group">
                    <label>Profile Image:</label>
                    <input type="file" name="profile_image" accept="image/*">
                    <div class="file-requirements">JPG, PNG, or GIF. Max 2MB. (Optional but recommended)</div>
                </div>

                <button type="submit" class="btn" id="submitBtn">Submit Application</button>
            </form>
        <?php endif; ?>
    </div>

    <script>
    // Bio character counter
    const bioTextarea = document.getElementById('bio');
    const bioCounter = document.getElementById('bioCounter');
    const submitBtn = document.getElementById('submitBtn');

    function updateBioCounter() {
        const length = bioTextarea.value.length;
        bioCounter.textContent = length + ' / 50 characters minimum';
        
        if (length >= 50) {
            bioCounter.style.color = '#28a745';
        } else {
            bioCounter.style.color = '#dc3545';
        }
        
        validateForm();
    }

    function validateForm() {
        const displayName = document.getElementById('display_name').value.trim();
        const bio = bioTextarea.value.trim();
        const isValid = displayName.length >= 2 && bio.length >= 50;
        
        submitBtn.disabled = !isValid;
        submitBtn.style.opacity = isValid ? '1' : '0.6';
    }

    bioTextarea.addEventListener('input', updateBioCounter);
    document.getElementById('display_name').addEventListener('input', validateForm);

    // Initial validation
    updateBioCounter();
    validateForm();

    // Form submission loading state
    document.getElementById('creatorForm').addEventListener('submit', function() {
        submitBtn.innerHTML = 'Submitting...';
        submitBtn.disabled = true;
    });
    </script>
</body>
</html>
