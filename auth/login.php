<?php
// auth/login.php - FIXED VERSION with better error handling
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

// Get return_to parameter for redirect after login
$return_to = isset($_GET['return_to']) ? $_GET['return_to'] : '';

// Check if already logged in and redirect appropriately
if (isset($_SESSION['user_id'])) {
    try {
        require_once '../config/database.php';
        $db = new Database();
        $db->query('SELECT id FROM creators WHERE applicant_user_id = :user_id AND is_active = 1');
        $db->bind(':user_id', $_SESSION['user_id']);
        $is_creator = $db->single();
        
        if ($is_creator) {
            header('Location: ../creators/dashboard.php');
        } else {
            if ($return_to) {
                header('Location: ..' . $return_to);
            } else {
                header('Location: ../creators/index.php');
            }
        }
        exit;
    } catch (Exception $e) {
        error_log("Login redirect error: " . $e->getMessage());
        // Continue to show login page if error
    }
}

require_once '../config/database.php';

// Try to load optional security features if they exist
$use_csrf = file_exists('../config/csrf.php');
$use_sanitizer = file_exists('../config/sanitizer.php');

if ($use_csrf) {
    require_once '../config/csrf.php';
}
if ($use_sanitizer) {
    require_once '../config/sanitizer.php';
}

$errors = [];

if ($_POST) {
    // CSRF Protection (if available)
    if ($use_csrf) {
        try {
            CSRFProtection::requireValidToken();
        } catch (Exception $e) {
            $errors[] = "Security validation failed. Please try again.";
        }
    }
    
    if (empty($errors)) {
        // Get return_to from form if it exists
        $return_to = $_POST['return_to'] ?? $return_to;
        
        // Sanitize inputs (if sanitizer available, otherwise just trim)
        if ($use_sanitizer) {
            $email = InputSanitizer::sanitizeEmail($_POST['email']);
        } else {
            $email = trim($_POST['email']);
        }
        $password = $_POST['password'];
        
        // Validation
        if (empty($email)) {
            $errors[] = "Email is required";
        }
        
        if (empty($password)) {
            $errors[] = "Password is required";
        }
        
        // If no errors, attempt login
        if (empty($errors)) {
            try {
                $db = new Database();
                
                // Get user by email - support both old (password) and new (password_hash) column names
                $db->query('SELECT * FROM users WHERE email = :email');
                $db->bind(':email', $email);
                $user = $db->single();
                
                if ($user) {
                    // Check password - support both column names
                    $password_hash = isset($user->password_hash) ? $user->password_hash : $user->password;
                    
                    if (password_verify($password, $password_hash)) {
                        // Login successful
                        $_SESSION['user_id'] = $user->id;
                        $_SESSION['username'] = $user->username;
                        $_SESSION['full_name'] = isset($user->full_name) ? $user->full_name : $user->username;
                        $_SESSION['email'] = $user->email;
                        
                        // Regenerate session ID for security
                        session_regenerate_id(true);
                        
                        // Check if user is a creator or fan and redirect accordingly
                        $db->query('SELECT id FROM creators WHERE applicant_user_id = :user_id AND is_active = 1');
                        $db->bind(':user_id', $user->id);
                        $is_creator = $db->single();
                        
                        if ($is_creator) {
                            header('Location: ../creators/dashboard.php');
                        } else {
                            if ($return_to) {
                                header('Location: ..' . $return_to);
                            } else {
                                header('Location: ../creators/index.php');
                            }
                        }
                        exit;
                    } else {
                        $errors[] = "Invalid email or password";
                    }
                } else {
                    $errors[] = "Invalid email or password";
                }
            } catch (Exception $e) {
                error_log("Login error: " . $e->getMessage());
                $errors[] = "Login failed. Please try again.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Login - TopicLaunch</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .login-container {
            background: white;
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            width: 100%;
            max-width: 400px;
        }
        
        .logo {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .logo h1 {
            font-size: 32px;
            color: #667eea;
            margin-bottom: 10px;
        }
        
        .logo p {
            color: #666;
            font-size: 14px;
        }
        
        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        
        .security-note {
            background: #e3f2fd;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 20px;
            font-size: 14px;
            text-align: center;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
        }
        
        input[type="email"],
        input[type="password"] {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.3s;
        }
        
        input[type="email"]:focus,
        input[type="password"]:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .btn-login {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: transform 0.2s;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
        }
        
        .links {
            text-align: center;
            margin-top: 20px;
        }
        
        .links a {
            color: #667eea;
            text-decoration: none;
            font-size: 14px;
        }
        
        .links a:hover {
            text-decoration: underline;
        }
        
        .divider {
            text-align: center;
            margin: 20px 0;
            color: #999;
            font-size: 14px;
        }
        
        @media (max-width: 480px) {
            .login-container {
                padding: 30px 20px;
            }
            .logo h1 {
                font-size: 28px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo">
            <h1>TopicLaunch</h1>
            <p>Welcome Back</p>
        </div>
        
        <div class="security-note">
            🔒 Your login is secure
        </div>
        
        <?php if (!empty($errors)): ?>
            <div class="error">
                <?php foreach ($errors as $error): ?>
                    <?php echo htmlspecialchars($error); ?><br>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <?php if ($use_csrf): ?>
                <?php echo CSRFProtection::getTokenField(); ?>
            <?php endif; ?>
            
            <!-- Pass through return_to parameter -->
            <?php if ($return_to): ?>
                <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($return_to); ?>">
            <?php endif; ?>
            
            <div class="form-group">
                <label>Email:</label>
                <input type="email" name="email" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" required>
            </div>
            
            <div class="form-group">
                <label>Password:</label>
                <input type="password" name="password" required>
            </div>
            
            <button type="submit" class="btn-login">Login</button>
        </form>
        
        <div class="divider">OR</div>
        
        <div class="links">
            <a href="register.php<?php echo $return_to ? '?return_to=' . urlencode($return_to) : ''; ?>">Create an account</a><br>
            <a href="../index.php" style="margin-top: 10px; display: inline-block;">← Back to Home</a>
        </div>
    </div>
</body>
</html>
