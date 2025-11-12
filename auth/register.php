<?php
// auth/register.php - Updated to handle guest post-payment signup
session_start();
require_once '../config/database.php';
require_once '../config/csrf.php';
require_once '../config/sanitizer.php';

// Get parameters for post-payment signup
$topic_funded = isset($_GET['topic_funded']);
$topic_created = isset($_GET['topic_created']);
$session_id = $_GET['session_id'] ?? '';
$topic_id = $_GET['topic_id'] ?? 0;
$creator_id = $_GET['creator_id'] ?? 0;
$amount = $_GET['amount'] ?? 0;
$return_to = $_GET['return_to'] ?? '';

// Check if already logged in and redirect appropriately
if (isset($_SESSION['user_id'])) {
    $db = new Database();
    $db->query('SELECT id FROM creators WHERE applicant_user_id = :user_id AND is_active = 1');
    $db->bind(':user_id', $_SESSION['user_id']);
    $is_creator = $db->single();
    
    if ($is_creator) {
        header('Location: ../creators/dashboard.php');
    } else {
        // Fans go to return_to URL or browse creators
        if ($return_to) {
            header('Location: ..' . $return_to);
        } else {
            header('Location: ../creators/index.php');
        }
    }
    exit;
}

$helper = new DatabaseHelper();
$errors = [];
$user_type = $_GET['type'] ?? 'fan'; // 'creator' or 'fan'

