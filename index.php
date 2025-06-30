<?php
// index.php - Updated homepage with streamlined 3-step process
session_start();
require_once 'config/database.php';
require_once 'config/navigation.php';

$helper = new DatabaseHelper();
$creators = $helper->getAllCreators();

// Handle login form
$login_error = '';
if ($_POST && isset($_POST['email']) && isset($_POST['password'])) {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    
    if (!empty($email) && !empty($password)) {
        $db = new Database();
        $db->query('SELECT * FROM users WHERE email = :email AND is_active = 1');
        $db->bind(':email', $email);
        $user = $db->single();
        
        if ($user && password_verify($password, $user->password_hash)) {
            $_SESSION['user_id'] = $user->id;
            $_SESSION['username'] = $user->username;
            $_SESSION['full_name'] = $user->full_name;
            $_SESSION['email'] = $user->email;
            session_regenerate_id(true);
            header('Location: dashboard/index.php');
            exit;
        } else {
            $login_error = 'Invalid email or password';
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>TopicLaunch - Fund Topics from Your Favorite YouTuber</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; background: #f5f5f5;         }
        
        /* Navigation */
        .topiclaunch-nav {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 15px 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .nav-container {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 20px;
        }
        .nav-logo {
            font-size: 24px;
            font-weight: bold;
            color: white;
            text-decoration: none;
        }
        .login-form {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .login-input {
            padding: 8px 12px;
            border: none;
            border-radius: 4px;
            font-size: 14px;
        }
        .login-btn {
            background: white;
            color: #667eea;
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
        }
        .login-btn:hover { background: #f0f0f0; }
        .login-error { color: #ff6b6b; font-size: 12px; margin-left: 10px; }
        
        /* Hero Section */
        .hero { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 60px 20px; text-align: center; }
        .hero h1 { font-size: 48px; margin: 0 0 20px 0; font-weight: bold; }
        .hero p { font-size: 20px; margin: 0 0 30px 0; opacity: 0.9; }
        .hero-buttons { display: flex; gap: 20px; justify-content: center; flex-wrap: wrap; }
        .btn { padding: 15px 30px; border-radius: 8px; text-decoration: none; font-weight: bold; font-size: 16px; transition: all 0.3s; }
        .btn-success { background: #28a745; color: white; }
        .btn-success:hover { background: #218838; color: white; text-decoration: none; }
        .btn-secondary { background: rgba(255,255,255,0.2); color: white; border: 2px solid white; }
        .btn-secondary:hover { background: rgba(255,255,255,0.3); }
        
        .container { max-width: 1200px; margin: 0 auto; padding: 40px 20px; }
        .section-title { font-size: 32px; text-align: center; margin-bottom: 40px; color: #333; }
        
        /* 3-Step Process */
        .process-steps { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 30px; margin: 60px 0; }
        .process-step { background: white; padding: 30px; border-radius: 12px; text-align: center; box-shadow: 0 4px 12px rgba(0,0,0,0.1); position: relative; }
        .process-step::before {
            content: attr(data-step);
            position: absolute;
            top: -15px;
            left: 50%;
            transform: translateX(-50%);
            background: #667eea;
            color: white;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 14px;
        }
        .process-icon { font-size: 48px; margin-bottom: 20px; }
        .process-step h3 { color: #333; margin-bottom: 15px; }
        
        /* Footer */
        .footer {
            background: #333;
            color: #999;
            text-align: center;
            padding: 20px;
            margin-top: 60px;
            font-size: 14px;
        }
        .footer a {
            color: #999;
            text-decoration: none;
        }
        .footer a:hover {
            color: white;
        }
        
        @media (max-width: 768px) {
            .hero h1 { font-size: 36px; }
            .hero p { font-size: 18px; }
            .hero-buttons { flex-direction: column; align-items: center; }
            .process-steps { grid-template-columns: 1fr; }
            .login-form { flex-direction: column; gap: 5px; }
            .nav-container { flex-direction: column; gap: 15px; }
        }
