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
    header('Location: /index.php');
    exit;
}

$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

// Basic validation
if (empty($email) || empty($password)) {
    header('Location: /index.php?error=missing_credentials');
    exit;
}

try {
    $db = new Database();

    // Check for admin email first
    define('ADMIN_EMAIL', 'marekodavis@gmail.com');

    if ($email === ADMIN_EMAIL) {
        // Admin login
        $db->query('SELECT id, username, email, password_hash FROM users WHERE email = :email');
        $db->bind(':email', $email);
        $user = $db->single();

        if ($user && password_verify($password, $user->password_hash)) {
            $_SESSION['user_id'] = $user->id;
            $_SESSION['username'] = 'Admin';
            $_SESSION['email'] = $user->email;
            $_SESSION['is_admin'] = true;

            header('Location: /admin/index.php');
            exit;
        } else {
            header('Location: /index.php?error=invalid_credentials');
            exit;
        }
    }

    // Creator login
    $db->query('
        SELECT u.id, u.username, u.email, u.password_hash, c.id as creator_id, c.display_name
        FROM users u
        LEFT JOIN creators c ON c.applicant_user_id = u.id
        WHERE u.email = :email
    ');
    $db->bind(':email', $email);
    $user = $db->single();

    if (!$user) {
        header('Location: /index.php?error=invalid_credentials');
        exit;
    }

    // Verify password
    if (!password_verify($password, $user->password_hash)) {
        header('Location: /index.php?error=invalid_credentials');
        exit;
    }

    // Set session variables
    $_SESSION['user_id'] = $user->id;
    $_SESSION['username'] = $user->display_name ?? $user->username;
    $_SESSION['email'] = $user->email;

    if ($user->creator_id) {
        $_SESSION['creator_id'] = $user->creator_id;
    }

    // Redirect to appropriate dashboard
    if ($user->creator_id) {
        header('Location: /creators/dashboard.php');
    } else {
        // Regular user (contributor)
        header('Location: /index.php');
    }
    exit;

} catch (Exception $e) {
    error_log("Login error: " . $e->getMessage());
    header('Location: /index.php?error=login_failed');
    exit;
}
?>
