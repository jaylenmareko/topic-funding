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
            --tl-pink: #E8305A;
            --tl-pink-light: #F7C0D0;
            --tl-pink-dark: #B01F3F;
            --tl-black: #111010;
            --tl-off: #1C1C1C;
            --tl-card: #1e1e1e;
            --tl-border: #2a2a2a;
            --tl-muted: #888888;
            --tl-dimmed: #555555;
            --white: #ffffff;
            /* kept for creator cards section */
            --hot-pink: #E8305A;
            --deep-pink: #B01F3F;
            --gray-light: #2a2a2a;
            --gray-med: #888888;
            --gray-dark: #cccccc;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--tl-black);
            color: var(--white);
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            animation: fadeIn 0.5s ease-in;
        }

        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(24px); } to { opacity: 1; transform: translateY(0); } }

        /* ── Navigation ── */
        .topiclaunch-nav {
            background: var(--tl-black);
            border-bottom: 1px solid var(--tl-border);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .nav-container {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 30px;
        }

        .nav-logo {
            font-size: 20px;
            font-weight: 500;
            color: var(--white);
            text-decoration: none;
            letter-spacing: -0.3px;
        }
        .nav-logo span { color: var(--tl-pink); }

        .nav-center { display: flex; gap: 24px; align-items: center; }

        .nav-link {
            color: var(--tl-muted);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: color 0.2s;
        }
        .nav-link:hover { color: var(--white); }

        .nav-buttons { display: flex; gap: 12px; align-items: center; }

        .nav-login-btn {
            color: var(--tl-muted);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: color 0.2s;
        }
        .nav-login-btn:hover { color: var(--white); }

        .nav-getstarted-btn {
            background: var(--tl-pink);
            color: var(--white);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            padding: 8px 18px;
            border-radius: 8px;
            transition: background 0.2s;
        }
        .nav-getstarted-btn:hover { background: var(--tl-pink-dark); }

        /* ── Hero ── */
        .hero {
            background: var(--tl-black);
            padding: 72px 30px 56px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .hero-container {
            max-width: 680px;
            margin: 0 auto;
            position: relative;
            z-index: 2;
            animation: fadeInUp 0.7s ease-out 0.1s both;
        }

        .hero-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            font-size: 11px;
            font-weight: 500;
            color: var(--tl-pink);
            background: rgba(232,48,90,0.12);
            padding: 5px 13px;
            border-radius: 20px;
            margin-bottom: 22px;
            letter-spacing: 0.5px;
        }
        .hero-eyebrow-dot {
            width: 6px; height: 6px;
            border-radius: 50%;
            background: var(--tl-pink);
            display: inline-block;
            flex-shrink: 0;
        }

        .hero h1 {
            font-size: 40px;
            font-weight: 600;
            color: var(--white);
            line-height: 1.15;
            letter-spacing: -0.8px;
            margin: 0 0 18px;
        }
        .hero h1 .pink { color: var(--tl-pink); }

        .hero-subhead {
            font-size: 15px;
            color: var(--tl-muted);
            max-width: 420px;
            margin: 0 auto 34px;
            line-height: 1.65;
        }

        .hero-cta-row {
            display: flex;
            justify-content: center;
            gap: 12px;
            align-items: center;
            margin-bottom: 0;
        }

        .hero-cta {
            background: var(--tl-pink);
            color: var(--white);
            padding: 13px 26px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            transition: background 0.2s;
            display: inline-block;
        }
        .hero-cta:hover { background: var(--tl-pink-dark); }

        .hero-cta-ghost {
            background: transparent;
            color: var(--tl-muted);
            padding: 12px 22px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            border: 1px solid var(--tl-border);
            transition: border-color 0.2s, color 0.2s;
            display: inline-block;
        }
        .hero-cta-ghost:hover { border-color: #555; color: var(--white); }

        /* stat row */
        .hero-stat-row {
            display: flex;
            justify-content: center;
            align-items: stretch;
            margin-top: 50px;
            border-top: 1px solid #222;
            padding-top: 32px;
        }
        .hero-stat { flex: 1; text-align: center; max-width: 150px; }
        .hero-stat-n { font-size: 26px; font-weight: 600; color: var(--white); }
        .hero-stat-n span { color: var(--tl-pink); }
        .hero-stat-label { font-size: 11px; color: #555; margin-top: 4px; }
        .hero-stat-divider { width: 1px; background: #222; align-self: stretch; margin: 0 10px; }

        /* ── Why section (cards) ── */
        .why-section {
            background: #161616;
            padding: 36px 30px 40px;
            border-top: 1px solid #222;
        }
        .why-container { max-width: 900px; margin: 0 auto; }
        .why-label {
            font-size: 11px;
            color: #555;
            letter-spacing: 0.8px;
            font-weight: 500;
            margin-bottom: 18px;
            text-transform: uppercase;
        }
        .why-cards {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
        }
        .why-card {
            background: var(--tl-card);
            border-radius: 12px;
            padding: 18px 16px;
            border: 1px solid var(--tl-border);
        }
        .why-card-icon {
            width: 34px; height: 34px;
            border-radius: 8px;
            background: rgba(232,48,90,0.15);
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 12px;
        }
        .why-card-icon svg { width: 16px; height: 16px; }
        .why-card-title { font-size: 13px; font-weight: 500; color: #e0e0e0; margin-bottom: 5px; }
        .why-card-desc { font-size: 11px; color: #666; line-height: 1.55; }

        /* ── Creator input strip ── */
        .creator-strip {
            background: var(--tl-black);
            padding: 20px 30px;
            border-top: 1px solid #1e1e1e;
            display: flex;
            align-items: center;
            gap: 14px;
            max-width: 900px;
            margin: 0 auto;
        }
        .strip-avatar {
            width: 34px; height: 34px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--tl-pink), var(--tl-pink-dark));
            display: flex; align-items: center; justify-content: center;
            font-size: 12px; font-weight: 500; color: var(--white);
            flex-shrink: 0;
        }
        .strip-input {
            flex: 1;
            background: #1a1a1a;
            border-radius: 8px;
            padding: 9px 14px;
            border: 1px solid var(--tl-border);
            font-size: 12px;
            color: #555;
            font-family: inherit;
        }
        .strip-send {
            width: 34px; height: 34px;
            background: var(--tl-pink);
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
            text-decoration: none;
            transition: background 0.2s;
        }
        .strip-send:hover { background: var(--tl-pink-dark); }

        /* ── Creators browse section ── */
        .creators-section {
            background: var(--tl-black);
            padding: 56px 30px 80px;
        }
        .creators-container { max-width: 1400px; margin: 0 auto; }

        .section-header { margin-bottom: 36px; text-align: center; }
        .section-eyebrow {
            font-size: 11px;
            font-weight: 500;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            color: #555;
            margin-bottom: 10px;
        }
        .section-title {
            font-size: 26px;
            font-weight: 600;
            color: var(--white);
            letter-spacing: -0.4px;
        }
        .section-subtitle {
            font-size: 14px;
            color: var(--tl-muted);
            margin-top: 8px;
        }

        /* Search */
        .search-section { margin: 0 auto 36px; max-width: 700px; }
        .search-bar {
            background: #1a1a1a;
            border-radius: 10px;
            padding: 6px;
            border: 1px solid var(--tl-border);
            margin-bottom: 14px;
            transition: border-color 0.2s;
        }
        .search-bar:focus-within { border-color: rgba(232,48,90,0.4); }
        .search-input-wrapper { position: relative; }
        .search-input {
            width: 100%;
            padding: 11px 18px 11px 46px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 400;
            outline: none;
            background: transparent;
            color: var(--white);
        }
        .search-input::placeholder { color: #555; }
        .search-icon { position: absolute; left: 18px; top: 50%; transform: translateY(-50%); color: #555; width: 16px; height: 16px; }
        .topic-filters { display: flex; flex-wrap: wrap; gap: 7px; justify-content: center; }
        .topic-filter-btn {
            padding: 6px 14px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 500;
            border: 1px solid var(--tl-border);
            background: transparent;
            color: #666;
            cursor: pointer;
            transition: all 0.15s;
            white-space: nowrap;
        }
        .topic-filter-btn:hover { border-color: var(--tl-pink); color: var(--tl-pink); }
        .topic-filter-btn.active { background: var(--tl-pink); border-color: var(--tl-pink); color: var(--white); }

        /* Creator Cards */
        .creators-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 14px;
        }

        .creator-card {
            background: var(--tl-card);
            border-radius: 14px;
            border: 1px solid var(--tl-border);
            padding: 22px;
            transition: all 0.22s;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            display: flex;
            flex-direction: column;
        }
        .creator-card:hover {
            transform: translateY(-3px);
            border-color: rgba(232,48,90,0.35);
            box-shadow: 0 8px 24px rgba(232,48,90,0.08);
        }

        .creator-card-top {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 12px;
        }

        .creator-card-image {
            width: 56px; height: 56px;
            flex-shrink: 0;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--tl-pink), var(--tl-pink-dark));
            display: flex; align-items: center; justify-content: center;
            overflow: hidden;
        }
        .creator-card-image img { width: 100%; height: 100%; object-fit: cover; }

        .creator-initial { font-size: 22px; color: var(--white); font-weight: 600; }

        .creator-card-identity { flex: 1; min-width: 0; }

        .creator-name {
            font-size: 16px;
            font-weight: 600;
            color: var(--white);
            margin-bottom: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .creator-handle { font-size: 12px; color: #555; font-weight: 400; }

        .creator-bio {
            font-size: 12px;
            line-height: 1.55;
            color: #777;
            margin-bottom: 12px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .creator-topics { display: flex; flex-wrap: wrap; gap: 5px; margin-bottom: 16px; }

        .creator-topic-tag {
            font-size: 11px;
            font-weight: 500;
            padding: 4px 11px;
            border-radius: 6px;
            background: transparent;
            color: #666;
            border: 1px solid var(--tl-border);
        }

        .creator-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 14px;
            border-top: 1px solid var(--tl-border);
            margin-top: auto;
        }

        .creator-price { font-size: 18px; color: var(--white); font-weight: 600; display: inline; }

        .price-label {
            font-size: 11px;
            color: #555;
            font-weight: 500;
            margin-left: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .fund-btn {
            background: var(--tl-pink);
            color: var(--white);
            border: none;
            padding: 9px 18px;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.2s;
            border-radius: 8px;
        }
        .fund-btn:hover { background: var(--tl-pink-dark); }

        /* ── Footer ── */
        .footer {
            background: var(--tl-black);
            border-top: 1px solid var(--tl-border);
            color: #555;
            text-align: center;
            padding: 36px 30px;
            font-size: 13px;
        }
        .footer a {
            color: var(--tl-pink);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.2s;
        }
        .footer a:hover { color: var(--white); }
        .footer-links {
            margin-top: 16px;
            display: flex;
            justify-content: center;
            gap: 28px;
        }

        /* ── Responsive ── */
        @media (max-width: 1024px) {
            .creators-grid { grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); }
            .why-cards { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px) {
            .nav-center { display: none; }
            .hero { padding: 56px 20px 48px; }
            .hero h1 { font-size: 30px; }
            .hero-stat-row { gap: 0; }
            .creators-grid { grid-template-columns: 1fr; }
            .why-cards { grid-template-columns: 1fr; }
            .creator-strip { padding: 16px 20px; }
        }
        @media (max-width: 480px) {
            .hero-cta-row { flex-direction: column; align-items: stretch; }
            .hero-cta, .hero-cta-ghost { text-align: center; }
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
            <div class="hero-eyebrow">
                <span class="hero-eyebrow-dot"></span>
                CREATORS KEEP 90%
            </div>
            <h1>
                Your fans <span class="pink">commission</span><br>your best work.
            </h1>
            <p class="hero-subhead">
                TopicLaunch connects creators with fans who want custom content — on their terms, at your price.
            </p>
            <div class="hero-cta-row">
                <a href="creators/signup.php" class="hero-cta">Launch your page</a>
                <a href="creators/index.php" class="hero-cta-ghost">See how it works</a>
            </div>

            <div class="hero-stat-row">
                <div class="hero-stat">
                    <div class="hero-stat-n">90<span>%</span></div>
                    <div class="hero-stat-label">Revenue to creator</div>
                </div>
                <div class="hero-stat-divider"></div>
                <div class="hero-stat">
                    <div class="hero-stat-n">0<span>$</span></div>
                    <div class="hero-stat-label">Cost to sign up</div>
                </div>
                <div class="hero-stat-divider"></div>
                <div class="hero-stat">
                    <div class="hero-stat-n">Any<span> topic</span></div>
                    <div class="hero-stat-label">You set the terms</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Why TopicLaunch Cards -->
    <div class="why-section">
        <div class="why-container">
            <div class="why-label">WHY TOPICLAUNCH</div>
            <div class="why-cards">
                <div class="why-card">
                    <div class="why-card-icon">
                        <svg viewBox="0 0 16 16" fill="none"><path d="M8 2L10 6H14L11 9L12 13L8 11L4 13L5 9L2 6H6L8 2Z" fill="#E8305A"/></svg>
                    </div>
                    <div class="why-card-title">Fan-driven</div>
                    <div class="why-card-desc">Fans request topics, you deliver. Demand before you create.</div>
                </div>
                <div class="why-card">
                    <div class="why-card-icon">
                        <svg viewBox="0 0 16 16" fill="none"><rect x="2" y="2" width="12" height="12" rx="3" fill="#E8305A"/><path d="M5 8h6M8 5v6" stroke="#fff" stroke-width="1.5"/></svg>
                    </div>
                    <div class="why-card-title">Set your price</div>
                    <div class="why-card-desc">You control what you charge. No platform minimums.</div>
                </div>
                <div class="why-card">
                    <div class="why-card-icon">
                        <svg viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="6" fill="#E8305A"/><path d="M6 8l1.5 1.5L10 6" stroke="#fff" stroke-width="1.5" stroke-linecap="round"/></svg>
                    </div>
                    <div class="why-card-title">Keep 90%</div>
                    <div class="why-card-desc">The highest payout in the creator economy.</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Creator strip -->
    <div style="background:#161616; border-top:1px solid #1e1e1e; border-bottom:1px solid #1e1e1e;">
        <div class="creator-strip">
            <div class="strip-avatar">JD</div>
            <div class="strip-input">Commission a video about morning routines for athletes...</div>
            <a href="/creators/signup.php" class="strip-send">
                <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="M14 8L2 2l2 6-2 6 12-6z" fill="#fff"/></svg>
            </a>
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
