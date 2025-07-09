<?php
// creators/edit.php - Simplified creator profile editing (display name + profile image only)
session_start();
require_once '../config/database.php';

// Check if user is logged in
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

// Check if this creator belongs to the logged in user
$db = new Database();
$db->query('SELECT applicant_user_id FROM creators WHERE id = :id');
$db->bind(':id', $creator_id);
$creator_check = $db->single();

if (!$creator_check || $creator_check->applicant_user_id != $_SESSION['user_id']) {
    header('Location: ../dashboard/index.php');
    exit;
}

$errors = [];
$success = '';

// Handle form submission
if ($_POST) {
    $display_name = trim($_POST['display_name']);
    
    // Validation
    if (empty($display_name) || strlen($display_name) < 2) {
        $errors[] = "Display name must be at least 2 characters";
    }
    
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
            SET display_name = :display_name, profile_image = :profile_image
            WHERE id = :id
        ');
        $db->bind(':display_name', $display_name);
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
    <title>Edit Profile - TopicLaunch</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
        .container { max-width: 500px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .nav { margin-bottom: 20px; }
        .nav a { color: #007bff; text-decoration: none; margin-right: 15px; }
        .header { text-align: center; margin-bottom: 30px; }
        .header h1 { margin: 0 0 10px 0; color: #333; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: bold; color: #333; }
        input[type="text"], input[type="file"] { 
            width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 6px; box-sizing: border-box; font-size: 16px;
        }
        .current-image { margin: 15px 0; text-align: center; }
        .current-image img { max-width: 100px; height: 100px; object-fit: cover; border-radius: 50%; }
        .btn { background: #28a745; color: white; padding: 15px 30px; border: none; border-radius: 6px; cursor: pointer; font-size: 16px; width: 100%; font-weight: bold; }
        .btn:hover { background: #218838; }
        .error { color: red; margin-bottom: 15px; padding: 12px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 6px; }
        .success { color: green; margin-bottom: 20px; padding: 15px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 6px; }
        small { color: #666; font-size: 14px; }
        
        @media (max-width: 600px) {
            .container { margin: 10px; padding: 20px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="nav">
            <a href="../dashboard/index.php">← Back to Dashboard</a>
        </div>

        <div class="header">
            <h1>✏️ Edit Profile</h1>
            <p>Update your display name and profile image</p>
        </div>

        <?php if (!empty($errors)): ?>
            <?php foreach ($errors as $error): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <div class="form-group">
                <label>Display Name:</label>
                <input type="text" name="display_name" value="<?php echo htmlspecialchars($creator->display_name); ?>" required minlength="2">
                <small>This is how your name appears to fans</small>
            </div>

            <div class="form-group">
                <label>Profile Image:</label>
                <?php if ($creator->profile_image): ?>
                    <div class="current-image">
                        <p><strong>Current image:</strong></p>
                        <img src="../uploads/creators/<?php echo htmlspecialchars($creator->profile_image); ?>" alt="Current profile">
                    </div>
                <?php endif; ?>
                <input type="file" name="profile_image" accept="image/*">
                <small>JPG, PNG, or GIF. Max 2MB. Leave empty to keep current image.</small>
            </div>

            <button type="submit" class="btn">
                💾 Update Profile
            </button>
        </form>
    </div>

    <script>
    // Preview image before upload
    document.querySelector('input[type="file"]').addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                // Create preview if it doesn't exist
                let preview = document.getElementById('imagePreview');
                if (!preview) {
                    preview = document.createElement('div');
                    preview.id = 'imagePreview';
                    preview.style.cssText = 'margin: 15px 0; text-align: center;';
                    e.target.parentNode.insertBefore(preview, e.target.nextSibling);
                }
                preview.innerHTML = '<p><strong>New image preview:</strong></p><img src="' + e.target.result + '" style="max-width: 100px; height: 100px; object-fit: cover; border-radius: 50%;">';
            };
            reader.readAsDataURL(file);
        }
    });
    </script>
</body>
</html>
