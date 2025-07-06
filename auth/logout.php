<?php
// auth/logout.php - Updated to redirect to login page instead of landing page
session_start();

// Store username for goodbye message
$username = isset($_SESSION['username']) ? $_SESSION['username'] : 'User';

// Destroy all session data
session_destroy();

// Show a brief goodbye message before redirecting to LOGIN PAGE (not landing page)
?>
<!DOCTYPE html>
<html>
<head>
    <title>Logged Out - Topic Funding</title>
    <meta http-equiv="refresh" content="2;url=login.php">
    <style>
        body { font-family: Arial, sans-serif; text-align: center; padding: 50px; background: #f5f5f5; }
        .logout-message { background: white; padding: 30px; border-radius: 8px; max-width: 400px; margin: 0 auto; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .success { color: #28a745; margin-bottom: 15px; }
    </style>
</head>
<body>
    <div class="logout-message">
        <div class="success">✅ Logged out successfully!</div>
        <p>Goodbye, <?php echo htmlspecialchars($username); ?>!</p>
        <p>Redirecting to login page...</p>
        <a href="login.php">Click here if not redirected automatically</a>
    </div>
</body>
</html>
