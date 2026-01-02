<?php
// creators/signup.php - YouTuber Signup with Email & Channel Verification
session_start();

// Only redirect to dashboard if already logged in AND verified
if (isset($_SESSION['user_id'])) {
    // Try to find database.php
    if (file_exists('../config/database.php')) {
        require_once '../config/database.php';
    } elseif (file_exists(__DIR__ . '/../config/database.php')) {
        require_once __DIR__ . '/../config/database.php';
    } elseif (file_exists($_SERVER['DOCUMENT_ROOT'] . '/config/database.php')) {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/config/database.php';
    }
    
    // Check if user is a verified creator
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
        // Continue to signup page
    }
}

$error = '';
$success = '';
$step = $_GET['step'] ?? 'signup'; // signup, verify

// Handle verification step
if ($step === 'verify' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_code'])) {
    // Try to find database.php in multiple locations
    if (file_exists('../config/database.php')) {
        require_once '../config/database.php';
    } elseif (file_exists(__DIR__ . '/../config/database.php')) {
        require_once __DIR__ . '/../config/database.php';
    } elseif (file_exists($_SERVER['DOCUMENT_ROOT'] . '/config/database.php')) {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/config/database.php';
    } else {
        die('ERROR: Cannot find config/database.php. Please check file location.');
    }
    
    $entered_code = trim($_POST['verify_code'] ?? '');
    $signup_data = $_SESSION['pending_signup'] ?? null;
    
    if (!$signup_data) {
        $error = 'Session expired. Please sign up again.';
        $step = 'signup';
    } else {
        // Check if verification code matches
        if ($entered_code === $signup_data['verification_code']) {
            // Verify the code is in their YouTube channel description
            $youtube_api_key = 'YOUR_YOUTUBE_API_KEY';
            $skip_youtube_validation = ($youtube_api_key === 'YOUR_YOUTUBE_API_KEY');
            
            $code_found_in_channel = false;
            
            if (!$skip_youtube_validation) {
                // Get channel description from YouTube API
                $youtube_api_url = "https://www.googleapis.com/youtube/v3/channels?part=snippet&forHandle={$signup_data['youtube_handle']}&key={$youtube_api_key}";
                
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $youtube_api_url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                $response = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                if ($http_code === 200 && $response) {
                    $data = json_decode($response, true);
                    if (isset($data['items'][0]['snippet']['description'])) {
                        $description = $data['items'][0]['snippet']['description'];
                        // Check if verification code is in the description
                        if (stripos($description, $entered_code) !== false) {
                            $code_found_in_channel = true;
                        } else {
                            $error = 'Verification code not found in your YouTube channel description. Please add "TopicLaunch Verification: ' . $entered_code . '" to your channel About section and try again.';
                        }
                    } else {
                        $error = 'Could not access your YouTube channel description. Please try again.';
                    }
                } else {
                    $error = 'Could not verify your YouTube channel. Please ensure the handle is correct.';
                }
            } else {
                // DEVELOPMENT ONLY: Skip YouTube verification
                // WARNING: Remove this in production to prevent impersonation!
                $code_found_in_channel = true;
            }
            
            if ($code_found_in_channel && !$error) {
                // NOW create the account (only after verification)
                try {
                    $db = new Database();
                    
                    error_log("Creating verified user account...");
                    
                    // Create user with verified status
                    $db->query('INSERT INTO users (username, email, password_hash, is_verified, verified_at, created_at) VALUES (:username, :email, :password_hash, 1, NOW(), NOW())');
                    $db->bind(':username', $signup_data['username']);
                    $db->bind(':email', $signup_data['email']);
                    $db->bind(':password_hash', $signup_data['password_hash']);
                    $db->execute();
                    
                    $user_id = $db->lastInsertId();
                    error_log("User created with ID: " . $user_id);
                    
                    // Create active creator profile
                    $db->query('INSERT INTO creators (applicant_user_id, username, display_name, minimum_topic_price, is_active, created_at) VALUES (:user_id, :username, :display_name, :minimum_topic_price, 1, NOW())');
                    $db->bind(':user_id', $user_id);
                    $db->bind(':username', $signup_data['youtube_handle']);
                    $db->bind(':display_name', $signup_data['youtube_handle']);
                    $db->bind(':minimum_topic_price', $signup_data['minimum_topic_price']);
                    $db->execute();
                    
                    $creator_id = $db->lastInsertId();
                    error_log("Creator profile created with ID: " . $creator_id);
                    
                    // Move profile photo from temp to final location
                    if (!empty($signup_data['profile_photo_temp'])) {
                        $temp_path = '../uploads/temp/' . $signup_data['profile_photo_temp'];
                        $final_dir = '../uploads/creators/';
                        
                        if (!file_exists($final_dir)) {
                            mkdir($final_dir, 0755, true);
                        }
                        
                        $file_extension = pathinfo($signup_data['profile_photo_temp'], PATHINFO_EXTENSION);
                        $final_filename = 'creator_' . $creator_id . '_' . time() . '.' . $file_extension;
                        $final_path = $final_dir . $final_filename;
                        
                        if (file_exists($temp_path) && rename($temp_path, $final_path)) {
                            // Update creator with profile photo, bio, and payout methods
                            $db->query('UPDATE creators SET profile_image = :profile_image, bio = :bio, paypal_email = :paypal_email, venmo_handle = :venmo_handle WHERE id = :creator_id');
                            $db->bind(':profile_image', $final_filename);
                            $db->bind(':bio', $signup_data['bio'] ?? '');
                            $db->bind(':paypal_email', $signup_data['paypal_email'] ?? '');
                            $db->bind(':venmo_handle', $signup_data['venmo_handle'] ?? '');
                            $db->bind(':creator_id', $creator_id);
                            $db->execute();
                            error_log("Profile photo, bio, and payout methods saved");
                        }
                    } else {
                        // Save bio and payout methods even if no photo
                        $db->query('UPDATE creators SET bio = :bio, paypal_email = :paypal_email, venmo_handle = :venmo_handle WHERE id = :creator_id');
                        $db->bind(':bio', $signup_data['bio'] ?? '');
                        $db->bind(':paypal_email', $signup_data['paypal_email'] ?? '');
                        $db->bind(':venmo_handle', $signup_data['venmo_handle'] ?? '');
                        $db->bind(':creator_id', $creator_id);
                        $db->execute();
                    }
                    
                    error_log("Creator profile fully configured");
                    
                    // Log user in
                    $_SESSION['user_id'] = $user_id;
                    $_SESSION['email'] = $signup_data['email'];
                    unset($_SESSION['pending_signup']);
                    
                    error_log("User verified and logged in, redirecting to dashboard");
                    
                    // Redirect to dashboard
                    header('Location: dashboard.php');
                    exit;
                    
                } catch (Exception $e) {
                    error_log("Account creation error: " . $e->getMessage());
                    $error = 'Failed to create account. Please try again or contact support.';
                }
            }
        } else {
            $error = 'Invalid verification code. Please check the code we sent to your email.';
        }
    }
}

