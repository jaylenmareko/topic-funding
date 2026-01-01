<?php
// index.php - YOUTUBE CREATORS ONLY
session_start();

// Try to load database config
$db_available = false;
if (file_exists('config/database.php')) {
    require_once 'config/database.php';
    $db_available = true;
} elseif (file_exists(__DIR__ . '/config/database.php')) {
    require_once __DIR__ . '/config/database.php';
    $db_available = true;
}

// Redirect logged-in creators to dashboard
if (isset($_SESSION['user_id']) && $db_available) {
    try {
        $db = new Database();
        $db->query('SELECT id FROM creators WHERE applicant_user_id = :user_id AND is_active = 1');
        $db->bind(':user_id', $_SESSION['user_id']);
        $is_creator = $db->single();
        
        if ($is_creator) {
            header('Location: creators/dashboard.php');
            exit;
        }
    } catch (Exception $e) {
        error_log("Database error in index.php: " . $e->getMessage());
    }
}

// Fetch all active creators for display
$creators = [];
$total_creators = 0;
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

if ($db_available) {
    try {
        $db = new Database();
        
        if ($search_query) {
            // Remove spaces from search query for flexible matching
            $search_no_spaces = str_replace(' ', '', $search_query);
            
            $db->query('
                SELECT * FROM creators 
                WHERE is_active = 1 
                AND (
                    REPLACE(LOWER(display_name), " ", "") LIKE :search 
                    OR REPLACE(LOWER(username), " ", "") LIKE :search
                    OR LOWER(display_name) LIKE :search_with_spaces
                    OR LOWER(username) LIKE :search_with_spaces
                )
                ORDER BY display_name ASC
            ');
            $db->bind(':search', '%' . strtolower($search_no_spaces) . '%');
            $db->bind(':search_with_spaces', '%' . strtolower($search_query) . '%');
        } else {
            $db->query('SELECT * FROM creators WHERE is_active = 1 ORDER BY display_name ASC LIMIT 24');
        }
        
        $creators = $db->resultSet();
        $total_creators = count($creators);
    } catch (Exception $e) {
        error_log("Database error fetching creators: " . $e->getMessage());
        $creators = [];
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>TopicLaunch - Fund Topics from Your Favorite YouTubers</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            margin: 0; 
            padding: 0; 
            background: white;
            animation: fadeIn 0.6s ease-in;
        }
        
        /* Fade-in animation on page load */
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Staggered animations for sections */
        .hero {
            animation: fadeInUp 0.8s ease-out 0.1s both;
        }
        
        .creators-section {
            animation: fadeInUp 0.8s ease-out 0.3s both;
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Navigation */
        .topiclaunch-nav {
            background: white;
            padding: 15px 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border-bottom: 1px solid #f0f0f0;
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
            color: #FF0000;
            text-decoration: none;
        }

        /* Login Bar */
        .nav-buttons {
            display: flex;
            gap: 15px;
            align-items: center;
        }
        
        .nav-login-btn {
            color: #333;
            text-decoration: none;
            font-size: 15px;
            font-weight: 500;
            padding: 8px 16px;
            transition: color 0.2s;
        }
        
        .nav-login-btn:hover {
            color: #FF0000;
        }
        
        .nav-getstarted-btn {
            background: #FF0000;
            color: white;
            text-decoration: none;
            font-size: 15px;
            font-weight: 600;
            padding: 10px 24px;
            border-radius: 50px;
            transition: all 0.2s;
        }
        
        .nav-getstarted-btn:hover {
            background: #CC0000;
            transform: translateY(-1px);
        } {
            width: 180px;
        }
        .login-bar input[type="password"] {
            width: 140px;
        }
        .login-bar button {
            padding: 8px 20px;
            background: white;
            color: #FF0000;
            border: none;
            border-radius: 4px;
            font-weight: bold;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
        }
        .login-bar button:hover {
            background: #f0f0f0;
            transform: translateY(-1px);
        }
        .login-error {
            color: #ffe6e6;
            font-size: 12px;
            margin-left: 10px;
        }
        
        /* Hero Section */
        .hero { 
            background: linear-gradient(180deg, #fafafa 0%, #ffffff 100%);
            color: #333; 
            padding: 40px 20px 40px 20px; 
            text-align: center;
            min-height: 90vh;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            align-items: center;
            padding-top: 80px;
            position: relative;
        }
        
        .hero::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-image: radial-gradient(circle at 20% 50%, rgba(255, 0, 0, 0.03) 0%, transparent 50%),
                              radial-gradient(circle at 80% 80%, rgba(255, 0, 102, 0.03) 0%, transparent 50%);
            pointer-events: none;
        }
        .hero h1 { font-size: 56px; margin: 0 0 15px 0; font-weight: bold; color: #000; line-height: 1.2; }
        .hero h1 span.red { color: #FF0000; }
        .hero p { font-size: 18px; margin: 0 0 35px 0; color: #666; max-width: 600px; }
        
        /* YouTuber Signup Button */
        .youtuber-signup-btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: linear-gradient(135deg, #FF0000 0%, #CC0000 100%);
            color: white;
            padding: 16px 32px;
            border-radius: 50px;
            font-size: 18px;
            font-weight: bold;
            text-decoration: none;
            box-shadow: 0 4px 15px rgba(255,0,0,0.3);
            transition: all 0.3s ease;
            border: none;
        }
        
        .youtuber-signup-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 25px rgba(255,0,0,0.4);
            filter: brightness(1.1);
        }
        
        .youtuber-signup-btn svg {
            stroke: white;
        }
        
        /* 2-Step Process Boxes */
        .signup-steps {
            display: flex;
            gap: 30px;
            justify-content: center;
            margin-top: 50px;
            padding: 0 20px;
            max-width: 900px;
            margin-left: auto;
            margin-right: auto;
        }
        
        .step-box {
            background: #fafafa;
            border-radius: 12px;
            padding: 30px 25px;
            flex: 1;
            max-width: 280px;
            text-align: center;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            border: 1px solid #f0f0f0;
        }
        
        .step-box:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }
        
        .step-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #FF0000, #FF6B6B);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 28px;
        }
        
        .step-number {
            position: absolute;
            top: -8px;
            right: -8px;
            background: white;
            color: #FF0000;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 14px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .step-box h3 {
            color: #000;
            font-size: 17px;
            margin: 0 0 12px 0;
            font-weight: 600;
        }
        
        .step-box p {
            color: #666;
            font-size: 14px;
            line-height: 1.6;
            margin: 0;
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
            background: #FF0000;
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
            margin: 25px auto 60px auto;
            padding: 0 20px;
        }
        
        .fan-section-header {
            text-align: center;
            margin-bottom: 10px;
        }
        
        .fan-heading {
            font-size: 32px;
            color: #000;
            margin-bottom: 5px;
            font-weight: 700;
        }
        
        .fan-subheading {
            font-size: 16px;
            color: #666;
            margin-bottom: 30px;
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
            border-radius: 12px;
            padding: 20px;
            transition: all 0.2s;
            cursor: pointer;
            text-decoration: none;
            display: flex;
            flex-direction: column;
            gap: 15px;
            color: inherit;
        }
        
        .creator-card-kalshi:hover {
            border-color: #FF0000;
            box-shadow: 0 4px 12px rgba(255, 0, 0, 0.1);
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
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: linear-gradient(135deg, #FF0000 0%, #CC0000 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
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
            font-size: 17px;
            font-weight: 600;
            color: #111827;
            margin-bottom: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .creator-handle-kalshi {
            font-size: 14px;
            color: #6b7280;
        }
        
        .creator-bio {
            font-size: 14px;
            color: #6b7280;
            line-height: 1.5;
            margin: 12px 0 16px 0;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .creator-price-section {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            padding-top: 16px;
            margin-top: 16px;
            border-top: 1px solid #e5e7eb;
        }
        
        .creator-price {
            display: flex;
            align-items: baseline;
            gap: 4px;
            padding: 0;
        }
        
        .price-amount {
            font-size: 24px;
            font-weight: 700;
            color: #111827;
        }
        
        .price-label {
            font-size: 12px;
            color: #6b7280;
            font-weight: 500;
        }
        
        .fund-topics-btn {
            padding: 10px 20px;
            background: #10b981;
            color: white;
            border: none;
            border-radius: 50px;
            font-size: 14px;
            font-weight: 700;
            white-space: nowrap;
            flex-shrink: 0;
            transition: all 0.2s;
        }
        
        .fund-topics-btn:hover {
            background: #059669;
            transform: scale(1.05);
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
            border-color: #FF0000;
        }
        
        .search-creators-btn {
            padding: 12px 24px;
            background: #FF0000;
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }
        
        .search-creators-btn:hover {
            background: #CC0000;
        }
        
        .load-more-container {
            text-align: center;
            margin-top: 30px;
        }
        
        .load-more-btn {
            display: inline-block;
            padding: 12px 32px;
            background: #FF0000;
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
            color: #FF0000;
            border: 2px solid #FF0000;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.2s;
        }
        
        .view-all-btn:hover {
            background: #FF0000;
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
            .hero { padding-top: 60px; }
            .hero h1 { font-size: 40px; }
            .hero p { font-size: 16px; margin-bottom: 30px; }
            .demo-video-section iframe { height: 250px; }
            .process-steps { grid-template-columns: 1fr; }
            .testimonial-label { font-size: 24px; }
            .creators-grid-landing { grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); }
            .creators-section h2 { font-size: 24px; }
            .fan-heading { font-size: 28px; }
            .fan-subheading { font-size: 16px; }
            
            /* Stack signup steps on mobile */
            .signup-steps {
                flex-direction: column;
                align-items: center;
                gap: 20px;
                margin-top: 35px;
            }
            
            .step-box {
                max-width: 100%;
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="topiclaunch-nav">
        <div class="nav-container">
            <span class="nav-logo">TopicLaunch</span>

            <!-- Nav Buttons -->
            <div class="nav-buttons">
                <a href="auth/login.php" class="nav-login-btn">Log In</a>
                <a href="creators/signup.php" class="nav-getstarted-btn">Get Started</a>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <div class="hero">
        <h1>Fans Fund Topics for <span class="red">YouTubers</span></h1>
        <p>Request that video from your favorite YouTuber and get it delivered for a small contribution</p>
        
        <!-- YouTuber Signup Button -->
        <div style="margin-top: 30px;">
            <a href="creators/signup.php" class="youtuber-signup-btn">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path>
                    <circle cx="9" cy="7" r="4"></circle>
                    <path d="M22 21v-2a4 4 0 0 0-3-3.87"></path>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                </svg>
                Start Earning
            </a>
        </div>
        
        <!-- 2-Step Process -->
        <div class="signup-steps">
            <div class="step-box">
                <div class="step-icon">
                    💡
                </div>
                <h3>Fans Crowdfund Topics</h3>
                <p>Fans make the FIRST contribution for a video idea. Then others chip in until goal is reached.</p>
            </div>
            
            <div class="step-box">
                <div class="step-icon">
                    ⚡
                </div>
                <h3>48-Hour Delivery</h3>
                <p>Creator delivers and gets 90% of funding, or fans get refunded.</p>
            </div>
        </div>
    </div>

    <!-- Creators Grid Section - Kalshi Style -->
    <div class="creators-section">
        <!-- Fan Section Header -->
        <div class="fan-section-header">
            <h2 class="fan-heading">Trending YouTubers</h2>
            <p class="fan-subheading">Top YouTubers growing fast this week.</p>
        </div>
        
        <!-- Search Bar -->
        <div class="search-creators-container">
            <form method="GET" action="" class="search-creators-form" id="searchForm">
                <input type="text" 
                       id="searchInput"
                       name="search" 
                       class="search-creators-input" 
                       placeholder="Search YouTubers" 
                       autocomplete="off"
                       value="<?php echo htmlspecialchars($search_query ?? ''); ?>">
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
                    
                    <?php if (!empty($creator->bio)): ?>
                    <div class="creator-bio">
                        <?php echo htmlspecialchars($creator->bio); ?>
                    </div>
                    <?php endif; ?>
                    
                    <div class="creator-price-section">
                        <div class="creator-price">
                            <span class="price-amount">$<?php echo number_format($creator->minimum_topic_price ?? 100, 2); ?></span>
                            <span class="price-label">/ PER TOPIC</span>
                        </div>
                        
                        <button class="fund-topics-btn" onclick="event.preventDefault(); window.location.href='/<?php echo htmlspecialchars($creator->display_name); ?>'">
                            Fund Topics
                        </button>
                    </div>
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
    // Live Search Functionality
    const searchInput = document.getElementById('searchInput');
    const creatorsGrid = document.getElementById('creatorsGrid');
    const loadMoreContainer = document.querySelector('.load-more-container');
    
    if (searchInput) {
        searchInput.addEventListener('input', function(e) {
            const query = e.target.value.trim();
            
            if (query.length === 0) {
                // Show all creators when search is empty
                const cards = creatorsGrid.querySelectorAll('.creator-card-kalshi');
                cards.forEach(card => {
                    card.style.display = 'flex';
                });
                // Show load more button when not searching
                if (loadMoreContainer) {
                    loadMoreContainer.style.display = 'block';
                }
            } else {
                // Search as user types
                performSearch(query);
            }
        });
    }
    
    function performSearch(query) {
        // Normalize query - lowercase
        const searchQuery = query.toLowerCase();
        const searchQueryNoSpaces = searchQuery.replace(/\s+/g, '');
        
        // Get all creator cards
        const cards = creatorsGrid.querySelectorAll('.creator-card-kalshi');
        let visibleCount = 0;
        
        cards.forEach(card => {
            const nameElement = card.querySelector('.creator-name-kalshi');
            const handleElement = card.querySelector('.creator-handle-kalshi');
            
            if (!nameElement || !handleElement) return;
            
            // Get text and normalize
            const name = nameElement.textContent.toLowerCase();
            const handle = handleElement.textContent.replace('@', '').toLowerCase();
            const nameNoSpaces = name.replace(/\s+/g, '');
            const handleNoSpaces = handle.replace(/\s+/g, '');
            
            // Match in multiple ways for flexibility
            const matches = 
                name.includes(searchQuery) || 
                nameNoSpaces.includes(searchQueryNoSpaces) ||
                handle.includes(searchQuery) || 
                handleNoSpaces.includes(searchQueryNoSpaces);
            
            if (matches) {
                card.style.display = 'flex';
                visibleCount++;
            } else {
                card.style.display = 'none';
            }
        });
        
        // Hide load more button during search
        if (loadMoreContainer) {
            loadMoreContainer.style.display = 'none';
        }
    }
    
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
