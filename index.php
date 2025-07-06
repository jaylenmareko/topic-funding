<?php
// index.php - Updated to redirect fans to creators page, creators to dashboard
session_start();
require_once 'config/database.php';

// REDIRECT LOGGED IN USERS BASED ON ROLE
if (isset($_SESSION['user_id'])) {
    $db = new Database();
    $db->query('SELECT id FROM creators WHERE applicant_user_id = :user_id AND is_active = 1');
    $db->bind(':user_id', $_SESSION['user_id']);
    $is_creator = $db->single();
    
    if ($is_creator) {
        // Creators go to dashboard
        header('Location: dashboard/index.php');
    } else {
        // Fans go to browse YouTubers (main page for fans)
        header('Location: creators/index.php');
    }
    exit;
}

// Only show landing page content to non-logged in users
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
            
            // Check if user is creator or fan and redirect accordingly
            $db->query('SELECT id FROM creators WHERE applicant_user_id = :user_id AND is_active = 1');
            $db->bind(':user_id', $user->id);
            $is_creator = $db->single();
            
            if ($is_creator) {
                header('Location: dashboard/index.php'); // Creators go to dashboard
            } else {
                header('Location: creators/index.php'); // Fans go to browse YouTubers
            }
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
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; background: #f5f5f5; }
        
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
        
        /* User Type Selector */
        .user-type-selector {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            max-width: 800px;
            margin: 0 auto;
            padding: 0 20px;
        }
        .user-type {
            background: rgba(255,255,255,0.1);
            padding: 40px 30px;
            border-radius: 15px;
            text-align: center;
            border: 2px solid rgba(255,255,255,0.2);
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }
        .user-type:hover {
            background: rgba(255,255,255,0.15);
            border-color: rgba(255,255,255,0.4);
            transform: translateY(-5px);
        }
        .user-icon {
            font-size: 60px;
            margin-bottom: 20px;
            display: block;
        }
        .user-type h3 {
            color: white;
            margin: 0 0 15px 0;
            font-size: 24px;
        }
        .user-type p {
            color: rgba(255,255,255,0.9);
            margin: 0 0 25px 0;
            font-size: 16px;
            line-height: 1.4;
        }
        .btn-youtuber, .btn-fan {
            display: inline-block;
            padding: 15px 30px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: bold;
            font-size: 16px;
            transition: all 0.3s;
            border: 2px solid transparent;
        }
        .btn-youtuber {
            background: #ff0000;
            color: white;
        }
        .btn-youtuber:hover {
            background: #cc0000;
            color: white;
            text-decoration: none;
            transform: translateY(-2px);
        }
        .btn-fan {
            background: #28a745;
            color: white;
        }
        .btn-fan:hover {
            background: #218838;
            color: white;
            text-decoration: none;
            transform: translateY(-2px);
        }
        
        .container { max-width: 1200px; margin: 0 auto; padding: 40px 20px; }
        .section-title { font-size: 32px; text-align: center; margin-bottom: 40px; color: #333; }
        
        /* 3-Step Process for Fans */
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
            .user-type-selector { 
                grid-template-columns: 1fr;
                gap: 20px;
            }
            .user-type {
                padding: 30px 20px;
            }
            .user-icon {
                font-size: 50px;
            }
            .process-steps { grid-template-columns: 1fr; }
            .login-form { flex-direction: column; gap: 5px; }
            .nav-container { flex-direction: column; gap: 15px; }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="topiclaunch-nav">
        <div class="nav-container">
            <span class="nav-logo">TopicLaunch</span>
            
            <!-- Only show login form since logged in users are redirected -->
            <form method="POST" class="login-form">
                <input type="email" name="email" placeholder="Email" class="login-input" required>
                <input type="password" name="password" placeholder="Password" class="login-input" required>
                <button type="submit" class="login-btn">Login</button>
                <?php if ($login_error): ?>
                    <span class="login-error"><?php echo htmlspecialchars($login_error); ?></span>
                <?php endif; ?>
            </form>
        </div>
    </nav>

    <!-- Hero Section - Only for Guests -->
    <div class="hero">
        <h1>Fund Topics from Your Favorite YouTuber</h1>
        <p>Propose specific topics, fund them with the community, and creators deliver in 48 hours</p>
        
        <!-- User Type Selection -->
        <div class="user-type-selector">
            <div class="user-type youtuber">
                <div class="user-icon">📺</div>
                <h3>Are you a YouTuber?</h3>
                <a href="auth/register.php?type=creator" class="btn-youtuber">
                    Join as YouTuber
                </a>
            </div>
            
            <div class="user-type fan">
                <div class="user-icon">💰</div>
                <h3>Are you a Fan?</h3>
                <a href="auth/register.php?type=fan" class="btn-fan">
                    Propose a Topic
                </a>
            </div>
        </div>
    </div>

    <div class="container">
        <!-- 3-Step Process for Fans -->
        <div class="process-steps">
            <div class="process-step" data-step="1">
                <div class="process-icon">💡</div>
                <h3>Create & Fund</h3>
                <p>Have an idea for your favorite YouTuber? Create the topic and make the first contribution. Your topic goes live immediately!</p>
            </div>
            <div class="process-step" data-step="2">
                <div class="process-icon">🤝</div>
                <h3>Community Backs It</h3>
                <p>Other fans join in to fund the topic. Once the goal is reached, the YouTuber gets notified to create the content. (TopicLaunch takes 10%)</p>
            </div>
            <div class="process-step" data-step="3">
                <div class="process-icon">⚡</div>
                <h3>48-Hour Delivery</h3>
                <p>YouTubers have 48 hours to deliver your requested content, or everyone gets automatically refunded.</p>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer">
        <div>
            <p>&copy; 2025 TopicLaunch. All rights reserved.</p>
        </div>
    </footer>
</body>
</html>
