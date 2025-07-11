<?php
// creators/edit.php - Updated with YouTube handle input like registration form
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
    $youtube_handle = trim($_POST['youtube_handle']);
    
    // Remove @ if user included it
    if (strpos($youtube_handle, '@') === 0) {
        $youtube_handle = substr($youtube_handle, 1);
    }
    
    // Additional cleanup - trim again after @ removal
    $youtube_handle = trim($youtube_handle);
    
    // Validation
    if (empty($youtube_handle)) {
        $errors[] = "YouTube handle is required";
    } elseif (strlen($youtube_handle) < 3) {
        $errors[] = "YouTube handle must be at least 3 characters";
    } elseif (!preg_match('/^[a-zA-Z0-9_.-]+$/', $youtube_handle)) {
        $errors[] = "YouTube handle can only contain letters, numbers, dots, dashes, and underscores";
    } elseif (preg_match('/^[0-9._-]+$/', $youtube_handle)) {
        $errors[] = "YouTube handle must contain at least one letter";
    } else {
        // Check if this handle is already used by another creator
        $db->query('SELECT id FROM creators WHERE display_name = :display_name AND id != :current_id');
        $db->bind(':display_name', $youtube_handle);
        $db->bind(':current_id', $creator_id);
        if ($db->single()) {
            $errors[] = "YouTube handle already exists";
        } else {
            // Verify YouTube handle exists
            $youtube_url = "https://www.youtube.com/@" . $youtube_handle;
            $headers = @get_headers($youtube_url);
            if (!$headers || strpos($headers[0], '200') === false) {
                $errors[] = "YouTube handle '@{$youtube_handle}' does not exist. Please enter a valid YouTube handle.";
            }
        }
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
        
        // Update both display_name and platform_url
        $new_platform_url = 'https://youtube.com/@' . $youtube_handle;
        
        $db->query('
            UPDATE creators 
            SET display_name = :display_name, profile_image = :profile_image, platform_url = :platform_url
            WHERE id = :id
        ');
        $db->bind(':display_name', $youtube_handle);
        $db->bind(':profile_image', $profile_image);
        $db->bind(':platform_url', $new_platform_url);
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

// Extract current handle from display_name (remove @ if present)
$current_handle = $creator->display_name;
if (strpos($current_handle, '@') === 0) {
    $current_handle = substr($current_handle, 1);
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
        
        /* YouTube Handle Styling */
        .youtube-handle-group { position: relative; }
        .youtube-at-symbol { 
            position: absolute; 
            left: 0px; 
            top: 0px; 
            background: #f8f9fa; 
            padding: 12px 12px; 
            border-radius: 6px 0 0 6px; 
            border: 1px solid #ddd; 
            border-right: none; 
            color: #666; 
            font-weight: bold;
            z-index: 2;
            font-size: 16px;
        }
        .youtube-handle-input { 
            padding-left: 45px !important; 
            position: relative;
            z-index: 1;
        }
        .requirement { color: #666; font-size: 12px; margin-top: 5px; }
        .requirement.valid { color: #28a745; }
        .requirement.invalid { color: #dc3545; }
        
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
            <p>Update your YouTube handle and profile image</p>
        </div>

        <?php if (!empty($errors)): ?>
            <?php foreach ($errors as $error): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" id="profileForm">
            <div class="form-group">
                <label>YouTube Handle:</label>
                <div class="youtube-handle-group">
                    <span class="youtube-at-symbol">@</span>
                    <input type="text" name="youtube_handle" id="youtube_handle" class="youtube-handle-input"
                           value="<?php echo htmlspecialchars($current_handle); ?>" 
                           required pattern="[a-zA-Z0-9_.-]*[a-zA-Z]+[a-zA-Z0-9_.-]*"
                           title="Must contain at least one letter and only letters, numbers, dots, dashes, underscores"
                           placeholder="MrBeast">
                </div>
                <div class="requirement" id="handle-req">Example: MrBeast, PewDiePie, etc. Must contain at least one letter.</div>
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

            <button type="submit" class="btn" id="submitBtn">
                💾 Update Profile
            </button>
        </form>
    </div>

    <script>
    // YouTube handle validation and auto-trim (same as registration form)
    document.getElementById('youtube_handle').addEventListener('input', function() {
        let value = this.value;
        
        // Automatically trim spaces
        value = value.trim();
        
        // Remove @ if user types it
        if (value.startsWith('@')) {
            value = value.substring(1);
        }
        
        // Remove youtube.com/ if user pastes full URL
        if (value.includes('youtube.com/')) {
            const match = value.match(/youtube\.com\/@?([a-zA-Z0-9_.-]+)/);
            if (match) {
                value = match[1];
            }
        }
        
        // Update the input value if it was modified
        if (this.value !== value) {
            this.value = value;
        }
        
        // Real-time validation feedback
        const handleReq = document.getElementById('handle-req');
        const submitBtn = document.getElementById('submitBtn');
        
        if (value.length >= 3 && /[a-zA-Z]/.test(value) && /^[a-zA-Z0-9_.-]+$/.test(value)) {
            this.style.borderColor = '#28a745';
            handleReq.classList.add('valid');
            handleReq.classList.remove('invalid');
            handleReq.textContent = '✓ Valid YouTube handle format';
            submitBtn.disabled = false;
            submitBtn.style.opacity = '1';
        } else if (value.length > 0) {
            this.style.borderColor = '#dc3545';
            handleReq.classList.add('invalid');
            handleReq.classList.remove('valid');
            
            if (value.length < 3) {
                handleReq.textContent = '✗ Must be at least 3 characters';
            } else if (!(/[a-zA-Z]/.test(value))) {
                handleReq.textContent = '✗ Must contain at least one letter';
            } else if (!(/^[a-zA-Z0-9_.-]+$/.test(value))) {
                handleReq.textContent = '✗ Only letters, numbers, dots, dashes, underscores allowed';
            }
            submitBtn.disabled = true;
            submitBtn.style.opacity = '0.6';
        } else {
            this.style.borderColor = '#ddd';
            handleReq.classList.remove('valid', 'invalid');
            handleReq.textContent = 'Example: MrBeast, PewDiePie, etc. Must contain at least one letter.';
            submitBtn.disabled = true;
            submitBtn.style.opacity = '0.6';
        }
    });

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

    // Form submission feedback
    document.getElementById('profileForm').addEventListener('submit', function(e) {
        const handle = document.getElementById('youtube_handle').value.trim();
        
        if (!handle || handle.length < 3) {
            e.preventDefault();
            alert('Please enter a valid YouTube handle (3+ characters)');
            return;
        }
        
        if (!confirm('Update your YouTube handle to @' + handle + '?\n\nThis will update your display name and channel URL.')) {
            e.preventDefault();
            return;
        }
        
        document.getElementById('submitBtn').innerHTML = '⏳ Updating Profile...';
        document.getElementById('submitBtn').disabled = true;
    });
    
    // Initial validation
    document.getElementById('youtube_handle').dispatchEvent(new Event('input'));
    </script>
</body>
</html>
