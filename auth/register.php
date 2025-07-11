<?php
// auth/register.php - Updated to redirect fans to creators page for faster transactions
session_start();
require_once '../config/database.php';
require_once '../config/csrf.php';
require_once '../config/sanitizer.php';

// Redirect if already logged in - Check if they're a creator first
if (isset($_SESSION['user_id'])) {
    $db = new Database();
    $db->query('SELECT id FROM creators WHERE applicant_user_id = :user_id AND is_active = 1');
    $db->bind(':user_id', $_SESSION['user_id']);
    $is_creator = $db->single();
    
    if ($is_creator) {
        header('Location: ../dashboard/index.php'); // Creators go to dashboard
    } else {
        header('Location: ../creators/index.php'); // Fans go to browse creators
    }
    exit;
}

$helper = new DatabaseHelper();
$errors = [];
$user_type = $_GET['type'] ?? 'fan'; // 'creator' or 'fan'

if ($_POST) {
    // CSRF Protection
    CSRFProtection::requireValidToken();
    
    if ($user_type === 'creator') {
        // For creators, use YouTube handle instead of username
        $youtube_handle = trim($_POST['youtube_handle'] ?? '');
        
        // Remove @ if user included it
        if (strpos($youtube_handle, '@') === 0) {
            $youtube_handle = substr($youtube_handle, 1);
        }
        
        // Additional cleanup - trim again after @ removal
        $youtube_handle = trim($youtube_handle);
        
        // Use YouTube handle as username
        $username = $youtube_handle;
        
        // Handle profile image upload for creators
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
    } else {
        // For fans, use regular username
        $username = trim(InputSanitizer::sanitizeString($_POST['username']));
    }
    
    $email = InputSanitizer::sanitizeEmail($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Validation
    if ($user_type === 'creator') {
        // YouTube handle validation
        if (empty($youtube_handle)) {
            $errors[] = "YouTube handle is required";
        } elseif (strlen($youtube_handle) < 3) {
            $errors[] = "YouTube handle must be at least 3 characters";
        } elseif (!preg_match('/^[a-zA-Z0-9_.-]+$/', $youtube_handle)) {
            $errors[] = "YouTube handle can only contain letters, numbers, dots, dashes, and underscores";
        } elseif (preg_match('/^[0-9._-]+$/', $youtube_handle)) {
            $errors[] = "YouTube handle must contain at least one letter";
        } elseif ($helper->usernameExists($username)) {
            $errors[] = "YouTube handle already exists";
        } else {
            // Verify YouTube handle exists
            $youtube_url = "https://www.youtube.com/@" . $youtube_handle;
            $headers = @get_headers($youtube_url);
            if (!$headers || strpos($headers[0], '200') === false) {
                $errors[] = "YouTube handle '@{$youtube_handle}' does not exist. Please enter a valid YouTube handle.";
            }
        }
    } else {
        // Regular username validation for fans
        if (empty($username)) {
            $errors[] = "Username is required";
        } elseif (strlen($username) < 3) {
            $errors[] = "Username must be at least 3 characters";
        } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            $errors[] = "Username can only contain letters, numbers, and underscores";
        } elseif ($helper->usernameExists($username)) {
            $errors[] = "Username already exists";
        }
    }
    
    if (empty($email)) {
        $errors[] = "Email is required";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format";
    } elseif ($helper->emailExists($email)) {
        $errors[] = "Email already registered";
    } else {
        // Verify email domain actually exists
        $domain = substr(strrchr($email, "@"), 1);
        
        // Check if domain has valid format first
        if (!preg_match('/^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $domain)) {
            $errors[] = "Invalid email domain format";
        } else {
            $domain_exists = false;
            
            // Method 1: Check MX records (most reliable for email)
            if (function_exists('checkdnsrr')) {
                if (checkdnsrr($domain, "MX")) {
                    $domain_exists = true;
                }
                // Method 2: Check A records if no MX
                elseif (checkdnsrr($domain, "A")) {
                    $domain_exists = true;
                }
                // Method 3: Check AAAA records (IPv6)
                elseif (checkdnsrr($domain, "AAAA")) {
                    $domain_exists = true;
                }
            }
            
            // Fallback method: gethostbyname
            if (!$domain_exists && function_exists('gethostbyname')) {
                $ip = gethostbyname($domain);
                if ($ip !== $domain && filter_var($ip, FILTER_VALIDATE_IP)) {
                    $domain_exists = true;
                }
            }
            
            // If all methods fail, domain doesn't exist
            if (!$domain_exists) {
                $errors[] = "The email domain '{$domain}' does not exist. Please check your email address and try again.";
            }
        }
    }
    
    if (empty($password)) {
        $errors[] = "Password is required";
    } elseif (!InputSanitizer::validatePassword($password)) {
        $errors[] = "Password must be at least 8 characters with at least one letter and one number";
    }
    
    if ($password !== $confirm_password) {
        $errors[] = "Passwords do not match";
    }
    
    // If no errors, handle based on user type
    if (empty($errors)) {
        if ($user_type === 'creator') {
            // For creators: Create account and creator profile immediately
            try {
                $db = new Database();
                $db->beginTransaction();
                
                // Create user account
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $user_id = $helper->createUser($username, $email, $password_hash, $username);
                
                if (!$user_id) {
                    throw new Exception("Failed to create user account");
                }
                
                // Generate creator username from user username
                $creator_username = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $username)) . '_' . $user_id;
                
                // Construct YouTube URL with @username format
                $platform_url = 'https://youtube.com/@' . $youtube_handle;
                
                // Create creator profile immediately
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
                
                $db->bind(':username', $creator_username);
                $db->bind(':display_name', $username);
                $db->bind(':email', $email);
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
                $db->bind(':manual_payout_threshold', 100.00);
                $db->execute();
                
                $creator_id = $db->lastInsertId();
                
                // Update profile image filename with actual creator ID
                if ($profile_image) {
                    $file_extension = pathinfo($profile_image, PATHINFO_EXTENSION);
                    $final_filename = 'creator_' . $creator_id . '_' . time() . '.' . $file_extension;
                    $old_path = '../uploads/creators/' . $profile_image;
                    $new_path = '../uploads/creators/' . $final_filename;
                    
                    if (rename($old_path, $new_path)) {
                        // Update creator with final image filename
                        $db->query('UPDATE creators SET profile_image = :profile_image WHERE id = :id');
                        $db->bind(':profile_image', $final_filename);
                        $db->bind(':id', $creator_id);
                        $db->execute();
                    }
                }
                
                $db->endTransaction();
                
                // Auto-login the creator
                $_SESSION['user_id'] = $user_id;
                $_SESSION['username'] = $username;
                $_SESSION['full_name'] = $username;
                $_SESSION['email'] = $email;
                
                session_regenerate_id(true);
                
                // Redirect to creator dashboard
                header('Location: ../creators/dashboard.php');
                exit;
                
            } catch (Exception $e) {
                $db->cancelTransaction();
                $errors[] = "Registration failed: " . $e->getMessage();
                error_log("Creator registration error: " . $e->getMessage());
            }
        } else {
            // For fans: Create account immediately and auto-login
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $user_id = $helper->createUser($username, $email, $password_hash, $username);
            
            if ($user_id) {
                // Auto-login the user
                $_SESSION['user_id'] = $user_id;
                $_SESSION['username'] = $username;
                $_SESSION['full_name'] = $username;
                $_SESSION['email'] = $email;
                
                // Regenerate session ID for security
                session_regenerate_id(true);
                
                // REDIRECT FANS TO BROWSE CREATORS FOR FASTER TRANSACTIONS
                header('Location: ../creators/index.php');
                exit;
            } else {
                $errors[] = "Registration failed. Please try again.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Join TopicLaunch</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 400px; margin: 50px auto; padding: 20px; }
        .header { text-align: center; margin-bottom: 30px; }
        .user-type-indicator { 
            background: <?php echo $user_type === 'creator' ? '#ff0000' : '#28a745'; ?>; 
            color: white; 
            padding: 10px 20px; 
            border-radius: 20px; 
            margin-bottom: 20px; 
            text-align: center; 
            font-weight: bold; 
        }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input[type="text"], input[type="email"], input[type="password"] { 
            width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box;
        }
        .btn { background: <?php echo $user_type === 'creator' ? '#ff0000' : '#28a745'; ?>; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; width: 100%; }
        .btn:hover { opacity: 0.9; }
        .btn:disabled { background: #6c757d; cursor: not-allowed; }
        .error { color: red; margin-bottom: 10px; }
        .links { text-align: center; margin-top: 20px; }
        .password-requirements { background: #f8f9fa; padding: 10px; border-radius: 4px; margin-bottom: 15px; font-size: 14px; }
        .requirement { color: #666; font-size: 12px; }
        .requirement.valid { color: #28a745; }
        .requirement.invalid { color: #dc3545; }
        .youtube-handle-group { position: relative; }
        .youtube-at-symbol { 
            position: absolute; 
            left: 0px; 
            top: 0px; 
            background: #f8f9fa; 
            padding: 8px 12px; 
            border-radius: 4px 0 0 4px; 
            border: 1px solid #ddd; 
            border-right: none; 
            color: #666; 
            font-weight: bold;
            z-index: 2;
        }
        .youtube-handle-input { 
            padding-left: 45px !important; 
            position: relative;
            z-index: 1;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="user-type-indicator">
            <?php if ($user_type === 'creator'): ?>
                📺 YouTuber Registration
            <?php else: ?>
                💰 Fan Registration
            <?php endif; ?>
        </div>
    </div>
    
    <?php if (!empty($errors)): ?>
        <div class="errors">
            <?php foreach ($errors as $error): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    
    <form method="POST" id="registrationForm" enctype="multipart/form-data">
        <?php echo CSRFProtection::getTokenField(); ?>
        
        <?php if ($user_type === 'creator'): ?>
            <!-- Profile Image for Creators -->
            <div class="form-group">
                <label for="profile_image">Profile Image (Optional):</label>
                <input type="file" id="profile_image" name="profile_image" accept="image/*">
                <div class="requirement">JPG, PNG, or GIF. Max 2MB. Can add later.</div>
            </div>

            <!-- YouTube Handle for Creators -->
            <div class="form-group">
                <label>YouTube Handle:</label>
                <div class="youtube-handle-group">
                    <span class="youtube-at-symbol">@</span>
                    <input type="text" name="youtube_handle" id="youtube_handle" class="youtube-handle-input"
                           value="<?php echo isset($_POST['youtube_handle']) ? htmlspecialchars($_POST['youtube_handle']) : ''; ?>" 
                           required pattern="[a-zA-Z0-9_.-]*[a-zA-Z]+[a-zA-Z0-9_.-]*"
                           title="Must contain at least one letter and only letters, numbers, dots, dashes, underscores"
                           placeholder="MrBeast">
                </div>
                <div class="requirement">Example: MrBeast, PewDiePie, etc. Must contain at least one letter.</div>
            </div>
        <?php else: ?>
            <!-- Regular Username for Fans -->
            <div class="form-group">
                <label>Username:</label>
                <input type="text" name="username" id="username" value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>" required pattern="[a-zA-Z0-9_]{3,}" title="3+ characters, letters, numbers, and underscores only">
                <div class="requirement">3+ characters, letters, numbers, and underscores only</div>
            </div>
        <?php endif; ?>
        
        <div class="form-group">
            <label>Email:</label>
            <input type="email" name="email" id="email" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" required>
            <div class="requirement" id="email-req">• Must be a valid email address</div>
        </div>
        
        <div class="form-group">
            <label>Password:</label>
            <input type="password" name="password" id="password" required minlength="8">
            <div class="password-requirements">
                <div class="requirement" id="length-req">• At least 8 characters</div>
                <div class="requirement" id="letter-req">• At least one letter</div>
                <div class="requirement" id="number-req">• At least one number</div>
            </div>
        </div>
        
        <div class="form-group">
            <label>Confirm Password:</label>
            <input type="password" name="confirm_password" id="confirm_password" required>
        </div>
        
        <button type="submit" class="btn" id="submitBtn">
            <?php if ($user_type === 'creator'): ?>
                📺 Create YouTuber Account
            <?php else: ?>
                💰 Create Account & Browse YouTubers
            <?php endif; ?>
        </button>
    </form>

    <div class="links">
        <a href="login.php">Already have an account? Login here</a>
    </div>

    <script>
    // Real-time password validation
    const password = document.getElementById('password');
    const confirmPassword = document.getElementById('confirm_password');
    const lengthReq = document.getElementById('length-req');
    const letterReq = document.getElementById('letter-req');
    const numberReq = document.getElementById('number-req');
    const emailReq = document.getElementById('email-req');
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
        
        // Check if passwords match
        const passwordsMatch = confirmPwd && pwd === confirmPwd;
        
        // Email validation (basic format check - server will verify domain exists)
        const email = document.getElementById('email').value.trim();
        const emailRegex = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;
        const emailValid = emailRegex.test(email);
        
        // Enable/disable submit button
        <?php if ($user_type === 'creator'): ?>
        const handle = document.getElementById('youtube_handle').value.trim();
        const isValid = pwd.length >= 8 && /[A-Za-z]/.test(pwd) && /[0-9]/.test(pwd) && passwordsMatch && handle.length >= 3 && /[a-zA-Z]/.test(handle) && emailValid;
        <?php else: ?>
        const username = document.getElementById('username').value.trim();
        const isValid = pwd.length >= 8 && /[A-Za-z]/.test(pwd) && /[0-9]/.test(pwd) && passwordsMatch && username.length >= 3 && emailValid;
        <?php endif; ?>
        
        submitBtn.disabled = !isValid;
        submitBtn.style.opacity = isValid ? '1' : '0.6';
    }

    password.addEventListener('input', validatePassword);
    confirmPassword.addEventListener('input', validatePassword);
    document.getElementById('email').addEventListener('input', function() {
        const email = this.value.trim();
        
        if (email) {
            // Basic format validation on frontend
            const emailRegex = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;
            
            if (emailRegex.test(email)) {
                emailReq.classList.add('valid');
                emailReq.classList.remove('invalid');
                emailReq.textContent = '• Valid email format (domain will be verified on submit)';
                this.style.borderColor = '#28a745';
            } else {
                emailReq.classList.add('invalid');
                emailReq.classList.remove('valid');
                emailReq.textContent = '• Invalid email format';
                this.style.borderColor = '#dc3545';
            }
        } else {
            emailReq.classList.remove('valid', 'invalid');
            emailReq.textContent = '• Must be a valid email address';
            this.style.borderColor = '#ddd';
        }
        
        validatePassword(); // Revalidate form
    });
    
    <?php if ($user_type === 'creator'): ?>
    // YouTube handle validation and auto-trim
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
        const isValid = value.length >= 3 && /[a-zA-Z]/.test(value) && /^[a-zA-Z0-9_.-]+$/.test(value);
        this.style.borderColor = value ? (isValid ? '#28a745' : '#dc3545') : '#ddd';
        
        validatePassword(); // Revalidate form
    });
    <?php else: ?>
    // Auto-trim username spaces and real-time validation
    document.getElementById('username').addEventListener('input', function() {
        let value = this.value;
        
        // Automatically trim spaces
        value = value.trim();
        
        // Update the input value if it was modified
        if (this.value !== value) {
            this.value = value;
        }
        
        // Real-time validation feedback
        const isValid = value.length >= 3 && /^[a-zA-Z0-9_]+$/.test(value);
        this.style.borderColor = value ? (isValid ? '#28a745' : '#dc3545') : '#ddd';
        
        validatePassword(); // Revalidate form
    });
    <?php endif; ?>
    
    // Form submission feedback
    document.getElementById('registrationForm').addEventListener('submit', function() {
        submitBtn.innerHTML = '⏳ Creating Account...';
        submitBtn.disabled = true;
    });
    
    // Initial validation
    validatePassword();
    </script>
</body>
</html>
