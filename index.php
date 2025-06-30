<?php
// index.php - Updated homepage with mentor's feedback
session_start();
require_once 'config/database.php';
require_once 'config/navigation.php';

$helper = new DatabaseHelper();
$creators = $helper->getAllCreators();

// Handle login form
$login_error = '';
if ($_POST && isset($_POST['email']) && isset($_POST['password'])) {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    
    if (!empty($email) && !empty($password)) {
        $db = new Database();
        $db->query('SELECT * FROM users WHERE email = :email AND is_active = 1');
        $db->bind(':email', $email);
        $user = $db->single();
        
        if ($user && password_verify($password, $user->password_hash)) {
            $_SESSION['user_id'] = $user->id;
            $_SESSION['username'] = $user->username;
            $_SESSION['full_name'] = $user->full_name;
            $_SESSION['email'] = $user->email;
            session_regenerate_id(true);
            header('Location: dashboard/index.php');
            exit;
        } else {
            $login_error = 'Invalid email or password';
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>TopicLaunch - Fund Topics from Your Favorite YouTuber</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; background: #f5f5f5; }
        
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
        .login-error { color: #ff6b6b; font-size: 12px; margin-left: 10px; }
        
        /* Hero Section */
        .hero { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 60px 20px; text-align: center; }
        .hero h1 { font-size: 48px; margin: 0 0 20px 0; font-weight: bold; }
        .hero p { font-size: 20px; margin: 0 0 30px 0; opacity: 0.9; }
        .hero-buttons { display: flex; gap: 20px; justify-content: center; flex-wrap: wrap; }
        .btn { padding: 15px 30px; border-radius: 8px; text-decoration: none; font-weight: bold; font-size: 16px; transition: all 0.3s; }
        .btn-success { background: #28a745; color: white; }
        .btn-success:hover { background: #218838; color: white; text-decoration: none; }
        .btn-secondary { background: rgba(255,255,255,0.2); color: white; border: 2px solid white; }
        .btn-secondary:hover { background: rgba(255,255,255,0.3); }
        
        .container { max-width: 1200px; margin: 0 auto; padding: 40px 20px; }
        .section-title { font-size: 32px; text-align: center; margin-bottom: 40px; color: #333; }
        
        /* 3-Step Process */
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
        .process-step h3 { color: #333; margin-bottom: 15px; }
        
        /* Creator Grid */
        .creator-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 25px; }
        .creator-card { background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); transition: transform 0.3s; }
        .creator-card:hover { transform: translateY(-5px); }
        .creator-header { display: flex; gap: 15px; align-items: start; margin-bottom: 15px; }
        .creator-image { width: 70px; height: 70px; background: linear-gradient(135deg, #667eea, #764ba2); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 28px; color: white; }
        .creator-info h3 { margin: 0 0 8px 0; color: #333; font-size: 20px; }
        .creator-stats { display: flex; gap: 20px; margin: 15px 0; }
        .stat { text-align: center; }
        .stat-number { font-weight: bold; color: #667eea; font-size: 18px; }
        .stat-label { font-size: 12px; color: #666; }
        .platform-badge { background: #f0f0f0; padding: 4px 12px; border-radius: 15px; font-size: 12px; color: #666; }
        .btn-card { background: #28a745; color: white; padding: 12px 20px; border-radius: 6px; text-decoration: none; display: inline-block; margin-top: 15px; }
        .btn-card:hover { background: #218838; color: white; text-decoration: none; }
        .empty-state { text-align: center; color: #666; padding: 60px 20px; background: white; border-radius: 12px; }
        
        /* Footer */
        .footer {
            background: #333;
            color: #999;
            text-align: center;
            padding: 20px;
            margin-top: 60px;
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
            .hero-buttons { flex-direction: column; align-items: center; }
            .creator-grid { grid-template-columns: 1fr; }
            .process-steps { grid-template-columns: 1fr; }
            .login-form { flex-direction: column; gap: 5px; }
            .nav-container { flex-direction: column; gap: 15px; }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="topiclaunch-nav">
        <div class="nav-container">
            <a href="index.php" class="nav-logo">TopicLaunch</a>
            
            <?php if (!isset($_SESSION['user_id'])): ?>
                <form method="POST" class="login-form">
                    <input type="email" name="email" placeholder="Email" class="login-input" required>
                    <input type="password" name="password" placeholder="Password" class="login-input" required>
                    <button type="submit" class="login-btn">Login</button>
                    <?php if ($login_error): ?>
                        <span class="login-error"><?php echo htmlspecialchars($login_error); ?></span>
                    <?php endif; ?>
                </form>
            <?php else: ?>
                <div style="color: white;">
                    Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?>!
                    <a href="dashboard/index.php" style="color: white; margin-left: 15px;">Dashboard</a>
                    <a href="auth/logout.php" style="color: white; margin-left: 15px;">Logout</a>
                </div>
            <?php endif; ?>
        </div>
    </nav>

    <!-- Hero Section -->
    <div class="hero">
        <h1>Fund Topics from Your Favorite YouTuber</h1>
        <p>Propose specific topics you want to see covered, fund them with the community, and creators deliver in 48 hours</p>
        
        <div class="hero-buttons">
            <?php if (isset($_SESSION['user_id'])): ?>
                <a href="creators/index.php" class="btn btn-secondary">Browse Creators</a>
                <a href="topics/create.php" class="btn btn-success">Fund a Topic</a>
            <?php else: ?>
                <a href="creators/index.php" class="btn btn-secondary">Browse Creators</a>
                <a href="auth/register.php" class="btn btn-success">Fund a Topic</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="container">
        <!-- How It Works - 3 Steps -->
        <div class="process-steps">
            <div class="process-step" data-step="1">
                <div class="process-icon">💡</div>
                <h3>Propose & Fund</h3>
                <p>Have an idea for your favorite YouTuber? Propose specific topics and make the first contribution to get it started.</p>
            </div>
            <div class="process-step" data-step="2">
                <div class="process-icon">🤝</div>
                <h3>Community Backs It</h3>
                <p>Other fans join in to fund the topic. Once the goal is reached, the YouTuber gets notified to create the content.</p>
            </div>
            <div class="process-step" data-step="3">
                <div class="process-icon">⚡</div>
                <h3>48-Hour Delivery</h3>
                <p>YouTubers have 48 hours to deliver your requested content, or everyone gets automatically refunded.</p>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer">
        <div>
            <p>&copy; 2025 TopicLaunch. All rights reserved.</p>
            <p>
                <a href="#">Privacy Policy</a> | 
                <a href="#">Terms of Service</a> | 
                <a href="#">Contact</a>
            </p>
        </div>
    </footer>
</body>
</html>
