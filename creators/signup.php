<?php
session_start();
require_once '../config/database.php';

// If already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: /creators/dashboard.php');
    exit;
}

// Process form submission (POST request)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $youtube_handle = trim($_POST['youtube_handle'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    // Validation
    $errors = [];

    // Validate YouTube handle
    if (empty($youtube_handle)) {
        $errors[] = 'invalid_handle';
    } else {
        // Remove @ if present
        if (strpos($youtube_handle, '@') === 0) {
            $youtube_handle = substr($youtube_handle, 1);
        }
        // Validate format
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $youtube_handle)) {
            $errors[] = 'invalid_handle';
        }
    }

    // Validate email
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'invalid_email';
    }

    // Validate password
    if (strlen($password) < 8) {
        $errors[] = 'weak_password';
    }

    // If no validation errors, create account
    if (empty($errors)) {
        try {
            $db = new Database();

            // Check if email already exists
            $db->query('SELECT id FROM users WHERE email = :email');
            $db->bind(':email', $email);
            $existing_user = $db->single();

            if ($existing_user) {
                $errors[] = 'email_exists';
            } else {
                // Check if YouTube handle already exists
                $db->query('SELECT id FROM creators WHERE display_name = :handle OR username LIKE :handle_pattern');
                $db->bind(':handle', $youtube_handle);
                $db->bind(':handle_pattern', '%' . $youtube_handle . '%');
                $existing_creator = $db->single();

                if ($existing_creator) {
                    $errors[] = 'handle_exists';
                } else {
                    // Hash password
                    $password_hash = password_hash($password, PASSWORD_DEFAULT);

                    // Handle profile picture upload
                    $profile_image = null;
                    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
                        $upload_dir = '../uploads/creators/';
                        if (!is_dir($upload_dir)) {
                            mkdir($upload_dir, 0755, true);
                        }

                        $file = $_FILES['profile_picture'];
                        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                        $allowed_extensions = ['jpg', 'jpeg', 'png'];

                        // Validate file
                        if (in_array($file_extension, $allowed_extensions) && $file['size'] <= 5 * 1024 * 1024) {
                            $filename = 'creator_' . uniqid() . '.' . $file_extension;
                            if (move_uploaded_file($file['tmp_name'], $upload_dir . $filename)) {
                                $profile_image = $filename;
                            }
                        }
                    }

                    // Start transaction
                    $db->beginTransaction();

                    // Create user account
                    $username = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $youtube_handle));
                    $full_name = $youtube_handle;

                    $db->query('INSERT INTO users (username, email, password_hash, full_name, is_active) VALUES (:username, :email, :password_hash, :full_name, 1)');
                    $db->bind(':username', $username);
                    $db->bind(':email', $email);
                    $db->bind(':password_hash', $password_hash);
                    $db->bind(':full_name', $full_name);
                    $db->execute();

                    $user_id = $db->lastInsertId();

                    // Create creator account
                    $creator_username = $username . '_' . $user_id;
                    $platform_url = 'https://youtube.com/@' . $youtube_handle;

                    $db->query('
                        INSERT INTO creators (
                            username, display_name, email, bio, platform_url,
                            subscriber_count, default_funding_threshold, commission_rate,
                            is_verified, is_active, applicant_user_id, application_status,
                            profile_image
                        ) VALUES (
                            :username, :display_name, :email, :bio, :platform_url,
                            1000, 50.00, 5.00, 1, 1, :applicant_user_id, "approved", :profile_image
                        )
                    ');

                    $db->bind(':username', $creator_username);
                    $db->bind(':display_name', $youtube_handle);
                    $db->bind(':email', $email);
                    $db->bind(':bio', 'YouTube Creator on TopicLaunch');
                    $db->bind(':platform_url', $platform_url);
                    $db->bind(':applicant_user_id', $user_id);
                    $db->bind(':profile_image', $profile_image);
                    $db->execute();

                    $creator_id = $db->lastInsertId();

                    // Commit transaction
                    $db->endTransaction();

                    // Set session variables
                    $_SESSION['user_id'] = $user_id;
                    $_SESSION['username'] = $youtube_handle;
                    $_SESSION['email'] = $email;
                    $_SESSION['creator_id'] = $creator_id;

                    // Redirect to dashboard
                    header('Location: /creators/dashboard.php?welcome=1');
                    exit;
                }
            }
        } catch (Exception $e) {
            if (isset($db)) {
                $db->cancelTransaction();
            }
            error_log("Registration error: " . $e->getMessage());
            $errors[] = 'registration_failed';
        }
    }
}

// Error messages
$error_messages = [
    'invalid_handle' => 'Please enter a valid YouTube handle',
    'invalid_email' => 'Please enter a valid email address',
    'weak_password' => 'Password must be at least 8 characters',
    'email_exists' => 'An account with this email already exists',
    'handle_exists' => 'This YouTube handle is already registered',
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

        <?php if (!empty($errors)): ?>
            <div class="error-message">
                <?php echo htmlspecialchars($error_messages[$errors[0]] ?? 'An error occurred'); ?>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" id="signupForm">
            <div class="form-group">
                <label for="youtube_handle">YouTube Handle *</label>
                <input type="text" id="youtube_handle" name="youtube_handle" required
                       placeholder="@yourhandle" pattern="^@?[a-zA-Z0-9_-]+$"
                       value="<?php echo isset($youtube_handle) ? htmlspecialchars($youtube_handle) : ''; ?>">
                <div class="input-hint">Your YouTube channel handle (e.g., @mrbeast)</div>
            </div>

            <div class="form-group">
                <label for="email">Email Address *</label>
                <input type="email" id="email" name="email" required
                       placeholder="your@email.com"
                       value="<?php echo isset($email) ? htmlspecialchars($email) : ''; ?>">
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
