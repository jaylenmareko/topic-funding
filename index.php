<?php
// index.php - FIXED VERSION with better error handling
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

// Only require database if we need to check user status
if (isset($_SESSION['user_id'])) {
    try {
        require_once 'config/database.php';
        $db = new Database();
        $db->query('SELECT id FROM creators WHERE applicant_user_id = :user_id AND is_active = 1');
        $db->bind(':user_id', $_SESSION['user_id']);
        $is_creator = $db->single();
        
        if ($is_creator) {
            header('Location: creators/dashboard.php');
        } else {
            header('Location: creators/index.php');
        }
        exit;
    } catch (Exception $e) {
        error_log("Index redirect error: " . $e->getMessage());
        // Clear broken session and show landing page
        session_destroy();
        session_start();
    }
}

// Handle login form
$login_error = '';
if ($_POST && isset($_POST['email']) && isset($_POST['password'])) {
    require_once 'config/database.php';
    
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    
    if (!empty($email) && !empty($password)) {
        try {
            $db = new Database();
            $db->query('SELECT * FROM users WHERE email = :email');
            $db->bind(':email', $email);
            $user = $db->single();
            
            if ($user) {
                // Support both old and new password column names
                $password_hash = isset($user->password_hash) ? $user->password_hash : $user->password;
                
                if (password_verify($password, $password_hash)) {
                    $_SESSION['user_id'] = $user->id;
                    $_SESSION['username'] = $user->username;
                    $_SESSION['full_name'] = isset($user->full_name) ? $user->full_name : $user->username;
                    $_SESSION['email'] = $user->email;
                    session_regenerate_id(true);
                    
                    // Check if user is creator or fan and redirect accordingly
                    $db->query('SELECT id FROM creators WHERE applicant_user_id = :user_id AND is_active = 1');
                    $db->bind(':user_id', $user->id);
                    $is_creator = $db->single();
                    
                    if ($is_creator) {
                        header('Location: creators/dashboard.php');
                    } else {
                        header('Location: creators/index.php');
                    }
                    exit;
                } else {
                    $login_error = 'Invalid email or password';
                }
            } else {
                $login_error = 'Invalid email or password';
            }
        } catch (Exception $e) {
            error_log("Login error: " . $e->getMessage());
            $login_error = 'Login failed. Please try again.';
        }
    } else {
        $login_error = 'Please enter both email and password';
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>TopicLaunch - Fund Topics from Your Favorite Creator</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; margin: 0; padding: 0; background: #f5f5f5; }
        
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
        .login-error { color: #ffcccc; font-size: 12px; margin-left: 10px; background: rgba(255,0,0,0.2); padding: 5px 10px; border-radius: 4px; }
        
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
        .user-type h3 {
            margin-bottom: 15px;
            font-size: 24px;
        }
        .user-type p {
            margin-bottom: 20px;
            font-size: 16px;
            opacity: 0.9;
        }
        .btn-creator, .btn-fan {
            display: inline-block;
            padding: 15px 30px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: bold;
            font-size: 16px;
            transition: all 0.3s;
            border: 2px solid transparent;
        }
        .btn-creator {
            background: #ff0000;
            color: white;
        }
        .btn-creator:hover {
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
        
        /* 2-Step Process for Fans */
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
        .process-step h3 { color: #333; margin-bottom: 15px; font-size: 22px; }
        .process-step p { color: #666; line-height: 1.6; }
        
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
            .process-steps { grid-template-columns: 1fr; }
            .login-form { 
                flex-direction: column; 
                gap: 8px;
                width: 100%;
            }
            .login-input {
                width: 100%;
            }
            .login-btn {
                width: 100%;
            }
            .nav-container { 
                flex-direction: column; 
                gap: 15px;
            }
            .login-error {
                margin-left: 0;
                margin-top: 5px;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="topiclaunch-nav">
        <div class="nav-container">
            <span class="nav-logo">TopicLaunch</span>
            
            <form method="POST" class="login-form">
                <input type="email" name="email" placeholder="Email" class="login-input" required value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                <input type="password" name="password" placeholder="Password" class="login-input" required>
                <button type="submit" class="login-btn">Login</button>
                <?php if ($login_error): ?>
                    <span class="login-error"><?php echo htmlspecialchars($login_error); ?></span>
                <?php endif; ?>
            </form>
        </div>
    </nav>

    <!-- Hero Section -->
    <div class="hero">
        <h1>Fund Topics for Creators</h1>
        
        <!-- User Type Selection -->
        <div class="user-type-selector">
            <div class="user-type creator">
                <a href="auth/register.php?type=creator" class="btn-creator">
                    Creator Signup
                </a>
            </div>
            
            <div class="user-type fan">
                <a href="auth/register.php?type=fan" class="btn-fan">
                    Fan Signup
                </a>
            </div>
        </div>
    </div>

    <div class="container">
        <!-- 2-Step Process for Fans -->
        <div class="process-steps">
            <div class="process-step" data-step="1">
                <div class="process-icon">💡</div>
                <h3>Fund Topics</h3>
                <p>Make the FIRST contribution for a video idea. Then others chip in until goal is reached.</p>
            </div>
            <div class="process-step" data-step="2">
                <div class="process-icon">⚡</div>
                <h3>48-Hour Delivery</h3>
                <p>Creator delivers and gets 90% of funding, or fans get refunded.</p>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer">
        <div>
            <p>&copy; 2025 TopicLaunch. All rights reserved.</p>
            <p style="margin-top: 10px;">
                <a href="auth/login.php">Login</a> | 
                <a href="auth/register.php?type=creator">Creator Signup</a> | 
                <a href="auth/register.php?type=fan">Fan Signup</a>
            </p>
        </div>
    </footer>
</body>
</html>
