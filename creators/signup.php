<?php
// creators/signup.php - Updated with username field
session_start();

// Redirect if already logged in as verified creator
if (isset($_SESSION['user_id'])) {
    if (file_exists('../config/database.php')) {
        require_once __DIR__ . '/../config/database.php';
    } elseif (file_exists(__DIR__ . '/../config/database.php')) {
        require_once __DIR__ . '/../config/database.php';
    }
    
    try {
        $db = new Database();
        $db->query('SELECT c.id FROM creators c JOIN users u ON c.applicant_user_id = u.id WHERE c.applicant_user_id = :user_id AND c.is_active = 1 AND u.is_verified = 1');
        $db->bind(':user_id', $_SESSION['user_id']);
        $verified_creator = $db->single();
        
        if ($verified_creator) {
            header('Location: dashboard.php');
            exit;
        }
    } catch (Exception $e) {
        // Continue to signup
    }
}

$error = '';
$success = '';

// Handle signup form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (file_exists('../config/database.php')) {
        require_once __DIR__ . '/../config/database.php';
    } elseif (file_exists(__DIR__ . '/../config/database.php')) {
        require_once __DIR__ . '/../config/database.php';
    }
    
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $bio = trim($_POST['bio'] ?? '');
    $minimum_topic_price = trim($_POST['minimum_topic_price'] ?? '');
    $paypal_email = trim($_POST['paypal_email'] ?? '');
    $venmo_handle = trim($_POST['venmo_handle'] ?? '');
    $agree_terms = isset($_POST['agree_terms']);
    $video_topics = isset($_POST['video_topics']) ? $_POST['video_topics'] : [];
    
    // Validation
    if (empty($username) || empty($email) || empty($password) || empty($confirm_password) || empty($minimum_topic_price)) {
        $error = 'All required fields must be filled';
    } elseif (!isset($_FILES['profile_photo']) || $_FILES['profile_photo']['error'] !== 0) {
        $error = 'Profile photo is required';
    } elseif (empty($paypal_email) && empty($venmo_handle)) {
        $error = 'At least one payout method (PayPal or Venmo) is required';
    } elseif (!$agree_terms) {
        $error = 'You must agree to the terms of service';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email format';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long';
    } elseif (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
        $error = 'Username must be 3-30 characters and contain only letters, numbers, and underscores';
    } elseif (!is_numeric($minimum_topic_price) || $minimum_topic_price <= 0) {
        $error = 'Please enter a valid price';
    } else {
        $allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp'];
        $max_size = 10 * 1024 * 1024;
        
        if (!in_array($_FILES['profile_photo']['type'], $allowed_types)) {
            $error = 'Profile photo must be JPG, PNG, or WebP format';
        } elseif ($_FILES['profile_photo']['size'] > $max_size) {
            $error = 'Profile photo must be less than 10MB';
        } else {
            try {
                $db = new Database();
                $db->query('SELECT id FROM creators WHERE username = :username');
                $db->bind(':username', $username);
                $existing_username = $db->single();
                
                if ($existing_username) {
                    $error = 'Username already taken. Please choose another.';
                } else {
                    $db->query('SELECT id FROM users WHERE email = :email');
                    $db->bind(':email', $email);
                    $existing_email = $db->single();
                    
                    if ($existing_email) {
                        $error = 'Email already registered';
                    } else {
                        $password_hash = password_hash($password, PASSWORD_DEFAULT);
                        $db->query('INSERT INTO users (username, email, password_hash, is_verified, verified_at, created_at) VALUES (:username, :email, :password_hash, 1, NOW(), NOW())');
                        $db->bind(':username', $username);
                        $db->bind(':email', $email);
                        $db->bind(':password_hash', $password_hash);
                        $db->execute();
                        $user_id = $db->lastInsertId();
                        
                        $upload_dir = '../uploads/creators/';
                        if (!file_exists($upload_dir)) mkdir($upload_dir, 0755, true);
                        $file_extension = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
                        $profile_filename = 'creator_' . $user_id . '_' . time() . '.' . $file_extension;
                        $profile_path = $upload_dir . $profile_filename;
                        
                        if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $profile_path)) {
                            $video_topics_json = json_encode($video_topics);
                            try {
                                $db->query('INSERT INTO creators (applicant_user_id, username, display_name, profile_image, bio, minimum_topic_price, paypal_email, venmo_handle, video_topics, is_active, created_at) VALUES (:user_id, :username, :display_name, :profile_image, :bio, :minimum_topic_price, :paypal_email, :venmo_handle, :video_topics, 1, NOW())');
                                $db->bind(':user_id', $user_id);
                                $db->bind(':username', $username);
                                $db->bind(':display_name', $username);
                                $db->bind(':profile_image', $profile_filename);
                                $db->bind(':bio', $bio);
                                $db->bind(':minimum_topic_price', floatval($minimum_topic_price));
                                $db->bind(':paypal_email', $paypal_email);
                                $db->bind(':venmo_handle', $venmo_handle);
                                $db->bind(':video_topics', $video_topics_json);
                                $db->execute();
                            } catch (Exception $e) {
                                // Fallback: insert without video_topics if column doesn't exist yet
                                $db->query('INSERT INTO creators (applicant_user_id, username, display_name, profile_image, bio, minimum_topic_price, paypal_email, venmo_handle, is_active, created_at) VALUES (:user_id, :username, :display_name, :profile_image, :bio, :minimum_topic_price, :paypal_email, :venmo_handle, 1, NOW())');
                                $db->bind(':user_id', $user_id);
                                $db->bind(':username', $username);
                                $db->bind(':display_name', $username);
                                $db->bind(':profile_image', $profile_filename);
                                $db->bind(':bio', $bio);
                                $db->bind(':minimum_topic_price', floatval($minimum_topic_price));
                                $db->bind(':paypal_email', $paypal_email);
                                $db->bind(':venmo_handle', $venmo_handle);
                                $db->execute();
                            }
                            
                            $_SESSION['user_id'] = $user_id;
                            $_SESSION['email'] = $email;
                            header('Location: dashboard.php');
                            exit;
                        } else {
                            $error = 'Failed to upload profile photo. Please try again.';
                        }
                    }
                }
            } catch (Exception $e) {
                error_log("Signup error: " . $e->getMessage());
                $error = 'An error occurred during signup. Please try again.';
            }
        }
    }
}

