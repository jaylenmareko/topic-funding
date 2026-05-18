<?php
// api/paypal-connect.php - Initiates PayPal OAuth for creator payout account connection
session_start();
require_once __DIR__ . '/../config/paypal-keys.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$host        = $_SERVER['HTTP_HOST'] ?? 'topiclaunch.com';
$redirect_uri = 'https://' . $host . '/api/paypal-connect-return.php';

// Scopes: openid = identity, email = their PayPal email
$params = http_build_query([
    'client_id'     => PAYPAL_CLIENT_ID,
    'response_type' => 'code',
    'scope'         => 'openid email',
    'redirect_uri'  => $redirect_uri,
]);

$oauth_url = 'https://www.paypal.com/signin/authorize?' . $params;

header('Content-Type: application/json');
echo json_encode(['success' => true, 'url' => $oauth_url]);
