<?php
// creators/verify.php - Simplified (removed creator_verification table)
session_start();

// Redirect to dashboard since verification is not implemented
if (isset($_SESSION['user_id'])) {
    $db = new Database();
    $db->query('SELECT id FROM creators WHERE applicant_user_id = :user_id AND is_active = 1');
    $db->bind(':user_id', $_SESSION['user_id']);
    $is_creator = $db->single();
    
    if ($is_creator) {
        header('Location: ../creators/dashboard.php');
    } else {
        header('Location: ../dashboard/index.php');
    }
} else {
    header('Location: ../auth/login.php');
}
exit;
?>
