<?php
// creators/edit.php - Clean edit profile page
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$helper = new DatabaseHelper();
$creator_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$creator_id) {
    header('Location: dashboard.php');
    exit;
}

$creator = $helper->getCreatorById($creator_id);
if (!$creator) {
    header('Location: dashboard.php');
    exit;
}

// Verify ownership
$db = new Database();
$db->query('SELECT applicant_user_id FROM creators WHERE id = :id');
$db->bind(':id', $creator_id);
$creator_check = $db->single();

if (!$creator_check || $creator_check->applicant_user_id != $_SESSION['user_id']) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $youtube_handle = trim($_POST['youtube_handle'] ?? '');
        $bio = trim($_POST['bio'] ?? '');
        $minimum_topic_price = trim($_POST['minimum_topic_price'] ?? '');
        $paypal_email = trim($_POST['paypal_email'] ?? '');
        $venmo_handle = trim($_POST['venmo_handle'] ?? '');
        
        // Remove @ from YouTube handle
        $youtube_handle = str_replace('@', '', $youtube_handle);
        
        // Validation
        if (empty($youtube_handle)) {
            $error = 'YouTube handle is required';
        } elseif (!is_numeric($minimum_topic_price) || $minimum_topic_price < 10) {
            $error = 'Minimum topic price must be at least $10';
        } elseif ($minimum_topic_price > 10000) {
            $error = 'Minimum topic price cannot exceed $10,000';
        } elseif (!empty($paypal_email) && !filter_var($paypal_email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid PayPal email format';
        } else {
            // Handle profile image upload
            $profile_image = $creator->profile_image;
            if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../uploads/creators/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                
                $allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp'];
                $file_type = $_FILES['profile_image']['type'];
                $file_size = $_FILES['profile_image']['size'];
                
                if (!in_array($file_type, $allowed_types)) {
                    $error = 'Only JPG, PNG, and WebP images allowed';
                } elseif ($file_size > 5 * 1024 * 1024) {
                    $error = 'Image must be under 5MB';
                } else {
                    $file_extension = pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION);
                    $new_filename = 'creator_' . $creator_id . '_' . time() . '.' . $file_extension;
                    $upload_path = $upload_dir . $new_filename;
                    
                    if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $upload_path)) {
                        if ($creator->profile_image && file_exists($upload_dir . $creator->profile_image)) {
                            @unlink($upload_dir . $creator->profile_image);
                        }
                        $profile_image = $new_filename;
                    } else {
                        $error = 'Failed to upload image';
                    }
                }
            }
            
            // Handle password change (optional)
            if (!empty($_POST['new_password']) || !empty($_POST['confirm_password'])) {
                $new_password = $_POST['new_password'] ?? '';
                $confirm_password = $_POST['confirm_password'] ?? '';
                
                if (strlen($new_password) < 8) {
                    $error = 'New password must be at least 8 characters';
                } elseif ($new_password !== $confirm_password) {
                    $error = 'Passwords do not match';
                } else {
                    // Update password
                    try {
                        $db->query('UPDATE users SET password_hash = :password_hash WHERE id = :user_id');
                        $db->bind(':password_hash', password_hash($new_password, PASSWORD_DEFAULT));
                        $db->bind(':user_id', $_SESSION['user_id']);
                        $db->execute();
                    } catch (Exception $e) {
                        $error = 'Failed to update password: ' . $e->getMessage();
                    }
                }
            }
            
            // Update profile if no errors
            if (!$error) {
                try {
                    $platform_url = 'https://youtube.com/@' . $youtube_handle;
                    
                    $db->query('
                        UPDATE creators 
                        SET display_name = :display_name, 
                            username = :username,
                            bio = :bio,
                            profile_image = :profile_image, 
                            platform_url = :platform_url,
                            minimum_topic_price = :minimum_topic_price,
                            paypal_email = :paypal_email,
                            venmo_handle = :venmo_handle
                        WHERE id = :id
                    ');
                    $db->bind(':display_name', $youtube_handle);
                    $db->bind(':username', $youtube_handle);
                    $db->bind(':bio', $bio);
                    $db->bind(':profile_image', $profile_image);
                    $db->bind(':platform_url', $platform_url);
                    $db->bind(':minimum_topic_price', floatval($minimum_topic_price));
                    $db->bind(':paypal_email', $paypal_email);
                    $db->bind(':venmo_handle', $venmo_handle);
                    $db->bind(':id', $creator_id);
                    $db->execute();
                    
                    $success = 'Profile updated successfully!';
                    
                    // Refresh creator data
                    $creator = $helper->getCreatorById($creator_id);
                } catch (Exception $e) {
                    $error = 'Failed to update profile: ' . $e->getMessage();
                }
            }
        }
    } catch (Exception $e) {
        $error = 'An error occurred: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Edit Profile - TopicLaunch</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: white;
            min-height: 100vh;
            padding: 0;
            margin: 0;
        }
        
        /* Navigation */
        .topiclaunch-nav {
            background: white;
            padding: 15px 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border-bottom: 1px solid #f0f0f0;
        }
        
        .nav-container {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 20px;
        }
        
        .nav-logo {
            font-size: 24px;
            font-weight: bold;
            color: #FF0000;
            text-decoration: none;
        }
        
        .nav-link {
            color: #333;
            text-decoration: none;
            font-size: 15px;
            font-weight: 500;
            padding: 8px 16px;
            transition: color 0.2s;
        }
        
        .nav-link:hover {
            color: #FF0000;
        }
        
        /* Page Wrapper */
        .page-wrapper {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 60px 20px 40px;
            min-height: calc(100vh - 70px);
        }
        
        /* Page Header */
        .page-header {
            text-align: center;
            margin-bottom: 40px;
            max-width: 600px;
        }
        
        .page-title {
            font-size: 42px;
            font-weight: 700;
            color: #000;
            margin-bottom: 12px;
        }
        
        .page-subtitle {
            font-size: 16px;
            color: #6b7280;
        }
        
        /* Form Container */
        .edit-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            border: 1px solid #f0f0f0;
            padding: 30px;
            width: 100%;
            max-width: 500px;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 6px;
            color: #333;
            font-weight: 500;
            font-size: 13px;
        }
        
        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            transition: border-color 0.3s;
        }
        
        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }
        
        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #FF0000;
        }
        
        .form-group small {
            display: block;
            margin-top: 4px;
            color: #666;
            font-size: 12px;
        }
        
        /* Profile Photo */
        .profile-photo-group {
            margin-bottom: 20px;
        }
        
        .profile-photo-container {
            display: flex;
            gap: 20px;
            align-items: center;
        }
        
        .profile-photo-preview {
            width: 120px;
            height: 120px;
            border: 2px dashed #e5e7eb;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #fafafa;
            flex-shrink: 0;
            overflow: hidden;
        }
        
        .profile-photo-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .profile-photo-upload {
            flex: 1;
        }
        
        .upload-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            background: white;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            color: #374151;
            cursor: pointer;
            transition: all 0.2s;
            margin-bottom: 8px;
        }
        
        .upload-button:hover {
            border-color: #FF0000;
            color: #FF0000;
        }
        
        /* Payout Section */
        .payout-section {
            margin-bottom: 20px;
            padding-top: 10px;
        }
        
        .payout-section-label {
            display: block;
            margin-bottom: 12px;
            color: #000;
            font-weight: 600;
            font-size: 15px;
        }
        
        .payout-fields {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        
        .input-with-prefix {
            position: relative;
            display: flex;
            align-items: center;
        }
        
        .input-prefix {
            position: absolute;
            left: 12px;
            color: #6b7280;
            font-size: 15px;
        }
        
        .input-with-prefix-field {
            padding-left: 28px !important;
        }
        
        /* Password Section */
        .password-section {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
        }
        
        .password-section-label {
            display: block;
            margin-bottom: 12px;
            color: #000;
            font-weight: 600;
            font-size: 15px;
        }
        
        /* Submit Button */
        .submit-btn {
            width: 100%;
            padding: 12px;
            background: #FF0000;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            margin-top: 10px;
        }
        
        .submit-btn:hover {
            background: #CC0000;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(255,0,0,0.3);
        }
        
        /* Messages */
        .error-message {
            background: #fee;
            color: #c33;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            border-left: 4px solid #c33;
        }
        
        .success-message {
            background: #d4edda;
            color: #155724;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            border-left: 4px solid #28a745;
        }
        
        @media (max-width: 600px) {
            .page-title {
                font-size: 32px;
            }
            
            .payout-fields {
                grid-template-columns: 1fr;
            }
            
            .profile-photo-container {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="topiclaunch-nav">
        <div class="nav-container">
            <a href="/" class="nav-logo">TopicLaunch</a>
        </div>
    </nav>

    <div class="page-wrapper">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">Edit Profile</h1>
            <p class="page-subtitle">Update your profile information and settings</p>
        </div>
        
        <div class="edit-container">
            <?php if ($error): ?>
                <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="success-message"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            
            <form method="POST" enctype="multipart/form-data">
                <!-- Profile Photo -->
                <div class="form-group profile-photo-group">
                    <label>Profile Photo</label>
                    <div class="profile-photo-container">
                        <div class="profile-photo-preview" id="photoPreview">
                            <?php if ($creator->profile_image): ?>
                                <img src="../uploads/creators/<?php echo htmlspecialchars($creator->profile_image); ?>" alt="Profile">
                            <?php else: ?>
                                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#9ca3af" stroke-width="2">
                                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                    <circle cx="12" cy="7" r="4"></circle>
                                </svg>
                            <?php endif; ?>
                        </div>
                        <div class="profile-photo-upload">
                            <label for="profile_image" class="upload-button">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                    <polyline points="17 8 12 3 7 8"></polyline>
                                    <line x1="12" y1="3" x2="12" y2="15"></line>
                                </svg>
                                Upload New Photo
                            </label>
                            <input type="file" 
                                   id="profile_image" 
                                   name="profile_image" 
                                   accept="image/jpeg,image/png,image/jpg,image/webp"
                                   style="display: none;">
                            <small>JPG, PNG or WebP. Max 5MB.</small>
                        </div>
                    </div>
                </div>
                
                <!-- YouTube Handle -->
                <div class="form-group">
                    <label for="youtube_handle">YouTube Handle</label>
                    <input type="text" 
                           id="youtube_handle" 
                           name="youtube_handle" 
                           placeholder="@yourchannel"
                           value="<?php echo htmlspecialchars($creator->display_name ?? ''); ?>"
                           required>
                </div>
                
                <!-- Bio -->
                <div class="form-group">
                    <label for="bio">Bio (Optional)</label>
                    <textarea id="bio" 
                              name="bio" 
                              placeholder="Tell fans about yourself and what kind of videos you create..."><?php echo htmlspecialchars($creator->bio ?? ''); ?></textarea>
                </div>
                
                <!-- Minimum Price per Topic -->
                <div class="form-group">
                    <label for="minimum_topic_price">Minimum Price per Topic ($)</label>
                    <input type="number" 
                           id="minimum_topic_price" 
                           name="minimum_topic_price" 
                           min="10"
                           max="10000"
                           step="1"
                           value="<?php echo htmlspecialchars($creator->minimum_topic_price ?? 100); ?>"
                           required>
                    <small>You'll keep 90% of this amount.</small>
                </div>
                
                <!-- Payout Methods -->
                <div class="payout-section">
                    <label class="payout-section-label">Payout Methods</label>
                    
                    <div class="payout-fields">
                        <div class="form-group">
                            <label for="paypal_email">PayPal Email</label>
                            <input type="email" 
                                   id="paypal_email" 
                                   name="paypal_email" 
                                   placeholder="payouts@example.com"
                                   value="<?php echo htmlspecialchars($creator->paypal_email ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="venmo_handle">Venmo Handle</label>
                            <div class="input-with-prefix">
                                <span class="input-prefix">@</span>
                                <input type="text" 
                                       id="venmo_handle" 
                                       name="venmo_handle" 
                                       placeholder="yourhandle"
                                       class="input-with-prefix-field"
                                       value="<?php echo htmlspecialchars($creator->venmo_handle ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Change Password (Optional) -->
                <div class="password-section">
                    <label class="password-section-label">Change Password (Optional)</label>
                    
                    <div class="form-group">
                        <label for="new_password">New Password</label>
                        <input type="password" 
                               id="new_password" 
                               name="new_password" 
                               placeholder="Leave blank to keep current password">
                        <small>Must be at least 8 characters</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password">Confirm New Password</label>
                        <input type="password" 
                               id="confirm_password" 
                               name="confirm_password" 
                               placeholder="Re-enter new password">
                    </div>
                </div>
                
                <button type="submit" class="submit-btn">Save Changes</button>
            </form>
        </div>
    </div>
    
    <script>
    // Profile photo preview
    document.getElementById('profile_image').addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('photoPreview').innerHTML = '<img src="' + e.target.result + '" alt="Preview">';
            };
            reader.readAsDataURL(file);
        }
    });
    
    // Add @ to YouTube handle if not present
    document.getElementById('youtube_handle').addEventListener('blur', function() {
        let value = this.value.trim();
        if (value && !value.startsWith('@')) {
            this.value = '@' + value;
        }
    });
    </script>
</body>
</html>