// Handle signup step
if ($step === 'signup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Try to find database.php in multiple locations
    if (file_exists('../config/database.php')) {
        require_once '../config/database.php';
    } elseif (file_exists(__DIR__ . '/../config/database.php')) {
        require_once __DIR__ . '/../config/database.php';
    } elseif (file_exists($_SERVER['DOCUMENT_ROOT'] . '/config/database.php')) {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/config/database.php';
    } else {
        die('ERROR: Cannot find config/database.php. Please check file location.');
    }
    
    // Enable error logging
    error_log("=== SIGNUP ATTEMPT START ===");
    
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $youtube_handle = trim($_POST['youtube_handle'] ?? '');
    $bio = trim($_POST['bio'] ?? '');
    $minimum_topic_price = trim($_POST['minimum_topic_price'] ?? '');
    $paypal_email = trim($_POST['paypal_email'] ?? '');
    $venmo_handle = trim($_POST['venmo_handle'] ?? '');
    $agree_terms = isset($_POST['agree_terms']);
    
    error_log("Email: " . $email);
    error_log("YouTube Handle: " . $youtube_handle);
    error_log("Minimum Topic Price: " . $minimum_topic_price);
    error_log("Has Bio: " . (!empty($bio) ? 'Yes' : 'No'));
    error_log("Has Profile Photo: " . (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === 0 ? 'Yes' : 'No'));
    error_log("PayPal: " . (!empty($paypal_email) ? 'Yes' : 'No'));
    error_log("Venmo: " . (!empty($venmo_handle) ? 'Yes' : 'No'));
    
    // Validation
    if (empty($email) || empty($password) || empty($confirm_password) || empty($youtube_handle) || empty($minimum_topic_price)) {
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
    } elseif (!is_numeric($minimum_topic_price) || $minimum_topic_price < 10) {
        $error = 'Minimum topic price must be at least $10';
    } elseif ($minimum_topic_price > 10000) {
        $error = 'Minimum topic price cannot exceed $10,000';
    } else {
        // Validate profile photo
        $allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp'];
        $max_size = 5 * 1024 * 1024; // 5MB
        
        if (!in_array($_FILES['profile_photo']['type'], $allowed_types)) {
            $error = 'Profile photo must be JPG, PNG, or WebP format';
        } elseif ($_FILES['profile_photo']['size'] > $max_size) {
            $error = 'Profile photo must be less than 5MB';
        } else {
        // Validate YouTube handle
        $youtube_handle_clean = str_replace('@', '', $youtube_handle);
        
        // Check if YouTube handle exists
        $youtube_api_key = 'YOUR_YOUTUBE_API_KEY';
        $skip_youtube_validation = ($youtube_api_key === 'YOUR_YOUTUBE_API_KEY');
        
        $youtube_exists = false;
        
        if (!$skip_youtube_validation) {
            $youtube_api_url = "https://www.googleapis.com/youtube/v3/channels?part=snippet&forHandle={$youtube_handle_clean}&key={$youtube_api_key}";
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $youtube_api_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($http_code === 200 && $response) {
                $data = json_decode($response, true);
                if (isset($data['pageInfo']['totalResults']) && $data['pageInfo']['totalResults'] > 0) {
                    $youtube_exists = true;
                }
            }
            
            if (!$youtube_exists) {
                $error = 'YouTube handle does not exist. Please enter a valid YouTube handle.';
            }
        } else {
            $youtube_exists = true;
        }
        
        if ($youtube_exists && !$error) {
            try {
                $db = new Database();
                
                error_log("Checking for existing email...");
                
                // Check if email already exists
                $db->query('SELECT id FROM users WHERE email = :email');
                $db->bind(':email', $email);
                $existing_user = $db->single();
                
                if ($existing_user) {
                    $error = 'Email already registered';
                    error_log("Email already exists");
                } else {
                    error_log("Checking for existing YouTube handle...");
                    
                    // Check if YouTube handle already taken
                    $db->query('SELECT id FROM creators WHERE username = :username');
                    $db->bind(':username', $youtube_handle_clean);
                    $existing_creator = $db->single();
                    
                    if ($existing_creator) {
                        $error = 'YouTube handle already registered on TopicLaunch';
                        error_log("YouTube handle already exists");
                    } else {
                        error_log("Creating user account...");
                        
                        // Generate verification code
                        $verification_code = strtoupper(substr(md5(uniqid(rand(), true)), 0, 8));
                        
                        // Generate username from email
                        $username = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', explode('@', $email)[0]));
                        
                        error_log("Generated username: " . $username);
                        error_log("Verification code: " . $verification_code);
                        
                        // Handle profile photo upload
                        $profile_photo_path = null;
                        if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === 0) {
                            $upload_dir = '../uploads/temp/';
                            if (!file_exists($upload_dir)) {
                                mkdir($upload_dir, 0755, true);
                            }
                            
                            $file_extension = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
                            $temp_filename = 'temp_' . uniqid() . '.' . $file_extension;
                            $temp_path = $upload_dir . $temp_filename;
                            
                            if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $temp_path)) {
                                $profile_photo_path = $temp_filename;
                                error_log("Profile photo uploaded temporarily: " . $temp_filename);
                            }
                        }
                        
                        // Store signup data in session (don't create account yet!)
                        $_SESSION['pending_signup'] = [
                            'email' => $email,
                            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                            'username' => $username,
                            'youtube_handle' => $youtube_handle_clean,
                            'bio' => $bio,
                            'profile_photo_temp' => $profile_photo_path,
                            'minimum_topic_price' => floatval($minimum_topic_price),
                            'paypal_email' => $paypal_email,
                            'venmo_handle' => $venmo_handle,
                            'verification_code' => $verification_code,
                            'created_at' => time()
                        ];
                        
                        error_log("Signup data stored in session, waiting for verification");
                        
                        // Send verification email
                        $to = $email;
                        $subject = 'Verify Your TopicLaunch Account';
                        $message = "Welcome to TopicLaunch!\n\n";
                        $message .= "Your verification code is: {$verification_code}\n\n";
                        $message .= "To verify your YouTube channel ownership:\n";
                        $message .= "1. Go to your YouTube channel: https://youtube.com/@{$youtube_handle_clean}\n";
                        $message .= "2. Click 'Customize Channel' or go to your About section\n";
                        $message .= "3. Add this text anywhere in your channel description:\n\n";
                        $message .= "TopicLaunch Verification: {$verification_code}\n\n";
                        $message .= "4. Return to TopicLaunch and enter your verification code\n\n";
                        $message .= "This code expires in 24 hours.\n\n";
                        $message .= "If you didn't sign up for TopicLaunch, please ignore this email.\n\n";
                        $message .= "Questions? Contact us at support@topiclaunch.com";
                        
                        $headers = "From: TopicLaunch <noreply@topiclaunch.com>\r\n";
                        $headers .= "Reply-To: support@topiclaunch.com\r\n";
                        $headers .= "X-Mailer: PHP/" . phpversion();
                        
                        $mail_sent = mail($to, $subject, $message, $headers);
                        error_log("Email sent: " . ($mail_sent ? 'YES' : 'NO'));
                        
                        error_log("Redirecting to verification page...");
                        
                        // Redirect to verification step
                        header('Location: signup.php?step=verify');
                        exit;
                    }
                }
            } catch (Exception $e) {
                error_log("ERROR: " . $e->getMessage());
                error_log("Stack trace: " . $e->getTraceAsString());
                $error = 'An error occurred during signup. Please try again. Error: ' . $e->getMessage();
            }
        }
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>YouTuber Signup - TopicLaunch</title>
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
        
        /* Navigation - Rizzdem Style */
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
            cursor: pointer;
        }

        /* Nav Center Links */
        .nav-center {
            display: flex;
            gap: 30px;
            align-items: center;
        }
        
        .nav-link {
            color: #6b7280;
            text-decoration: none;
            font-size: 15px;
            font-weight: 500;
            transition: color 0.2s;
        }
        
        .nav-link:hover {
            color: #FF0000;
        }
        
        .nav-buttons {
            display: flex;
            gap: 15px;
            align-items: center;
        }
        
        .nav-login-btn {
            color: #333;
            text-decoration: none;
            font-size: 15px;
            font-weight: 500;
            padding: 8px 16px;
            transition: color 0.2s;
        }
        
        .nav-login-btn:hover {
            color: #FF0000;
        }
        
        .nav-getstarted-btn {
            background: #FF0000;
            color: white;
            text-decoration: none;
            font-size: 15px;
            font-weight: 600;
            padding: 10px 24px;
            border-radius: 50px;
            transition: all 0.2s;
        }
        
        .nav-getstarted-btn:hover {
            background: #CC0000;
            transform: translateY(-1px);
        }
        
        /* Page Wrapper */
        .page-wrapper {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 60px 20px 40px;
            min-height: calc(100vh - 70px);
        }
        
        /* Page Header - Outside Box */
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
            line-height: 1.6;
        }
        
        .signup-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            border: 1px solid #f0f0f0;
            padding: 30px;
            width: 100%;
            max-width: 480px;
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
        
        .form-group input {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        
        .form-group textarea {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            resize: vertical;
            transition: border-color 0.3s;
        }
        
        .form-group input[type="file"] {
            display: none;
        }
        
        /* Profile Photo Upload - Rizzdem Style */
        .profile-photo-group {
            margin-bottom: 20px;
        }
        
        .required-star {
            color: #FF0000;
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
        
        .upload-button svg {
            stroke: currentColor;
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
        
        .label-note {
            color: #6b7280;
            font-weight: 400;
            font-size: 13px;
        }
        
        .payout-fields {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 8px;
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
            pointer-events: none;
        }
        
        .input-with-prefix-field {
            padding-left: 28px !important;
        }
        
        .payout-note {
            display: block;
            color: #6b7280;
            font-size: 12px;
            margin-top: 8px;
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
        
        .checkbox-group {
            margin-bottom: 20px;
        }
        
        .checkbox-label {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            cursor: pointer;
            font-weight: normal;
        }
        
        .checkbox-label input[type="checkbox"] {
            width: auto;
            margin-top: 3px;
            cursor: pointer;
        }
        
        .checkbox-label span {
            flex: 1;
            font-size: 14px;
            color: #333;
        }
        
        .checkbox-label a {
            color: #FF0000;
            text-decoration: none;
        }
        
        .checkbox-label a:hover {
            text-decoration: underline;
        }
        
        .error-message {
            background: #fee;
            color: #c33;
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            border-left: 4px solid #c33;
        }
        
        .success-message {
            background: #efe;
            color: #3c3;
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            border-left: 4px solid #3c3;
        }
        
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
        }
        
        .submit-btn:hover {
            background: #CC0000;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(255,0,0,0.3);
        }
        
        .password-requirements {
            background: #f8f9fa;
            padding: 8px 12px;
            border-radius: 6px;
            margin-top: 6px;
            font-size: 12px;
            color: #666;
        }
        
        .password-requirements ul {
            margin: 3px 0 0 18px;
        }
        
        .password-requirements li {
            margin: 2px 0;
        }
        
        .warning-note {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 13px;
            color: #856404;
        }
        
        .info-box {
            background: #e7f3ff;
            border-left: 4px solid #2196F3;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            color: #0c5460;
        }
        
        .info-box h3 {
            color: #0c5460;
            font-size: 16px;
            margin-bottom: 10px;
        }
        
        .info-box ol {
            margin-left: 20px;
        }
        
        .info-box li {
            margin: 8px 0;
        }
        
        .verification-code {
            background: #f8f9fa;
            border: 2px dashed #666;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            font-size: 24px;
            font-weight: bold;
            font-family: 'Courier New', monospace;
            color: #FF0000;
            margin: 15px 0;
            letter-spacing: 3px;
        }
        
        @media (max-width: 768px) {
            .signup-container {
                padding: 30px 20px;
            }
            
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
            
            .profile-photo-preview {
                width: 100px;
                height: 100px;
            }

            .nav-center {
                display: none;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="topiclaunch-nav">
        <div class="nav-container">
            <a href="/" class="nav-logo">TopicLaunch</a>
            
            <!-- Center Navigation Links -->
            <div class="nav-center">
                <a href="/#creators" class="nav-link">Browse YouTubers</a>
                <a href="/creators/signup.php" class="nav-link">For YouTubers</a>
            </div>

            <div class="nav-buttons">
                <a href="/auth/login.php" class="nav-login-btn">Log In</a>
                <a href="/creators/signup.php" class="nav-getstarted-btn">Get Started</a>
            </div>
        </div>
    </nav>

    <div class="page-wrapper">
        <!-- Page Header - Outside Box -->
        <div class="page-header">
            <h1 class="page-title"><?php echo $step === 'verify' ? 'Verify Your Channel' : 'Get Started'; ?></h1>
            <p class="page-subtitle"><?php echo $step === 'verify' ? 'Complete your verification to start earning' : 'Join TopicLaunch as a YouTuber'; ?></p>
        </div>
        
        <div class="signup-container">
        
        <?php if ($error): ?>
            <div class="error-message">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success-message">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($step === 'verify'): ?>
            <!-- Verification Step -->
            <div class="info-box">
                <h3>📧 Check Your Email!</h3>
                <p style="margin-bottom: 10px;">We've sent a verification code to your email. Follow these steps:</p>
                <ol>
                    <li>Check your email for the verification code</li>
                    <li>Go to your YouTube channel and click "Customize Channel"</li>
                    <li>Add the code to your channel description or About section</li>
                    <li>Enter the code below to complete verification</li>
                </ol>
            </div>
            
            <form method="POST" action="?step=verify" id="verifyForm">
                <div class="form-group">
                    <label for="verify_code">Verification Code</label>
                    <input type="text" 
                           id="verify_code" 
                           name="verify_code" 
                           placeholder="Enter 8-character code"
                           maxlength="8"
                           style="text-transform: uppercase; letter-spacing: 2px; font-family: 'Courier New', monospace;"
                           required>
                    <small>Enter the code from your email (e.g., ABC12345)</small>
                </div>
                
                <button type="submit" class="submit-btn">Verify & Complete Signup</button>
            </form>
            
        <?php else: ?>
            <!-- Signup Step -->
            <form method="POST" action="" id="signupForm" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="youtube_handle">YouTube Handle</label>
                    <input type="text" 
                           id="youtube_handle" 
                           name="youtube_handle" 
                           placeholder="@yourchannel"
                           value="<?php echo htmlspecialchars($_POST['youtube_handle'] ?? ''); ?>"
                           required>
                    <small>Enter your YouTube handle (e.g., @mrbeast)</small>
                </div>
                
                <div class="form-group profile-photo-group">
                    <label for="profile_photo">Profile Photo <span class="required-star">*</span></label>
                    <div class="profile-photo-container">
                        <div class="profile-photo-preview" id="photoPreview">
                            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#9ca3af" stroke-width="2">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                <circle cx="12" cy="7" r="4"></circle>
                            </svg>
                        </div>
                        <div class="profile-photo-upload">
                            <label for="profile_photo" class="upload-button">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
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
                
                <div class="form-group">
                    <label for="bio">Bio (Optional)</label>
                    <textarea 
                           id="bio" 
                           name="bio" 
                           placeholder="Tell fans about yourself and what kind of videos you create..."
                           rows="3"><?php echo htmlspecialchars($_POST['bio'] ?? ''); ?></textarea>
                </div>
                
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" 
                           id="email" 
                           name="email" 
                           placeholder="your@email.com"
                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                           required>
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" 
                           id="password" 
                           name="password" 
                           placeholder="Create a password"
                           required>
                    <div class="password-requirements">
                        <ul>
                            <li>Be at least 8 characters long</li>
                        </ul>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <input type="password" 
                           id="confirm_password" 
                           name="confirm_password" 
                           placeholder="Re-enter your password"
                           required>
                </div>
                
                <div class="payout-section">
                    <label class="payout-section-label">Payout Method <span class="label-note">(at least one required)</span></label>
                    
                    <div class="payout-fields">
                        <div class="form-group">
                            <label for="paypal_email">PayPal Email</label>
                            <input type="email" 
                                   id="paypal_email" 
                                   name="paypal_email" 
                                   placeholder="payouts@example.com"
                                   value="<?php echo htmlspecialchars($_POST['paypal_email'] ?? ''); ?>">
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
                                       value="<?php echo htmlspecialchars($_POST['venmo_handle'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                    
                    <small class="payout-note">We'll use your preferred method to send your earnings securely.</small>
                </div>
                
                <div class="form-group">
                    <label for="minimum_topic_price">Minimum Price per Topic ($)</label>
                    <input type="number" 
                           id="minimum_topic_price" 
                           name="minimum_topic_price" 
                           placeholder="100"
                           min="10"
                           max="10000"
                           step="1"
                           value="<?php echo htmlspecialchars($_POST['minimum_topic_price'] ?? ''); ?>"
                           required>
                    <small>Set your price per topic. You'll keep 90% of this amount.</small>
                </div>
                
                <div class="form-group checkbox-group">
                    <label class="checkbox-label">
                        <input type="checkbox" 
                               id="agree_terms" 
                               name="agree_terms" 
                               required>
                        <span>I agree to the <a href="/terms.php" target="_blank">Terms of Service</a></span>
                    </label>
                    <small>You must agree to the terms to create an account.</small>
                </div>
                
                <button type="submit" class="submit-btn">Create Account</button>
            </form>
        <?php endif; ?>
        </div>
    </div>
    
    <script>
    <?php if ($step === 'signup'): ?>
    // Profile photo preview
    document.getElementById('profile_photo').addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const preview = document.getElementById('photoPreview');
                preview.innerHTML = '<img src="' + e.target.result + '" alt="Preview">';
            };
            reader.readAsDataURL(file);
        }
    });
    
    // Client-side password and payout validation
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
        
        // Validate at least one payout method
        const paypalEmail = document.getElementById('paypal_email').value.trim();
        const venmoHandle = document.getElementById('venmo_handle').value.trim();
        
        if (!paypalEmail && !venmoHandle) {
            e.preventDefault();
            alert('Please provide at least one payout method (PayPal or Venmo)');
            return false;
        }
    });
    
    // Add @ symbol automatically to YouTube handle if not present
    document.getElementById('youtube_handle').addEventListener('blur', function() {
        let value = this.value.trim();
        if (value && !value.startsWith('@')) {
            this.value = '@' + value;
        }
    });
    <?php endif; ?>
    
    <?php if ($step === 'verify'): ?>
    // Auto-uppercase verification code
    document.getElementById('verify_code').addEventListener('input', function() {
        this.value = this.value.toUpperCase();
    });
    <?php endif; ?>
    </script>
</body>
</html>
