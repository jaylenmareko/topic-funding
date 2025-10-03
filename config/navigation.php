<?php
// config/navigation.php - Updated navigation with edit profile button
function renderNavigation($current_page = '', $is_profile_page = false) {
    // Check if user is logged in
    $is_logged_in = isset($_SESSION['user_id']);
    $user_id = $_SESSION['user_id'] ?? 0;
    $username = $_SESSION['username'] ?? '';
    
    // Check user roles
    $is_admin = $is_logged_in && in_array($user_id, [1, 2, 9]);
    $is_creator = false;
    $creator_id = null;
    
    if ($is_logged_in) {
        $db = new Database();
        $db->query('SELECT id FROM creators WHERE applicant_user_id = :user_id AND is_active = 1');
        $db->bind(':user_id', $user_id);
        $creator = $db->single();
        if ($creator) {
            $is_creator = true;
            $creator_id = $creator->id;
        }
    }
    
    // Determine base path based on current location
    $base_path = '';
    if (strpos($_SERVER['REQUEST_URI'], '/admin/') !== false) $base_path = '../';
    if (strpos($_SERVER['REQUEST_URI'], '/auth/') !== false) $base_path = '../';
    if (strpos($_SERVER['REQUEST_URI'], '/creators/') !== false) $base_path = '../';
    if (strpos($_SERVER['REQUEST_URI'], '/topics/') !== false) $base_path = '../';
    if (strpos($_SERVER['REQUEST_URI'], '/dashboard/') !== false) $base_path = '../';
    
    // Determine logo link based on user type
    $logo_link = $base_path . 'index.php'; // Default to home page
    
    if ($is_logged_in) {
        if ($is_creator) {
            // Creators: logo goes to their dashboard
            $logo_link = $base_path . 'creators/dashboard.php';
        } else {
            // Fans: logo goes to browse creators
            $logo_link = $base_path . 'creators/index.php';
        }
    }
    ?>
    
    <style>
    .topiclaunch-nav {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        padding: 15px 0;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        margin-bottom: 20px;
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
        cursor: pointer;
        transition: opacity 0.3s;
    }
    .nav-logo:hover { 
        color: white; 
        text-decoration: none;
        opacity: 0.9;
    }
    .nav-links {
        display: flex;
        align-items: center;
        gap: 25px;
    }
    .nav-link {
        color: white;
        text-decoration: none;
        padding: 8px 16px;
        border-radius: 6px;
        transition: all 0.3s;
        font-weight: 500;
    }
    .nav-link:hover {
        background: rgba(255,255,255,0.2);
        color: white;
        text-decoration: none;
    }
    .nav-link.active {
        background: rgba(255,255,255,0.3);
        color: white;
    }
    .nav-user {
        display: flex;
        align-items: center;
        gap: 15px;
        color: white;
    }
    .nav-btn {
        background: rgba(255,255,255,0.2);
        color: white;
        padding: 8px 16px;
        border-radius: 6px;
        text-decoration: none;
        font-weight: 500;
        transition: all 0.3s;
    }
    .nav-btn:hover {
        background: rgba(255,255,255,0.3);
        color: white;
        text-decoration: none;
    }
    .nav-btn.creator {
        background: #2ed573;
    }
    .nav-btn.creator:hover {
        background: #26c965;
    }
    .nav-btn.admin {
        background: #ff4757;
    }
    .nav-btn.admin:hover {
        background: #ff3742;
    }
    .nav-btn.dashboard {
        background: #667eea;
    }
    .nav-btn.dashboard:hover {
        background: #5a6fd8;
    }
    .nav-mobile-toggle {
        display: none;
        background: none;
        border: none;
        color: white;
        font-size: 24px;
        cursor: pointer;
    }
    
    @media (max-width: 768px) {
        .nav-links {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: #667eea;
            flex-direction: column;
            padding: 20px;
            gap: 15px;
        }
        .nav-links.mobile-open {
            display: flex;
        }
        .nav-mobile-toggle {
            display: block;
        }
        .nav-user {
            flex-direction: column;
            gap: 10px;
            align-items: flex-start;
        }
    }
    </style>
    
    <nav class="topiclaunch-nav">
        <div class="nav-container">
            <!-- Clickable Logo -->
            <a href="<?php echo $logo_link; ?>" class="nav-logo">TopicLaunch</a>
            
            <div class="nav-links" id="navLinks">
                <?php if ($is_logged_in): ?>
                    <!-- Main Navigation for Logged In Users -->
                    
                    <?php if ($is_creator): ?>
                        <!-- Creator Navigation - Only show Dashboard button when NOT on dashboard -->
                        <?php if ($current_page !== 'dashboard'): ?>
                        <a href="<?php echo $base_path; ?>creators/dashboard.php" class="nav-btn dashboard">
                            📊 Dashboard
                        </a>
                        <?php endif; ?>
                    <?php else: ?>
                        <!-- Fan Navigation - NO DASHBOARD BUTTON, just YouTubers link -->
                        <a href="<?php echo $base_path; ?>creators/index.php" class="nav-link <?php echo $current_page === 'browse_creators' ? 'active' : ''; ?>">
                            YouTubers
                        </a>
                    <?php endif; ?>
                    
                    <!-- Admin Panel -->
                    <?php if ($is_admin): ?>
                        <a href="<?php echo $base_path; ?>admin/creators.php" class="nav-btn admin">
                            Admin
                        </a>
                    <?php endif; ?>
                    
                    <!-- User Info & Actions -->
                    <div class="nav-user">
                        <span>Hi, <?php echo htmlspecialchars($username); ?>!</span>
                        
                        <?php if ($is_creator && $creator_id): ?>
                            <a href="<?php echo $base_path; ?>creators/edit.php?id=<?php echo $creator_id; ?>" class="nav-link">
                                Edit Profile
                            </a>
                        <?php endif; ?>
                        
                        <a href="<?php echo $base_path; ?>auth/logout.php" class="nav-link">Logout</a>
                    </div>
                    
                <?php else: ?>
                    <!-- Navigation for Guests -->
                    <?php if (!$is_profile_page): ?>
                        <!-- Only show these buttons when NOT on a profile page -->
                        <a href="<?php echo $base_path; ?>creators/apply.php" class="nav-btn creator">
                            📺 Join as Creator
                        </a>
                        
                        <a href="<?php echo $base_path; ?>auth/login.php" class="nav-btn">
                            Login
                        </a>
                        
                        <a href="<?php echo $base_path; ?>auth/register.php" class="nav-btn creator">
                            Get Started
                        </a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            
            <button class="nav-mobile-toggle" onclick="toggleMobileNav()">
                ☰
            </button>
        </div>
    </nav>
    
    <script>
    function toggleMobileNav() {
        const navLinks = document.getElementById('navLinks');
        navLinks.classList.toggle('mobile-open');
    }
    
    // Close mobile nav when clicking outside
    document.addEventListener('click', function(e) {
        const navLinks = document.getElementById('navLinks');
        const toggleBtn = document.querySelector('.nav-mobile-toggle');
        
        if (!navLinks.contains(e.target) && !toggleBtn.contains(e.target)) {
            navLinks.classList.remove('mobile-open');
        }
    });
    </script>
    
    <?php
}
?>
