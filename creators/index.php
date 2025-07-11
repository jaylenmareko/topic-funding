<?php
// creators/index.php - Simplified browse creators page with creator access control
session_start();
require_once '../config/database.php';

// Check if logged in user is a creator - redirect them to dashboard
if (isset($_SESSION['user_id'])) {
    $db = new Database();
    $db->query('SELECT id FROM creators WHERE applicant_user_id = :user_id AND is_active = 1');
    $db->bind(':user_id', $_SESSION['user_id']);
    $is_creator = $db->single();
    
    if ($is_creator) {
        // Creators should not access this page - redirect to dashboard
        header('Location: ../dashboard/index.php');
        exit;
    }
}

try {
    $helper = new DatabaseHelper();
    $creators = $helper->getAllCreators();
} catch (Exception $e) {
    die('Database error: ' . $e->getMessage());
}

// Simple search functionality
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

if ($search) {
    $creators = array_filter($creators, function($creator) use ($search) {
        return stripos($creator->display_name, $search) !== false || 
               stripos($creator->bio, $search) !== false;
    });
}

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
            
            // Check if user is a creator - redirect to dashboard
            $db->query('SELECT id FROM creators WHERE applicant_user_id = :user_id AND is_active = 1');
            $db->bind(':user_id', $user->id);
            $is_creator = $db->single();
            
            if ($is_creator) {
                header('Location: ../dashboard/index.php'); // Creators go to dashboard
            } else {
                header('Location: ' . $_SERVER['REQUEST_URI']); // Fans stay here
            }
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
    <title>Browse YouTubers - TopicLaunch</title>
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
        
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .header { background: white; padding: 30px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .header h1 { margin: 0 0 15px 0; color: #333; }
        
        .filters { display: flex; gap: 20px; align-items: center; flex-wrap: wrap; margin-bottom: 20px; }
        .search-box { flex: 1; max-width: 300px; }
        .search-box input { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 25px; font-size: 16px; }
        .search-box button { background: #667eea; color: white; padding: 12px 20px; border: none; border-radius: 25px; margin-left: 10px; cursor: pointer; }
        
        .creator-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 25px; }
        .creator-card { 
            background: white; 
            padding: 25px; 
            border-radius: 12px; 
            box-shadow: 0 4px 12px rgba(0,0,0,0.08); 
            transition: transform 0.3s, box-shadow 0.3s;
            text-align: center;
        }
        .creator-card:hover { transform: translateY(-3px); box-shadow: 0 8px 20px rgba(0,0,0,0.15); }
        
        .creator-image { 
            width: 80px; 
            height: 80px; 
            background: linear-gradient(135deg, #667eea, #764ba2); 
            border-radius: 50%; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            font-size: 32px; 
            color: white; 
            font-weight: bold; 
            margin: 0 auto 20px auto;
        }
        
        .creator-name { 
            font-size: 20px; 
            font-weight: bold; 
            color: #333; 
            margin: 0 0 20px 0; 
        }
        
        .creator-actions { display: flex; gap: 10px; margin-top: 20px; }
        .btn { 
            background: #667eea; 
            color: white; 
            padding: 12px 20px; 
            text-decoration: none; 
            border-radius: 6px; 
            display: inline-block; 
            font-weight: 500; 
            transition: background 0.3s; 
            text-align: center; 
        }
        .btn:hover { background: #5a6fd8; color: white; text-decoration: none; }
        .btn-outline { background: transparent; color: #667eea; border: 2px solid #667eea; }
        .btn-outline:hover { background: #667eea; color: white; }
        
        .empty-state { text-align: center; color: #666; padding: 60px 20px; background: white; border-radius: 12px; }
        
        @media (max-width: 768px) {
            .container { padding: 15px; }
            .creator-grid { grid-template-columns: 1fr; }
            .filters { flex-direction: column; align-items: stretch; }
            .search-box { max-width: none; }
            .login-form { flex-direction: column; gap: 5px; }
            .nav-container { flex-direction: column; gap: 15px; }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="topiclaunch-nav">
        <div class="nav-container">
            <a href="../index.php" class="nav-logo">TopicLaunch</a>
            
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
                    <a href="../dashboard/index.php" style="color: white; margin-left: 15px;">Dashboard</a>
                    <a href="../auth/logout.php" style="color: white; margin-left: 15px;">Logout</a>
                </div>
            <?php endif; ?>
        </div>
    </nav>

    <div class="container">
        <div class="header">
            <h1>Browse YouTubers</h1>
            
            <!-- Search -->
            <div class="filters">
                <form method="GET" class="search-box">
                    <input type="text" name="search" placeholder="Search YouTubers..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit">Search</button>
                </form>

                <?php if ($search): ?>
                    <a href="index.php" style="background: #6c757d; color: white; padding: 12px 20px; text-decoration: none; border-radius: 25px;">Clear Search</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($search): ?>
            <div style="margin-bottom: 20px; color: #666;">
                Search results for "<strong><?php echo htmlspecialchars($search); ?></strong>" • 
                <strong><?php echo count($creators); ?></strong> YouTubers found
            </div>
        <?php endif; ?>

        <?php if (empty($creators)): ?>
            <div class="empty-state">
                <?php if ($search): ?>
                    <h3>No YouTubers found</h3>
                    <p>No YouTubers match your search criteria</p>
                    <a href="index.php" class="btn">View All YouTubers</a>
                <?php else: ?>
                    <h3>No YouTubers yet</h3>
                    <p>Be the first to join as a YouTuber!</p>
                    <a href="apply.php" class="btn">Apply to be a YouTuber</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="creator-grid">
                <?php foreach ($creators as $creator): ?>
                    <div class="creator-card">
                        <div class="creator-image">
                            <?php if ($creator->profile_image && file_exists('../uploads/creators/' . $creator->profile_image)): ?>
                                <img src="../uploads/creators/<?php echo htmlspecialchars($creator->profile_image); ?>" 
                                     alt="Profile" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                            <?php else: ?>
                                <?php echo strtoupper(substr($creator->display_name, 0, 1)); ?>
                            <?php endif; ?>
                        </div>
                        
                        <h3 class="creator-name">
                            <?php echo htmlspecialchars($creator->display_name); ?>
                        </h3>
                        
                        <div class="creator-actions">
                            <?php if (isset($_SESSION['user_id'])): ?>
                                <a href="profile.php?id=<?php echo $creator->id; ?>" class="btn">Fund Topics</a>
                            <?php endif; ?>
                            <?php if ($creator->platform_url): ?>
                                <a href="<?php echo htmlspecialchars($creator->platform_url); ?>" target="_blank" class="btn btn-outline">Visit Channel</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
