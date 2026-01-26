<?php
// index.php - FOR WOMEN WHO RUN IT
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
$total_creators_in_db = 0;
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

if ($db_available) {
    try {
        $db = new Database();
        
        // Get total count first
        $db->query('SELECT COUNT(*) as total FROM creators WHERE is_active = 1');
        $count_result = $db->single();
        $total_creators_in_db = $count_result->total ?? 0;
        
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
    <title>TopicLaunch - Turn Attention Into Intentional Income</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta charset="UTF-8">
    <meta name="description" content="For Creators Who Run It. Set your price, get paid upfront, and create on your terms. Built for the next generation of content creators.">
    
    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://topiclaunch.com/">
    <meta property="og:title" content="TopicLaunch - Turn Attention Into Intentional Income">
    <meta property="og:description" content="You're the CEO. Set your price, get paid upfront, and create on your terms.">
    <meta property="og:image" content="https://topiclaunch.com/og-image.png">
    
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Playfair+Display:wght@600;700;800&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --hot-pink: #FF006B;
            --deep-pink: #E6005F;
            --black: #000000;
            --off-white: #FAFAFA;
            --white: #FFFFFF;
            --cream: #FFF8F5;
            --gray-dark: #1A1A1A;
            --gray-med: #666666;
            --gray-light: #E5E5E5;
        }
        
        * { 
            margin: 0; 
            padding: 0; 
            box-sizing: border-box; 
        }
        
        body { 
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            margin: 0; 
            padding: 0; 
            background: var(--white);
            color: var(--black);
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            animation: fadeIn 0.6s ease-in;
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
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
        
        @keyframes fadeInScale {
            from {
                opacity: 0;
                transform: scale(0.95);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }
        
        /* Navigation */
        .topiclaunch-nav {
            background: var(--white);
            padding: 16px 0;
            border-bottom: 1px solid var(--gray-light);
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        
        .nav-container {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 30px;
        }
        
        .nav-logo {
            font-family: 'Playfair Display', serif;
            font-size: 22px;
            font-weight: 700;
            color: var(--black);
            text-decoration: none;
            letter-spacing: -0.5px;
        }
        
        .nav-logo span {
            color: var(--hot-pink);
        }

        .nav-center {
            display: flex;
            gap: 30px;
            align-items: center;
        }
        
        .nav-link {
            color: var(--gray-dark);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: color 0.2s;
        }
        
        .nav-link:hover {
            color: var(--hot-pink);
        }

        .nav-buttons {
            display: flex;
            gap: 15px;
            align-items: center;
        }
        
        .nav-login-btn {
            color: var(--gray-dark);
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            padding: 0;
            transition: color 0.2s;
        }
        
        .nav-login-btn:hover {
            color: var(--hot-pink);
        }
        
        .nav-getstarted-btn {
            background: var(--hot-pink);
            color: var(--white);
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            padding: 10px 24px;
            border-radius: 50px;
            transition: all 0.2s;
        }
        
        .nav-getstarted-btn:hover {
            background: var(--deep-pink);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(255, 0, 107, 0.3);
        }
        
        /* Hero Section */
        .hero { 
            background: var(--black);
            padding: 80px 30px 100px 30px; 
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .hero::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 600px;
            height: 600px;
            background: radial-gradient(circle, var(--hot-pink) 0%, transparent 70%);
            opacity: 0.15;
            animation: pulse 8s ease-in-out infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 0.15; }
            50% { transform: scale(1.1); opacity: 0.25; }
        }
        
        .hero-container {
            max-width: 1000px;
            margin: 0 auto;
            position: relative;
            z-index: 2;
            animation: fadeInUp 0.8s ease-out 0.2s both;
        }
        
        .hero-eyebrow {
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: var(--hot-pink);
            margin-bottom: 20px;
            animation: fadeInUp 0.6s ease-out 0.3s both;
        }
        
        .hero h1 { 
            font-family: 'Playfair Display', serif;
            font-size: 68px; 
            margin: 0 0 25px 0; 
            font-weight: 700; 
            color: var(--white); 
            line-height: 1.1;
            letter-spacing: -1.5px;
            animation: fadeInUp 0.8s ease-out 0.4s both;
        }
        
        .hero h1 .pink { 
            color: var(--hot-pink);
            font-style: italic;
        }
        
        .hero-subhead {
            font-size: 20px;
            font-weight: 400;
            color: var(--off-white);
            max-width: 700px;
            margin: 0 auto 40px auto;
            line-height: 1.6;
            animation: fadeInUp 0.8s ease-out 0.5s both;
        }
        
        .hero-cta {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            background: var(--hot-pink);
            color: var(--white);
            padding: 18px 45px;
            border-radius: 50px;
            font-size: 16px;
            font-weight: 700;
            text-decoration: none;
            transition: all 0.3s ease;
            box-shadow: 0 8px 25px rgba(255, 0, 107, 0.25);
            animation: fadeInScale 0.6s ease-out 0.7s both;
        }
        
        .hero-cta:hover {
            background: var(--deep-pink);
            transform: translateY(-2px);
            box-shadow: 0 12px 35px rgba(255, 0, 107, 0.35);
        }
        
        /* Value Props */
        .value-props {
            background: var(--white);
            padding: 80px 30px;
            animation: fadeInUp 0.8s ease-out 0.4s both;
        }
        
        .value-container {
            max-width: 1200px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 50px;
        }
        
        .value-card {
            text-align: center;
            padding: 40px 30px;
            background: var(--cream);
            border-radius: 20px;
            transition: all 0.3s ease;
        }
        
        .value-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
        }
        
        .value-icon {
            font-size: 48px;
            margin-bottom: 20px;
        }
        
        .value-card h3 {
            font-family: 'Playfair Display', serif;
            font-size: 24px;
            margin-bottom: 15px;
            font-weight: 700;
            color: var(--black);
        }
        
        .value-card p {
            font-size: 16px;
            line-height: 1.6;
            color: var(--gray-dark);
            font-weight: 400;
        }
        
        /* Creators Section */
        .creators-section {
            background: var(--cream);
            padding: 80px 30px 100px 30px;
            animation: fadeInUp 0.8s ease-out 0.5s both;
        }
        
        .creators-container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .section-header {
            margin-bottom: 50px;
            text-align: center;
        }
        
        .section-eyebrow {
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: var(--hot-pink);
            margin-bottom: 15px;
        }
        
        .section-title {
            font-family: 'Playfair Display', serif;
            font-size: 48px;
            margin-bottom: 15px;
            font-weight: 700;
            color: var(--black);
        }
        
        .section-subtitle {
            font-size: 18px;
            color: var(--gray-med);
            font-weight: 400;
        }
        
        /* Search */
        .search-section {
            background: var(--white);
            border-radius: 50px;
            padding: 8px;
            margin-bottom: 50px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
            margin-bottom: 50px;
        }
        
        .search-input-wrapper {
            position: relative;
        }
        
        .search-input {
            width: 100%;
            padding: 16px 20px 16px 50px;
            border: none;
            border-radius: 50px;
            font-size: 15px;
            font-weight: 500;
            outline: none;
            background: transparent;
        }
        
        .search-icon {
            position: absolute;
            left: 20px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-med);
            width: 18px;
            height: 18px;
        }
        
        /* Creator Cards */
        .creators-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 30px;
        }
        
        .creator-card {
            background: var(--white);
            border-radius: 20px;
            overflow: hidden;
            transition: all 0.3s;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            display: block;
            box-shadow: 0 2px 10px rgba(0,0,0,0.06);
        }
        
        .creator-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 12px 30px rgba(255, 0, 107, 0.15);
        }
        
        .creator-card-image {
            width: 100%;
            aspect-ratio: 1;
            background: linear-gradient(135deg, var(--hot-pink) 0%, var(--deep-pink) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        
        .creator-card-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .creator-initial {
            font-family: 'Playfair Display', serif;
            font-size: 72px;
            color: var(--white);
            font-weight: 700;
        }
        
        .creator-card-content {
            padding: 25px;
        }
        
        .creator-name {
            font-family: 'Playfair Display', serif;
            font-size: 22px;
            margin-bottom: 5px;
            font-weight: 700;
            color: var(--black);
        }
        
        .creator-handle {
            font-size: 14px;
            color: var(--hot-pink);
            font-weight: 600;
            margin-bottom: 15px;
        }
        
        .creator-bio {
            font-size: 14px;
            line-height: 1.6;
            color: var(--gray-dark);
            margin-bottom: 20px;
            min-height: 60px;
            font-weight: 400;
        }
        
        .creator-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 20px;
            border-top: 1px solid var(--gray-light);
        }
        
        .creator-price {
            font-family: 'Playfair Display', serif;
            font-size: 26px;
            color: var(--black);
            font-weight: 700;
        }
        
        .price-label {
            font-size: 11px;
            color: var(--gray-med);
            font-weight: 600;
            display: block;
            margin-top: 2px;
            letter-spacing: 1px;
            text-transform: uppercase;
        }
        
        .fund-btn {
            background: var(--hot-pink);
            color: var(--white);
            border: none;
            padding: 10px 24px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            border-radius: 50px;
        }
        
        .fund-btn:hover {
            background: var(--deep-pink);
            transform: scale(1.05);
        }
        
        /* Footer */
        .footer {
            background: var(--black);
            color: var(--gray-light);
            text-align: center;
            padding: 50px 30px;
            font-size: 14px;
        }
        
        .footer a {
            color: var(--hot-pink);
            text-decoration: none;
            font-weight: 600;
            transition: color 0.2s;
        }
        
        .footer a:hover {
            color: var(--white);
        }
        
        .footer-links {
            margin-top: 20px;
            display: flex;
            justify-content: center;
            gap: 30px;
        }
        
        @media (max-width: 1024px) {
            .value-container {
                grid-template-columns: 1fr;
                gap: 30px;
            }
            
            .creators-grid {
                grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            }
        }
        
        @media (max-width: 768px) {
            .nav-center {
                display: none;
            }
            
            .hero {
                padding: 60px 20px 80px 20px;
            }
            
            .hero h1 {
                font-size: 42px;
                letter-spacing: -1px;
            }
            
            .hero-subhead {
                font-size: 18px;
            }
            
            .section-title {
                font-size: 36px;
            }
            
            .creators-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="topiclaunch-nav">
        <div class="nav-container">
            <a href="/" class="nav-logo">Topic<span>Launch</span></a>
            
            <div class="nav-center">
                <a href="/creators/" class="nav-link">Browse Creators</a>
                <a href="/creators/signup.php" class="nav-link">For Creators</a>
            </div>
            
            <div class="nav-buttons">
                <a href="/auth/login.php" class="nav-login-btn">Log In</a>
                <a href="/creators/signup.php" class="nav-getstarted-btn">Get Started</a>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <div class="hero">
        <div class="hero-container">
            <div class="hero-eyebrow">FOR CREATORS WHO RUN IT</div>
            <h1>
                Your Content.<br>
                Your Rules.<br>
                <span class="pink">Your Money.</span>
            </h1>
            <p class="hero-subhead">
                Set your price, your audience pays upfront, you create on your terms.
            </p>
            <a href="creators/signup.php" class="hero-cta">
                Start Earning
            </a>
        </div>
    </div>

    <!-- Value Props -->
    <div class="value-props">
        <div class="value-container">
            <div class="value-card">
                <div class="value-icon">👑</div>
                <h3>You're the CEO Here</h3>
                <p>Set your own rates. No negotiating with brands who lowball you. You decide what your content is worth.</p>
            </div>
            <div class="value-card">
                <div class="value-icon">💰</div>
                <h3>Get Paid First</h3>
                <p>Your audience pays upfront before you create. No waiting 90 days. No chasing payments.</p>
            </div>
            <div class="value-card">
                <div class="value-icon">⚡</div>
                <h3>48-Hour Delivery</h3>
                <p>Deliver within 48 hours and keep 90% of what you earn or everyone gets refunded.</p>
            </div>
        </div>
    </div>

    <!-- Creators Section -->
    <div class="creators-section">
        <div class="creators-container">
            <div class="section-header">
                <div class="section-eyebrow">RISING STARS</div>
                <h2 class="section-title">Creators Getting Paid</h2>
                <p class="section-subtitle">Real people turning attention into income.</p>
            </div>
            
            <div class="search-section">
                <div class="search-input-wrapper">
                    <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="8"></circle>
                        <path d="m21 21-4.35-4.35"></path>
                    </svg>
                    <input type="text" 
                           id="searchInput"
                           class="search-input" 
                           placeholder="Search creators..." 
                           autocomplete="off"
                           value="<?php echo htmlspecialchars($search_query ?? ''); ?>">
                </div>
            </div>
            
            <div class="creators-grid" id="creatorsGrid">
                <?php foreach ($creators as $creator): ?>
                    <a href="/<?php echo htmlspecialchars($creator->display_name); ?>" class="creator-card">
                        <div class="creator-card-image">
                            <?php if ($creator->profile_image): ?>
                                <img src="/uploads/creators/<?php echo htmlspecialchars($creator->profile_image); ?>" 
                                     alt="<?php echo htmlspecialchars($creator->display_name); ?>">
                            <?php else: ?>
                                <div class="creator-initial"><?php echo strtoupper(substr($creator->display_name, 0, 1)); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="creator-card-content">
                            <div class="creator-name">
                                <?php echo htmlspecialchars($creator->display_name); ?>
                            </div>
                            <div class="creator-handle">
                                @<?php echo htmlspecialchars($creator->display_name); ?>
                            </div>
                            <div class="creator-bio">
                                <?php echo !empty($creator->bio) ? htmlspecialchars($creator->bio) : 'Building my empire, one post at a time'; ?>
                            </div>
                            <div class="creator-footer">
                                <div>
                                    <div class="creator-price">
                                        $<?php echo number_format($creator->minimum_topic_price ?? 100, 0); ?>
                                    </div>
                                    <span class="price-label">per request</span>
                                </div>
                                <button class="fund-btn" onclick="event.preventDefault(); window.location.href='/<?php echo htmlspecialchars($creator->display_name); ?>'">
                                    Support
                                </button>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer">
        <p>&copy; 2025 TopicLaunch. Built for creators who know their worth.</p>
        <div class="footer-links">
            <a href="/terms.php">Terms</a>
            <a href="mailto:report@topiclaunch.com">Report</a>
            <a href="/creators/signup.php">Start Earning</a>
        </div>
    </footer>

    <script>
    const searchInput = document.getElementById('searchInput');
    const creatorsGrid = document.getElementById('creatorsGrid');
    
    if (searchInput) {
        searchInput.addEventListener('input', function(e) {
            const query = e.target.value.trim().toLowerCase();
            const queryNoSpaces = query.replace(/\s+/g, '');
            
            const cards = creatorsGrid.querySelectorAll('.creator-card');
            
            cards.forEach(card => {
                const nameElement = card.querySelector('.creator-name');
                const handleElement = card.querySelector('.creator-handle');
                
                if (!nameElement || !handleElement) return;
                
                const name = nameElement.textContent.toLowerCase();
                const handle = handleElement.textContent.replace('@', '').toLowerCase();
                const nameNoSpaces = name.replace(/\s+/g, '');
                const handleNoSpaces = handle.replace(/\s+/g, '');
                
                const matches = 
                    name.includes(query) || 
                    nameNoSpaces.includes(queryNoSpaces) ||
                    handle.includes(query) || 
                    handleNoSpaces.includes(queryNoSpaces);
                
                card.style.display = matches ? 'block' : 'none';
            });
        });
    }
    </script>
</body>
</html>
