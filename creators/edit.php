<?php
// creators/edit.php - Simple creator profile editing (for testing image uploads)
session_start();
require_once '../config/database.php';

// This is a simplified version for testing - in production you'd have proper creator authentication
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$helper = new DatabaseHelper();
$creator_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$creator_id) {
    header('Location: index.php');
    exit;
}

$creator = $helper->getCreatorById($creator_id);
if (!$creator) {
    header('Location: index.php');
    exit;
}

$errors = [];
$success = '';

// Handle form submission
if ($_POST) {
    $display_name = trim($_POST['display_name']);
    $bio = trim($_POST['bio']);
    $platform_url = trim($_POST['platform_url']);
    $subscriber_count = (int)$_POST['subscriber_count'];
    $default_funding_threshold = (float)$_POST['default_funding_threshold'];
    
    // Handle image upload
    $profile_image = $creator->profile_image; // Keep existing image by default
    
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
            $new_filename = 'creator_' . $creator_id . '_' . time() . '.' . $file_extension;
            $upload_path = $upload_dir . $new_filename;
            
            if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $upload_path)) {
                // Delete old image if it exists
                if ($creator->profile_image && file_exists($upload_dir . $creator->profile_image)) {
                    unlink($upload_dir . $creator->profile_image);
                }
                $profile_image = $new_filename;
            } else {
                $errors[] = "Failed to upload image";
            }
        }
    }
    
    // Update creator if no errors
    if (empty($errors)) {
        $db = new Database();
        $db->query('
            UPDATE creators 
            SET display_name = :display_name, bio = :bio, platform_url = :platform_url, 
                subscriber_count = :subscriber_count, default_funding_threshold = :threshold,
                profile_image = :profile_image
            WHERE id = :id
        ');
        $db->bind(':display_name', $display_name);
        $db->bind(':bio', $bio);
        $db->bind(':platform_url', $platform_url);
        $db->bind(':subscriber_count', $subscriber_count);
        $db->bind(':threshold', $default_funding_threshold);
        $db->bind(':profile_image', $profile_image);
        $db->bind(':id', $creator_id);
        
        if ($db->execute()) {
            $success = "Profile updated successfully!";
            // Refresh creator data
            $creator = $helper->getCreatorById($creator_id);
        } else {
            $errors[] = "Failed to update profile";
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Edit Creator Profile - Topic Funding</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
        .container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .nav { margin-bottom: 20px; }
        .nav a { color: #007bff; text-decoration: none; margin-right: 15px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input[type="text"], input[type="url"], input[type="number"], textarea { 
            width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box;
        }
        textarea { height: 100px; resize: vertical; }
        .current-image { margin: 10px 0; }
        .current-image img { max-width: 100px; height: 100px; object-fit: cover; border-radius: 50%; }
        .btn { background: #007bff; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; }
        .btn:hover { background: #0056b3; }
        .error { color: red; margin-bottom: 10px; }
        .success { color: green; margin-bottom: 10px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="nav">
            <a href="profile.php?id=<?php echo $creator_id; ?>">← Back to Profile</a>
        </div>

        <h1>Edit Creator Profile</h1>

        <?php if (!empty($errors)): ?>
            <?php foreach ($errors as $error): ?>
                <div class="error"><?php echo $error; ?></div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="success"><?php echo $success; ?></div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <div class="form-group">
                <label>Display Name:</label>
                <input type="text" name="display_name" value="<?php echo htmlspecialchars($creator->display_name); ?>" required>
            </div>

            <div class="form-group">
                <label>Bio:</label>
                <textarea name="bio" required><?php echo htmlspecialchars($creator->bio); ?></textarea>
            </div>

            <div class="form-group">
                <label>Platform URL:</label>
                <input type="url" name="platform_url" value="<?php echo htmlspecialchars($creator->platform_url); ?>">
            </div>

            <div class="form-group">
                <label>Subscriber Count:</label>
                <input type="number" name="subscriber_count" value="<?php echo $creator->subscriber_count; ?>" min="0">
            </div>

            <div class="form-group">
                <label>Default Funding Threshold ($):</label>
                <input type="number" name="default_funding_threshold" value="<?php echo $creator->default_funding_threshold; ?>" min="1" step="0.01">
            </div>

            <div class="form-group">
                <label>Profile Image:</label>
                <?php if ($creator->profile_image): ?>
                    <div class="current-image">
                        <p>Current image:</p>
                        <img src="../uploads/creators/<?php echo htmlspecialchars($creator->profile_image); ?>" alt="Current profile">
                    </div>
                <?php endif; ?>
                <input type="file" name="profile_image" accept="image/*">
                <small>JPG, PNG, or GIF. Max 2MB.</small>
            </div>

            <button type="submit" class="btn">Update Profile</button>
        </form>
    </div>
</body>
</html>
