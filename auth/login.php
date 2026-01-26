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
    <title>Log In - TopicLaunch - For Creators Who Run It</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700;800&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --hot-pink: #FF006B;
            --deep-pink: #E6005F;
            --black: #000000;
            --white: #FFFFFF;
            --gray-dark: #1A1A1A;
            --gray-med: #666666;
            --gray-light: #E5E5E5;
            --cream: #FAF8F6;
        }
        
        * { 
            margin: 0; 
            padding: 0; 
            box-sizing: border-box; 
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--cream);
            min-height: 100vh;
        }
        
        /* Navigation */
        .nav {
            position: sticky;
            top: 0;
            background: var(--white);
            padding: 16px 0;
            border-bottom: 1px solid var(--gray-light);
            z-index: 100;
        }
        
        .nav-container {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 30px;
        }
        
        .nav-logo {
            font-family: 'Playfair Display', serif;
            font-size: 22px;
            font-weight: 700;
            text-decoration: none;
            letter-spacing: -0.5px;
        }
        
        .nav-logo .topic { color: var(--black); }
        .nav-logo .launch { color: var(--hot-pink); }
        
        .nav-center {
            display: flex;
            gap: 35px;
            align-items: center;
        }
        
        .nav-link {
            color: var(--gray-dark);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: color 0.2s;
        }
        
        .nav-link:hover {
            color: var(--hot-pink);
        }
        
        .nav-buttons {
            display: flex;
            gap: 20px;
            align-items: center;
        }
        
        .nav-login-btn {
            color: var(--hot-pink);
            text-decoration: none;
            font-size: 14px;
            font-weight: 700;
            pointer-events: none;
        }
        
        .nav-cta-btn {
            background: var(--hot-pink);
            color: var(--white);
            text-decoration: none;
            font-size: 14px;
            font-weight: 700;
            padding: 10px 24px;
            border-radius: 50px;
            transition: all 0.2s;
        }
        
        .nav-cta-btn:hover {
            background: var(--deep-pink);
            transform: translateY(-1px);
        }
        
        /* Login Page */
        .login-wrapper {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            min-height: calc(100vh - 70px);
            padding: 80px 30px 60px;
        }
        
        .login-icon {
            width: 64px;
            height: 64px;
            background: linear-gradient(135deg, var(--hot-pink) 0%, var(--deep-pink) 100%);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
        }
        
        .login-icon svg {
            width: 32px;
            height: 32px;
            stroke: var(--white);
            stroke-width: 2.5;
        }
        
        .login-title {
            font-family: 'Playfair Display', serif;
            text-align: center;
            font-size: 42px;
            font-weight: 700;
            color: var(--black);
            margin-bottom: 10px;
        }
        
        .login-subtitle {
            text-align: center;
            font-size: 16px;
            color: var(--gray-med);
            margin-bottom: 40px;
            font-weight: 400;
        }
        
        .login-container {
            background: var(--white);
            border-radius: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            padding: 40px;
            width: 100%;
            max-width: 440px;
        }
        
        .form-group {
            margin-bottom: 24px;
        }
        
        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: var(--black);
            margin-bottom: 8px;
        }
        
        .form-input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid var(--gray-light);
            border-radius: 12px;
            font-size: 15px;
            font-family: 'Inter', sans-serif;
            transition: all 0.2s;
        }
        
        .form-input:focus {
            outline: none;
            border-color: var(--hot-pink);
            box-shadow: 0 0 0 4px rgba(255, 0, 107, 0.1);
        }
        
        .form-input::placeholder {
            color: var(--gray-med);
        }
        
        .forgot-password {
            text-align: right;
            margin-bottom: 28px;
        }
        
        .forgot-password a {
            color: var(--hot-pink);
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
        }
        
        .forgot-password a:hover {
            text-decoration: underline;
        }
        
        .login-btn {
            width: 100%;
            padding: 14px;
            background: var(--hot-pink);
            color: var(--white);
            border: none;
            border-radius: 50px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .login-btn:hover {
            background: var(--deep-pink);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(255, 0, 107, 0.25);
        }
        
        .login-btn:active {
            transform: translateY(0);
        }
        
        .divider {
            text-align: center;
            margin: 28px 0;
            color: var(--gray-light);
            font-size: 14px;
            font-weight: 500;
        }
        
        .signup-link {
            text-align: center;
            color: var(--gray-dark);
            font-size: 15px;
        }
        
        .signup-link a {
            color: var(--hot-pink);
            text-decoration: none;
            font-weight: 700;
        }
        
        .signup-link a:hover {
            text-decoration: underline;
        }
        
        .error-message {
            background: #FEF2F2;
            color: #DC2626;
            padding: 14px 18px;
            border-radius: 12px;
            margin-bottom: 24px;
            font-size: 14px;
            border-left: 4px solid #DC2626;
            font-weight: 500;
        }
        
        @media (max-width: 768px) {
            .nav-center {
                display: none;
            }
            
            .login-container {
                padding: 30px 25px;
            }
            
            .login-wrapper {
                padding: 60px 20px;
            }
            
            .login-title {
                font-size: 36px;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="nav">
        <div class="nav-container">
            <a href="/" class="nav-logo">
                <span class="topic">Topic</span><span class="launch">Launch</span>
            </a>
            
            <div class="nav-center">
                <a href="/creators/" class="nav-link">Browse Creators</a>
                <a href="/creators/signup.php" class="nav-link">For Creators</a>
            </div>

            <div class="nav-buttons">
                <a href="/auth/login.php" class="nav-login-btn">Log In</a>
                <a href="/creators/signup.php" class="nav-cta-btn">Get Started</a>
            </div>
        </div>
    </nav>

    <div class="login-wrapper">
        <div class="login-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"></path>
                <circle cx="12" cy="7" r="4"></circle>
            </svg>
        </div>
        
        <h1 class="login-title">Welcome Back</h1>
        <p class="login-subtitle">Log in to your TopicLaunch account</p>
        
        <div class="login-container">
            <?php if ($error): ?>
                <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label class="form-label">Email Address</label>
                    <input type="email" 
                           name="email" 
                           class="form-input" 
                           placeholder="your@email.com"
                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                           required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Password</label>
                    <input type="password" 
                           name="password" 
                           class="form-input" 
                           placeholder="Enter your password"
                           required>
                </div>
                
                <div class="forgot-password">
                    <a onclick="alert('Please contact support@topiclaunch.com to reset your password.')">Forgot password?</a>
                </div>
                
                <button type="submit" class="login-btn">Sign In</button>
            </form>
            
            <div class="divider">──────</div>
            
            <p class="signup-link">
                New creator? <a href="/creators/signup.php">Sign up here</a>
            </p>
        </div>
    </div>
</body>
</html>
