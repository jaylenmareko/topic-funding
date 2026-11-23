<?php
// creators/edit.php - Updated with topic categories
session_start();
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$helper = new DatabaseHelper();
$creator_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$creator_id) { header('Location: dashboard.php'); exit; }

$creator = $helper->getCreatorById($creator_id);
if (!$creator) { header('Location: dashboard.php'); exit; }

$db = new Database();
$db->query('SELECT applicant_user_id FROM creators WHERE id = :id');
$db->bind(':id', $creator_id);
$creator_check = $db->single();

if (!$creator_check || $creator_check->applicant_user_id != $_SESSION['user_id']) {
    header('Location: dashboard.php');
    exit;
}

// Load current user email
$db->query('SELECT email FROM users WHERE id = :user_id');
$db->bind(':user_id', $_SESSION['user_id']);
$current_user = $db->single();
$current_email = $current_user->email ?? '';

$topic_options = ['Fitness', 'Health', 'Motivation', 'Therapy', 'Dating', 'Business', 'Money', 'Psychology', 'Career', 'Cosmetics', 'Family', 'Technology & AI'];
$creator_topics = [];
if (!empty($creator->video_topics)) {
    $decoded = json_decode($creator->video_topics, true);
    if (is_array($decoded)) $creator_topics = $decoded;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $username = trim($_POST['username'] ?? '');
        $bio = mb_substr(trim($_POST['bio'] ?? ''), 0, 100);
        $minimum_topic_price = trim($_POST['minimum_topic_price'] ?? '');
        $paypal_email = trim($_POST['paypal_email'] ?? '');
        $venmo_handle = trim($_POST['venmo_handle'] ?? '');
        $selected_topics = isset($_POST['video_topics']) ? $_POST['video_topics'] : [];
        $topics_json = json_encode(array_filter($selected_topics, fn($t) => in_array($t, $topic_options)));
        $creator_topics = $selected_topics;
        
        if (empty($username)) {
            $error = 'Username is required';
        } elseif (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
            $error = 'Username must be 3-30 characters and contain only letters, numbers, and underscores';
        } elseif (!is_numeric($minimum_topic_price) || $minimum_topic_price <= 0) {
            $error = 'Please enter a valid price';
        } elseif (!empty($paypal_email) && !filter_var($paypal_email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid PayPal email format';
        } else {
            $db->query('SELECT id FROM creators WHERE username = :username AND id != :creator_id');
            $db->bind(':username', $username);
            $db->bind(':creator_id', $creator_id);
            $existing_username = $db->single();
            
            if ($existing_username) {
                $error = 'Username already taken. Please choose another.';
            } else {
                $profile_image = $creator->profile_image;
                $profile_image_data = $creator->profile_image_data ?? null;
                if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
                    $allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp'];
                    $file_type = $_FILES['profile_image']['type'];
                    $file_size = $_FILES['profile_image']['size'];
                    if (!in_array($file_type, $allowed_types)) {
                        $error = 'Only JPG, PNG, and WebP images allowed';
                    } elseif ($file_size > 5 * 1024 * 1024) {
                        $error = 'Image must be under 5MB';
                    } else {
                        try {
                            require_once __DIR__ . '/../config/cloudinary.php';
                            $tmp_path = $_FILES['profile_image']['tmp_name'];
                            $cloudinary_url = cloudinary_upload($tmp_path, 'creator_' . $creator_id . '_' . time());
                            $profile_image      = $cloudinary_url;
                            $profile_image_data = null;
                        } catch (Exception $e) {
                            $error = 'Failed to upload image: ' . $e->getMessage();
                        }
                    }
                }
                
                if (!empty($_POST['new_email'])) {
                    $new_email = trim($_POST['new_email']);
                    if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
                        $error = 'Please enter a valid email address';
                    } else {
                        $db->query('SELECT id FROM users WHERE email = :email AND id != :user_id');
                        $db->bind(':email', $new_email);
                        $db->bind(':user_id', $_SESSION['user_id']);
                        if ($db->single()) {
                            $error = 'That email is already in use by another account';
                        } else {
                            $db->query('UPDATE users SET email = :email WHERE id = :user_id');
                            $db->bind(':email', $new_email);
                            $db->bind(':user_id', $_SESSION['user_id']);
                            $db->execute();
                        }
                    }
                }

                if (!$error && (!empty($_POST['new_password']) || !empty($_POST['confirm_password']))) {
                    $new_password = $_POST['new_password'] ?? '';
                    $confirm_password = $_POST['confirm_password'] ?? '';
                    if (strlen($new_password) < 8) {
                        $error = 'New password must be at least 8 characters';
                    } elseif ($new_password !== $confirm_password) {
                        $error = 'Passwords do not match';
                    } else {
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
                
                if (!$error) {
                    try {
                        $db->query('UPDATE creators SET display_name = :display_name, username = :username, bio = :bio, profile_image = :profile_image, profile_image_data = :profile_image_data, minimum_topic_price = :minimum_topic_price, paypal_email = :paypal_email, venmo_handle = :venmo_handle, video_topics = :video_topics WHERE id = :id');
                        $db->bind(':display_name', $username);
                        $db->bind(':username', $username);
                        $db->bind(':bio', $bio);
                        $db->bind(':profile_image', $profile_image);
                        $db->bind(':profile_image_data', $profile_image_data);
                        $db->bind(':minimum_topic_price', floatval($minimum_topic_price));
                        $db->bind(':paypal_email', $paypal_email);
                        $db->bind(':venmo_handle', $venmo_handle);
                        $db->bind(':video_topics', $topics_json);
                        $db->bind(':id', $creator_id);
                        $db->execute();
                        $success = 'Profile updated successfully!';
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #FAF8F6; min-height: 100vh; color: #111010; }
        .topiclaunch-nav { background: #fff; border-bottom: 1px solid #E5E5E5; padding: 16px 0; box-shadow: 0 1px 4px rgba(0,0,0,0.04); position: sticky; top: 0; z-index: 1000; }
        .nav-container { max-width: 1200px; margin: 0 auto; display: flex; justify-content: space-between; align-items: center; padding: 0 30px; }
        .nav-logo { font-family: 'Inter', sans-serif; font-size: 20px; font-weight: 500; color: #111010; text-decoration: none; letter-spacing: -0.3px; }
        .nav-logo span { color: #E8305A; }
        .nav-links { display: flex; gap: 20px; align-items: center; }
        .nav-link { color: #111010; text-decoration: none; font-size: 13px; font-weight: 500; transition: color 0.2s; }
        .nav-link:hover { color: #E8305A; }
        .page-wrapper { display: flex; flex-direction: column; align-items: center; padding: 56px 20px 48px; min-height: calc(100vh - 70px); }
        .page-header { text-align: center; margin-bottom: 34px; max-width: 620px; }
        .page-title { font-family: 'Inter', sans-serif; font-size: 40px; font-weight: 600; color: #111010; margin-bottom: 10px; letter-spacing: -0.8px; }
        .page-subtitle { font-size: 15px; color: #888; }
        .edit-container { background: #fff; border-radius: 16px; box-shadow: 0 2px 12px rgba(0,0,0,0.06); border: 1px solid #E5E5E5; padding: 36px; width: 100%; max-width: 380px; }
        .form-group { margin-bottom: 22px; }
        .form-group label { display: block; margin-bottom: 8px; color: #888; font-weight: 500; font-size: 11px; text-transform: uppercase; letter-spacing: 0.4px; }
        .form-group input, .form-group textarea { width: 100%; padding: 11px 14px; border: 1px solid #E5E5E5; border-radius: 8px; font-size: 14px; font-family: inherit; transition: all 0.2s; background: #fff; color: #111010; }
        .form-group input:focus, .form-group textarea:focus { outline: none; border-color: #E8305A; box-shadow: 0 0 0 3px rgba(232, 48, 90, 0.08); }
        .form-group textarea { resize: vertical; min-height: 88px; }
        .form-group small { display: block; margin-top: 6px; color: #aaa; font-size: 12px; }
        .input-with-prefix { position: relative; }
        .input-prefix { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #aaa; font-size: 14px; font-weight: 500; pointer-events: none; }
        .input-with-prefix-field { padding-left: 34px !important; }
        .profile-photo-container { display: flex; gap: 18px; align-items: center; }
        .profile-photo-preview { width: 96px; height: 96px; border: 1px solid #E5E5E5; border-radius: 12px; display: flex; align-items: center; justify-content: center; background: #FAF8F6; flex-shrink: 0; overflow: hidden; }
        .profile-photo-preview img { width: 100%; height: 100%; object-fit: cover; }
        .profile-photo-upload { flex: 1; }
        .upload-button { display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; background: #fff; border: 1px solid #E5E5E5; border-radius: 8px; font-size: 13px; font-weight: 500; color: #111010; cursor: pointer; transition: all 0.2s; }
        .upload-button:hover { border-color: #E8305A; color: #E8305A; }
        .password-section { margin-top: 28px; padding-top: 22px; border-top: 1px solid #E5E5E5; }
        .password-section-label { display: block; margin-bottom: 12px; color: #111010; font-weight: 500; font-size: 11px; text-transform: uppercase; letter-spacing: 0.4px; }
        .submit-btn { width: 100%; padding: 13px; background: #E8305A; color: white; border: none; border-radius: 10px; font-size: 14px; font-weight: 500; cursor: pointer; transition: background 0.2s; margin-top: 10px; }
        .submit-btn:hover { background: #B01F3F; }
        .error-message { background: #FEF2F2; color: #DC2626; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 13px; border-left: 3px solid #DC2626; }
        .success-message { background: #F0FDF4; color: #166534; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 13px; border-left: 3px solid #16A34A; }

        .videos-about-section { margin-bottom: 24px; }
        .videos-about-label { display: block; margin-bottom: 4px; color: #111010; font-weight: 500; font-size: 11px; text-transform: uppercase; letter-spacing: 0.4px; }
        .videos-about-sublabel { display: block; margin-bottom: 14px; color: #aaa; font-size: 12px; }
        .topics-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .topic-checkbox-item { display: flex; align-items: center; gap: 10px; cursor: pointer; }
        .topic-checkbox-item input[type="checkbox"] { width: 18px; height: 18px; border: 1px solid #d1d5db; border-radius: 50%; appearance: none; -webkit-appearance: none; cursor: pointer; flex-shrink: 0; position: relative; transition: all 0.2s; background: #fff; }
        .topic-checkbox-item input[type="checkbox"]:checked { background: #E8305A; border-color: #E8305A; }
        .topic-checkbox-item input[type="checkbox"]:checked::after { content: ''; position: absolute; left: 4px; top: 1px; width: 5px; height: 9px; border: 2px solid white; border-top: none; border-left: none; transform: rotate(45deg); }
        .topic-checkbox-item span { font-size: 13px; color: #111010; font-weight: 500; }

        @media (max-width: 768px) { .nav-container { padding: 0 20px; } .page-wrapper { padding: 44px 16px 36px; } .edit-container { padding: 24px 18px; } .page-title { font-size: 30px; } .profile-photo-container { flex-direction: column; align-items: flex-start; } .topics-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <nav class="topiclaunch-nav">
        <div class="nav-container">
            <a href="/" class="nav-logo">TopicLaunch</a>
            <div class="nav-links">
                <a href="dashboard.php" class="nav-link">Dashboard</a>
                <a href="../auth/logout.php" class="nav-link">Log Out</a>
            </div>
        </div>
    </nav>

    <div class="page-wrapper">
        <div class="page-header">
            <h1 class="page-title">Edit Profile</h1>
            <p class="page-subtitle">Update your profile information and settings</p>
        </div>
        
        <div class="edit-container">
            <?php if ($error): ?><div class="error-message"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
            <?php if ($success): ?><div class="success-message"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
            
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label>Profile Photo</label>
                    <div class="profile-photo-container">
                        <div class="profile-photo-preview" id="photoPreview">
                            <?php $edit_img_src = $creator->profile_image_data ?: ($creator->profile_image ? '../uploads/creators/' . $creator->profile_image : ''); ?>
                            <?php if ($edit_img_src): ?>
                                <img src="<?php echo htmlspecialchars($edit_img_src); ?>" alt="Profile">
                            <?php else: ?>
                                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#9ca3af" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                            <?php endif; ?>
                        </div>
                        <div class="profile-photo-upload">
                            <label for="profile_image" class="upload-button">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                                Upload New Photo
                            </label>
                            <input type="file" id="profile_image" name="profile_image" accept="image/jpeg,image/png,image/jpg,image/webp" style="display: none;">
                            <small>JPG, PNG or WebP. Max 5MB.</small>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="username">Username</label>
                    <div class="input-with-prefix">
                        <span class="input-prefix">@</span>
                        <input type="text" id="username" name="username" placeholder="username" class="input-with-prefix-field" pattern="[a-zA-Z0-9_]{3,30}" value="<?php echo htmlspecialchars($creator->username ?? ''); ?>" required>
                    </div>
                    <small>3-30 characters. Letters, numbers, and underscores only.</small>
                </div>

                <!-- Videos About -->
                <div class="videos-about-section">
                    <label class="videos-about-label">Videos About</label>
                    <span class="videos-about-sublabel">Select all topics you do videos on.</span>
                    <div class="topics-grid">
                        <?php foreach ($topic_options as $topic): ?>
                            <label class="topic-checkbox-item">
                                <input type="checkbox" name="video_topics[]" value="<?php echo htmlspecialchars($topic); ?>"
                                    <?php echo in_array($topic, $creator_topics) ? 'checked' : ''; ?>>
                                <span><?php echo htmlspecialchars($topic); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="bio">Bio <span style="font-weight:400; color:#aaa;">(Optional)</span></label>
                    <div style="position:relative;">
                        <textarea id="bio" name="bio" placeholder="Tell your audience about yourself..." maxlength="100" style="padding-bottom:22px;"><?php echo htmlspecialchars($creator->bio ?? ''); ?></textarea>
                        <span id="bioCount" style="position:absolute; bottom:8px; right:10px; font-size:10px; color:#aaa; pointer-events:none; z-index:2; background:rgba(255,255,255,0.9); padding:1px 3px; border-radius:3px;"></span>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="minimum_topic_price">Minimum Price per Topic ($)</label>
                    <input type="number" id="minimum_topic_price" name="minimum_topic_price" step="1" value="<?php echo htmlspecialchars($creator->minimum_topic_price ?? 100); ?>" required>
                    <small>You'll keep 90% of this amount.</small>
                </div>
                
                <div class="password-section">
                    <label class="password-section-label">Change Email (Optional)</label>
                    <div class="form-group">
                        <label for="new_email">New Email</label>
                        <input type="email" id="new_email" name="new_email" placeholder="<?php echo htmlspecialchars($current_email); ?>">
                        <small>Leave blank to keep your current email.</small>
                    </div>
                </div>

                <div class="password-section">
                    <label class="password-section-label">Change Password (Optional)</label>
                    <div class="form-group">
                        <label for="new_password">New Password</label>
                        <input type="password" id="new_password" name="new_password" placeholder="Leave blank to keep current password">
                        <small>Must be at least 8 characters</small>
                    </div>
                    <div class="form-group">
                        <label for="confirm_password">Confirm New Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" placeholder="Re-enter new password">
                    </div>
                </div>
                
                <button type="submit" class="submit-btn">Save Changes</button>
            </form>
        </div>
    </div>
    
    <script>
    document.getElementById('profile_image').addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) { document.getElementById('photoPreview').innerHTML = '<img src="' + e.target.result + '" alt="Preview">'; };
            reader.readAsDataURL(file);
        }
    });
    </script>
    <script>
    (function() {
        const bio = document.getElementById('bio');
        const bioCount = document.getElementById('bioCount');
        function update() { bioCount.textContent = bio.value.length + '/100'; }
        bio.addEventListener('input', update);
        update();
    })();
    </script>
</body>
</html>
