<?php
// api/paypal-connect-return.php - Handles PayPal OAuth return, saves creator's verified PayPal email
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/paypal-keys.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /auth/login.php');
    exit;
}

$code  = $_GET['code']  ?? '';
$error = $_GET['error'] ?? '';

if ($error || !$code) {
    header('Location: /creators/dashboard.php?paypal_return=cancelled');
    exit;
}

try {
    $host         = $_SERVER['HTTP_HOST'] ?? 'topiclaunch.com';
    $redirect_uri = 'https://' . $host . '/api/paypal-connect-return.php';

    // Exchange authorization code for access token
    $ch = curl_init(PAYPAL_BASE_URL . '/v1/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => $redirect_uri,
        ]),
        CURLOPT_USERPWD    => PAYPAL_CLIENT_ID . ':' . PAYPAL_SECRET,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $response = json_decode(curl_exec($ch), true);
    $code_h   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code_h !== 200 || empty($response['access_token'])) {
        error_log('PayPal OAuth token exchange failed: ' . json_encode($response));
        header('Location: /creators/dashboard.php?paypal_return=error');
        exit;
    }

    $access_token = $response['access_token'];

    // Get the creator's PayPal email from userinfo endpoint
    $ch = curl_init(PAYPAL_BASE_URL . '/v1/identity/oauth2/userinfo?schema=paypalv1');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json',
        ],
    ]);
    $userinfo = json_decode(curl_exec($ch), true);
    $code_h   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code_h !== 200 || empty($userinfo['emails'][0]['value'])) {
        error_log('PayPal userinfo failed: ' . json_encode($userinfo));
        header('Location: /creators/dashboard.php?paypal_return=error');
        exit;
    }

    $paypal_email = $userinfo['emails'][0]['value'];

    // Save to creator record
    $db = new Database();
    $db->query("
        UPDATE creators SET paypal_email = :paypal_email, updated_at = NOW()
        WHERE applicant_user_id = :user_id AND is_active = 1
    ");
    $db->bind(':paypal_email', $paypal_email);
    $db->bind(':user_id',      $_SESSION['user_id']);
    $db->execute();

    error_log("PayPal connected for user {$_SESSION['user_id']}: $paypal_email");
    header('Location: /creators/dashboard.php?paypal_return=success');
    exit;

} catch (Exception $e) {
    error_log('PayPal connect return error: ' . $e->getMessage());
    header('Location: /creators/dashboard.php?paypal_return=error');
    exit;
}
