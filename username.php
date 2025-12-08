<?php
// username.php - Vanity URL handler
require_once 'config/database.php';

$username = isset($_GET['username']) ? trim($_GET['username']) : '';

if (empty($username)) {
    header('Location: index.php');
    exit;
}

try {
    $db = new Database();
    
    // Look up creator by display_name (their platform handle)
    $db->query('SELECT id FROM creators WHERE display_name = :username AND is_active = 1');
    $db->bind(':username', $username);
    $creator = $db->single();
    
    if ($creator) {
        // Redirect to creator profile page
        header('Location: creators/profile.php?id=' . $creator->id);
        exit;
    } else {
        // Creator not found - redirect to browse creators
        header('Location: creators/index.php');
        exit;
    }
} catch (Exception $e) {
    error_log("Vanity URL error: " . $e->getMessage());
    header('Location: creators/index.php');
    exit;
}
?>
