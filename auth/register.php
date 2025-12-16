<?php
// auth/register.php - CREATOR REGISTRATION ONLY
session_start();
require_once '../config/database.php';
require_once '../config/csrf.php';
require_once '../config/sanitizer.php';

// Check if already logged in
if (isset($_SESSION['user_id'])) {
    $db = new Database();
    $db->query('SELECT id FROM creators WHERE applicant_user_id = :user_id AND is_active = 1');
    $db->bind(':user_id', $_SESSION['user_id']);
    $is_creator = $db->single();
    
    if ($is_creator) {
        header('Location: ../creators/dashboard.php');
        exit;
    }
}

$helper = new DatabaseHelper();
$errors = [];

if ($_POST) {
    // CSRF Protection
    CSRFProtection::requireValidToken();
    
    // Get platform type
    $platform_type = trim($_POST['platform_type'] ?? 'youtube');
    
    // Get platform handle
    $platform_handle = trim($_POST['platform_handle'] ?? '');
    
    // Remove @ if user included it
    if (strpos($platform_handle, '@') === 0) {
        $platform_handle = substr($platform_handle, 1);
    }
    
    // Trim again after @ removal
    $platform_handle = trim($platform_handle);
    
    // Use platform handle as username
    $username = $platform_handle;
    
    // Get PayPal email
    $paypal_email = trim(InputSanitizer::sanitizeEmail($_POST['paypal_email'] ?? ''));
    
    // Handle profile image upload
    $profile_image = null;
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/creators/';
        
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
        $file_type = $_FILES['profile_image']['type'];
        $file_size = $_FILES['profile_image']['size'];
        
        if (!in_array($file_type, $allowed_types)) {
            $errors[] = "Only JPG, PNG, and GIF images are allowed";
        } elseif ($file_size > 2 * 1024 * 1024) {
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
    
    $email = InputSanitizer::sanitizeEmail($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Platform handle validation
    if (empty($platform_handle)) {
        $errors[] = "Platform handle is required";
    } elseif (strlen($platform_handle) < 3) {
        $errors[] = "Platform handle must be at least 3 characters";
    } elseif (!preg_match('/^[a-zA-Z0-9_.-]+$/', $platform_handle)) {
        $errors[] = "Platform handle can only contain letters, numbers, dots, dashes, and underscores";
    } elseif (preg_match('/^[0-9._-]+$/', $platform_handle)) {
        $errors[] = "Platform handle must contain at least one letter";
    } else {
        // Check if this platform handle + platform type combo already exists
        $db = new Database();
        $db->query('SELECT id FROM creators WHERE platform_type = :platform_type AND platform_url LIKE :platform_url');
        $db->bind(':platform_type', $platform_type);
        
        $platform_urls = [
            'youtube' => '%youtube.com/@' . $platform_handle . '%',
            'instagram' => '%instagram.com/' . $platform_handle . '%',
            'tiktok' => '%tiktok.com/@' . $platform_handle . '%'
        ];
        $check_url = $platform_urls[$platform_type] ?? '%' . $platform_handle . '%';
        
        $db->bind(':platform_url', $check_url);
        $existing_creator = $db->single();
        
        if ($existing_creator) {
            $errors[] = "This " . ucfirst($platform_type) . " handle is already registered on TopicLaunch";
        }
    }
    
    // Verify platform handle exists (only if no errors so far)
    if (empty($errors) && $platform_type === 'youtube') {
        $platform_url = "https://www.youtube.com/@" . $platform_handle;
        $headers = @get_headers($platform_url);
        if (!$headers || strpos($headers[0], '200') === false) {
            $errors[] = "YouTube handle '@{$platform_handle}' does not exist. Please enter a valid YouTube handle.";
        }
    }
    
    // PayPal email validation
    if (empty($paypal_email)) {
        $errors[] = "PayPal email is required for payments";
    } elseif (!filter_var($paypal_email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid PayPal email address";
    }
    
    // Email validation
    if (empty($email)) {
        $errors[] = "Email is required";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format";
    } elseif ($helper->emailExists($email)) {
        $errors[] = "Email already registered";
    }
    
    // Password validation
    if (empty($password)) {
        $errors[] = "Password is required";
    } elseif (!InputSanitizer::validatePassword($password)) {
        $errors[] = "Password must be at least 8 characters with at least one letter and one number";
    }
    
    if ($password !== $confirm_password) {
        $errors[] = "Passwords do not match";
    }
    
    // If no errors, create account
    if (empty($errors)) {
        try {
            $db = new Database();
            $db->beginTransaction();
            
            // Create user account
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $user_id = $helper->createUser($username, $email, $password_hash, $username);
            
            if (!$user_id) {
                throw new Exception("Failed to create user account");
            }
            
            // Generate creator username
            $creator_username = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $username)) . '_' . $user_id;
            
            // Construct platform URL
            $platform_urls = [
                'youtube' => 'https://youtube.com/@' . $platform_handle,
                'instagram' => 'https://instagram.com/' . $platform_handle,
                'tiktok' => 'https://tiktok.com/@' . $platform_handle
            ];
            
            $platform_url = $platform_urls[$platform_type] ?? 'https://youtube.com/@' . $platform_handle;
            
            // Create creator profile
            $db->query('
                INSERT INTO creators (
                    username, display_name, email, bio, platform_type, platform_url, 
                    subscriber_count, default_funding_threshold, commission_rate, 
                    is_verified, is_active, applicant_user_id, application_status,
                    manual_payout_threshold, paypal_email
                ) VALUES (
                    :username, :display_name, :email, :bio, :platform_type, :platform_url,
                    :subscriber_count, :default_funding_threshold, :commission_rate,
                    :is_verified, :is_active, :applicant_user_id, :application_status,
                    :manual_payout_threshold, :paypal_email
                )
            ');
            
            $db->bind(':username', $creator_username);
            $db->bind(':display_name', $username);
            $db->bind(':email', $email);
            $db->bind(':bio', ucfirst($platform_type) . ' Creator on TopicLaunch');
            $db->bind(':platform_type', $platform_type);
            $db->bind(':platform_url', $platform_url);
            $db->bind(':subscriber_count', 1000);
            $db->bind(':default_funding_threshold', 50.00);
            $db->bind(':commission_rate', 5.00);
            $db->bind(':is_verified', 1);
            $db->bind(':is_active', 1);
            $db->bind(':applicant_user_id', $user_id);
            $db->bind(':application_status', 'approved');
            $db->bind(':manual_payout_threshold', 100.00);
            $db->bind(':paypal_email', $paypal_email);
            $db->execute();
            
            $creator_id = $db->lastInsertId();
            
            // Update profile image if uploaded
            if ($profile_image) {
                $file_extension = pathinfo($profile_image, PATHINFO_EXTENSION);
                $final_filename = 'creator_' . $creator_id . '_' . time() . '.' . $file_extension;
                $old_path = '../uploads/creators/' . $profile_image;
                $new_path = '../uploads/creators/' . $final_filename;
                
                if (rename($old_path, $new_path)) {
                    $db->query('UPDATE creators SET profile_image = :profile_image WHERE id = :id');
                    $db->bind(':profile_image', $final_filename);
                    $db->bind(':id', $creator_id);
                    $db->execute();
                }
            }
            
            $db->endTransaction();
            
            // Auto-login
            $_SESSION['user_id'] = $user_id;
            $_SESSION['username'] = $username;
            $_SESSION['full_name'] = $username;
            $_SESSION['email'] = $email;
            
            session_regenerate_id(true);
            
            header('Location: ../creators/dashboard.php');
            exit;
            
        } catch (Exception $e) {
            $db->cancelTransaction();
            $errors[] = "Registration failed: " . $e->getMessage();
            error_log("Creator registration error: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Creator Registration - TopicLaunch</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; background: #f5f5f5; }
        
        .nav {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 15px 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .nav-container {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 0 20px;
        }
        .nav-logo {
            font-size: 24px;
            font-weight: bold;
            color: white;
            text-decoration: none;
        }
        
        .container { max-width: 500px; margin: 0 auto; padding: 20px; }
        .header { text-align: center; margin-bottom: 30px; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .header h1 { margin: 0 0 10px 0; color: #333; }
        
        .form-container { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: bold; color: #333; }
        input[type="text"], input[type="email"], input[type="password"], input[type="file"], select { 
            width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 6px; box-sizing: border-box; font-size: 16px;
        }
        select { cursor: pointer; }
        .btn { background: #667eea; color: white; padding: 15px 30px; border: none; border-radius: 6px; cursor: pointer; font-size: 16px; width: 100%; font-weight: bold; }
        .btn:hover { opacity: 0.9; }
        .btn:disabled { background: #6c757d; cursor: not-allowed; opacity: 0.6; }
        .error { color: red; margin-bottom: 15px; padding: 12px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 6px; }
        .requirement { color: #666; font-size: 12px; margin-top: 5px; }
        .requirement.valid { color: #28a745; }
        .requirement.invalid { color: #dc3545; }
        
        .platform-handle-group { position: relative; }
        .platform-at-symbol { 
            position: absolute; 
            left: 0px; 
            top: 0px; 
            background: #f8f9fa; 
            padding: 12px; 
            border-radius: 6px 0 0 6px; 
            border: 1px solid #ddd; 
            border-right: none; 
            color: #666; 
            font-weight: bold;
            z-index: 2;
        }
        .platform-handle-input { 
            padding-left: 45px !important; 
            position: relative;
            z-index: 1;
        }
        
        .platform-select-group {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .platform-option {
            flex: 1;
            min-width: 80px;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 8px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
        }
        .platform-option:hover {
            border-color: #667eea;
            background: #f8f9ff;
        }
        .platform-option.selected {
            border-color: #667eea;
            background: #667eea;
            color: white;
        }
        .platform-option input[type="radio"] {
            display: none;
        }
        .platform-logo {
            width: 32px;
            height: 32px;
            margin: 0 auto 5px;
        }
        .platform-name {
            font-size: 12px;
            font-weight: 600;
        }
        
        .login-link { text-align: center; margin-top: 20px; }
        .login-link a { color: #007bff; text-decoration: none; }
        .login-link a:hover { text-decoration: underline; }
        
        @media (max-width: 600px) {
            .container { padding: 15px; }
            .header, .form-container { padding: 20px; }
            .platform-option {
                min-width: 70px;
            }
        }
    </style>
</head>
<body>
    <nav class="nav">
        <div class="nav-container">
            <a href="../index.php" class="nav-logo">TopicLaunch</a>
        </div>
    </nav>

    <div class="container">
        <div class="header">
            <h1>Creator Registration</h1>
        </div>
        
        <?php if (!empty($errors)): ?>
            <?php foreach ($errors as $error): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endforeach; ?>
        <?php endif; ?>
        
        <div class="form-container">
            <form method="POST" id="registrationForm" enctype="multipart/form-data">
                <?php echo CSRFProtection::getTokenField(); ?>
                
                <!-- Platform Selection -->
                <div class="form-group">
                    <label>Choose Your Platform:</label>
                    <div class="platform-select-group">
                        <label class="platform-option selected" data-platform="youtube">
                            <input type="radio" name="platform_type" value="youtube" checked>
                            <div class="platform-logo">
                                <svg viewBox="0 0 24 24" fill="#FF0000">
                                    <path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/>
                                </svg>
                            </div>
                            <div class="platform-name">YouTube</div>
                        </label>
                        
                        <label class="platform-option" data-platform="instagram">
                            <input type="radio" name="platform_type" value="instagram">
                            <div class="platform-logo">
                                <svg viewBox="0 0 24 24" fill="url(#instagram-gradient)">
                                    <defs>
                                        <radialGradient id="instagram-gradient" cx="30%" cy="107%" r="150%">
                                            <stop offset="0%" style="stop-color:#fdf497" />
                                            <stop offset="5%" style="stop-color:#fdf497" />
                                            <stop offset="45%" style="stop-color:#fd5949" />
                                            <stop offset="60%" style="stop-color:#d6249f" />
                                            <stop offset="90%" style="stop-color:#285AEB" />
                                        </radialGradient>
                                    </defs>
                                    <path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/>
                                </svg>
                            </div>
                            <div class="platform-name">Instagram</div>
                        </label>
                        
                        <label class="platform-option" data-platform="tiktok">
                            <input type="radio" name="platform_type" value="tiktok">
                            <div class="platform-logo">
                                <svg viewBox="0 0 24 24" fill="none">
                                    <path d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 0 1-5.2 1.74 2.89 2.89 0 0 1 2.31-4.64 2.93 2.93 0 0 1 .88.13V9.4a6.84 6.84 0 0 0-1-.05A6.33 6.33 0 0 0 5 20.1a6.34 6.34 0 0 0 10.86-4.43v-7a8.16 8.16 0 0 0 4.77 1.52v-3.4a4.85 4.85 0 0 1-1-.1z" fill="#00F2EA"/>
                                    <path d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 0 1-5.2 1.74 2.89 2.89 0 0 1 2.31-4.64 2.93 2.93 0 0 1 .88.13V9.4a6.84 6.84 0 0 0-1-.05A6.33 6.33 0 0 0 5 20.1a6.34 6.34 0 0 0 10.86-4.43v-7a8.16 8.16 0 0 0 4.77 1.52v-3.4a4.85 4.85 0 0 1-1-.1z" fill="#FF004F"/>
                                </svg>
                            </div>
                            <div class="platform-name">TikTok</div>
                        </label>
                    </div>
                </div>

                <!-- Profile Image -->
                <div class="form-group">
                    <label for="profile_image">Profile Image (Optional):</label>
                    <input type="file" id="profile_image" name="profile_image" accept="image/*">
                    <div class="requirement">JPG, PNG, or GIF. Max 2MB. Can add later.</div>
                </div>

                <!-- Platform Handle -->
                <div class="form-group">
                    <label id="platform-handle-label">YouTube Handle:</label>
                    <div class="platform-handle-group">
                        <span class="platform-at-symbol">@</span>
                        <input type="text" name="platform_handle" id="platform_handle" class="platform-handle-input"
                               value="<?php echo isset($_POST['platform_handle']) ? htmlspecialchars($_POST['platform_handle']) : ''; ?>" 
                               required pattern="[a-zA-Z0-9_.-]*[a-zA-Z]+[a-zA-Z0-9_.-]*"
                               placeholder="MrBeast">
                    </div>
                    <div class="requirement" id="platform-handle-hint">Example: MrBeast, PewDiePie, etc. Must contain at least one letter.</div>
                </div>

                <!-- PayPal Email -->
                <div class="form-group">
                    <label>PayPal Email (for payments):</label>
                    <input type="email" name="paypal_email" id="paypal_email" 
                           value="<?php echo isset($_POST['paypal_email']) ? htmlspecialchars($_POST['paypal_email']) : ''; ?>" 
                           required placeholder="your-paypal@email.com">
                </div>
                
                <div class="form-group">
                    <label>Email:</label>
                    <input type="email" name="email" id="email" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" required>
                </div>
                
                <div class="form-group">
                    <label>Password:</label>
                    <input type="password" name="password" id="password" required minlength="8">
                    <div id="password-requirements" style="margin-top: 8px;">
                        <div class="requirement" id="length-req">• At least 8 characters</div>
                        <div class="requirement" id="letter-req">• At least one letter</div>
                        <div class="requirement" id="number-req">• At least one number</div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Confirm Password:</label>
                    <input type="password" name="confirm_password" id="confirm_password" required>
                </div>
                
                <button type="submit" class="btn" id="submitBtn">Create Creator Account</button>
            </form>
            
            <div class="login-link">
                <a href="login.php">Already have an account? Login here</a>
            </div>
        </div>
    </div>

    <script>
    // Platform selection logic
    document.querySelectorAll('.platform-option').forEach(option => {
        option.addEventListener('click', function() {
            document.querySelectorAll('.platform-option').forEach(opt => opt.classList.remove('selected'));
            this.classList.add('selected');
            this.querySelector('input[type="radio"]').checked = true;
            
            const platform = this.dataset.platform;
            const label = document.getElementById('platform-handle-label');
            const input = document.getElementById('platform_handle');
            const hint = document.getElementById('platform-handle-hint');
            
            const platformData = {
                youtube: {
                    label: 'YouTube Handle:',
                    placeholder: 'MrBeast',
                    hint: 'Example: MrBeast, PewDiePie, etc. Must contain at least one letter.'
                },
                instagram: {
                    label: 'Instagram Username:',
                    placeholder: 'cristiano',
                    hint: 'Example: cristiano, selenagomez, etc.'
                },
                tiktok: {
                    label: 'TikTok Username:',
                    placeholder: 'charlidamelio',
                    hint: 'Example: charlidamelio, khaby.lame, etc.'
                }
            };
            
            const data = platformData[platform];
            label.textContent = data.label;
            input.placeholder = data.placeholder;
            hint.textContent = data.hint;
        });
    });

    // Password validation
    const password = document.getElementById('password');
    const confirmPassword = document.getElementById('confirm_password');
    const lengthReq = document.getElementById('length-req');
    const letterReq = document.getElementById('letter-req');
    const numberReq = document.getElementById('number-req');
    const submitBtn = document.getElementById('submitBtn');

    function validatePassword() {
        const pwd = password.value;
        const confirmPwd = confirmPassword.value;
        
        // Length check
        if (pwd.length >= 8) {
            lengthReq.classList.add('valid');
            lengthReq.classList.remove('invalid');
        } else {
            lengthReq.classList.add('invalid');
            lengthReq.classList.remove('valid');
        }
        
        // Letter check
        if (/[A-Za-z]/.test(pwd)) {
            letterReq.classList.add('valid');
            letterReq.classList.remove('invalid');
        } else {
            letterReq.classList.add('invalid');
            letterReq.classList.remove('valid');
        }
        
        // Number check
        if (/[0-9]/.test(pwd)) {
            numberReq.classList.add('valid');
            numberReq.classList.remove('invalid');
        } else {
            numberReq.classList.add('invalid');
            numberReq.classList.remove('valid');
        }
        
        const passwordsMatch = confirmPwd && pwd === confirmPwd;
        const email = document.getElementById('email').value.trim();
        const emailValid = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/.test(email);
        const handle = document.getElementById('platform_handle').value.trim();
        const paypalEmail = document.getElementById('paypal_email').value.trim();
        const paypalValid = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/.test(paypalEmail);
        
        const isValid = pwd.length >= 8 && /[A-Za-z]/.test(pwd) && /[0-9]/.test(pwd) && passwordsMatch && handle.length >= 3 && /[a-zA-Z]/.test(handle) && emailValid && paypalValid;
        
        submitBtn.disabled = !isValid;
        submitBtn.style.opacity = isValid ? '1' : '0.6';
    }

    password.addEventListener('input', validatePassword);
    confirmPassword.addEventListener('input', validatePassword);
    document.getElementById('email').addEventListener('input', validatePassword);
    document.getElementById('paypal_email').addEventListener('input', validatePassword);
    
    // Platform handle validation
    document.getElementById('platform_handle').addEventListener('input', function() {
        let value = this.value.trim();
        
        if (value.startsWith('@')) {
            value = value.substring(1);
        }
        
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
        
        if (this.value !== value) {
            this.value = value;
        }
        
        const isValid = value.length >= 3 && /[a-zA-Z]/.test(value) && /^[a-zA-Z0-9_.-]+$/.test(value);
        this.style.borderColor = value ? (isValid ? '#28a745' : '#dc3545') : '#ddd';
        
        validatePassword();
    });
    
    // Form submission
    document.getElementById('registrationForm').addEventListener('submit', function() {
        submitBtn.innerHTML = '⏳ Creating Account...';
        submitBtn.disabled = true;
    });
    
    validatePassword();
    </script>
</body>
</html>
