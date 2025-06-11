<?php
// debug_session.php - Create this file in your root directory to debug sessions
session_start();

echo "<h2>Session Debug Info</h2>";
echo "<p><strong>Session ID:</strong> " . session_id() . "</p>";
echo "<p><strong>Session Data:</strong></p>";
echo "<pre>";
print_r($_SESSION);
echo "</pre>";

if (isset($_SESSION['user_id'])) {
    echo "<p style='color: green;'>✅ User is logged in as: " . htmlspecialchars($_SESSION['username']) . " (ID: " . $_SESSION['user_id'] . ")</p>";
} else {
    echo "<p style='color: red;'>❌ No user logged in</p>";
}

echo "<p><a href='auth/logout.php'>Logout</a> | <a href='index.php'>Home</a></p>";
?>
