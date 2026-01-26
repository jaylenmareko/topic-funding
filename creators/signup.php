<?php
// creators/signup.php - Updated with username field
session_start();

// Redirect if already logged in as verified creator
if (isset($_SESSION['user_id'])) {
    if (file_exists('../config/database.php')) {
        require_once '../config/database.php';
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
        require_once '../config/database.php';
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
        // Validate profile photo
        $allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp'];
        $max_size = 10 * 1024 * 1024; // 10MB
        
        if (!in_array($_FILES['profile_photo']['type'], $allowed_types)) {
            $error = 'Profile photo must be JPG, PNG, or WebP format';
        } elseif ($_FILES['profile_photo']['size'] > $max_size) {
            $error = 'Profile photo must be less than 10MB';
        } else {
            try {
                $db = new Database();
                
                // Check if username exists
                $db->query('SELECT id FROM creators WHERE username = :username');
                $db->bind(':username', $username);
                $existing_username = $db->single();
                
                if ($existing_username) {
                    $error = 'Username already taken. Please choose another.';
                } else {
                    // Check if email exists
                    $db->query('SELECT id FROM users WHERE email = :email');
                    $db->bind(':email', $email);
                    $existing_email = $db->single();
                    
                    if ($existing_email) {
                        $error = 'Email already registered';
                    } else {
                        // Create user account
                        $password_hash = password_hash($password, PASSWORD_DEFAULT);
                        
                        $db->query('INSERT INTO users (username, email, password_hash, is_verified, verified_at, created_at) VALUES (:username, :email, :password_hash, 1, NOW(), NOW())');
                        $db->bind(':username', $username);
                        $db->bind(':email', $email);
                        $db->bind(':password_hash', $password_hash);
                        $db->execute();
                        
                        $user_id = $db->lastInsertId();
                        
                        // Upload profile photo
                        $upload_dir = '../uploads/creators/';
                        if (!file_exists($upload_dir)) {
                            mkdir($upload_dir, 0755, true);
                        }
                        
                        $file_extension = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
                        $profile_filename = 'creator_' . $user_id . '_' . time() . '.' . $file_extension;
                        $profile_path = $upload_dir . $profile_filename;
                        
                        if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $profile_path)) {
                            // Create creator profile
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
                            
                            // Log user in
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
?>
<!DOCTYPE html>
<html>
<head>
    <title>Creator Signup - TopicLaunch - For Creators Who Run It</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700;800&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --hot-pink: #FF006B;
            --deep-pink: #E6005F;
            --black: #000000;
            --white: #FFFFFF;
            --gray-dark: #1A1A1A;
            --gray-med: #666666;
            --gray-light: #E5E5E5;
            --cream: #FAF8F6;
            --off-white: #F5F5F5;
        }
        
        * { 
            margin: 0; 
            padding: 0; 
            box-sizing: border-box; 
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--cream);
            min-height: 100vh;
        }
        
        /* Navigation */
        .nav {
            position: sticky;
            top: 0;
            background: var(--white);
            padding: 16px 0;
            border-bottom: 1px solid var(--gray-light);
            z-index: 100;
        }
        
        .nav-container {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 30px;
        }
        
        .nav-logo {
            font-family: 'Playfair Display', serif;
            font-size: 22px;
            font-weight: 700;
            text-decoration: none;
            letter-spacing: -0.5px;
        }
        
        .nav-logo .topic { color: var(--black); }
        .nav-logo .launch { color: var(--hot-pink); }
        
        .nav-center {
            display: flex;
            gap: 35px;
            align-items: center;
        }
        
        .nav-link {
            color: var(--gray-dark);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: color 0.2s;
        }
        
        .nav-link:hover {
            color: var(--hot-pink);
        }
        
        .nav-buttons {
            display: flex;
            gap: 20px;
            align-items: center;
        }
        
        .nav-login-btn {
            color: var(--gray-dark);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: color 0.2s;
        }
        
        .nav-login-btn:hover {
            color: var(--hot-pink);
        }
        
        .nav-cta-btn {
            background: var(--hot-pink);
            color: var(--white);
            text-decoration: none;
            font-size: 14px;
            font-weight: 700;
            padding: 10px 24px;
            border-radius: 50px;
            transition: all 0.2s;
        }
        
        .nav-cta-btn:hover {
            background: var(--deep-pink);
            transform: translateY(-1px);
        }
        
        /* Page Layout */
        .page-wrapper {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 80px 30px 80px;
            min-height: calc(100vh - 70px);
        }
        
        .page-header {
            text-align: center;
            margin-bottom: 50px;
            max-width: 700px;
        }
        
        .page-eyebrow {
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: var(--hot-pink);
            margin-bottom: 15px;
        }
        
        .page-title {
            font-family: 'Playfair Display', serif;
            font-size: 56px;
            font-weight: 700;
            color: var(--black);
            margin-bottom: 15px;
            line-height: 1.1;
        }
        
        .page-subtitle {
            font-size: 18px;
            color: var(--gray-med);
            line-height: 1.6;
            font-weight: 400;
        }
        
        .signup-container {
            background: var(--white);
            border-radius: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            padding: 40px;
            width: 100%;
            max-width: 480px;
        }
        
        /* Form Styles */
        .form-group {
            margin-bottom: 24px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: var(--black);
            font-weight: 600;
            font-size: 14px;
        }
        
        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid var(--gray-light);
            border-radius: 12px;
            font-size: 15px;
            font-family: 'Inter', sans-serif;
            transition: all 0.2s;
            background: var(--white);
        }
        
        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--hot-pink);
            box-shadow: 0 0 0 4px rgba(255, 0, 107, 0.1);
        }
        
        .form-group textarea {
            resize: vertical;
            min-height: 100px;
            line-height: 1.6;
        }
        
        .form-group small {
            display: block;
            margin-top: 8px;
            color: var(--gray-med);
            font-size: 13px;
            font-weight: 400;
        }
        
        /* Username Field */
        .username-group {
            margin-bottom: 24px;
        }
        
        .username-input-wrapper {
            position: relative;
        }
        
        .username-prefix {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-med);
            font-size: 16px;
            font-weight: 500;
            pointer-events: none;
        }
        
        .username-input {
            padding-left: 40px !important;
            font-size: 16px;
            font-weight: 500;
        }
        
        .username-url-preview {
            margin-top: 10px;
            color: var(--gray-med);
            font-size: 13px;
            font-weight: 400;
        }
        
        .username-url-preview .url-domain {
            color: var(--gray-dark);
        }
        
        .username-url-preview .url-username {
            color: var(--hot-pink);
            font-weight: 600;
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
            border: 2px solid var(--gray-light);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--cream);
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
            gap: 10px;
            padding: 10px 20px;
            background: var(--white);
            border: 2px solid var(--gray-light);
            border-radius: 50px;
            font-size: 14px;
            font-weight: 700;
            color: var(--gray-dark);
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .upload-button:hover {
            border-color: var(--hot-pink);
            color: var(--hot-pink);
        }
        
        /* Payout Section */
        .payout-section-label {
            display: block;
            margin-bottom: 12px;
            color: var(--black);
            font-weight: 600;
            font-size: 14px;
        }
        
        .label-note {
            color: var(--gray-med);
            font-weight: 400;
            font-size: 13px;
        }
        
        .payout-fields {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        
        .payout-field-wrapper label {
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 8px;
            display: block;
            color: var(--gray-dark);
        }
        
        .input-with-prefix {
            position: relative;
        }
        
        .input-prefix {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-med);
            font-size: 15px;
            font-weight: 500;
            pointer-events: none;
        }
        
        .input-with-prefix-field {
            padding-left: 36px !important;
        }
        
        /* Checkbox */
        .checkbox-group {
            margin-bottom: 24px;
        }
        
        .checkbox-label {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            cursor: pointer;
        }
        
        .checkbox-label input[type="checkbox"] {
            width: 20px;
            height: 20px;
            margin-top: 2px;
            cursor: pointer;
            accent-color: var(--hot-pink);
        }
        
        .checkbox-label span {
            flex: 1;
            font-size: 14px;
            color: var(--gray-dark);
            line-height: 1.5;
        }
        
        .checkbox-label a {
            color: var(--hot-pink);
            text-decoration: none;
            font-weight: 600;
        }
        
        .checkbox-label a:hover {
            text-decoration: underline;
        }
        
        /* Messages */
        .error-message {
            background: #FEF2F2;
            color: #DC2626;
            padding: 14px 18px;
            border-radius: 12px;
            margin-bottom: 24px;
            font-size: 14px;
            border-left: 4px solid #DC2626;
            font-weight: 500;
        }
        
        /* Submit Button */
        .submit-btn {
            width: 100%;
            padding: 14px;
            background: var(--hot-pink);
            color: var(--white);
            border: none;
            border-radius: 50px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .submit-btn:hover {
            background: var(--deep-pink);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(255, 0, 107, 0.25);
        }
        
        .submit-btn:active {
            transform: translateY(0);
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .nav-center { 
                display: none; 
            }
            
            .signup-container { 
                padding: 30px 25px; 
            }
            
            .page-title { 
                font-size: 42px; 
            }
            
            .page-wrapper {
                padding: 60px 20px;
            }
            
            .payout-fields { 
                grid-template-columns: 1fr; 
            }
            
            .profile-photo-container {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .profile-photo-preview {
                width: 100px;
                height: 100px;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="nav">
        <div class="nav-container">
            <a href="/" class="nav-logo">
                <span class="topic">Topic</span><span class="launch">Launch</span>
            </a>
            
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
            <?php if ($error): ?>
                <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <form method="POST" enctype="multipart/form-data" id="signupForm">
                <!-- Username Field -->
                <div class="form-group username-group">
                    <label for="username">Username</label>
                    <div class="username-input-wrapper">
                        <span class="username-prefix">@</span>
                        <input type="text" 
                               id="username" 
                               name="username" 
                               class="username-input"
                               placeholder="username"
                               pattern="[a-zA-Z0-9_]{3,30}"
                               value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                               required>
                    </div>
                    <div class="username-url-preview">
                        Your profile: <span class="url-domain">topiclaunch.com/</span><span class="url-username" id="usernamePreview">username</span>
                    </div>
                </div>
                
                <!-- Profile Photo -->
                <div class="form-group">
                    <label for="profile_photo">Profile Photo</label>
                    <div class="profile-photo-container">
                        <div class="profile-photo-preview" id="photoPreview">
                            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#666666" stroke-width="2">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                <circle cx="12" cy="7" r="4"></circle>
                            </svg>
                        </div>
                        <div class="profile-photo-upload">
                            <label for="profile_photo" class="upload-button">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                    <polyline points="17 8 12 3 7 8"></polyline>
                                    <line x1="12" y1="3" x2="12" y2="15"></line>
                                </svg>
                                Upload Photo
                            </label>
                            <input type="file" 
                                   id="profile_photo" 
                                   name="profile_photo" 
                                   accept="image/jpeg,image/png,image/jpg,image/webp"
                                   style="display: none;"
                                   required>
                            <small>JPG, PNG or WebP. Max 10MB.</small>
                        </div>
                    </div>
                </div>
                
                <!-- Bio -->
                <div class="form-group">
                    <label for="bio">Bio (Optional)</label>
                    <textarea id="bio" 
                              name="bio" 
                              placeholder="Tell your audience what you're all about..."><?php echo htmlspecialchars($_POST['bio'] ?? ''); ?></textarea>
                </div>
                
                <!-- Email -->
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" 
                           id="email" 
                           name="email" 
                           placeholder="your@email.com"
                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                           required>
                </div>
                
                <!-- Password -->
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" 
                           id="password" 
                           name="password" 
                           placeholder="Create a secure password"
                           required>
                    <small>Minimum 8 characters</small>
                </div>
                
                <!-- Confirm Password -->
                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <input type="password" 
                           id="confirm_password" 
                           name="confirm_password" 
                           placeholder="Re-enter your password"
                           required>
                </div>
                
                <!-- Minimum Topic Price -->
                <div class="form-group">
                    <label for="minimum_topic_price">Price per Request ($)</label>
                    <input type="number" 
                           id="minimum_topic_price" 
                           name="minimum_topic_price" 
                           placeholder="100"
                           step="1"
                           min="1"
                           value="<?php echo htmlspecialchars($_POST['minimum_topic_price'] ?? ''); ?>"
                           required>
                    <small>You keep 90% of every request. Set what you're worth.</small>
                </div>
                
                <!-- Payout Methods -->
                <div class="form-group">
                    <label class="payout-section-label">Payout Method <span class="label-note">(at least one required)</span></label>
                    
                    <div class="payout-fields">
                        <div class="payout-field-wrapper">
                            <label for="paypal_email">PayPal Email</label>
                            <input type="email" 
                                   id="paypal_email" 
                                   name="paypal_email" 
                                   placeholder="payouts@example.com"
                                   value="<?php echo htmlspecialchars($_POST['paypal_email'] ?? ''); ?>">
                        </div>
                        
                        <div class="payout-field-wrapper">
                            <label for="venmo_handle">Venmo Handle</label>
                            <div class="input-with-prefix">
                                <span class="input-prefix">@</span>
                                <input type="text" 
                                       id="venmo_handle" 
                                       name="venmo_handle" 
                                       placeholder="yourhandle"
                                       class="input-with-prefix-field"
                                       value="<?php echo htmlspecialchars($_POST['venmo_handle'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Terms Checkbox -->
                <div class="checkbox-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="agree_terms" required>
                        <span>I agree to the <a href="/terms.php" target="_blank">Terms of Service</a></span>
                    </label>
                </div>
                
                <button type="submit" class="submit-btn">Create Account</button>
            </form>
        </div>
    </div>
    
    <script>
    // Username preview
    document.getElementById('username').addEventListener('input', function() {
        const username = this.value.trim() || 'username';
        document.getElementById('usernamePreview').textContent = username;
    });
    
    // Profile photo preview
    document.getElementById('profile_photo').addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('photoPreview').innerHTML = '<img src="' + e.target.result + '" alt="Preview">';
            };
            reader.readAsDataURL(file);
        }
    });
    
    // Form validation
    document.getElementById('signupForm').addEventListener('submit', function(e) {
        const password = document.getElementById('password').value;
        const confirmPassword = document.getElementById('confirm_password').value;
        
        if (password !== confirmPassword) {
            e.preventDefault();
            alert('Passwords do not match!');
            return false;
        }
        
        if (password.length < 8) {
            e.preventDefault();
            alert('Password must be at least 8 characters long!');
            return false;
        }
        
        const paypalEmail = document.getElementById('paypal_email').value.trim();
        const venmoHandle = document.getElementById('venmo_handle').value.trim();
        
        if (!paypalEmail && !venmoHandle) {
            e.preventDefault();
            alert('Please provide at least one payout method (PayPal or Venmo)');
            return false;
        }
    });
    </script>
</body>
</html>
