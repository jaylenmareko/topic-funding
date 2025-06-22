<?php
// auth/login.php - Secured with CSRF protection
session_start();
require_once '../config/database.php';
require_once '../config/csrf.php';
require_once '../config/sanitizer.php';

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

$helper = new DatabaseHelper();
$errors = [];

if ($_POST) {
    // CSRF Protection
    CSRFProtection::requireValidToken();
    
    $email = InputSanitizer::sanitizeEmail($_POST['email']);
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
        $user = $helper->getUserByEmail($email);
        
        if ($user && password_verify($password, $user->password_hash)) {
            // Login successful
            $_SESSION['user_id'] = $user->id;
            $_SESSION['username'] = $user->username;
            $_SESSION['full_name'] = $user->full_name;
            $_SESSION['email'] = $user->email;
            
            // Regenerate session ID for security
            session_regenerate_id(true);
            
            // Redirect to home page
            header('Location: ../index.php');
            exit;
        } else {
            $errors[] = "Invalid email or password";
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Login - Topic Funding</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 400px; margin: 50px auto; padding: 20px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input[type="email"], input[type="password"] { 
            width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box;
        }
        .btn { background: #007bff; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; width: 100%; }
        .btn:hover { background: #0056b3; }
        .error { color: red; margin-bottom: 10px; }
        .links { text-align: center; margin-top: 20px; }
        .security-note { background: #e3f2fd; padding: 10px; border-radius: 4px; margin-bottom: 20px; font-size: 14px; }
    </style>
</head>
<body>
    <h2>Login to Topic Funding</h2>
    
    <div class="security-note">
        🔒 Your login is protected with advanced security measures.
    </div>
    
    <?php if (!empty($errors)): ?>
        <div class="errors">
            <?php foreach ($errors as $error): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    
    <form method="POST">
        <?php echo CSRFProtection::getTokenField(); ?>
        
        <div class="form-group">
            <label>Email:</label>
            <input type="email" name="email" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" required>
        </div>
        
        <div class="form-group">
            <label>Password:</label>
            <input type="password" name="password" required>
        </div>
        
        <button type="submit" class="btn">Login</button>
    </form>
    
    <div class="links">
        <a href="register.php">Don't have an account? Register here</a><br>
        <a href="../index.php">Back to Home</a>
    </div>
</body>
</html>
