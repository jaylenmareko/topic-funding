<?php
// index.php - UPDATED - Creator signup only, no claim profile
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

// Only require database if we need to check user status (creators only)
if (isset($_SESSION['user_id'])) {
    try {
        require_once 'config/database.php';
        $db = new Database();
        $db->query('SELECT id FROM creators WHERE applicant_user_id = :user_id AND is_active = 1');
        $db->bind(':user_id', $_SESSION['user_id']);
        $is_creator = $db->single();
        
        if ($is_creator) {
            header('Location: creators/dashboard.php');
            exit;
        }
    } catch (Exception $e) {
        error_log("Index redirect error: " . $e->getMessage());
    }
}

// Creator login only
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
                $password_hash = isset($user->password_hash) ? $user->password_hash : $user->password;
                
                if (password_verify($password, $password_hash)) {
                    $_SESSION['user_id'] = $user->id;
                    $_SESSION['username'] = $user->username;
                    $_SESSION['full_name'] = isset($user->full_name) ? $user->full_name : $user->username;
                    $_SESSION['email'] = $user->email;
                    session_regenerate_id(true);
                    
                    // Check if creator
                    $db->query('SELECT id FROM creators WHERE applicant_user_id = :user_id AND is_active = 1');
                    $db->bind(':user_id', $user->id);
                    $is_creator = $db->single();
                    
                    if ($is_creator) {
                        header('Location: creators/dashboard.php');
                        exit;
                    } else {
                        $login_error = 'Creator login only';
                    }
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
        
        /* Creator Signup Box */
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
        .btn-creator {
            display: inline-block;
            padding: 15px 30px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: bold;
            font-size: 16px;
            transition: all 0.3s;
            background: #ff0000;
            color: white;
        }
        .btn-creator:hover {
            background: #cc0000;
            color: white;
            text-decoration: none;
            transform: translateY(-2px);
        }
        
        /* Demo Video Section */
        .demo-video-section {
            max-width: 800px;
            margin: 40px auto;
            padding: 0 20px;
        }
        .demo-video-section iframe {
            width: 100%;
            height: 450px;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }
        
        .container { max-width: 1200px; margin: 0 auto; padding: 40px 20px; }
        
        /* 2-Step Process */
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
        
        /* Testimonial Label */
        .testimonial-label {
            text-align: center;
            font-size: 28px;
            color: #333;
            margin: 40px 0 20px 0;
            font-weight: 600;
        }
        
        /* Footer */
        .footer {
            background: #333;
            color: #999;
            text-align: center;
            padding: 20px;
            margin-top: 0;
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
            .user-type {
                padding: 30px 20px;
            }
            .demo-video-section iframe {
                height: 250px;
            }
            .process-steps { grid-template-columns: 1fr; }
            .testimonial-label { font-size: 24px; }
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
    <!-- Navigation - Creator Login Only -->
    <nav class="topiclaunch-nav">
        <div class="nav-container">
            <span class="nav-logo">TopicLaunch</span>
            
            <form method="POST" class="login-form">
                <input type="email" name="email" placeholder="Creator Email" class="login-input" required value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                <input type="password" name="password" placeholder="Password" class="login-input" required>
                <button type="submit" class="login-btn">Creator Login</button>
                <?php if ($login_error): ?>
                    <span class="login-error"><?php echo htmlspecialchars($login_error); ?></span>
                <?php endif; ?>
            </form>
        </div>
    </nav>

    <!-- Hero Section -->
    <div class="hero">
        <h1>Fund Topics for Creators</h1>
        <p>Support your favorite creators by funding video topics</p>
        
        <!-- Creator Signup Only -->
        <div style="max-width: 400px; margin: 0 auto;">
            <div class="user-type creator">
                <p>Get paid to make videos your fans want</p>
                <a href="auth/register.php?type=creator" class="btn-creator">
                    Creator Signup
                </a>
            </div>
        </div>
    </div>

    <!-- Demo Video Section -->
    <div class="demo-video-section">
        <iframe 
            src="https://www.youtube.com/embed/bAf2R5GWPxI" 
            frameborder="0" 
            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" 
            allowfullscreen>
        </iframe>
    </div>

    <!-- Platform Compatibility Section -->
    <div style="text-align: center; margin: 30px auto; max-width: 600px; padding: 20px;">
        <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 25px; border-radius: 15px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
            <h3 style="color: white; margin: 0 0 20px 0; font-size: 18px; font-weight: 600;">
                ✨ Works With Your Favorite Platforms
            </h3>
            <div style="display: flex; justify-content: center; align-items: center; gap: 40px; flex-wrap: wrap;">
                <!-- YouTube -->
                <div style="text-align: center;">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="#FF0000" style="filter: drop-shadow(0 2px 4px rgba(0,0,0,0.2));">
                        <path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/>
                    </svg>
                    <p style="color: white; margin: 8px 0 0 0; font-size: 12px; font-weight: 600;">YouTube</p>
                </div>
                
                <!-- Instagram -->
                <div style="text-align: center;">
                    <svg width="48" height="48" viewBox="0 0 24 24" style="filter: drop-shadow(0 2px 4px rgba(0,0,0,0.2));">
                        <defs>
                            <radialGradient id="instagram-gradient" cx="30%" cy="107%" r="150%">
                                <stop offset="0%" style="stop-color:#fdf497" />
                                <stop offset="5%" style="stop-color:#fdf497" />
                                <stop offset="45%" style="stop-color:#fd5949" />
                                <stop offset="60%" style="stop-color:#d6249f" />
                                <stop offset="90%" style="stop-color:#285AEB" />
                            </radialGradient>
                        </defs>
                        <path fill="url(#instagram-gradient)" d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/>
                    </svg>
                    <p style="color: white; margin: 8px 0 0 0; font-size: 12px; font-weight: 600;">Instagram</p>
                </div>
                
                <!-- TikTok -->
                <div style="text-align: center;">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" style="filter: drop-shadow(0 2px 4px rgba(0,0,0,0.2));">
                        <path d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 0 1-5.2 1.74 2.89 2.89 0 0 1 2.31-4.64 2.93 2.93 0 0 1 .88.13V9.4a6.84 6.84 0 0 0-1-.05A6.33 6.33 0 0 0 5 20.1a6.34 6.34 0 0 0 10.86-4.43v-7a8.16 8.16 0 0 0 4.77 1.52v-3.4a4.85 4.85 0 0 1-1-.1z" fill="#00F2EA"/>
                        <path d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 0 1-5.2 1.74 2.89 2.89 0 0 1 2.31-4.64 2.93 2.93 0 0 1 .88.13V9.4a6.84 6.84 0 0 0-1-.05A6.33 6.33 0 0 0 5 20.1a6.34 6.34 0 0 0 10.86-4.43v-7a8.16 8.16 0 0 0 4.77 1.52v-3.4a4.85 4.85 0 0 1-1-.1z" fill="#FF004F"/>
                    </svg>
                    <p style="color: white; margin: 8px 0 0 0; font-size: 12px; font-weight: 600;">TikTok</p>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <!-- 2-Step Process -->
        <div class="process-steps">
            <div class="process-step" data-step="1">
                <div class="process-icon">💡</div>
                <h3>Fans Fund Topics</h3>
                <p>Fans make the FIRST contribution for a video idea. Then others chip in until goal is reached.</p>
            </div>
            <div class="process-step" data-step="2">
                <div class="process-icon">⚡</div>
                <h3>48-Hour Delivery</h3>
                <p>Creator delivers and gets 90% of funding, or fans get refunded.</p>
            </div>
        </div>

        <!-- Testimonial Label -->
        <div class="testimonial-label">
            Testimonial ⬇️
        </div>
    </div>

    <!-- Testimonial Section -->
    <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 60px 20px;">
        <div style="max-width: 800px; margin: 0 auto; text-align: center;">
            <p style="color: rgba(255,255,255,0.9); font-size: 18px; margin-bottom: 30px;">
                From <a href="https://www.youtube.com/@abouxtoure" target="_blank" style="color: #FFD700; text-decoration: none; font-weight: bold; transition: opacity 0.3s;" onmouseover="this.style.opacity='0.8'" onmouseout="this.style.opacity='1'">@abouxtoure</a>
            </p>
            <video controls style="width: 100%; max-width: 600px; border-radius: 12px; box-shadow: 0 8px 24px rgba(0,0,0,0.3);">
                <source src="uploads/testimonial.mp4" type="video/mp4">
                Your browser does not support the video tag.
            </video>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer">
        <div>
            <p>&copy; 2025 TopicLaunch. All rights reserved.</p>
            <p style="margin-top: 10px;">
                <a href="auth/register.php?type=creator">Creator Signup</a>
            </p>
        </div>
    </footer>
</body>
</html>
