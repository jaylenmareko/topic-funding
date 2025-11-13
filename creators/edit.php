<?php
// creators/edit.php - Enhanced with PayPal email, email, and password updates
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
    header('Location: ../creators/dashboard.php');
    exit;
}

// Get user email for display
$db->query('SELECT email FROM users WHERE id = :user_id');
$db->bind(':user_id', $_SESSION['user_id']);
$user = $db->single();
$current_email = $user->email;

$errors = [];
$success = '';

// Handle form submission
if ($_POST) {
    try {
        $db->beginTransaction();
        
        // 1. Handle platform handle and image update
        $youtube_handle = trim($_POST['youtube_handle']);
        
        // Remove @ if user included it
        if (strpos($youtube_handle, '@') === 0) {
            $youtube_handle = substr($youtube_handle, 1);
        }
        
        // Additional cleanup - trim again after @ removal
        $youtube_handle = trim($youtube_handle);
        
        // Validation
        if (empty($youtube_handle)) {
            $errors[] = "Platform handle is required";
        } elseif (strlen($youtube_handle) < 3) {
            $errors[] = "Platform handle must be at least 3 characters";
        } elseif (!preg_match('/^[a-zA-Z0-9_.-]+$/', $youtube_handle)) {
            $errors[] = "Platform handle can only contain letters, numbers, dots, dashes, and underscores";
        } elseif (preg_match('/^[0-9._-]+$/', $youtube_handle)) {
            $errors[] = "Platform handle must contain at least one letter";
        } else {
            // Check if this handle is already used by another creator
            $db->query('SELECT id FROM creators WHERE display_name = :display_name AND id != :current_id');
            $db->bind(':display_name', $youtube_handle);
            $db->bind(':current_id', $creator_id);
            if ($db->single()) {
                $errors[] = "Platform handle already exists";
            } else {
                // Verify platform handle exists (only for YouTube)
                if ($creator->platform_type === 'youtube') {
                    $youtube_url = "https://www.youtube.com/@" . $youtube_handle;
                    $headers = @get_headers($youtube_url);
                    if (!$headers || strpos($headers[0], '200') === false) {
                        $errors[] = "YouTube handle '@{$youtube_handle}' does not exist. Please enter a valid YouTube handle.";
                    }
                }
            }
        }
        
        // 2. Handle PayPal email update
        $paypal_email = trim($_POST['paypal_email']);
        if (!empty($paypal_email) && !filter_var($paypal_email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Please enter a valid PayPal email address";
        }
        
        // 3. Handle email update
        $new_email = trim($_POST['email']);
        if (empty($new_email)) {
            $errors[] = "Email is required";
        } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Please enter a valid email address";
        } else {
            // Check if email already exists for another user
            $db->query('SELECT id FROM users WHERE email = :email AND id != :user_id');
            $db->bind(':email', $new_email);
            $db->bind(':user_id', $_SESSION['user_id']);
            
            if ($db->single()) {
                $errors[] = "Email already exists for another account";
            }
        }
        
        // 4. Handle password update (optional)
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        $update_password = false;
        if (!empty($current_password) || !empty($new_password) || !empty($confirm_password)) {
            if (empty($current_password)) {
                $errors[] = "Current password is required when changing password";
            } elseif (empty($new_password)) {
                $errors[] = "New password is required";
            } elseif (strlen($new_password) < 8) {
                $errors[] = "New password must be at least 8 characters";
            } elseif (!preg_match('/[A-Za-z]/', $new_password) || !preg_match('/[0-9]/', $new_password)) {
                $errors[] = "New password must contain at least one letter and one number";
            } elseif ($new_password !== $confirm_password) {
                $errors[] = "New passwords do not match";
            } else {
                // Verify current password
                $db->query('SELECT password_hash FROM users WHERE id = :user_id');
                $db->bind(':user_id', $_SESSION['user_id']);
                $user = $db->single();
                
                if (!password_verify($current_password, $user->password_hash)) {
                    $errors[] = "Current password is incorrect";
                } else {
                    $update_password = true;
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
        
        // If no errors, update everything
        if (empty($errors)) {
            // Update creator profile with dynamic platform URL
            $platform_urls = [
                'youtube' => 'https://youtube.com/@' . $youtube_handle,
                'instagram' => 'https://instagram.com/' . $youtube_handle,
                'tiktok' => 'https://tiktok.com/@' . $youtube_handle
            ];
            $new_platform_url = $platform_urls[$creator->platform_type] ?? 'https://youtube.com/@' . $youtube_handle;
            
            $db->query('
                UPDATE creators 
                SET display_name = :display_name, 
                    profile_image = :profile_image, 
                    platform_url = :platform_url,
                    paypal_email = :paypal_email,
                    email = :email
                WHERE id = :id
            ');
            $db->bind(':display_name', $youtube_handle);
            $db->bind(':profile_image', $profile_image);
            $db->bind(':platform_url', $new_platform_url);
            $db->bind(':paypal_email', $paypal_email);
            $db->bind(':email', $new_email);
            $db->bind(':id', $creator_id);
            $db->execute();
            
            // Update user email
            $db->query('UPDATE users SET email = :email WHERE id = :user_id');
            $db->bind(':email', $new_email);
            $db->bind(':user_id', $_SESSION['user_id']);
            $db->execute();
            
            // Update session email
            $_SESSION['email'] = $new_email;
            
            // Update password if requested
            if ($update_password) {
                $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                
                $db->query('UPDATE users SET password_hash = :password_hash WHERE id = :user_id');
                $db->bind(':password_hash', $new_password_hash);
                $db->bind(':user_id', $_SESSION['user_id']);
                $db->execute();
            }
            
            $db->endTransaction();
            $success = "All information updated successfully!";
            
            // Refresh data
            $creator = $helper->getCreatorById($creator_id);
            $db->query('SELECT email FROM users WHERE id = :user_id');
            $db->bind(':user_id', $_SESSION['user_id']);
            $user = $db->single();
            $current_email = $user->email;
        } else {
            $db->cancelTransaction();
        }
        
    } catch (Exception $e) {
        $db->cancelTransaction();
        $errors[] = "Failed to update information: " . $e->getMessage();
        error_log("Profile update error: " . $e->getMessage());
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
        .container { max-width: 600px; margin: 0 auto; }
        .nav { margin-bottom: 20px; }
        .nav a { color: #007bff; text-decoration: none; margin-right: 15px; }
        .header { text-align: center; margin-bottom: 30px; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .header h1 { margin: 0 0 10px 0; color: #333; }
        .section { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .section h3 { margin-top: 0; color: #333; border-bottom: 2px solid #f1f3f4; padding-bottom: 10px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: bold; color: #333; }
        input[type="text"], input[type="email"], input[type="password"], input[type="file"] { 
            width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 6px; box-sizing: border-box; font-size: 16px;
        }
        .current-image { margin: 15px 0; text-align: center; }
        .current-image img { max-width: 100px; height: 100px; object-fit: cover; border-radius: 50%; }
        .btn { background: #28a745; color: white; padding: 15px 30px; border: none; border-radius: 6px; cursor: pointer; font-size: 16px; width: 100%; font-weight: bold; }
        .btn:hover { background: #218838; }
        .btn:disabled { background: #6c757d; cursor: not-allowed; opacity: 0.6; }
        .btn-paypal { background: #0070ba; }
        .btn-paypal:hover { background: #005ea6; }
        .btn-email { background: #007bff; }
        .btn-email:hover { background: #0056b3; }
        .btn-password { background: #dc3545; }
        .btn-password:hover { background: #c82333; }
        .error { color: red; margin-bottom: 15px; padding: 12px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 6px; }
        .success { color: green; margin-bottom: 20px; padding: 15px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 6px; }
        small { color: #666; font-size: 14px; }
        
        /* Platform Handle Styling */
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
        .current-value { background: #f8f9fa; padding: 10px; border-radius: 4px; color: #666; margin-bottom: 10px; }
        
        @media (max-width: 600px) {
            .container { margin: 10px; padding: 10px; }
            .section { padding: 20px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="nav">
            <a href="../creators/dashboard.php">← Back to Dashboard</a>
        </div>

        <div class="header">
            <h1>✏️ Edit Profile</h1>
        </div>

        <?php if (!empty($errors)): ?>
            <?php foreach ($errors as $error): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <!-- Single Combined Form -->
        <div class="section">
            <form method="POST" enctype="multipart/form-data" id="editForm">
                
                <h3>📺 Profile Information</h3>
                <div class="form-group">
                    <label><?php echo ucfirst($creator->platform_type); ?> Handle:</label>
                    <div class="youtube-handle-group">
                        <?php if ($creator->platform_type !== 'instagram'): ?>
                            <span class="youtube-at-symbol">@</span>
                        <?php endif; ?>
                        <input type="text" name="youtube_handle" id="youtube_handle" 
                               class="<?php echo $creator->platform_type === 'instagram' ? '' : 'youtube-handle-input'; ?>"
                               value="<?php echo htmlspecialchars($current_handle); ?>" 
                               required pattern="[a-zA-Z0-9_.-]*[a-zA-Z]+[a-zA-Z0-9_.-]*"
                               title="Must contain at least one letter and only letters, numbers, dots, dashes, underscores"
                               placeholder="<?php echo $creator->platform_type === 'youtube' ? 'MrBeast' : ($creator->platform_type === 'instagram' ? 'cristiano' : 'charlidamelio'); ?>">
                    </div>
                    <div class="requirement" id="handle-req">
                        <?php if ($creator->platform_type === 'youtube'): ?>
                            Example: MrBeast, PewDiePie, etc.
                        <?php elseif ($creator->platform_type === 'instagram'): ?>
                            Example: cristiano, selenagomez, etc.
                        <?php else: ?>
                            Example: charlidamelio, khaby.lame, etc.
                        <?php endif; ?>
                    </div>
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

                <h3 style="margin-top: 30px;">💰 PayPal Email</h3>
                <div class="current-value">
                    <strong>Current PayPal Email:</strong> <?php echo htmlspecialchars($creator->paypal_email ?: 'Not set'); ?>
                </div>
                <div class="form-group">
                    <label>PayPal Email:</label>
                    <input type="email" name="paypal_email" 
                           value="<?php echo htmlspecialchars($creator->paypal_email ?: ''); ?>" 
                           placeholder="your-paypal@email.com">
                    <small>Used for receiving payments from completed topics</small>
                </div>

                <h3 style="margin-top: 30px;">📧 Account Email</h3>
                <div class="current-value">
                    <strong>Current Email:</strong> <?php echo htmlspecialchars($current_email); ?>
                </div>
                <div class="form-group">
                    <label>New Email Address:</label>
                    <input type="email" name="email" 
                           value="<?php echo htmlspecialchars($current_email); ?>" 
                           required>
                    <small>Used for login and important account notifications</small>
                </div>

                <h3 style="margin-top: 30px;">🔒 Change Password (Optional)</h3>
                <div class="form-group">
                    <label>Current Password:</label>
                    <input type="password" name="current_password" placeholder="Leave blank to keep current password">
                    <small>Only required if changing password</small>
                </div>

                <div class="form-group">
                    <label>New Password:</label>
                    <input type="password" name="new_password" id="new_password" minlength="8" placeholder="Leave blank to keep current password">
                    <div class="requirement" id="password-req">At least 8 characters with one letter and one number</div>
                </div>

                <div class="form-group">
                    <label>Confirm New Password:</label>
                    <input type="password" name="confirm_password" id="confirm_password" placeholder="Leave blank to keep current password">
                    <div class="requirement" id="match-req">Passwords must match</div>
                </div>

                <button type="submit" class="btn" id="submitBtn">
                    💾 Update All Information
                </button>
            </form>
        </div>
    </div>

    <script>
    // Platform handle validation and auto-trim
    document.getElementById('youtube_handle').addEventListener('input', function() {
        let value = this.value;
        
        // Automatically trim spaces
        value = value.trim();
        
        // Remove @ if user types it (except for Instagram which doesn't use @)
        const platform = '<?php echo $creator->platform_type; ?>';
        if (platform !== 'instagram' && value.startsWith('@')) {
            value = value.substring(1);
        }
        
        // Remove platform URLs if user pastes them
        const urlPatterns = [
            /youtube\.com\/@?([a-zA-Z0-9_.-]+)/,
            /instagram\.com\/([a-zA-Z0-9_.-]+)/,
            /tiktok\.com\/@?([a-zA-Z0-9_.-]+)/
        ];
        
        for (const pattern of urlPatterns) {
            const match = value.match(pattern);
            if (match) {
                value = match[1];
                break;
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
            handleReq.textContent = '✓ Valid platform handle format';
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
            const platform = '<?php echo $creator->platform_type; ?>';
            if (platform === 'youtube') {
                handleReq.textContent = 'Example: MrBeast, PewDiePie, etc.';
            } else if (platform === 'instagram') {
                handleReq.textContent = 'Example: cristiano, selenagomez, etc.';
            } else {
                handleReq.textContent = 'Example: charlidamelio, khaby.lame, etc.';
            }
            submitBtn.disabled = true;
            submitBtn.style.opacity = '0.6';
        }
    });

    // Password validation
    function validatePassword() {
        const newPassword = document.getElementById('new_password').value;
        const confirmPassword = document.getElementById('confirm_password').value;
        const passwordReq = document.getElementById('password-req');
        const matchReq = document.getElementById('match-req');
        const submitBtn = document.getElementById('submitBtn');
        
        let isValid = true;
        
        // If no password entered, skip validation
        if (!newPassword && !confirmPassword) {
            passwordReq.classList.remove('valid', 'invalid');
            matchReq.classList.remove('valid', 'invalid');
            passwordReq.textContent = 'At least 8 characters with one letter and one number';
            matchReq.textContent = 'Passwords must match';
            return;
        }
        
        // Password strength check
        if (newPassword.length >= 8 && /[A-Za-z]/.test(newPassword) && /[0-9]/.test(newPassword)) {
            passwordReq.classList.add('valid');
            passwordReq.classList.remove('invalid');
            passwordReq.textContent = '✓ Password meets requirements';
        } else if (newPassword.length > 0) {
            passwordReq.classList.add('invalid');
            passwordReq.classList.remove('valid');
            passwordReq.textContent = '✗ Must be 8+ characters with letter and number';
            isValid = false;
        } else {
            passwordReq.classList.remove('valid', 'invalid');
            passwordReq.textContent = 'At least 8 characters with one letter and one number';
            isValid = false;
        }
        
        // Password match check
        if (confirmPassword && newPassword === confirmPassword) {
            matchReq.classList.add('valid');
            matchReq.classList.remove('invalid');
            matchReq.textContent = '✓ Passwords match';
        } else if (confirmPassword.length > 0) {
            matchReq.classList.add('invalid');
            matchReq.classList.remove('valid');
            matchReq.textContent = '✗ Passwords do not match';
            isValid = false;
        } else {
            matchReq.classList.remove('valid', 'invalid');
            matchReq.textContent = 'Passwords must match';
            isValid = false;
        }
        
        // Don't disable submit button if passwords are just empty
        if (!newPassword && !confirmPassword) {
            submitBtn.disabled = false;
            submitBtn.style.opacity = '1';
        } else {
            submitBtn.disabled = !isValid;
            submitBtn.style.opacity = isValid ? '1' : '0.6';
        }
    }

    document.getElementById('new_password').addEventListener('input', validatePassword);
    document.getElementById('confirm_password').addEventListener('input', validatePassword);

    // Form submission feedback
    document.getElementById('editForm').addEventListener('submit', function(e) {
        const submitBtn = document.getElementById('submitBtn');
        if (submitBtn && !submitBtn.disabled) {
            submitBtn.innerHTML = '⏳ Updating All Information...';
            submitBtn.disabled = true;
        }
    });
    
    // Initial validation
    document.getElementById('youtube_handle').dispatchEvent(new Event('input'));
    validatePassword();
    </script>
</body>
</html>
