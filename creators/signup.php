<?php
session_start();

// If already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: /creators/dashboard.php');
    exit;
}

$error = isset($_GET['error']) ? $_GET['error'] : '';
$error_messages = [
    'invalid_handle' => 'Please enter a valid YouTube handle',
    'invalid_email' => 'Please enter a valid email address',
    'weak_password' => 'Password must be at least 8 characters',
    'email_exists' => 'An account with this email already exists',
    'handle_exists' => 'This YouTube handle is already registered',
    'upload_failed' => 'Failed to upload profile picture',
    'registration_failed' => 'Registration failed. Please try again.'
];
?>
<!DOCTYPE html>
<html>
<head>
    <title>Creator Signup - TopicLaunch</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .signup-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            max-width: 500px;
            width: 100%;
            padding: 40px;
        }

        .logo {
            text-align: center;
            margin-bottom: 30px;
        }

        .logo h1 {
            color: #FF0000;
            font-size: 32px;
            font-weight: bold;
        }

        .logo p {
            color: #666;
            margin-top: 8px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 600;
            font-size: 14px;
        }

        input[type="text"],
        input[type="email"],
        input[type="password"],
        input[type="file"] {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 15px;
            transition: border-color 0.3s;
        }

        input[type="text"]:focus,
        input[type="email"]:focus,
        input[type="password"]:focus {
            outline: none;
            border-color: #FF0000;
        }

        input[type="file"] {
            padding: 10px;
            cursor: pointer;
        }

        .input-hint {
            font-size: 13px;
            color: #666;
            margin-top: 6px;
        }

        .error-message {
            background: #fee;
            border: 1px solid #fcc;
            color: #c33;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .submit-btn {
            width: 100%;
            padding: 14px;
            background: #28a745;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: background 0.3s;
        }

        .submit-btn:hover {
            background: #218838;
        }

        .submit-btn:active {
            transform: scale(0.98);
        }

        .login-link {
            text-align: center;
            margin-top: 20px;
            color: #666;
            font-size: 14px;
        }

        .login-link a {
            color: #FF0000;
            text-decoration: none;
            font-weight: 600;
        }

        .login-link a:hover {
            text-decoration: underline;
        }

        .profile-preview {
            margin-top: 10px;
            text-align: center;
        }

        .profile-preview img {
            max-width: 150px;
            max-height: 150px;
            border-radius: 50%;
            border: 3px solid #e0e0e0;
        }

        @media (max-width: 600px) {
            .signup-container {
                padding: 30px 20px;
            }

            .logo h1 {
                font-size: 26px;
            }
        }
    </style>
</head>
<body>
    <div class="signup-container">
        <div class="logo">
            <h1>TopicLaunch</h1>
            <p>Join as a Creator</p>
        </div>

        <?php if ($error && isset($error_messages[$error])): ?>
            <div class="error-message">
                <?php echo htmlspecialchars($error_messages[$error]); ?>
            </div>
        <?php endif; ?>

        <form action="/auth/register.php" method="POST" enctype="multipart/form-data" id="signupForm">
            <div class="form-group">
                <label for="youtube_handle">YouTube Handle *</label>
                <input type="text" id="youtube_handle" name="youtube_handle" required
                       placeholder="@yourhandle" pattern="^@?[a-zA-Z0-9_-]+$"
                       value="<?php echo isset($_GET['handle']) ? htmlspecialchars($_GET['handle']) : ''; ?>">
                <div class="input-hint">Your YouTube channel handle (e.g., @mrbeast)</div>
            </div>

            <div class="form-group">
                <label for="email">Email Address *</label>
                <input type="email" id="email" name="email" required
                       placeholder="your@email.com"
                       value="<?php echo isset($_GET['email']) ? htmlspecialchars($_GET['email']) : ''; ?>">
                <div class="input-hint">We'll use this for login and notifications</div>
            </div>

            <div class="form-group">
                <label for="password">Password *</label>
                <input type="password" id="password" name="password" required
                       placeholder="••••••••" minlength="8">
                <div class="input-hint">At least 8 characters</div>
            </div>

            <div class="form-group">
                <label for="profile_picture">Profile Picture (Optional)</label>
                <input type="file" id="profile_picture" name="profile_picture"
                       accept="image/jpeg,image/png,image/jpg">
                <div class="input-hint">JPG or PNG, max 5MB</div>
                <div class="profile-preview" id="preview"></div>
            </div>

            <button type="submit" class="submit-btn">Create Creator Account</button>
        </form>

        <div class="login-link">
            Already have an account? <a href="/">Login here</a>
        </div>
    </div>

    <script>
    // Profile picture preview
    document.getElementById('profile_picture').addEventListener('change', function(e) {
        const file = e.target.files[0];
        const preview = document.getElementById('preview');

        if (file) {
            // Check file size (5MB max)
            if (file.size > 5 * 1024 * 1024) {
                alert('File is too large. Maximum size is 5MB.');
                e.target.value = '';
                preview.innerHTML = '';
                return;
            }

            // Show preview
            const reader = new FileReader();
            reader.onload = function(event) {
                preview.innerHTML = '<img src="' + event.target.result + '" alt="Profile Preview">';
            };
            reader.readAsDataURL(file);
        } else {
            preview.innerHTML = '';
        }
    });

    // Form validation
    document.getElementById('signupForm').addEventListener('submit', function(e) {
        const handle = document.getElementById('youtube_handle').value.trim();
        const email = document.getElementById('email').value.trim();
        const password = document.getElementById('password').value;

        // Validate YouTube handle
        if (!handle || handle.length < 2) {
            e.preventDefault();
            alert('Please enter a valid YouTube handle');
            return;
        }

        // Validate email
        if (!email || !email.includes('@')) {
            e.preventDefault();
            alert('Please enter a valid email address');
            return;
        }

        // Validate password
        if (password.length < 8) {
            e.preventDefault();
            alert('Password must be at least 8 characters');
            return;
        }
    });
    </script>
</body>
</html>
