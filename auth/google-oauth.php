<?php
// auth/google-oauth.php - Initiate Google OAuth flow
session_start();

// Google OAuth Configuration
define('GOOGLE_CLIENT_ID', '865712888292-jlvrfc960r433hcaip15gdjmvv6janoe.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', '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');
define('GOOGLE_REDIRECT_URI', 'https://topiclaunch.com/auth/google-callback.php');

// Build Google OAuth URL
$params = [
    'client_id' => GOOGLE_CLIENT_ID,
    'redirect_uri' => GOOGLE_REDIRECT_URI,
    'response_type' => 'code',
    'scope' => 'openid email profile',
    'access_type' => 'offline',
    'prompt' => 'consent'
];

$auth_url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);

// Redirect to Google
header('Location: ' . $auth_url);
exit;
