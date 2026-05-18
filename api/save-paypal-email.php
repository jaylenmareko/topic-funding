<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$data  = json_decode(file_get_contents('php://input'), true);
$email = trim($data['paypal_email'] ?? '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'error' => 'Invalid email address']);
    exit;
}

try {
    require_once __DIR__ . '/../config/database.php';
    $db = new Database();
    $db->query("UPDATE creators SET paypal_email = :email, updated_at = NOW() WHERE applicant_user_id = :user_id AND is_active = 1");
    $db->bind(':email',   $email);
    $db->bind(':user_id', $_SESSION['user_id']);
    $db->execute();

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    error_log('save-paypal-email error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
