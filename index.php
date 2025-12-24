<?php
// index.php - YOUTUBE CREATORS ONLY - Google OAuth
session_start();

// Redirect logged-in creators to dashboard
if (isset($_SESSION['user_id'])) {
    require_once 'config/database.php';
    $db = new Database();
    $db->query('SELECT id FROM creators WHERE applicant_user_id = :user_id AND is_active = 1');
    $db->bind(':user_id', $_SESSION['user_id']);
    $is_creator = $db->single();
    
    if ($is_creator) {
        header('Location: creators/dashboard.php');
        exit;
    }
}

// Fetch all active creators for display
require_once 'config/database.php';
$db = new Database();
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

if ($search_query) {
    $db->query('
        SELECT * FROM creators 
        WHERE is_active = 1 
        AND (display_name LIKE :search OR username LIKE :search)
        ORDER BY display_name ASC
    ');
    $db->bind(':search', '%' . $search_query . '%');
} else {
    $db->query('SELECT * FROM creators WHERE is_active = 1 ORDER BY display_name ASC LIMIT 24');
}

$creators = $db->resultSet();
$total_creators = count($creators);
?>
<!DOCTYPE html>
<html>
<head>
    <title>TopicLaunch - Fund Topics from Your Favorite YouTubers</title>
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
            justify-content: center;
            align-items: center;
            padding: 0 20px;
        }
        .nav-logo {
            font-size: 24px;
            font-weight: bold;
            color: white;
            text-decoration: none;
        }
        
        /* Hero Section */
        .hero { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 60px 20px; text-align: center; }
        .hero h1 { font-size: 48px; margin: 0 0 20px 0; font-weight: bold; }
        .hero p { font-size: 20px; margin: 0 0 40px 0; opacity: 0.9; }
        
        /* Google Sign In Button */
        .google-signin-container {
            max-width: 400px;
            margin: 0 auto;
            background: rgba(255,255,255,0.1);
            padding: 40px 30px;
            border-radius: 15px;
            border: 2px solid rgba(255,255,255,0.2);
            backdrop-filter: blur(10px);
        }
        
        .google-signin-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            background: white;
            color: #444;
            padding: 15px 30px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            font-size: 16px;
            transition: all 0.3s;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            width: 100%;
        }
        
        .google-signin-btn:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
            transform: translateY(-2px);
        }
        
        .google-icon {
            width: 20px;
            height: 20px;
        }
        
        .signup-subtitle {
            color: rgba(255,255,255,0.9);
            font-size: 14px;
            margin-top: 15px;
            text-align: center;
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
        
        /* Creators Section - Kalshi Style */
        .creators-section {
            max-width: 1200px;
            margin: 60px auto;
            padding: 0 20px;
        }
        
        .fan-section-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .fan-heading {
            font-size: 32px;
            color: #333;
            margin-bottom: 10px;
            font-weight: 600;
        }
        
        .fan-subheading {
            font-size: 18px;
            color: #666;
            margin-bottom: 0;
        }
        
        .creators-section h2 {
            font-size: 28px;
            color: #333;
            margin-bottom: 30px;
            font-weight: 600;
        }
        
        .creators-grid-landing {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 16px;
            margin-bottom: 30px;
        }
        
        .creator-card-kalshi {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 20px;
            transition: all 0.2s;
            cursor: pointer;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: space-between;
            color: inherit;
        }
        
        .creator-card-kalshi:hover {
            border-color: #667eea;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.1);
            transform: translateY(-2px);
        }
        
        .creator-card-header {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
            min-width: 0;
        }
        
        .creator-avatar-kalshi {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
            font-weight: bold;
            overflow: hidden;
            flex-shrink: 0;
        }
        
        .creator-avatar-kalshi img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .creator-info {
            flex: 1;
            min-width: 0;
        }
        
        .creator-name-kalshi {
            font-size: 16px;
            font-weight: 600;
            color: #111827;
            margin-bottom: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .creator-handle-kalshi {
            font-size: 14px;
            color: #6b7280;
        }
        
        .fund-topics-btn {
            padding: 8px 16px;
            background: #10b981;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            white-space: nowrap;
            flex-shrink: 0;
            transition: background 0.2s;
        }
        
        .fund-topics-btn:hover {
            background: #059669;
        }
        
        .search-creators-container {
            margin-bottom: 40px;
            display: flex;
            justify-content: center;
        }
        
        .search-creators-form {
            display: flex;
            gap: 10px;
            max-width: 500px;
            width: 100%;
        }
        
        .search-creators-input {
            flex: 1;
            padding: 12px 16px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 16px;
        }
        
        .search-creators-input:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .search-creators-btn {
            padding: 12px 24px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }
        
        .search-creators-btn:hover {
            background: #5568d3;
        }
        
        .load-more-container {
            text-align: center;
            margin-top: 30px;
        }
        
        .load-more-btn {
            display: inline-block;
            padding: 12px 32px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }
        
        .load-more-btn:hover {
            background: #5568d3;
        }
        
        .load-more-btn:disabled {
            background: #9ca3af;
            cursor: not-allowed;
        }
        
        .view-all-creators {
            text-align: center;
            margin-top: 30px;
        }
        
        .view-all-btn {
            display: inline-block;
            padding: 12px 24px;
            background: white;
            color: #667eea;
            border: 2px solid #667eea;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.2s;
        }
        
        .view-all-btn:hover {
            background: #667eea;
            color: white;
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
            .google-signin-container { padding: 30px 20px; }
            .demo-video-section iframe { height: 250px; }
            .process-steps { grid-template-columns: 1fr; }
            .testimonial-label { font-size: 24px; }
            .creators-grid-landing { grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); }
            .creators-section h2 { font-size: 24px; }
            .fan-heading { font-size: 28px; }
            .fan-subheading { font-size: 16px; }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="topiclaunch-nav">
        <div class="nav-container">
            <span class="nav-logo">TopicLaunch</span>
        </div>
    </nav>

    <!-- Hero Section -->
    <div class="hero">
        <h1>Fans Fund Topics for YouTubers</h1>
        <p>Get paid to make videos your fans want</p>
        
        <!-- Google Sign In -->
        <div class="google-signin-container">
            <p class="signup-subtitle" style="margin-top: 0; margin-bottom: 15px; font-size: 16px; font-weight: 600;">
                YouTuber Sign Up / Login
            </p>
            <a href="auth/google-oauth.php" class="google-signin-btn">
                <svg class="google-icon" viewBox="0 0 24 24">
                    <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                    <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                    <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                    <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                </svg>
                Continue with Google
            </a>
        </div>
    </div>

    <!-- Creators Grid Section - Kalshi Style -->
    <div class="creators-section">
        <!-- Fan Section Header -->
        <div class="fan-section-header">
            <h2 class="fan-heading">Fan Section</h2>
        </div>
        
        <!-- Search Bar -->
        <div class="search-creators-container">
            <form method="GET" action="" class="search-creators-form" id="searchForm">
                <input type="text" 
                       name="search" 
                       class="search-creators-input" 
                       placeholder="Search YouTubers" 
                       value="<?php echo htmlspecialchars($search_query ?? ''); ?>">
                <button type="submit" class="search-creators-btn">Search</button>
            </form>
        </div>
        
        <div class="creators-grid-landing" id="creatorsGrid">
            <?php foreach ($creators as $creator): ?>
                <a href="/<?php echo htmlspecialchars($creator->display_name); ?>" class="creator-card-kalshi">
                    <div class="creator-card-header">
                        <div class="creator-avatar-kalshi">
                            <?php if ($creator->profile_image): ?>
                                <img src="/uploads/creators/<?php echo htmlspecialchars($creator->profile_image); ?>" 
                                     alt="<?php echo htmlspecialchars($creator->display_name); ?>">
                            <?php else: ?>
                                <?php echo strtoupper(substr($creator->display_name, 0, 1)); ?>
                            <?php endif; ?>
                        </div>
                        <div class="creator-info">
                            <div class="creator-name-kalshi">
                                <?php echo htmlspecialchars($creator->display_name); ?>
                            </div>
                            <div class="creator-handle-kalshi">
                                @<?php echo htmlspecialchars($creator->display_name); ?>
                            </div>
                        </div>
                    </div>
                    <button class="fund-topics-btn" onclick="event.preventDefault(); window.location.href='/<?php echo htmlspecialchars($creator->display_name); ?>'">
                        Fund Topics
                    </button>
                </a>
            <?php endforeach; ?>
        </div>
        
        <?php if (!$search_query): ?>
        <!-- Load More Button (only shown when not searching) -->
        <div class="load-more-container">
            <button class="load-more-btn" id="loadMoreBtn" onclick="loadMoreCreators()">
                Load More Creators
            </button>
        </div>
        <?php endif; ?>
    </div>

    <!-- 2-Step Process and Testimonial Section -->
    <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 60px 20px;">
        <div class="container" style="max-width: 1200px; margin: 0 auto; padding: 0 20px;">
            <!-- 2-Step Process -->
            <div class="process-steps" style="margin-bottom: 60px;">
                <div class="process-step" data-step="1" style="background: rgba(255,255,255,0.95);">
                    <div class="process-icon">💡</div>
                    <h3>Fans Crowdfund Topics</h3>
                    <p>Fans make the FIRST contribution for a video idea. Then others chip in until goal is reached.</p>
                </div>
                <div class="process-step" data-step="2" style="background: rgba(255,255,255,0.95);">
                    <div class="process-icon">⚡</div>
                    <h3>48-Hour Delivery</h3>
                    <p>Creator delivers and gets 90% of funding, or fans get refunded.</p>
                </div>
            </div>

            <!-- Testimonial -->
            <div style="text-align: center;">
                <div class="testimonial-label" style="color: white; margin-bottom: 30px;">
                    Testimonial ⬇️
                </div>
                <p style="color: rgba(255,255,255,0.9); font-size: 18px; margin-bottom: 30px;">
                    From <a href="https://www.youtube.com/@abouxtoure" target="_blank" style="color: #FFD700; text-decoration: none; font-weight: bold; transition: opacity 0.3s;" onmouseover="this.style.opacity='0.8'" onmouseout="this.style.opacity='1'">@abouxtoure</a>
                </p>
                <video controls style="width: 100%; max-width: 600px; border-radius: 12px; box-shadow: 0 8px 24px rgba(0,0,0,0.3);">
                    <source src="uploads/testimonial.mp4" type="video/mp4">
                    Your browser does not support the video tag.
                </video>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer">
        <div>
            <p>&copy; 2025 TopicLaunch. All rights reserved.</p>
            <p style="margin-top: 10px;">
                <a href="/terms.php">Terms of Service</a> | 
                <a href="mailto:report@topiclaunch.com">Report Content</a>
            </p>
        </div>
    </footer>

    <script>
    let offset = 24; // Start after initial 24 creators
    let loading = false;

    function loadMoreCreators() {
        if (loading) return;
        loading = true;
        
        const btn = document.getElementById('loadMoreBtn');
        btn.textContent = 'Loading...';
        btn.disabled = true;

        fetch(`/api/load-more-creators.php?offset=${offset}`)
            .then(response => response.json())
            .then(data => {
                if (data.creators && data.creators.length > 0) {
                    const grid = document.getElementById('creatorsGrid');
                    
                    data.creators.forEach(creator => {
                        const card = createCreatorCard(creator);
                        grid.insertAdjacentHTML('beforeend', card);
                    });
                    
                    offset += data.creators.length;
                    
                    if (data.creators.length < 12) {
                        btn.textContent = 'No More Creators';
                        btn.disabled = true;
                    } else {
                        btn.textContent = 'Load More Creators';
                        btn.disabled = false;
                    }
                } else {
                    btn.textContent = 'No More Creators';
                    btn.disabled = true;
                }
                loading = false;
            })
            .catch(error => {
                console.error('Error loading creators:', error);
                btn.textContent = 'Error - Try Again';
                btn.disabled = false;
                loading = false;
            });
    }

    function createCreatorCard(creator) {
        const initial = creator.display_name.charAt(0).toUpperCase();
        const profileImage = creator.profile_image 
            ? `<img src="/uploads/creators/${creator.profile_image}" alt="${creator.display_name}">` 
            : initial;
        
        return `
            <a href="/${creator.display_name}" class="creator-card-kalshi">
                <div class="creator-card-header">
                    <div class="creator-avatar-kalshi">
                        ${profileImage}
                    </div>
                    <div class="creator-info">
                        <div class="creator-name-kalshi">${creator.display_name}</div>
                        <div class="creator-handle-kalshi">@${creator.display_name}</div>
                    </div>
                </div>
                <button class="fund-topics-btn" onclick="event.preventDefault(); window.location.href='/${creator.display_name}'">
                    Fund Topics
                </button>
            </a>
        `;
    }
    </script>
</body>
</html>