if ($_POST) {
    // CSRF Protection
    CSRFProtection::requireValidToken();
    
    // Get return_to from form if it exists
    $return_to = $_POST['return_to'] ?? $return_to;
    
    if ($user_type === 'creator') {
        // Get platform type for creators
        $platform_type = trim($_POST['platform_type'] ?? 'youtube');
        
        // For creators, use platform handle instead of username
        $platform_handle = trim($_POST['platform_handle'] ?? '');
        
        // Remove @ if user included it
        if (strpos($platform_handle, '@') === 0) {
            $platform_handle = substr($platform_handle, 1);
        }
        
        // Additional cleanup - trim again after @ removal
        $platform_handle = trim($platform_handle);
        
        // Use platform handle as username
        $username = $platform_handle;
        
        // Get PayPal email for creators
        $paypal_email = trim(InputSanitizer::sanitizeEmail($_POST['paypal_email'] ?? ''));
        
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
        
        // PayPal email validation for creators
        if (empty($paypal_email)) {
            $errors[] = "PayPal email is required for payments";
        } elseif (!filter_var($paypal_email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Please enter a valid PayPal email address";
        } elseif (!preg_match('/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $paypal_email)) {
            $errors[] = "Please enter a properly formatted PayPal email address";
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
        // Platform handle validation
        if (empty($platform_handle)) {
            $errors[] = "Platform handle is required";
        } elseif (strlen($platform_handle) < 3) {
            $errors[] = "Platform handle must be at least 3 characters";
        } elseif (!preg_match('/^[a-zA-Z0-9_.-]+$/', $platform_handle)) {
            $errors[] = "Platform handle can only contain letters, numbers, dots, dashes, and underscores";
        } elseif (preg_match('/^[0-9._-]+$/', $platform_handle)) {
            $errors[] = "Platform handle must contain at least one letter";
        } elseif ($helper->usernameExists($username)) {
            $errors[] = "Platform handle already exists";
        } else {
            // Verify platform handle exists
            if ($platform_type === 'youtube') {
                $platform_url = "https://www.youtube.com/@" . $platform_handle;
                $headers = @get_headers($platform_url);
                if (!$headers || strpos($headers[0], '200') === false) {
                    $errors[] = "YouTube handle '@{$platform_handle}' does not exist. Please enter a valid YouTube handle.";
                }
            }
            // Add validation for other platforms as needed
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
            // For creators: Create account and creator profile immediately with PayPal
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
                
                // Construct platform URL based on platform type
                $platform_urls = [
                    'youtube' => 'https://youtube.com/@' . $platform_handle,
                    'instagram' => 'https://instagram.com/' . $platform_handle,
                    'tiktok' => 'https://tiktok.com/@' . $platform_handle,
                    'twitter' => 'https://twitter.com/' . $platform_handle,
                    'twitch' => 'https://twitch.tv/' . $platform_handle
                ];
                
                $platform_url = $platform_urls[$platform_type] ?? 'https://youtube.com/@' . $platform_handle;
                
                // Create creator profile immediately with PayPal email
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
                        manual_payout_threshold,
                        paypal_email
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
                        :manual_payout_threshold,
                        :paypal_email
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
                
                // Redirect directly to creator dashboard
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
                
                // Handle post-payment redirects
                if ($topic_funded && $topic_id) {
                    // Redirect to topic view page
                    header('Location: ../topics/view.php?id=' . $topic_id . '&welcome=1');
                } elseif ($topic_created && $creator_id) {
                    // Redirect to creator profile
                    header('Location: ../creators/profile.php?id=' . $creator_id . '&created=1');
                } elseif ($return_to) {
                    // Redirect to return_to URL
                    header('Location: ..' . $return_to);
                } else {
                    // Default redirect to browse creators
                    header('Location: ../creators/index.php');
                }
                exit;
            } else {
                $errors[] = "Registration failed. Please try again.";
            }
        }
    }
}

// Get topic info for display if post-payment signup
$topic_info = null;
$creator_info = null;

if (($topic_funded || $topic_created) && $topic_id) {
    try {
        $helper = new DatabaseHelper();
        $topic_info = $helper->getTopicById($topic_id);
    } catch (Exception $e) {
        // Ignore error, just don't show topic info
    }
}

if ($topic_created && $creator_id) {
    try {
        $creator_info = $helper->getCreatorById($creator_id);
    } catch (Exception $e) {
        // Ignore error, just don't show creator info
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo $topic_funded ? 'Complete Your Account - Payment Successful!' : 'Join TopicLaunch'; ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; background: #f5f5f5; }
        
        /* Guest-friendly navigation */
        .guest-nav {
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
        .success-icon { font-size: 48px; margin-bottom: 15px; }
        .header h1 { margin: 0 0 10px 0; color: #333; }
        .header p { margin: 0; color: #666; }
        
        .payment-success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 20px; border-radius: 8px; margin-bottom: 20px; text-align: center; }
        .topic-summary { background: #e3f2fd; border: 1px solid #2196f3; padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .topic-title { font-weight: bold; color: #1976d2; margin-bottom: 5px; }
        
        .form-container { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: bold; color: #333; }
        input[type="text"], input[type="email"], input[type="password"], input[type="file"], select { 
            width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 6px; box-sizing: border-box; font-size: 16px;
        }
        select { cursor: pointer; }
        .btn { background: <?php echo $user_type === 'creator' ? '#667eea' : '#28a745'; ?>; color: white; padding: 15px 30px; border: none; border-radius: 6px; cursor: pointer; font-size: 16px; width: 100%; font-weight: bold; }
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
    <!-- Guest-friendly navigation -->
    <nav class="guest-nav">
        <div class="nav-container">
            <span class="nav-logo">TopicLaunch</span>
        </div>
    </nav>

    <div class="container">
        <?php if ($topic_funded): ?>
        <!-- Post-payment success message -->
        <div class="payment-success">
            <div class="success-icon">✅</div>
            <h3 style="margin: 0 0 10px 0;">Payment Successful!</h3>
            <p style="margin: 0;">Thank you for funding this topic! Complete your account to track your contribution.</p>
        </div>
        
        <?php if ($topic_info): ?>
        <div class="topic-summary">
            <div class="topic-title"><?php echo htmlspecialchars($topic_info->title); ?></div>
            <div style="color: #666; font-size: 14px;">
                By <?php echo htmlspecialchars($topic_info->creator_name); ?>
                <?php if ($amount): ?>• Your contribution: $<?php echo number_format($amount, 2); ?><?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <?php elseif ($topic_created): ?>
        <!-- Post-topic creation message -->
        <div class="payment-success">
            <div class="success-icon">🎉</div>
            <h3 style="margin: 0 0 10px 0;">Topic Created Successfully!</h3>
            <p style="margin: 0;">Your payment was processed and topic is now live! Complete your account to track it.</p>
        </div>
        
        <?php if ($creator_info): ?>
        <div class="topic-summary">
            <div style="color: #666; font-size: 14px;">
                Topic created for @<?php echo htmlspecialchars($creator_info->display_name); ?>
            </div>
        </div>
        <?php endif; ?>
        
        <?php else: ?>
        <!-- Normal signup header -->
        <div class="header">
            <h1><?php echo $user_type === 'creator' ? 'Join as Creator' : 'Join as Fan'; ?></h1>
            <p><?php echo $user_type === 'creator' ? 'Get paid for creating requested content' : 'Fund topics from your favorite creators'; ?></p>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($errors)): ?>
            <?php foreach ($errors as $error): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endforeach; ?>
        <?php endif; ?>
        
        <div class="form-container">
            <form method="POST" id="registrationForm" enctype="multipart/form-data">
                <?php echo CSRFProtection::getTokenField(); ?>
                
                <!-- Pass through parameters for post-payment signup -->
                <?php if ($topic_funded): ?>
                    <input type="hidden" name="topic_funded" value="1">
                    <input type="hidden" name="topic_id" value="<?php echo htmlspecialchars($topic_id); ?>">
                    <input type="hidden" name="amount" value="<?php echo htmlspecialchars($amount); ?>">
                <?php endif; ?>
                
                <?php if ($topic_created): ?>
                    <input type="hidden" name="topic_created" value="1">
                    <input type="hidden" name="creator_id" value="<?php echo htmlspecialchars($creator_id); ?>">
                <?php endif; ?>
                
                <?php if ($return_to): ?>
                    <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($return_to); ?>">
                <?php endif; ?>
                
                <?php if ($user_type === 'creator'): ?>
                    <!-- Platform Selection -->
                    <div class="form-group">
                        <label>Choose Your Platform:</label>
                        <div class="platform-select-group">
                            <label class="platform-option selected" data-platform="youtube">
                                <input type="radio" name="platform_type" value="youtube" checked>
                                <div class="platform-logo">
                                    <svg viewBox="0 0 24 24" fill="currentColor">
                                        <path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/>
                                    </svg>
                                </div>
                                <div class="platform-name">YouTube</div>
                            </label>
                            
                            <label class="platform-option" data-platform="instagram">
                                <input type="radio" name="platform_type" value="instagram">
                                <div class="platform-logo">
                                    <img src="../uploads/platform_logos/ig_logo.png" alt="Instagram" style="width: 100%; height: 100%; object-fit: contain;">
                                </div>
                                <div class="platform-name">Instagram</div>
                            </label>
                            
                            <label class="platform-option" data-platform="tiktok">
                                <input type="radio" name="platform_type" value="tiktok">
                                <div class="platform-logo">
                                    <img src="../uploads/platform_logos/tiktok_logo.webp" alt="TikTok" style="width: 100%; height: 100%; object-fit: contain;">
                                </div>
                                <div class="platform-name">TikTok</div>
                            </label>
                            
                            <label class="platform-option" data-platform="twitter">
                                <input type="radio" name="platform_type" value="twitter">
                                <div class="platform-logo">
                                    <svg viewBox="0 0 24 24" fill="currentColor">
                                        <path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/>
                                    </svg>
                                </div>
                                <div class="platform-name">Twitter</div>
                            </label>
                            
                            <label class="platform-option" data-platform="twitch">
                                <input type="radio" name="platform_type" value="twitch">
                                <div class="platform-logo">
                                    <svg viewBox="0 0 24 24" fill="currentColor">
                                        <path d="M11.571 4.714h1.715v5.143H11.57zm4.715 0H18v5.143h-1.714zM6 0L1.714 4.286v15.428h5.143V24l4.286-4.286h3.428L22.286 12V0zm14.571 11.143l-3.428 3.428h-3.429l-3 3v-3H6.857V1.714h13.714Z"/>
                                    </svg>
                                </div>
                                <div class="platform-name">Twitch</div>
                            </label>
                        </div>
                    </div>

                    <!-- Profile Image for Creators -->
                    <div class="form-group">
                        <label for="profile_image">Profile Image (Optional):</label>
                        <input type="file" id="profile_image" name="profile_image" accept="image/*">
                        <div class="requirement">JPG, PNG, or GIF. Max 2MB. Can add later.</div>
                    </div>

                    <!-- Platform Handle for Creators -->
                    <div class="form-group">
                        <label id="platform-handle-label">YouTube Handle:</label>
                        <div class="platform-handle-group">
                            <span class="platform-at-symbol">@</span>
                            <input type="text" name="platform_handle" id="platform_handle" class="platform-handle-input"
                                   value="<?php echo isset($_POST['platform_handle']) ? htmlspecialchars($_POST['platform_handle']) : ''; ?>" 
                                   required pattern="[a-zA-Z0-9_.-]*[a-zA-Z]+[a-zA-Z0-9_.-]*"
                                   title="Must contain at least one letter and only letters, numbers, dots, dashes, underscores"
                                   placeholder="MrBeast">
                        </div>
                        <div class="requirement" id="platform-handle-hint">Example: MrBeast, PewDiePie, etc. Must contain at least one letter.</div>
                    </div>

                    <!-- PayPal Email for Creators -->
                    <div class="form-group">
                        <label>PayPal Email (for payments):</label>
                        <input type="email" name="paypal_email" id="paypal_email" 
                               value="<?php echo isset($_POST['paypal_email']) ? htmlspecialchars($_POST['paypal_email']) : ''; ?>" 
                               required placeholder="your-paypal@email.com">
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
                
                <button type="submit" class="btn" id="submitBtn">
                    <?php if ($topic_funded || $topic_created): ?>
                        Complete Account
                    <?php elseif ($user_type === 'creator'): ?>
                        Create Creator Account
                    <?php else: ?>
                        💰 Create Account & Browse Creators
                    <?php endif; ?>
                </button>
            </form>
            
            <?php if (!$topic_funded && !$topic_created): ?>
            <div class="login-link">
                <a href="login.php<?php echo $return_to ? '?return_to=' . urlencode($return_to) : ''; ?>">Already have an account? Login here</a>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    // Platform selection logic
    document.querySelectorAll('.platform-option').forEach(option => {
        option.addEventListener('click', function() {
            // Remove selected class from all options
            document.querySelectorAll('.platform-option').forEach(opt => opt.classList.remove('selected'));
            
            // Add selected class to clicked option
            this.classList.add('selected');
            
            // Check the radio button
            this.querySelector('input[type="radio"]').checked = true;
            
            // Update label and placeholder based on platform
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
                },
                twitter: {
                    label: 'Twitter/X Handle:',
                    placeholder: 'elonmusk',
                    hint: 'Example: elonmusk, TheRock, etc.'
                },
                twitch: {
                    label: 'Twitch Username:',
                    placeholder: 'ninja',
                    hint: 'Example: ninja, pokimane, etc.'
                }
            };
            
            const data = platformData[platform];
            label.textContent = data.label;
            input.placeholder = data.placeholder;
            hint.textContent = data.hint;
        });
    });

    // Real-time password validation
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
        
        // Check if passwords match
        const passwordsMatch = confirmPwd && pwd === confirmPwd;
        
        // Email validation (basic format check - server will verify domain exists)
        const email = document.getElementById('email').value.trim();
        const emailRegex = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;
        const emailValid = emailRegex.test(email);
        
        // Enable/disable submit button
        <?php if ($user_type === 'creator'): ?>
        const handle = document.getElementById('platform_handle').value.trim();
        const paypalEmail = document.getElementById('paypal_email').value.trim();
        const paypalValid = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/.test(paypalEmail);
        const isValid = pwd.length >= 8 && /[A-Za-z]/.test(pwd) && /[0-9]/.test(pwd) && passwordsMatch && handle.length >= 3 && /[a-zA-Z]/.test(handle) && emailValid && paypalValid;
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
                this.style.borderColor = '#28a745';
            } else {
                this.style.borderColor = '#dc3545';
            }
        } else {
            this.style.borderColor = '#ddd';
        }
        
        validatePassword(); // Revalidate form
    });
    
    <?php if ($user_type === 'creator'): ?>
    // PayPal email validation for creators
    document.getElementById('paypal_email').addEventListener('input', function() {
        const email = this.value.trim();
        
        if (email) {
            const emailRegex = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;
            
            if (emailRegex.test(email)) {
                this.style.borderColor = '#28a745';
            } else {
                this.style.borderColor = '#dc3545';
            }
        } else {
            this.style.borderColor = '#ddd';
        }
        
        validatePassword(); // Revalidate form
    });
    
    // Platform handle validation and auto-trim
    document.getElementById('platform_handle').addEventListener('input', function() {
        let value = this.value;
        
        // Automatically trim spaces
        value = value.trim();
        
        // Remove @ if user types it
        if (value.startsWith('@')) {
            value = value.substring(1);
        }
        
        // Remove platform URLs if user pastes them
        const urlPatterns = [
            /youtube\.com\/@?([a-zA-Z0-9_.-]+)/,
            /instagram\.com\/([a-zA-Z0-9_.-]+)/,
            /tiktok\.com\/@?([a-zA-Z0-9_.-]+)/,
            /twitter\.com\/([a-zA-Z0-9_.-]+)/,
            /twitch\.tv\/([a-zA-Z0-9_.-]+)/
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
        <?php if ($topic_funded || $topic_created): ?>
        submitBtn.innerHTML = '⏳ Completing Account...';
        <?php else: ?>
        submitBtn.innerHTML = '⏳ Creating Account...';
        <?php endif; ?>
        submitBtn.disabled = true;
    });
    
    // Initial validation
    validatePassword();
    </script>
</body>
</html>
