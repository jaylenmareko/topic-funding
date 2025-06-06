<?php
// creators/apply.php - Creator application form
session_start();
require_once '../config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$errors = [];
$success = '';

if ($_POST) {
    $display_name = trim($_POST['display_name']);
    $bio = trim($_POST['bio']);
    $platform_type = $_POST['platform_type'];
    $platform_url = trim($_POST['platform_url']);
    $subscriber_count = (int)$_POST['subscriber_count'];
    $default_funding_threshold = (float)$_POST['default_funding_threshold'];
    
    // Validation
    if (empty($display_name)) {
        $errors[] = "Display name is required";
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
    
    // Handle image upload
    $profile_image = null;
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/creators/';
        
        // Create directory if it doesn't exist
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
        $file_type = $_FILES['profile_image']['type'];
        $file_size = $_FILES['profile_image']['size'];
        
        if (!in_array($file_type, $allowed_types)) {
            $errors[] = "Only JPG, PNG, and GIF images are allowed";
        } elseif ($file_size > 2 * 1024 * 1024) { // 2MB limit
            $errors[] = "Image must be less than 2MB";
        } else {
            $file_extension = pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION);
            $new_filename = 'creator_' . $_SESSION['user_id'] . '_' . time() . '.' . $file_extension;
            $upload_path = $upload_dir . $new_filename;
            
            if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $upload_path)) {
                $profile_image = $new_filename;
            } else {
                $errors[] = "Failed to upload image";
            }
        }
    }
    
    // Create creator application if no errors
    if (empty($errors)) {
        try {
            $db = new Database();
            $db->query('
                INSERT INTO creators (display_name, bio, platform_type, platform_url, 
                                    subscriber_count, default_funding_threshold, profile_image,
                                    applicant_user_id, is_active, application_status) 
                VALUES (:display_name, :bio, :platform_type, :platform_url, 
                        :subscriber_count, :threshold, :profile_image, :user_id, 0, "pending")
            ');
            $db->bind(':display_name', $display_name);
            $db->bind(':bio', $bio);
            $db->bind(':platform_type', $platform_type);
            $db->bind(':platform_url', $platform_url);
            $db->bind(':subscriber_count', $subscriber_count);
            $db->bind(':threshold', $default_funding_threshold);
            $db->bind(':profile_image', $profile_image);
            $db->bind(':user_id', $_SESSION['user_id']);
            
            if ($db->execute()) {
                $success = "Creator application submitted successfully! We'll review it and get back to you.";
            } else {
                $errors[] = "Failed to submit application. Please try again.";
            }
        } catch (Exception $e) {
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Apply to be a Creator - Topic Funding</title>
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
        .btn { background: #007bff; color: white; padding: 12px 24px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; }
        .btn:hover { background: #0056b3; }
        .error { color: red; margin-bottom: 10px; }
        .success { color: green; margin-bottom: 10px; padding: 15px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; }
        .requirements { background: #f8f9fa; padding: 15px; border-radius: 4px; margin-bottom: 20px; }
        .requirements h3 { margin-top: 0; }
        .requirements ul { margin-bottom: 0; }
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
                <div class="error"><?php echo $error; ?></div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="success"><?php echo $success; ?></div>
        <?php else: ?>
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label>Creator Display Name: *</label>
                    <input type="text" name="display_name" value="<?php echo isset($_POST['display_name']) ? htmlspecialchars($_POST['display_name']) : ''; ?>" required>
                    <small>The name your audience will see</small>
                </div>

                <div class="form-group">
                    <label>Bio: *</label>
                    <textarea name="bio" required placeholder="Tell us about yourself and your content (minimum 50 characters)"><?php echo isset($_POST['bio']) ? htmlspecialchars($_POST['bio']) : ''; ?></textarea>
                </div>

                <div class="form-group">
                    <label>Platform Type: *</label>
                    <select name="platform_type" required>
                        <option value="">Select Platform</option>
                        <option value="youtube" <?php echo (isset($_POST['platform_type']) && $_POST['platform_type'] == 'youtube') ? 'selected' : ''; ?>>YouTube</option>
                        <option value="twitch" <?php echo (isset($_POST['platform_type']) && $_POST['platform_type'] == 'twitch') ? 'selected' : ''; ?>>Twitch</option>
                        <option value="tiktok" <?php echo (isset($_POST['platform_type']) && $_POST['platform_type'] == 'tiktok') ? 'selected' : ''; ?>>TikTok</option>
                        <option value="instagram" <?php echo (isset($_POST['platform_type']) && $_POST['platform_type'] == 'instagram') ? 'selected' : ''; ?>>Instagram</option>
                        <option value="twitter" <?php echo (isset($_POST['platform_type']) && $_POST['platform_type'] == 'twitter') ? 'selected' : ''; ?>>Twitter/X</option>
                        <option value="podcast" <?php echo (isset($_POST['platform_type']) && $_POST['platform_type'] == 'podcast') ? 'selected' : ''; ?>>Podcast</option>
                        <option value="blog" <?php echo (isset($_POST['platform_type']) && $_POST['platform_type'] == 'blog') ? 'selected' : ''; ?>>Blog</option>
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
                    <input type="number" name="subscriber_count" value="<?php echo isset($_POST['subscriber_count']) ? $_POST['subscriber_count'] : ''; ?>" min="100" required>
                    <small>Current number of subscribers/followers</small>
                </div>

                <div class="form-group">
                    <label>Default Funding Threshold: *</label>
                    <input type="number" name="default_funding_threshold" value="<?php echo isset($_POST['default_funding_threshold']) ? $_POST['default_funding_threshold'] : '50'; ?>" min="10" step="0.01" required>
                    <small>Default amount needed to fund a topic (minimum $10)</small>
                </div>

                <div class="form-group">
                    <label>Profile Image:</label>
                    <input type="file" name="profile_image" accept="image/*">
                    <small>JPG, PNG, or GIF. Max 2MB. (Optional but recommended)</small>
                </div>

                <button type="submit" class="btn">Submit Application</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
