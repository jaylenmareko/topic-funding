<?php
// auth/google-callback.php - Google OAuth callback
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

session_start();
require_once '../config/database.php';

define('GOOGLE_CLIENT_ID', '865712888292-jlvrfc960r433hcaip15gdjmvv6janoe.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', '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');
define('GOOGLE_REDIRECT_URI', 'https://topiclaunch.com/auth/google-callback.php');

if (!isset($_GET['code'])) {
    header('Location: ../index.php?error=oauth_failed');
    exit;
}

try {
    // Exchange code for token
    $token_url = 'https://oauth2.googleapis.com/token';
    $token_data = [
        'code' => $_GET['code'],
        'client_id' => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri' => GOOGLE_REDIRECT_URI,
        'grant_type' => 'authorization_code'
    ];
    
    $ch = curl_init($token_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($token_data));
    $token_response = curl_exec($ch);
    curl_close($ch);
    
    $token_info = json_decode($token_response, true);
    
    if (!isset($token_info['access_token'])) {
        throw new Exception('Failed to get access token');
    }
    
    $access_token = $token_info['access_token'];
    
    // Get user info
    $userinfo_url = 'https://www.googleapis.com/oauth2/v2/userinfo';
    $ch = curl_init($userinfo_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $access_token]);
    $userinfo_response = curl_exec($ch);
    curl_close($ch);
    
    $user_info = json_decode($userinfo_response, true);
    
    // Get YouTube channel
    $youtube_url = 'https://www.googleapis.com/youtube/v3/channels?part=snippet&mine=true';
    $ch = curl_init($youtube_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $access_token]);
    $youtube_response = curl_exec($ch);
    curl_close($ch);
    
    $youtube_data = json_decode($youtube_response, true);
    
    if (!isset($youtube_data['items'][0])) {
        header('Location: ../index.php?error=no_youtube_channel');
        exit;
    }
    
    $channel = $youtube_data['items'][0]['snippet'];
    $channel_id = $youtube_data['items'][0]['id'];
    $channel_title = $channel['title'];
    $channel_custom_url = $channel['customUrl'] ?? $channel_title;
    
    if (strpos($channel_custom_url, '@') === 0) {
        $channel_custom_url = substr($channel_custom_url, 1);
    }
    
    $google_email = $user_info['email'];
    $profile_image_url = $channel['thumbnails']['default']['url'] ?? null;
    
    // Check if exists
    $db = new Database();
    $db->query('SELECT c.*, u.id as user_id FROM creators c LEFT JOIN users u ON c.applicant_user_id = u.id WHERE c.email = :email');
    $db->bind(':email', $google_email);
    $existing = $db->single();
    
    if ($existing) {
        $_SESSION['user_id'] = $existing->user_id;
        $_SESSION['username'] = $existing->display_name;
        $_SESSION['email'] = $existing->email;
        session_regenerate_id(true);
        header('Location: ../creators/dashboard.php');
        exit;
    }
    
    // Create new account
    $db->beginTransaction();
    
    $username = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $channel_custom_url));
    $password_hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    
    $db->query('INSERT INTO users (username, email, password_hash, full_name) VALUES (:username, :email, :password_hash, :full_name)');
    $db->bind(':username', $username);
    $db->bind(':email', $google_email);
    $db->bind(':password_hash', $password_hash);
    $db->bind(':full_name', $channel_title);
    $db->execute();
    
    $user_id = $db->lastInsertId();
    
    $creator_username = $username . '_' . $user_id;
    $platform_url = 'https://youtube.com/@' . $channel_custom_url;
    
    $db->query('
        INSERT INTO creators (
            username, display_name, email, bio, platform_url,
            subscriber_count, default_funding_threshold, commission_rate,
            is_verified, is_active, applicant_user_id, application_status
        ) VALUES (
            :username, :display_name, :email, :bio, :platform_url,
            1000, 50.00, 5.00, 1, 1, :applicant_user_id, "approved"
        )
    ');
    
    $db->bind(':username', $creator_username);
    $db->bind(':display_name', $channel_custom_url);
    $db->bind(':email', $google_email);
    $db->bind(':bio', 'YouTube Creator on TopicLaunch');
    $db->bind(':platform_url', $platform_url);
    $db->bind(':applicant_user_id', $user_id);
    $db->execute();
    
    $creator_id = $db->lastInsertId();
    
    // Save profile image
    if ($profile_image_url) {
        $upload_dir = '../uploads/creators/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        
        $image_data = @file_get_contents($profile_image_url);
        if ($image_data) {
            $filename = 'creator_' . $creator_id . '_' . time() . '.jpg';
            file_put_contents($upload_dir . $filename, $image_data);
            
            $db->query('UPDATE creators SET profile_image = :profile_image WHERE id = :id');
            $db->bind(':profile_image', $filename);
            $db->bind(':id', $creator_id);
            $db->execute();
        }
    }
    
    $db->endTransaction();
    
    $_SESSION['user_id'] = $user_id;
    $_SESSION['username'] = $channel_custom_url;
    $_SESSION['email'] = $google_email;
    session_regenerate_id(true);
    
    header('Location: ../creators/dashboard.php?welcome=1');
    exit;
    
} catch (Exception $e) {
    error_log("Google OAuth error: " . $e->getMessage());
    header('Location: ../index.php?error=oauth_error');
    exit;
}
