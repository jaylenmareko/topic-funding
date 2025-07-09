<?php
// auth/login.php - Updated to redirect fans to browse YouTubers
session_start();
require_once '../config/database.php';
require_once '../config/csrf.php';
require_once '../config/sanitizer.php';

// Check if already logged in and redirect appropriately
if (isset($_SESSION['user_id'])) {
    $db = new Database();
    $db->query('SELECT id FROM creators WHERE applicant_user_id = :user_id AND is_active = 1');
    $db->bind(':user_id', $_SESSION['user_id']);
    $is_creator = $db->single();
    
    if ($is_creator) {
        header('Location: ../creators/dashboard.php'); // Creators go to creator dashboard
    } else {
        header('Location: ../creators/index.php'); // Fans go to browse creators
    }
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
            
            // Check if user is a creator or fan and redirect accordingly
            $db = new Database();
            $db->query('SELECT id FROM creators WHERE applicant_user_id = :user_id AND is_active = 1');
            $db->bind(':user_id', $user->id);
            $is_creator = $db->single();
            
            if ($is_creator) {
                header('Location: ../creators/dashboard.php'); // Creators go to creator dashboard
            } else {
                header('Location: ../creators/index.php'); // Fans go to browse creators
            }
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
    <title>Login - TopicLaunch</title>
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
    <h2>Login to TopicLaunch</h2>
    
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
        <a href="../index.php">← Back to Home</a>
    </div>
</body>
</html>