$all_topics = ['Fitness', 'Health', 'Motivation', 'Therapy', 'Dating', 'Business', 'Money', 'Psychology', 'Career', 'Cosmetics', 'Family', 'Technology & AI'];
$selected_topics = isset($_POST['video_topics']) ? $_POST['video_topics'] : [];
?>
<!DOCTYPE html>
<html>
<head>
    <title>Creator Signup - TopicLaunch</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --tl-pink: #E8305A;
            --tl-pink-dark: #B01F3F;
            --tl-black: #111010;
            --tl-card: #1a1a1a;
            --tl-border: #2a2a2a;
            --tl-muted: #888888;
            --white: #ffffff;
            --hot-pink: #E8305A;
            --deep-pink: #B01F3F;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--tl-black);
            color: var(--white);
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }

        /* Nav */
        .nav { position: sticky; top: 0; background: var(--tl-black); border-bottom: 1px solid var(--tl-border); z-index: 100; }
        .nav-container { max-width: 1400px; margin: 0 auto; display: flex; justify-content: space-between; align-items: center; padding: 16px 30px; }
        .nav-logo { font-size: 20px; font-weight: 500; text-decoration: none; letter-spacing: -0.3px; }
        .nav-logo .topic { color: var(--white); }
        .nav-logo .launch { color: var(--tl-pink); }
        .nav-center { display: flex; gap: 24px; align-items: center; }
        .nav-link { color: var(--tl-muted); text-decoration: none; font-size: 13px; font-weight: 500; transition: color 0.2s; }
        .nav-link:hover { color: var(--white); }
        .nav-buttons { display: flex; gap: 12px; align-items: center; }
        .nav-login-btn { color: var(--tl-muted); text-decoration: none; font-size: 13px; font-weight: 500; transition: color 0.2s; }
        .nav-login-btn:hover { color: var(--white); }
        .nav-cta-btn { background: var(--tl-pink); color: var(--white); text-decoration: none; font-size: 13px; font-weight: 500; padding: 8px 18px; border-radius: 8px; transition: background 0.2s; }
        .nav-cta-btn:hover { background: var(--tl-pink-dark); }

        /* Page layout */
        .page-wrapper { display: flex; flex-direction: column; align-items: center; padding: 72px 30px 80px; min-height: calc(100vh - 57px); }
        .page-header { text-align: center; margin-bottom: 40px; max-width: 700px; }
        .page-title { font-size: 40px; font-weight: 600; color: var(--white); margin-bottom: 12px; line-height: 1.15; letter-spacing: -0.6px; }
        .page-subtitle { font-size: 15px; color: var(--tl-muted); line-height: 1.6; }

        /* Card */
        .signup-container { background: var(--tl-card); border: 1px solid var(--tl-border); border-radius: 16px; padding: 36px; width: 100%; max-width: 480px; }

        /* Form fields */
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 7px; color: var(--tl-muted); font-weight: 500; font-size: 11px; text-transform: uppercase; letter-spacing: 0.4px; }
        .form-group input, .form-group textarea {
            width: 100%; padding: 11px 14px; border: 1px solid var(--tl-border); border-radius: 8px;
            font-size: 14px; font-family: 'Inter', sans-serif; transition: border-color 0.2s;
            background: #111; color: var(--white);
        }
        .form-group input:focus, .form-group textarea:focus { outline: none; border-color: rgba(232,48,90,0.5); }
        .form-group input::placeholder, .form-group textarea::placeholder { color: #444; }
        .form-group textarea { resize: vertical; min-height: 90px; line-height: 1.6; }
        .form-group small { display: block; margin-top: 6px; color: #555; font-size: 12px; }

        /* Username */
        .username-group { margin-bottom: 20px; }
        .username-input-wrapper { position: relative; }
        .username-prefix { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--tl-muted); font-size: 15px; font-weight: 500; pointer-events: none; }
        .username-input { padding-left: 30px !important; font-size: 14px; font-weight: 500; }
        .username-url-preview { margin-top: 8px; color: #555; font-size: 12px; }
        .username-url-preview .url-domain { color: #666; }
        .username-url-preview .url-username { color: var(--tl-pink); font-weight: 500; }

        /* Profile photo */
        .profile-photo-container { display: flex; gap: 16px; align-items: center; }
        .profile-photo-preview { width: 80px; height: 80px; border: 1px solid var(--tl-border); border-radius: 12px; display: flex; align-items: center; justify-content: center; background: #111; flex-shrink: 0; overflow: hidden; }
        .profile-photo-preview img { width: 100%; height: 100%; object-fit: cover; }
        .profile-photo-upload { flex: 1; }
        .upload-button { display: inline-flex; align-items: center; gap: 8px; padding: 9px 16px; background: transparent; border: 1px solid var(--tl-border); border-radius: 8px; font-size: 13px; font-weight: 500; color: var(--tl-muted); cursor: pointer; transition: all 0.2s; }
        .upload-button:hover { border-color: var(--tl-pink); color: var(--tl-pink); }

        /* Payout */
        .payout-section-label { display: block; margin-bottom: 10px; color: var(--tl-muted); font-weight: 500; font-size: 11px; text-transform: uppercase; letter-spacing: 0.4px; }
        .label-note { color: #555; font-weight: 400; font-size: 11px; text-transform: none; letter-spacing: 0; }
        .payout-fields { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .payout-field-wrapper label { font-size: 11px; font-weight: 500; margin-bottom: 6px; display: block; color: var(--tl-muted); text-transform: uppercase; letter-spacing: 0.4px; }
        .input-with-prefix { position: relative; }
        .input-prefix { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--tl-muted); font-size: 14px; font-weight: 500; pointer-events: none; }
        .input-with-prefix-field { padding-left: 30px !important; }

        /* Topics */
        .videos-about-section { margin-bottom: 20px; }
        .videos-about-label { display: block; margin-bottom: 4px; color: var(--tl-muted); font-weight: 500; font-size: 11px; text-transform: uppercase; letter-spacing: 0.4px; }
        .videos-about-sublabel { display: block; margin-bottom: 12px; color: #555; font-size: 12px; }
        .topics-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        .topic-checkbox-item { display: flex; align-items: center; gap: 8px; cursor: pointer; }
        .topic-checkbox-item input[type="checkbox"] {
            width: 16px; height: 16px;
            border: 1px solid var(--tl-border); border-radius: 50%;
            appearance: none; -webkit-appearance: none;
            cursor: pointer; flex-shrink: 0; position: relative;
            transition: all 0.2s; background: #111; accent-color: unset;
        }
        .topic-checkbox-item input[type="checkbox"]:checked { background: var(--tl-pink); border-color: var(--tl-pink); }
        .topic-checkbox-item input[type="checkbox"]:checked::after {
            content: ''; position: absolute; left: 3px; top: 1px;
            width: 4px; height: 8px; border: 2px solid white;
            border-top: none; border-left: none; transform: rotate(45deg);
        }
        .topic-checkbox-item span { font-size: 13px; color: #aaa; font-weight: 400; }

        /* Terms checkbox */
        .checkbox-group { margin-bottom: 20px; }
        .checkbox-label { display: flex; align-items: flex-start; gap: 10px; cursor: pointer; }
        .checkbox-label > input[type="checkbox"] { width: 16px; height: 16px; margin-top: 2px; cursor: pointer; accent-color: var(--tl-pink); flex-shrink: 0; }
        .checkbox-label span { flex: 1; font-size: 13px; color: #888; line-height: 1.5; }
        .checkbox-label a { color: var(--tl-pink); text-decoration: none; font-weight: 500; }
        .checkbox-label a:hover { text-decoration: underline; }

        /* Error & submit */
        .error-message { background: rgba(220,38,38,0.1); color: #f87171; padding: 12px 14px; border-radius: 8px; margin-bottom: 20px; font-size: 13px; border-left: 3px solid #DC2626; }
        .submit-btn { width: 100%; padding: 13px; background: var(--tl-pink); color: var(--white); border: none; border-radius: 10px; font-size: 14px; font-weight: 500; cursor: pointer; transition: background 0.2s; font-family: 'Inter', sans-serif; }
        .submit-btn:hover { background: var(--tl-pink-dark); }

        @media (max-width: 768px) {
            .nav-center { display: none; }
            .signup-container { padding: 28px 22px; }
            .page-title { font-size: 30px; }
            .page-wrapper { padding: 56px 20px 60px; }
            .payout-fields { grid-template-columns: 1fr; }
            .profile-photo-container { flex-direction: column; align-items: flex-start; }
        }
    </style>
</head>
<body>
    <nav class="nav">
        <div class="nav-container">
            <a href="/" class="nav-logo"><span class="topic">Topic</span><span class="launch">Launch</span></a>
            <div class="nav-center">
                <a href="/creators/" class="nav-link">Browse Creators</a>
                <a href="/creators/signup.php" class="nav-link">For Creators</a>
            </div>
            <div class="nav-buttons">
                <a href="/auth/login.php" class="nav-login-btn">Log In</a>
                <a href="/creators/signup.php" class="nav-cta-btn">Get Started</a>
            </div>
        </div>
    </nav>
    <div class="page-wrapper">
        <div class="page-header">
            <h1 class="page-title">Start Earning Today</h1>
            <p class="page-subtitle">Set your price. You're the CEO here.</p>
        </div>
        <div class="signup-container">
            <?php if ($error): ?><div class="error-message"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
            <form method="POST" enctype="multipart/form-data" id="signupForm">
                <div class="form-group username-group">
                    <label for="username">Username</label>
                    <div class="username-input-wrapper">
                        <span class="username-prefix">@</span>
                        <input type="text" id="username" name="username" class="username-input" placeholder="username" pattern="[a-zA-Z0-9_]{3,30}" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" required>
                    </div>
                    <div class="username-url-preview">Your profile: <span class="url-domain">topiclaunch.com/</span><span class="url-username" id="usernamePreview">username</span></div>
                </div>
                <div class="form-group">
                    <label for="profile_photo">Profile Photo</label>
                    <div class="profile-photo-container">
                        <div class="profile-photo-preview" id="photoPreview">
                            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#666666" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                        </div>
                        <div class="profile-photo-upload">
                            <label for="profile_photo" class="upload-button">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                                Upload Photo
                            </label>
                            <input type="file" id="profile_photo" name="profile_photo" accept="image/jpeg,image/png,image/jpg,image/webp" style="display: none;">
                            <small>JPG, PNG or WebP. Max 10MB.</small>
                        </div>
                    </div>
                </div>
                <div class="form-group"><label for="bio">Bio (Optional)</label><textarea id="bio" name="bio" placeholder="Tell your audience what you're all about..."><?php echo htmlspecialchars($_POST['bio'] ?? ''); ?></textarea></div>
                <div class="form-group"><label for="email">Email</label><input type="email" id="email" name="email" placeholder="your@email.com" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required></div>
                <div class="form-group"><label for="password">Password</label><input type="password" id="password" name="password" placeholder="Create a secure password" required><small>Minimum 8 characters</small></div>
                <div class="form-group"><label for="confirm_password">Confirm Password</label><input type="password" id="confirm_password" name="confirm_password" placeholder="Re-enter your password" required></div>
                <div class="form-group"><label for="minimum_topic_price">Price per Request ($)</label><input type="number" id="minimum_topic_price" name="minimum_topic_price" placeholder="100" step="1" min="1" value="<?php echo htmlspecialchars($_POST['minimum_topic_price'] ?? ''); ?>" required><small>You keep 90% of every request. Set what you're worth.</small></div>

                <!-- Videos About -->
                <div class="videos-about-section">
                    <label class="videos-about-label">Videos About</label>
                    <span class="videos-about-sublabel">Select all topics you do videos on.</span>
                    <div class="topics-grid">
                        <?php foreach ($all_topics as $topic): ?>
                            <label class="topic-checkbox-item">
                                <input type="checkbox" name="video_topics[]" value="<?php echo htmlspecialchars($topic); ?>"
                                    <?php echo in_array($topic, $selected_topics) ? 'checked' : ''; ?>>
                                <span><?php echo htmlspecialchars($topic); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-group">
                    <label class="payout-section-label">Payout Method <span class="label-note">(at least one required)</span></label>
                    <div class="payout-fields">
                        <div class="payout-field-wrapper"><label for="paypal_email">PayPal Email</label><input type="email" id="paypal_email" name="paypal_email" placeholder="payouts@example.com" value="<?php echo htmlspecialchars($_POST['paypal_email'] ?? ''); ?>"></div>
                        <div class="payout-field-wrapper"><label for="venmo_handle">Venmo Handle</label><div class="input-with-prefix"><span class="input-prefix">@</span><input type="text" id="venmo_handle" name="venmo_handle" placeholder="yourhandle" class="input-with-prefix-field" value="<?php echo htmlspecialchars($_POST['venmo_handle'] ?? ''); ?>"></div></div>
                    </div>
                </div>
                <div class="checkbox-group"><label class="checkbox-label"><input type="checkbox" name="agree_terms" required><span>I agree to the <a href="/terms.php" target="_blank">Terms of Service</a></span></label></div>
                <button type="submit" class="submit-btn">Create Account</button>
            </form>
        </div>
    </div>
    <script>
    document.getElementById('username').addEventListener('input', function() { document.getElementById('usernamePreview').textContent = this.value.trim() || 'username'; });
    document.getElementById('profile_photo').addEventListener('change', function(e) { const file = e.target.files[0]; if (file) { const reader = new FileReader(); reader.onload = function(e) { document.getElementById('photoPreview').innerHTML = '<img src="' + e.target.result + '" alt="Preview">'; }; reader.readAsDataURL(file); } });
    document.getElementById('signupForm').addEventListener('submit', function(e) {
        const password = document.getElementById('password').value;
        const confirmPassword = document.getElementById('confirm_password').value;
        if (password !== confirmPassword) { e.preventDefault(); alert('Passwords do not match!'); return false; }
        if (password.length < 8) { e.preventDefault(); alert('Password must be at least 8 characters long!'); return false; }
        const paypalEmail = document.getElementById('paypal_email').value.trim();
        const venmoHandle = document.getElementById('venmo_handle').value.trim();
        if (!paypalEmail && !venmoHandle) { e.preventDefault(); alert('Please provide at least one payout method (PayPal or Venmo)'); return false; }
    });
    </script>
</body>
</html>
