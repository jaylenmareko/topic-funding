<?php
// auth/login.php - Minimal login page matching Rizzdem
session_start();

// If already logged in, redirect to appropriate page
if (isset($_SESSION['user_id'])) {
    require_once __DIR__ . '/../config/database.php';
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
    require_once __DIR__ . '/../config/database.php';
    
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --tl-pink: #E8305A;
            --tl-pink-dark: #B01F3F;
            --tl-card: #1a1a1a;
            --tl-border: #2a2a2a;
            --tl-muted: #888888;
            --white: #ffffff;
            --cream: #FAF8F6;
            --black: #111010;
            --gray-dark: #1A1A1A;
            --gray-med: #666666;
            --hot-pink: #E8305A;
            --deep-pink: #B01F3F;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--cream);
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }

        /* Navigation */
        .nav {
            position: sticky;
            top: 0;
            background: var(--white);
            padding: 16px 0;
            border-bottom: 1px solid #E5E5E5;
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
            font-size: 20px;
            font-weight: 500;
            text-decoration: none;
            letter-spacing: -0.3px;
        }

        .nav-logo .topic { color: var(--black); }
        .nav-logo .launch { color: var(--tl-pink); }

        .nav-center { display: flex; gap: 24px; align-items: center; }

        .nav-link {
            color: var(--gray-dark);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: color 0.2s;
        }
        .nav-link:hover { color: var(--tl-pink); }

        .nav-buttons { display: flex; gap: 12px; align-items: center; }

        .nav-login-btn {
            color: var(--tl-pink);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            pointer-events: none;
        }

        .nav-cta-btn {
            background: var(--tl-pink);
            color: var(--white);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            padding: 8px 18px;
            border-radius: 8px;
            transition: background 0.2s;
        }
        .nav-cta-btn:hover { background: var(--tl-pink-dark); }

        /* Login Page */
        .login-wrapper {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            min-height: calc(100vh - 57px);
            padding: 72px 30px 60px;
        }

        .login-icon {
            width: 56px;
            height: 56px;
            background: linear-gradient(135deg, var(--tl-pink), var(--tl-pink-dark));
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 22px;
        }
        .login-icon svg {
            width: 28px;
            height: 28px;
            stroke: var(--white);
            stroke-width: 2.5;
        }

        .login-title {
            text-align: center;
            font-size: 36px;
            font-weight: 600;
            color: var(--black);
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }

        .login-subtitle {
            text-align: center;
            font-size: 14px;
            color: var(--gray-med);
            margin-bottom: 36px;
        }

        .login-container {
            background: var(--white);
            border: 1px solid #E5E5E5;
            border-radius: 16px;
            padding: 36px;
            width: 100%;
            max-width: 440px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
        }

        .form-group { margin-bottom: 20px; }

        .form-label {
            display: block;
            font-size: 11px;
            font-weight: 500;
            color: var(--gray-med);
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-bottom: 8px;
        }

        .form-input {
            width: 100%;
            padding: 11px 14px;
            border: 1px solid #E5E5E5;
            border-radius: 8px;
            font-size: 14px;
            font-family: 'Inter', sans-serif;
            transition: border-color 0.2s;
            background: var(--white);
            color: var(--black);
        }
        .form-input:focus {
            outline: none;
            border-color: var(--tl-pink);
            box-shadow: 0 0 0 3px rgba(232,48,90,0.08);
        }
        .form-input::placeholder { color: #bbb; }

        .forgot-password {
            text-align: right;
            margin-bottom: 24px;
        }
        .forgot-password a {
            color: var(--tl-pink);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
        }
        .forgot-password a:hover { text-decoration: underline; }

        .login-btn {
            width: 100%;
            padding: 13px;
            background: var(--tl-pink);
            color: var(--white);
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.2s;
            font-family: 'Inter', sans-serif;
        }
        .login-btn:hover { background: var(--tl-pink-dark); }

        .divider {
            text-align: center;
            margin: 24px 0;
            color: #E5E5E5;
            font-size: 13px;
        }

        .signup-link {
            text-align: center;
            color: var(--gray-med);
            font-size: 14px;
        }
        .signup-link a {
            color: var(--tl-pink);
            text-decoration: none;
            font-weight: 500;
        }
        .signup-link a:hover { text-decoration: underline; }

        .error-message {
            background: #FEF2F2;
            color: #DC2626;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 13px;
            border-left: 3px solid #DC2626;
        }

        @media (max-width: 768px) {
            .nav-center { display: none; }
            .login-container { padding: 28px 22px; }
            .login-wrapper { padding: 56px 20px 40px; }
            .login-title { font-size: 28px; }
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
