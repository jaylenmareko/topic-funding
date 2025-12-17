<?php
// auth/google-oauth.php - Google OAuth for YouTube creators
session_start();
require_once '../config/database.php';

// ============================================
// GOOGLE OAUTH CONFIGURATION
// ============================================
// Get these from: https://console.cloud.google.com/
define('GOOGLE_CLIENT_ID', '865712888292-jlvrfc960r433hcaip15gdjmvv6janoe.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', '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');
define('GOOGLE_REDIRECT_URI', 'https://topiclaunch.com/auth/google-callback.php');

// Build Google OAuth URL
$google_oauth_url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
    'client_id' => GOOGLE_CLIENT_ID,
    'redirect_uri' => GOOGLE_REDIRECT_URI,
    'response_type' => 'code',
    'scope' => 'openid email profile https://www.googleapis.com/auth/youtube.readonly',
    'access_type' => 'offline',
    'prompt' => 'consent'
]);

// Redirect to Google
header('Location: ' . $google_oauth_url);
exit;
