<?php
// auth/register.php - Secured with CSRF protection and enhanced validation
session_start();
require_once '../config/database.php';
require_once '../config/csrf.php';
require_once '../config/sanitizer.php';

$helper = new DatabaseHelper();
$errors = [];
$success = '';

if ($_POST) {
    // CSRF Protection
    CSRFProtection::requireValidToken();
    
    $username = InputSanitizer::sanitizeString($_POST['username']);
    $email = InputSanitizer::sanitizeEmail($_POST['email']);
    $full_name = InputSanitizer::sanitizeString($_POST['full_name']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Validation
    if (empty($username)) {
        $errors[] = "Username is required";
    } elseif (strlen($username) < 3) {
        $errors[] = "Username must be at least 3 characters";
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        $errors[] = "Username can only contain letters, numbers, and underscores";
    } elseif ($helper->usernameExists($username)) {
        $errors[] = "Username already exists";
    }
    
    if (empty($email)) {
        $errors[] = "Email is required";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format";
    } elseif ($helper->emailExists($email)) {
        $errors[] = "Email already registered";
    }
    
    if (empty($full_name)) {
        $errors[] = "Full name is required";
    } elseif (strlen($full_name) < 2) {
        $errors[] = "Full name must be at least 2 characters";
    }
    
    if (empty($password)) {
        $errors[] = "Password is required";
    } elseif (!InputSanitizer::validatePassword($password)) {
        $errors[] = "Password must be at least 8 characters with at least one letter and one number";
    }
    
    if ($password !== $confirm_password) {
        $errors[] = "Passwords do not match";
    }
    
    // Rate limiting check (basic)
    if (isset($_SESSION['registration_attempts']) && $_SESSION['registration_attempts'] > 5) {
        $errors[] = "Too many registration attempts. Please try again later.";
    }
    
    // If no errors, create user
    if (empty($errors)) {
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $user_id = $helper->createUser($username, $email, $password_hash, $full_name);
        
        if ($user_id) {
            $success = "Registration successful! You can now login.";
            unset($_SESSION['registration_attempts']); // Reset attempts on success
        } else {
            $errors[] = "Registration failed. Please try again.";
        }
    } else {
        // Track failed attempts
        $_SESSION['registration_attempts'] = ($_SESSION['registration_attempts'] ?? 0) + 1;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Register - Topic Funding</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 400px; margin: 50px auto; padding: 20px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input[type="text"], input[type="email"], input[type="password"] { 
            width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box;
        }
        .btn { background: #007bff; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; width: 100%; }
        .btn:hover { background: #0056b3; }
        .error { color: red; margin-bottom: 10px; }
        .success { color: green; margin-bottom: 10px; }
        .links { text-align: center; margin-top: 20px; }
        .password-requirements { background: #f8f9fa; padding: 10px; border-radius: 4px; margin-bottom: 15px; font-size: 14px; }
        .security-note { background: #e3f2fd; padding: 10px; border-radius: 4px; margin-bottom: 20px; font-size: 14px; }
        .requirement { color: #666; font-size: 12px; }
        .requirement.valid { color: #28a745; }
        .requirement.invalid { color: #dc3545; }
    </style>
</head>
<body>
    <h2>Register for Topic Funding</h2>
    
    <div class="security-note">
        🔒 Your registration is protected with advanced security measures.
    </div>
    
    <?php if (!empty($errors)): ?>
        <div class="errors">
            <?php foreach ($errors as $error): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    
    <form method="POST" id="registrationForm">
        <?php echo CSRFProtection::getTokenField(); ?>
        
        <div class="form-group">
            <label>Username:</label>
            <input type="text" name="username" value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>" required pattern="[a-zA-Z0-9_]{3,}" title="3+ characters, letters, numbers, and underscores only">
            <div class="requirement">3+ characters, letters, numbers, and underscores only</div>
        </div>
        
        <div class="form-group">
            <label>Email:</label>
            <input type="email" name="email" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" required>
        </div>
        
        <div class="form-group">
            <label>Full Name:</label>
            <input type="text" name="full_name" value="<?php echo isset($_POST['full_name']) ? htmlspecialchars($_POST['full_name']) : ''; ?>" required>
        </div>
        
        <div class="form-group">
            <label>Password:</label>
            <input type="password" name="password" id="password" required minlength="8">
            <div class="password-requirements">
                <div class="requirement" id="length-req">• At least 8 characters</div>
                <div class="requirement" id="letter-req">• At least one letter</div>
                <div class="requirement" id="number-req">• At least one number</div>
            </div>
        </div>
        
        <div class="form-group">
            <label>Confirm Password:</label>
            <input type="password" name="confirm_password" id="confirm_password" required>
            <div class="requirement" id="match-req">• Passwords must match</div>
        </div>
        
        <button type="submit" class="btn" id="submitBtn">Register</button>
    </form>
    
    <div class="links">
        <a href="login.php">Already have an account? Login here</a><br>
        <a href="../index.php">Back to Home</a>
    </div>

    <script>
    // Real-time password validation
    const password = document.getElementById('password');
    const confirmPassword = document.getElementById('confirm_password');
    const lengthReq = document.getElementById('length-req');
    const letterReq = document.getElementById('letter-req');
    const numberReq = document.getElementById('number-req');
    const matchReq = document.getElementById('match-req');
    const submitBtn = document.getElementById('submitBtn');

    function validatePassword() {
        const pwd = password.value;
        const confirmPwd = confirmPassword.value;
        
        // Length check
        if (pwd.length >= 8) {
            lengthReq.classList.add('valid');
            lengthReq.classList.remove('invalid');
        } else {
            lengthReq.classList.add('invalid');
            lengthReq.classList.remove('valid');
        }
        
        // Letter check
        if (/[A-Za-z]/.test(pwd)) {
            letterReq.classList.add('valid');
            letterReq.classList.remove('invalid');
        } else {
            letterReq.classList.add('invalid');
            letterReq.classList.remove('valid');
        }
        
        // Number check
        if (/[0-9]/.test(pwd)) {
            numberReq.classList.add('valid');
            numberReq.classList.remove('invalid');
        } else {
            numberReq.classList.add('invalid');
            numberReq.classList.remove('valid');
        }
        
        // Match check
        if (confirmPwd && pwd === confirmPwd) {
            matchReq.classList.add('valid');
            matchReq.classList.remove('invalid');
        } else if (confirmPwd) {
            matchReq.classList.add('invalid');
            matchReq.classList.remove('valid');
        }
        
        // Enable/disable submit button
        const isValid = pwd.length >= 8 && /[A-Za-z]/.test(pwd) && /[0-9]/.test(pwd) && pwd === confirmPwd;
        submitBtn.disabled = !isValid;
        submitBtn.style.opacity = isValid ? '1' : '0.6';
    }

    password.addEventListener('input', validatePassword);
    confirmPassword.addEventListener('input', validatePassword);
    
    // Initial validation
    validatePassword();
    </script>
</body>
</html>
