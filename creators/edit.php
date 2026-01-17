<?php
// creators/edit.php - Updated with username field
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
        $username = trim($_POST['username'] ?? '');
        $bio = trim($_POST['bio'] ?? '');
        $minimum_topic_price = trim($_POST['minimum_topic_price'] ?? '');
        $paypal_email = trim($_POST['paypal_email'] ?? '');
        $venmo_handle = trim($_POST['venmo_handle'] ?? '');
        
        // Validation
        if (empty($username)) {
            $error = 'Username is required';
        } elseif (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
            $error = 'Username must be 3-30 characters and contain only letters, numbers, and underscores';
        } elseif (!is_numeric($minimum_topic_price) || $minimum_topic_price < 10) {
            $error = 'Minimum topic price must be at least $10';
        } elseif ($minimum_topic_price > 10000) {
            $error = 'Minimum topic price cannot exceed $10,000';
        } elseif (!empty($paypal_email) && !filter_var($paypal_email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid PayPal email format';
        } else {
            // Check if username is taken by another creator
            $db->query('SELECT id FROM creators WHERE username = :username AND id != :creator_id');
            $db->bind(':username', $username);
            $db->bind(':creator_id', $creator_id);
            $existing_username = $db->single();
            
            if ($existing_username) {
                $error = 'Username already taken. Please choose another.';
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
                        $db->query('
                            UPDATE creators 
                            SET display_name = :display_name, 
                                username = :username,
                                bio = :bio,
                                profile_image = :profile_image,
                                minimum_topic_price = :minimum_topic_price,
                                paypal_email = :paypal_email,
                                venmo_handle = :venmo_handle
                            WHERE id = :id
                        ');
                        $db->bind(':display_name', $username);
                        $db->bind(':username', $username);
                        $db->bind(':bio', $bio);
                        $db->bind(':profile_image', $profile_image);
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
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: white;
            min-height: 100vh;
        }
        
        /* Navigation */
        .topiclaunch-nav {
            background: white;
            border-bottom: 1px solid #e0e0e0;
            padding: 16px 0;
        }
        
        .nav-container {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 40px;
        }
        
        .nav-logo {
            font-size: 20px;
            font-weight: 700;
            color: #FF0000;
            text-decoration: none;
        }
        
        .nav-links {
            display: flex;
            gap: 24px;
            align-items: center;
        }
        
        .nav-link {
            padding: 8px 20px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            background: white;
            color: #333;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }
        
        .nav-link:hover {
            border-color: #999;
        }
        
        /* Page Layout */
        .page-wrapper {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 60px 20px 40px;
            min-height: calc(100vh - 70px);
        }
        
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
            padding: 40px;
            width: 100%;
            max-width: 520px;
        }
        
        .form-group {
            margin-bottom: 24px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #111827;
            font-weight: 600;
            font-size: 14px;
        }
        
        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            font-size: 15px;
            font-family: inherit;
            transition: all 0.2s;
        }
        
        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #FF0000;
            box-shadow: 0 0 0 3px rgba(255,0,0,0.1);
        }
        
        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }
        
        .form-group small {
            display: block;
            margin-top: 6px;
            color: #6b7280;
            font-size: 13px;
        }
        
        /* Username Field with @ prefix */
        .input-with-prefix {
            position: relative;
        }
        
        .input-prefix {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #6b7280;
            font-size: 16px;
            font-weight: 500;
            pointer-events: none;
        }
        
        .input-with-prefix-field {
            padding-left: 38px !important;
        }
        
        /* Profile Photo */
        .profile-photo-container {
            display: flex;
            gap: 20px;
            align-items: center;
        }
        
        .profile-photo-preview {
            width: 100px;
            height: 100px;
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
            padding: 10px 20px;
            background: white;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            color: #374151;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .upload-button:hover {
            border-color: #FF0000;
            color: #FF0000;
        }
        
        /* Payout Section */
        .payout-section-label {
            display: block;
            margin-bottom: 12px;
            color: #111827;
            font-weight: 600;
            font-size: 14px;
        }
        
        .payout-fields {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        
        /* Password Section */
        .password-section {
            margin-top: 30px;
            padding-top: 24px;
            border-top: 1px solid #e5e7eb;
        }
        
        .password-section-label {
            display: block;
            margin-bottom: 12px;
            color: #111827;
            font-weight: 600;
            font-size: 14px;
        }
        
        /* Submit Button */
        .submit-btn {
            width: 100%;
            padding: 14px;
            background: #FF0000;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            margin-top: 10px;
        }
        
        .submit-btn:hover {
            background: #CC0000;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(255,0,0,0.3);
        }
        
        /* Messages */
        .error-message {
            background: #fef2f2;
            color: #dc2626;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            border-left: 4px solid #dc2626;
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
        
        @media (max-width: 768px) {
            .edit-container { padding: 30px 20px; }
            .page-title { font-size: 32px; }
            .payout-fields { grid-template-columns: 1fr; }
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
            <div class="nav-links">
                <a href="dashboard.php" class="nav-link">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect>
                        <path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path>
                    </svg>
                    Dashboard
                </a>
                <a href="../auth/logout.php" class="nav-link">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"></path>
                    </svg>
                    Log Out
                </a>
            </div>
        </div>
    </nav>

    <div class="page-wrapper">
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
                <div class="form-group">
                    <label>Profile Photo</label>
                    <div class="profile-photo-container">
                        <div class="profile-photo-preview" id="photoPreview">
                            <?php if ($creator->profile_image): ?>
                                <img src="../uploads/creators/<?php echo htmlspecialchars($creator->profile_image); ?>" alt="Profile">
                            <?php else: ?>
                                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#9ca3af" stroke-width="2">
                                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                    <circle cx="12" cy="7" r="4"></circle>
                                </svg>
                            <?php endif; ?>
                        </div>
                        <div class="profile-photo-upload">
                            <label for="profile_image" class="upload-button">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
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
                
                <!-- Username -->
                <div class="form-group">
                    <label for="username">Username</label>
                    <div class="input-with-prefix">
                        <span class="input-prefix">@</span>
                        <input type="text" 
                               id="username" 
                               name="username" 
                               placeholder="username"
                               class="input-with-prefix-field"
                               pattern="[a-zA-Z0-9_]{3,30}"
                               value="<?php echo htmlspecialchars($creator->username ?? ''); ?>"
                               required>
                    </div>
                    <small>3-30 characters. Letters, numbers, and underscores only.</small>
                </div>
                
                <!-- Bio -->
                <div class="form-group">
                    <label for="bio">Bio (Optional)</label>
                    <textarea id="bio" 
                              name="bio" 
                              placeholder="Tell your audience about yourself..."><?php echo htmlspecialchars($creator->bio ?? ''); ?></textarea>
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
                <div class="form-group">
                    <label class="payout-section-label">Payout Methods</label>
                    
                    <div class="payout-fields">
                        <div>
                            <label for="paypal_email" style="font-size: 13px; font-weight: 500; margin-bottom: 6px;">PayPal Email</label>
                            <input type="email" 
                                   id="paypal_email" 
                                   name="paypal_email" 
                                   placeholder="payouts@example.com"
                                   value="<?php echo htmlspecialchars($creator->paypal_email ?? ''); ?>">
                        </div>
                        
                        <div>
                            <label for="venmo_handle" style="font-size: 13px; font-weight: 500; margin-bottom: 6px;">Venmo Handle</label>
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
    </script>
</body>
</html>
