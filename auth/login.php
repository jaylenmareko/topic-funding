<?php
// auth/login.php - Minimal login page matching Rizzdem
session_start();

// If already logged in, redirect to appropriate page
if (isset($_SESSION['user_id'])) {
    require_once '../config/database.php';
    $db = new Database();
    $db->query('SELECT id FROM creators WHERE applicant_user_id = :user_id AND is_active = 1');
    $db->bind(':user_id', $_SESSION['user_id']);
    $creator = $db->single();
    
    if ($creator) {
        header('Location: /creators/dashboard.php');
    } else {
        header('Location: /');
    }
    exit;
}

$error = '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once '../config/database.php';
    
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($email) || empty($password)) {
        $error = 'Please enter both email and password';
    } else {
        $db = new Database();
        $db->query('SELECT id, email, password_hash FROM users WHERE email = :email AND is_active = 1');
        $db->bind(':email', $email);
        $user = $db->single();
        
        if (!$user || !password_verify($password, $user->password_hash)) {
            $error = 'Invalid email or password';
        } else {
            // Login successful
            $_SESSION['user_id'] = $user->id;
            $_SESSION['email'] = $user->email;
            
            // Check if creator
            $db->query('SELECT id, username, display_name FROM creators WHERE applicant_user_id = :user_id AND is_active = 1');
            $db->bind(':user_id', $user->id);
            $creator = $db->single();
            
            if ($creator) {
                $_SESSION['username'] = $creator->display_name ?: $creator->username;
                header('Location: /creators/dashboard.php');
            } else {
                header('Location: /');
            }
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Log In - TopicLaunch</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #fafafa;
            min-height: 100vh;
            padding: 0;
        }
        
        /* Navigation */
        .topiclaunch-nav {
            background: white;
            padding: 15px 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border-bottom: 1px solid #f0f0f0;
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
            color: #FF0000;
            text-decoration: none;
            cursor: pointer;
        }
        
        /* Nav Center Links */
        .nav-center {
            display: flex;
            gap: 30px;
            align-items: center;
        }
        
        .nav-link {
            color: #6b7280;
            text-decoration: none;
            font-size: 15px;
            font-weight: 500;
            transition: color 0.2s;
        }
        
        .nav-link:hover {
            color: #FF0000;
        }
        
        .nav-buttons {
            display: flex;
            gap: 15px;
            align-items: center;
        }
        
        .nav-login-btn {
            color: #333;
            text-decoration: none;
            font-size: 15px;
            font-weight: 500;
            padding: 8px 16px;
            transition: color 0.2s;
        }
        
        .nav-login-btn:hover {
            color: #FF0000;
        }
        
        .nav-getstarted-btn {
            background: #FF0000;
            color: white;
            text-decoration: none;
            font-size: 15px;
            font-weight: 600;
            padding: 10px 24px;
            border-radius: 50px;
            transition: all 0.2s;
        }
        
        .nav-getstarted-btn:hover {
            background: #CC0000;
            transform: translateY(-1px);
        }
        
        /* Login Page */
        .login-wrapper {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            min-height: calc(100vh - 70px);
            padding: 80px 20px 40px;
        }
        
        .login-icon {
            width: 64px;
            height: 64px;
            background: linear-gradient(135deg, #FFE5E5 0%, #FFD1D1 100%);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
        }
        
        .login-icon svg {
            width: 32px;
            height: 32px;
            stroke: #FF0000;
        }
        
        .login-title {
            text-align: center;
            font-size: 28px;
            font-weight: 700;
            color: #000;
            margin-bottom: 8px;
        }
        
        .login-subtitle {
            text-align: center;
            font-size: 15px;
            color: #6b7280;
            margin-bottom: 32px;
        }
        
        .login-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            padding: 40px;
            width: 100%;
            max-width: 420px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
        }
        
        .form-label svg {
            width: 16px;
            height: 16px;
            stroke: #6b7280;
        }
        
        .form-input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 15px;
            transition: all 0.2s;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #FF0000;
        }
        
        .form-input::placeholder {
            color: #9ca3af;
        }
        
        .forgot-password {
            text-align: right;
            margin-bottom: 24px;
        }
        
        .forgot-password a {
            color: #FF0000;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
        }
        
        .forgot-password a:hover {
            text-decoration: underline;
        }
        
        .login-btn {
            width: 100%;
            padding: 14px;
            background: #FF0000;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .login-btn:hover {
            background: #CC0000;
            transform: translateY(-1px);
        }
        
        .divider {
            text-align: center;
            margin: 24px 0;
            color: #9ca3af;
            font-size: 14px;
        }
        
        .signup-link {
            text-align: center;
            color: #6b7280;
            font-size: 15px;
        }
        
        .signup-link a {
            color: #FF0000;
            text-decoration: none;
            font-weight: 600;
        }
        
        .signup-link a:hover {
            text-decoration: underline;
        }
        
        .error-message {
            background: #fee;
            color: #c33;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            border-left: 4px solid #c33;
        }
        
        @media (max-width: 640px) {
            .login-container {
                padding: 32px 24px;
            }
            
            .login-wrapper {
                padding-top: 40px;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="topiclaunch-nav">
        <div class="nav-container">
            <a href="/" class="nav-logo">TopicLaunch</a>
            
            <!-- Center Navigation Links -->
            <div class="nav-center">
                <a href="/creators/index.php" class="nav-link">Browse YouTubers</a>
                <a href="/creators/signup.php" class="nav-link">For YouTubers</a>
            </div>
            
            <div class="nav-buttons">
                <a href="/auth/login.php" class="nav-login-btn">Log In</a>
                <a href="/creators/signup.php" class="nav-getstarted-btn">Get Started</a>
            </div>
        </div>
    </nav>

    <div class="login-wrapper">
        <div class="login-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"></path>
                <circle cx="12" cy="7" r="4"></circle>
            </svg>
        </div>
        
        <h1 class="login-title">Welcome Back</h1>
        <p class="login-subtitle">Sign in to your TopicLaunch account</p>
        
        <div class="login-container">
            <?php if ($error): ?>
                <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label class="form-label">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                            <polyline points="22,6 12,13 2,6"></polyline>
                        </svg>
                        Email Address
                    </label>
                    <input type="email" 
                           name="email" 
                           class="form-input" 
                           placeholder="you@example.com"
                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                           required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                        </svg>
                        Password
                    </label>
                    <input type="password" 
                           name="password" 
                           class="form-input" 
                           placeholder="Enter password"
                           required>
                </div>
                
                <div class="forgot-password">
                    <a onclick="alert('Please contact support@topiclaunch.com to reset your password.')">Forgot password?</a>
                </div>
                
                <button type="submit" class="login-btn">Sign In</button>
            </form>
            
            <div class="divider">───────</div>
            
            <p class="signup-link">
                New YouTuber? <a href="/creators/signup.php">Sign up here</a>
            </p>
        </div>
    </div>
</body>
</html>
