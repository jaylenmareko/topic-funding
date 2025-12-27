<?php
session_start();
require_once '../config/database.php';

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: /creators/dashboard.php');
    exit;
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /creators/signup.php');
    exit;
}

$youtube_handle = trim($_POST['youtube_handle'] ?? '');
$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

// Validation
$errors = [];

// Validate YouTube handle
if (empty($youtube_handle)) {
    $errors[] = 'invalid_handle';
} else {
    // Remove @ if present
    if (strpos($youtube_handle, '@') === 0) {
        $youtube_handle = substr($youtube_handle, 1);
    }
    // Validate format
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $youtube_handle)) {
        $errors[] = 'invalid_handle';
    }
}

// Validate email
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'invalid_email';
}

// Validate password
if (strlen($password) < 8) {
    $errors[] = 'weak_password';
}

// If validation errors, redirect back
if (!empty($errors)) {
    $error_param = implode(',', $errors);
    header('Location: /creators/signup.php?error=' . $errors[0] . '&email=' . urlencode($email) . '&handle=' . urlencode($youtube_handle));
    exit;
}

try {
    $db = new Database();

    // Check if email already exists
    $db->query('SELECT id FROM users WHERE email = :email');
    $db->bind(':email', $email);
    $existing_user = $db->single();

    if ($existing_user) {
        header('Location: /creators/signup.php?error=email_exists&handle=' . urlencode($youtube_handle));
        exit;
    }

    // Check if YouTube handle already exists
    $db->query('SELECT id FROM creators WHERE display_name = :handle OR username LIKE :handle_pattern');
    $db->bind(':handle', $youtube_handle);
    $db->bind(':handle_pattern', '%' . $youtube_handle . '%');
    $existing_creator = $db->single();

    if ($existing_creator) {
        header('Location: /creators/signup.php?error=handle_exists&email=' . urlencode($email));
        exit;
    }

    // Hash password
    $password_hash = password_hash($password, PASSWORD_DEFAULT);

    // Handle profile picture upload
    $profile_image = null;
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/creators/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        $file = $_FILES['profile_picture'];
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed_extensions = ['jpg', 'jpeg', 'png'];

        // Validate file
        if (in_array($file_extension, $allowed_extensions) && $file['size'] <= 5 * 1024 * 1024) {
            $filename = 'creator_' . uniqid() . '.' . $file_extension;
            if (move_uploaded_file($file['tmp_name'], $upload_dir . $filename)) {
                $profile_image = $filename;
            }
        }
    }

    // Start transaction
    $db->beginTransaction();

    // Create user account
    $username = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $youtube_handle));
    $full_name = $youtube_handle;

    $db->query('INSERT INTO users (username, email, password_hash, full_name, is_active) VALUES (:username, :email, :password_hash, :full_name, 1)');
    $db->bind(':username', $username);
    $db->bind(':email', $email);
    $db->bind(':password_hash', $password_hash);
    $db->bind(':full_name', $full_name);
    $db->execute();

    $user_id = $db->lastInsertId();

    // Create creator account
    $creator_username = $username . '_' . $user_id;
    $platform_url = 'https://youtube.com/@' . $youtube_handle;

    $db->query('
        INSERT INTO creators (
            username, display_name, email, bio, platform_url,
            subscriber_count, default_funding_threshold, commission_rate,
            is_verified, is_active, applicant_user_id, application_status,
            profile_image
        ) VALUES (
            :username, :display_name, :email, :bio, :platform_url,
            1000, 50.00, 5.00, 1, 1, :applicant_user_id, "approved", :profile_image
        )
    ');

    $db->bind(':username', $creator_username);
    $db->bind(':display_name', $youtube_handle);
    $db->bind(':email', $email);
    $db->bind(':bio', 'YouTube Creator on TopicLaunch');
    $db->bind(':platform_url', $platform_url);
    $db->bind(':applicant_user_id', $user_id);
    $db->bind(':profile_image', $profile_image);
    $db->execute();

    $creator_id = $db->lastInsertId();

    // Commit transaction
    $db->endTransaction();

    // Set session variables
    $_SESSION['user_id'] = $user_id;
    $_SESSION['username'] = $youtube_handle;
    $_SESSION['email'] = $email;
    $_SESSION['creator_id'] = $creator_id;

    // Redirect to dashboard
    header('Location: /creators/dashboard.php?welcome=1');
    exit;

} catch (Exception $e) {
    if (isset($db)) {
        $db->cancelTransaction();
    }
    error_log("Registration error: " . $e->getMessage());
    header('Location: /creators/signup.php?error=registration_failed');
    exit;
}
?>
