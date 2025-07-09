<?php
// creators/apply.php - Removed PayPal email requirement
session_start();
require_once '../config/database.php';

// If user is already logged in, check if they're already a creator
if (isset($_SESSION['user_id'])) {
    $db = new Database();
    $db->query('SELECT id FROM creators WHERE applicant_user_id = :user_id AND is_active = 1');
    $db->bind(':user_id', $_SESSION['user_id']);
    $existing_creator = $db->single();
    
    if ($existing_creator) {
        header('Location: ../dashboard/index.php');
        exit;
    } else {
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

// Generate CSRF token if not exists
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_POST) {
    // CSRF Protection
    $token = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        $errors[] = "Security token expired. Please refresh the page and try again.";
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } else {
        $youtube_handle = trim($_POST['youtube_handle'] ?? '');
        
        // Remove @ if user included it
        if (strpos($youtube_handle, '@') === 0) {
            $youtube_handle = substr($youtube_handle, 1);
        }
        
        // Handle profile image upload
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
                $temp_filename = 'creator_temp_' . time() . '.' . $file_extension;
                $upload_path = $upload_dir . $temp_filename;
                
                if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $upload_path)) {
                    $profile_image = $temp_filename;
                } else {
                    $errors[] = "Failed to upload image";
                }
            }
        }
        
        // Enhanced validation
        if (empty($youtube_handle)) {
            $errors[] = "YouTube handle is required";
        } elseif (strlen($youtube_handle) < 3) {
            $errors[] = "YouTube handle must be at least 3 characters";
        } elseif (!preg_match('/^[a-zA-Z0-9_.-]+$/', $youtube_handle)) {
            $errors[] = "YouTube handle can only contain letters, numbers, dots, dashes, and underscores";
        } elseif (preg_match('/^[0-9._-]+$/', $youtube_handle)) {
            $errors[] = "YouTube handle must contain at least one letter";
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
                
                // Construct YouTube URL with @username format
                $platform_url = 'https://youtube.com/@' . $youtube_handle;
                
                // Create creator profile (NO PAYPAL EMAIL REQUIRED)
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
                        application_status,
                        manual_payout_threshold
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
                        :application_status,
                        :manual_payout_threshold
                    )
                ');
                
                $db->bind(':username', $username);
                $db->bind(':display_name', $pending_registration['username']);
                $db->bind(':email', $pending_registration['email']);
                $db->bind(':bio', 'YouTube Creator on TopicLaunch');
                $db->bind(':platform_type', 'youtube');
                $db->bind(':platform_url', $platform_url);
                $db->bind(':subscriber_count', 1000);
                $db->bind(':default_funding_threshold', 50.00);
                $db->bind(':commission_rate', 5.00);
                $db->bind(':is_verified', 1);
                $db->bind(':is_active', 1);
                $db->bind(':applicant_user_id', $user_id);
                $db->bind(':application_status', 'approved');
                $db->bind(':manual_payout_threshold', 100.00); // $100 minimum for payouts
                $db->execute();
                
                $creator_id = $db->lastInsertId();
                
                // Update profile image filename with actual creator ID
                if ($profile_image) {
                    $file_extension = pathinfo($profile_image, PATHINFO_EXTENSION);
                    $final_filename = 'creator_' . $creator_id . '_' . time() . '.' . $file_extension;
                    $old_path = $upload_dir . $profile_image;
                    $new_path = $upload_dir . $final_filename;
                    
                    if (rename($old_path, $new_path)) {
                        // Update creator with final image filename
                        $db->query('UPDATE creators SET profile_image = :profile_image WHERE id = :id');
                        $db->bind(':profile_image', $final_filename);
                        $db->bind(':id', $creator_id);
                        $db->execute();
                    }
                }
                
                $db->endTransaction();
                
                // Clear pending registration and log user in
                unset($_SESSION['pending_creator_registration']);
                $_SESSION['user_id'] = $user_id;
                $_SESSION['username'] = $pending_registration['username'];
                $_SESSION['full_name'] = $pending_registration['full_name'];
                $_SESSION['email'] = $pending_registration['email'];
                
                session_regenerate_id(true);
                
                $success = "🎉 YouTuber profile created successfully! Welcome to TopicLaunch.";
                
                error_log("Creator profile created successfully for new user " . $user_id . " with creator ID " . $creator_id);
                
                header('Location: ../dashboard/index.php');
                exit;
                
            } catch (Exception $e) {
                $db->cancelTransaction();
                $errors[] = "Registration failed: " . $e->getMessage();
                error_log("Creator application error: " . $e->getMessage());
            }
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Join as YouTuber - TopicLaunch</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; max-width: 400px; margin: 50px auto; padding: 20px; }
        .header { text-align: center; margin-bottom: 30px; }
        .header h2 { margin: 0 0 10px 0; color: #333; }
        .user-type-indicator { 
            background: #ff0000; 
            color: white; 
            padding: 10px 20px; 
            border-radius: 20px; 
            margin-bottom: 20px; 
            text-align: center; 
            font-weight: bold; 
        }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input[type="text"], input[type="file"] { 
            width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box;
        }
        .btn { background: #ff0000; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; width: 100%; }
        .btn:hover { opacity: 0.9; }
        .btn:disabled { background: #6c757d; cursor: not-allowed; }
        .error { color: red; margin-bottom: 10px; }
        .requirement { color: #666; font-size: 12px; }
        .requirement.valid { color: #28a745; }
        .requirement.invalid { color: #dc3545; }
        .simplified-note { background: #e8f5e8; padding: 15px; border-radius: 6px; margin-bottom: 20px; font-size: 14px; }
    </style>
</head>
<body>
    <div class="header">
        <h2>Join as YouTuber</h2>
        
        <div class="user-type-indicator">
            📺 YouTuber Registration
        </div>
    </div>
    
    <div class="simplified-note">
        <strong>💡 Simplified Setup:</strong> Just add your YouTube handle! You'll provide PayPal info later when you want to withdraw earnings (minimum $100).
    </div>
    
    <?php if (!empty($errors)): ?>
        <div class="errors">
            <?php foreach ($errors as $error): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    
    <form method="POST" id="registrationForm" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        
        <div class="form-group">
            <label for="profile_image">Profile Image (Optional):</label>
            <input type="file" id="profile_image" name="profile_image" accept="image/*">
            <div class="requirement">JPG, PNG, or GIF. Max 2MB. Can add later.</div>
        </div>

        <div class="form-group">
            <label for="youtube_handle">YouTube Handle: *</label>
            <div style="position: relative; display: flex; align-items: center;">
                <span style="background: #e9ecef; border: 1px solid #ddd; border-right: none; padding: 8px 12px; border-radius: 4px 0 0 4px; color: #666; font-weight: bold;">@</span>
                <input type="text" id="youtube_handle" name="youtube_handle" required 
                       style="border-left: none; border-radius: 0 4px 4px 0;"
                       placeholder="MrBeast"
                       pattern="[a-zA-Z0-9_.-]*[a-zA-Z]+[a-zA-Z0-9_.-]*"
                       title="Must contain at least one letter and only letters, numbers, dots, dashes, underscores"
                       value="<?php echo isset($_POST['youtube_handle']) ? htmlspecialchars($_POST['youtube_handle']) : ''; ?>">
            </div>
            <div class="requirement">Example: MrBeast, PewDiePie, etc. Must contain at least one letter.</div>
        </div>

        <button type="submit" class="btn" id="submitBtn">
            📺 Complete YouTuber Setup
        </button>
        
        <div style="text-align: center; margin-top: 15px; font-size: 12px; color: #666;">
            Your YouTube URL will be: youtube.com/@<span id="urlPreview">yourhandle</span>
        </div>
    </form>

    <script>
    // Enhanced validation
    document.getElementById('registrationForm').addEventListener('submit', function(e) {
        const youtubeHandle = document.querySelector('input[name="youtube_handle"]').value.trim();
        
        // YouTube handle validation
        if (!youtubeHandle || youtubeHandle.length < 3) {
            e.preventDefault();
            alert('Please enter a valid YouTube handle (3+ characters)');
            return;
        }
        
        // Check if handle contains at least one letter
        if (!/[a-zA-Z]/.test(youtubeHandle)) {
            e.preventDefault();
            alert('YouTube handle must contain at least one letter');
            return;
        }
        
        // Check for invalid characters
        if (!/^[a-zA-Z0-9_.-]+$/.test(youtubeHandle)) {
            e.preventDefault();
            alert('YouTube handle can only contain letters, numbers, dots, dashes, and underscores');
            return;
        }
        
        // Show loading state
        const submitBtn = document.getElementById('submitBtn');
        submitBtn.innerHTML = '⏳ Creating Your Profile...';
        submitBtn.disabled = true;
    });

    // Real-time validation and URL preview
    document.getElementById('youtube_handle').addEventListener('input', function() {
        let value = this.value;
        
        // Remove @ if user types it
        if (value.startsWith('@')) {
            this.value = value.substring(1);
            value = this.value;
        }
        
        // Remove youtube.com/ if user pastes full URL
        if (value.includes('youtube.com/')) {
            const match = value.match(/youtube\.com\/@?([a-zA-Z0-9_.-]+)/);
            if (match) {
                this.value = match[1];
                value = this.value;
            }
        }
        
        // Update URL preview
        document.getElementById('urlPreview').textContent = value || 'yourhandle';
        
        // Real-time validation feedback
        const isValid = value.length >= 3 && /[a-zA-Z]/.test(value) && /^[a-zA-Z0-9_.-]+$/.test(value);
        this.style.borderColor = value ? (isValid ? '#28a745' : '#dc3545') : '#ddd';
    });
    </script>
</body>
</html>
