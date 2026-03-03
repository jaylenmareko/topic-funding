<?php
// index.php - FOR THE NEXT GENERATION
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
    <title>TopicLaunch - Get Paid to Create Videos Requested by Fans</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta charset="UTF-8">
    <meta name="description" content="Set your price. Fans request custom content and pay upfront. You create it within 48 hours and keep 90%.">
    
    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://topiclaunch.com/">
    <meta property="og:title" content="TopicLaunch - Get Paid to Create Videos Requested by Fans">
    <meta property="og:description" content="Set your price. Fans request custom content and pay upfront. You create it and keep 90%.">
    <meta property="og:image" content="https://topiclaunch.com/og-image.png">
    
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    
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
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes fadeInScale {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
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
            font-family: 'Inter', sans-serif;
            font-size: 22px;
            font-weight: 700;
            color: var(--black);
            text-decoration: none;
            letter-spacing: -0.5px;
        }
        
        .nav-logo span { color: var(--hot-pink); }

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
        
        .nav-link:hover { color: var(--hot-pink); }

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
        
        .nav-login-btn:hover { color: var(--hot-pink); }
        
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
            background: var(--white);
            padding: 80px 30px 60px 30px; 
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
            opacity: 0.08;
            animation: pulse 8s ease-in-out infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 0.08; }
            50% { transform: scale(1.1); opacity: 0.12; }
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
            font-family: 'Inter', sans-serif;
            font-size: 52px; 
            margin: 0 0 25px 0; 
            font-weight: 600; 
            color: var(--black); 
            line-height: 1.15;
            letter-spacing: -2px;
            animation: fadeInUp 0.8s ease-out 0.4s both;
        }
        
        .hero h1 .pink { color: var(--hot-pink); }
        
        .hero-subhead {
            font-size: 22px;
            font-weight: 400;
            color: var(--gray-dark);
            max-width: 750px;
            margin: 0 auto 40px auto;
            line-height: 1.6;
            animation: fadeInUp 0.8s ease-out 0.5s both;
        }
        
        .desktop-break { display: block; }
        
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
        
        .platform-bar {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-top: 28px;
            gap: 8px;
        }

        /* Hero Step Cards */
        .hero-steps {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-top: 50px;
            margin-bottom: 10px;
            max-width: 860px;
            margin-left: auto;
            margin-right: auto;
        }

        .hero-step-card {
            background: var(--off-white);
            border: 1px solid var(--gray-light);
            border-radius: 16px;
            padding: 28px 24px;
            text-align: center;
            transition: all 0.3s ease;
        }

        .hero-step-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 24px rgba(255, 0, 107, 0.1);
            border-color: rgba(255, 0, 107, 0.2);
        }

        .hero-step-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 52px;
            height: 52px;
            background: rgba(255, 0, 107, 0.08);
            border-radius: 50%;
            color: var(--hot-pink);
            margin-bottom: 14px;
        }

        .hero-step-card h3 {
            font-size: 15px;
            font-weight: 700;
            color: var(--black);
            margin-bottom: 8px;
        }

        .step-label {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            color: var(--hot-pink);
            margin-bottom: 6px;
        }

        .hero-step-card p {
            font-size: 13px;
            color: var(--gray-dark);
            line-height: 1.6;
        }

        @media (max-width: 640px) {
            .hero-steps {
                grid-template-columns: 1fr;
            }
        }

        .mobile-browse-link {
            display: block;
            margin-top: 16px;
            font-size: 14px;
            font-weight: 600;
            color: var(--black);
            text-decoration: none;
            border-bottom: 1px solid rgba(0, 0, 0, 0.3);
            padding-bottom: 2px;
            transition: border-color 0.2s, color 0.2s;
            width: fit-content;
            margin-left: auto;
            margin-right: auto;
        }

        .mobile-browse-link:hover {
            color: var(--gray-dark);
            border-color: var(--gray-dark);
        }

        @media (max-width: 768px) {
            .mobile-browse-link {
                display: block;
            }
        }
        
        .platform-logos {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .platform-label {
            font-size: 12px;
            color: rgba(0,0,0,0.4);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 1.5px;
        }
        
        /* How It Works */
        .how-it-works {
            background: var(--off-white);
            padding: 80px 30px 60px 30px;
        }
        
        .how-container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .how-header {
            text-align: center;
            margin-bottom: 60px;
        }
        
        .how-header h2 {
            font-family: 'Inter', sans-serif;
            font-size: 48px;
            margin-bottom: 15px;
            font-weight: 700;
            color: var(--black);
        }
        
        .how-header p {
            font-size: 18px;
            color: var(--gray-med);
        }
        
        .steps-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 40px;
            margin-bottom: 50px;
        }
        
        .step-card {
            text-align: center;
            position: relative;
            background: var(--white);
            padding: 40px 30px;
            border-radius: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
        }
        
        .step-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.12);
        }
        
        .step-number {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 60px;
            height: 60px;
            background: var(--hot-pink);
            color: var(--white);
            border-radius: 50%;
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 20px;
            font-family: 'Inter', sans-serif;
        }
        
        .step-card h3 {
            font-family: 'Inter', sans-serif;
            font-size: 24px;
            margin-bottom: 12px;
            font-weight: 700;
            color: var(--black);
        }
        
        .step-card p {
            font-size: 16px;
            line-height: 1.6;
            color: var(--gray-dark);
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
        
        .value-icon { font-size: 48px; margin-bottom: 20px; }
        
        .value-card h3 {
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
            background: var(--white);
            padding: 60px 30px 100px 30px;
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
            font-family: 'Inter', sans-serif;
            font-size: 28px;
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
        .search-section { margin: 0 auto 40px auto; max-width: 700px; }
        .search-bar { background: var(--white); border-radius: 50px; padding: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.06); margin-bottom: 16px; border: 1.5px solid transparent; transition: border-color 0.2s; }
        .search-bar:focus-within { border-color: rgba(255,0,107,0.3); }
        .search-input-wrapper { position: relative; }
        .search-input { width: 100%; padding: 14px 20px 14px 50px; border: none; border-radius: 50px; font-size: 15px; font-weight: 500; outline: none; background: transparent; }
        .search-icon { position: absolute; left: 20px; top: 50%; transform: translateY(-50%); color: var(--gray-med); width: 18px; height: 18px; }
        .topic-filters { display: flex; flex-wrap: wrap; gap: 8px; justify-content: center; }
        .topic-filter-btn { padding: 7px 16px; border-radius: 50px; font-size: 13px; font-weight: 600; border: 1.5px solid var(--gray-light); background: var(--white); color: var(--gray-dark); cursor: pointer; transition: all 0.18s; white-space: nowrap; }
        .topic-filter-btn:hover { border-color: var(--hot-pink); color: var(--hot-pink); }
        .topic-filter-btn.active { background: var(--hot-pink); border-color: var(--hot-pink); color: var(--white); }
        
        /* Creator Cards */
        .creators-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(420px, 1fr));
            gap: 16px;
        }

        .creator-card {
            background: var(--white);
            border-radius: 16px;
            border: 1px solid var(--gray-light);
            padding: 24px;
            transition: all 0.25s;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            display: flex;
            flex-direction: column;
            box-shadow: 0 1px 4px rgba(0,0,0,0.05);
        }

        .creator-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 24px rgba(255, 0, 107, 0.12);
            border-color: rgba(255, 0, 107, 0.3);
        }

        .creator-card-top {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 14px;
        }

        .creator-card-image {
            width: 72px;
            height: 72px;
            flex-shrink: 0;
            border-radius: 50%;
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
            font-size: 28px;
            color: var(--white);
            font-weight: 700;
        }

        .creator-card-identity { flex: 1; min-width: 0; }

        .creator-name {
            font-size: 18px;
            font-weight: 700;
            color: var(--black);
            margin-bottom: 3px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .creator-handle {
            font-size: 13px;
            color: var(--gray-med);
            font-weight: 500;
        }

        .creator-bio {
            font-size: 13px;
            line-height: 1.55;
            color: #4B5563;
            margin-bottom: 14px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .creator-topics {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 18px;
        }

        .creator-topic-tag {
            font-size: 12px;
            font-weight: 600;
            padding: 5px 13px;
            border-radius: 50px;
            background: transparent;
            color: var(--gray-dark);
            border: 1.5px solid var(--gray-light);
        }

        .creator-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 16px;
            border-top: 1px solid var(--gray-light);
            margin-top: auto;
        }

        .creator-price {
            font-size: 20px;
            color: var(--black);
            font-weight: 700;
            display: inline;
        }

        .price-label {
            font-size: 11px;
            color: var(--gray-med);
            font-weight: 500;
            margin-left: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .fund-btn {
            background: var(--hot-pink);
            color: var(--white);
            border: none;
            padding: 10px 22px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            border-radius: 50px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .fund-btn:hover {
            background: var(--deep-pink);
            transform: scale(1.03);
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
        
        .footer a:hover { color: var(--white); }
        
        .footer-links {
            margin-top: 20px;
            display: flex;
            justify-content: center;
            gap: 30px;
        }
        
        @media (max-width: 1024px) {
            .steps-grid { grid-template-columns: 1fr; gap: 40px; }
            .creators-grid { grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); }
        }
        
        @media (max-width: 768px) {
            .nav-center { display: none; }
            .hero { padding: 60px 20px 80px 20px; }
            .hero h1 { font-size: 36px; letter-spacing: -1px; }
            .hero-subhead { font-size: 18px; }
            .desktop-break { display: inline; }
            .how-header h2, .section-title { font-size: 22px; }
            .creators-grid { grid-template-columns: 1fr; }
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
            <div class="hero-eyebrow">Closing the Gap Between Creators and Fans</div>
            <h1>
                Creators Get Paid.<br>
                <span class="pink">Fans Send Ideas.</span>
            </h1>
            <p class="hero-subhead">
                Send a topic request to the creator of your choice, pay their price, and get a guaranteed video made.
            </p>
            <a href="creators/signup.php" class="hero-cta">
                Start Earning
            </a>

            <a href="creators/index.php" class="mobile-browse-link">Browse Creators</a>


            <div class="hero-steps">
                <div class="hero-step-card">
                    <div class="hero-step-icon">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                    </div>
                    <div class="step-label">Step 1</div>
                    <h3>Fan Sends a Request</h3>
                    <p>Pick a creator, submit your topic idea, and pay their price upfront.</p>
                </div>
                <div class="hero-step-card">
                    <div class="hero-step-icon">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="23 7 16 12 23 17 23 7"></polygon><rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect></svg>
                    </div>
                    <div class="step-label">Step 2</div>
                    <h3>Creator Makes Content</h3>
                    <p>The creator films and delivers the video within 48 hours.</p>
                </div>
                <div class="hero-step-card">
                    <div class="hero-step-icon">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                    </div>
                    <div class="step-label">Step 3</div>
                    <h3>Creator Gets Paid</h3>
                    <p>We send 90% of the payment straight to the creator. No waiting.</p>
                </div>
            </div>

            <div class="platform-bar">
                <div class="platform-logos">
                    <!-- YouTube -->
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none"><path d="M23 7s-.3-1.7-1-2.4c-1-1-2.1-1-2.6-1.1C17 3 12 3 12 3s-5 0-7.4.5c-.5.1-1.6.1-2.6 1.1C1.3 5.3 1 7 1 7S.7 9 .7 11v1.8c0 2 .3 3.8.3 3.8s.3 1.7 1 2.4c1 1 2.3.9 2.9 1 2.1.2 7.1.3 7.1.3s5 0 7.4-.5c.5-.1 1.6-.1 2.6-1.1.7-.7 1-2.4 1-2.4s.3-1.8.3-3.8V11c0-2-.3-4-.3-4z" fill="rgba(0,0,0,0.5)"/><path d="M9.7 15.5l6.5-3.5-6.5-3.5v7z" fill="rgba(0,0,0,0.7)"/></svg>
                    <!-- Instagram -->
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none"><rect x="2" y="2" width="20" height="20" rx="5" stroke="rgba(0,0,0,0.5)" stroke-width="1.8" fill="none"/><circle cx="12" cy="12" r="4.5" stroke="rgba(0,0,0,0.5)" stroke-width="1.8" fill="none"/><circle cx="17.5" cy="6.5" r="1.2" fill="rgba(0,0,0,0.5)"/></svg>
                    <!-- TikTok -->
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none"><path d="M19.6 4.8c-1.1-.7-1.8-1.8-2-3h-2.9v12.9c0 1.5-1.2 2.7-2.7 2.7s-2.7-1.2-2.7-2.7 1.2-2.7 2.7-2.7c.3 0 .5 0 .8.1V8.1c-.3 0-.5-.1-.8-.1-3 0-5.5 2.5-5.5 5.5s2.5 5.5 5.5 5.5 5.5-2.5 5.5-5.5V9.1c1.1.7 2.4 1.1 3.8 1.1V7.3c-1.4 0-2.6-.7-3.6-1.7" fill="rgba(0,0,0,0.5)"/></svg>
                </div>
                <div class="platform-label">Works with any platform</div>
            </div>

        </div>
    </div>



    <!-- Creators Section -->
    <div class="creators-section">
        <div class="creators-container">
            <div class="section-header">
                <h2 class="section-title">Browse Creators & Send a Request</h2>
            </div>
            
            <div class="search-section">
                <div class="search-bar">
                    <div class="search-input-wrapper">
                        <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="11" cy="11" r="8"></circle>
                            <path d="m21 21-4.35-4.35"></path>
                        </svg>
                        <input type="text" id="searchInput" class="search-input" placeholder="Search creators by name or topic..." autocomplete="off" value="<?php echo htmlspecialchars($search_query ?? ''); ?>">
                    </div>
                </div>
                <div class="topic-filters" id="topicFilters">
                    <button class="topic-filter-btn active" data-topic="all">All</button>
                    <?php foreach (['Fitness', 'Health', 'Motivation', 'Therapy', 'Dating', 'Business', 'Money', 'Psychology', 'Career', 'Family', 'Technology & AI', 'Beauty', 'History', 'Cooking', 'Travel', 'Sports', 'Faith & Spirituality', 'Entertainment', 'Self-Improvement', 'Communication'] as $t): ?>
                    <button class="topic-filter-btn" data-topic="<?php echo htmlspecialchars($t); ?>"><?php echo htmlspecialchars($t); ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div class="creators-grid" id="creatorsGrid">
                <?php foreach ($creators as $creator): ?>
                    <a href="/<?php echo htmlspecialchars($creator->display_name); ?>" class="creator-card">
                        <div class="creator-card-top">
                            <div class="creator-card-image">
                                <?php if ($creator->profile_image): ?>
                                    <img src="/uploads/creators/<?php echo htmlspecialchars($creator->profile_image); ?>" 
                                         alt="<?php echo htmlspecialchars($creator->display_name); ?>">
                                <?php else: ?>
                                    <div class="creator-initial"><?php echo strtoupper(substr($creator->display_name, 0, 1)); ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="creator-card-identity">
                                <div class="creator-name"><?php echo htmlspecialchars($creator->display_name); ?></div>
                                <div class="creator-handle">@<?php echo htmlspecialchars($creator->display_name); ?></div>
                            </div>
                        </div>
                        <div class="creator-bio">
                            <?php echo !empty($creator->bio) ? htmlspecialchars($creator->bio) : 'Building my empire, one post at a time'; ?>
                        </div>
                        <?php
                        $topics = [];
                        if (!empty($creator->video_topics)) {
                            $decoded = json_decode($creator->video_topics, true);
                            if (is_array($decoded)) $topics = $decoded;
                        }
                        if (!empty($topics)): ?>
                        <div class="creator-topics">
                            <?php foreach (array_slice($topics, 0, 5) as $tag): ?>
                                <span class="creator-topic-tag"><?php echo htmlspecialchars($tag); ?></span>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <div class="creator-footer">
                            <div>
                                <span class="creator-price">$<?php echo number_format($creator->minimum_topic_price ?? 100, 0); ?></span>
                                <span class="price-label">/ per request</span>
                            </div>
                            <button class="fund-btn" onclick="event.preventDefault(); window.location.href='/<?php echo htmlspecialchars($creator->display_name); ?>'">
                                Send Request
                            </button>
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
    const filterBtns = document.querySelectorAll('.topic-filter-btn');
    let activeTopic = 'all';

    function filterCards() {
        const query = searchInput ? searchInput.value.trim().toLowerCase() : '';
        const cards = creatorsGrid.querySelectorAll('.creator-card');
        cards.forEach(card => {
            const name = (card.querySelector('.creator-name')?.textContent || '').toLowerCase();
            const handle = (card.querySelector('.creator-handle')?.textContent || '').replace('@','').toLowerCase();
            const tags = Array.from(card.querySelectorAll('.creator-topic-tag')).map(t => t.textContent.trim().toLowerCase());
            const matchesSearch = !query || name.includes(query) || handle.includes(query) || tags.some(t => t.includes(query));
            const matchesTopic = activeTopic === 'all' || tags.includes(activeTopic.toLowerCase());
            card.style.display = (matchesSearch && matchesTopic) ? 'flex' : 'none';
        });
    }

    if (searchInput) searchInput.addEventListener('input', filterCards);

    filterBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            filterBtns.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            activeTopic = btn.dataset.topic;
            filterCards();
        });
    });
    </script>
</body>
</html>
