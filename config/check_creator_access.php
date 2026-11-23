<?php
// config/check_creator_access.php
// Include this at the top of any page that creators should NOT access

if (!isset($_SESSION)) {
    session_start();
}

// Only check if user is logged in
if (isset($_SESSION['user_id'])) {
    // Get current page name
    $current_page = basename($_SERVER['PHP_SELF']);
    
    // Pages that creators ARE allowed to access
    $allowed_pages = ['dashboard.php', 'edit.php'];
    
    // If current page is NOT in allowed list, check if user is a creator
    if (!in_array($current_page, $allowed_pages)) {
        // Try to load database
        $db_loaded = false;
        if (file_exists(__DIR__ . '/database.php')) {
            require_once __DIR__ . '/database.php';
            $db_loaded = true;
        } elseif (file_exists(__DIR__ . '/../config/database.php')) {
            require_once __DIR__ . '/../config/database.php';
            $db_loaded = true;
        } elseif (file_exists('config/database.php')) {
            require_once 'config/database.php';
            $db_loaded = true;
        }
        
        if ($db_loaded) {
            try {
                $db = new Database();
                $db->query('SELECT id FROM creators WHERE applicant_user_id = :user_id AND is_active = 1');
                $db->bind(':user_id', $_SESSION['user_id']);
                $is_creator = $db->single();
                
                // If they're a creator, redirect to dashboard
                if ($is_creator) {
                    header('Location: /creators/dashboard.php');
                    exit;
                }
            } catch (Exception $e) {
                // If query fails, continue to page
                error_log("Creator access check failed: " . $e->getMessage());
            }
        }
    }
}
