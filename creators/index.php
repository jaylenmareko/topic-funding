<?php
// creators/index.php - Browse Creators page - UPDATED
session_start();

// Redirect logged-in creators to dashboard
if (isset($_SESSION['user_id'])) {
    require_once '../config/database.php';
    try {
        $db = new Database();
        $db->query('SELECT id FROM creators WHERE applicant_user_id = :user_id AND is_active = 1');
        $db->bind(':user_id', $_SESSION['user_id']);
        $is_creator = $db->single();
        
        if ($is_creator) {
            header('Location: /creators/dashboard.php');
            exit;
        }
    } catch (Exception $e) {
        error_log("Creator redirect check error: " . $e->getMessage());
    }
}

require_once '../config/database.php';

// Fetch all creators
try {
    $db = new Database();
    $db->query('SELECT * FROM creators WHERE is_active = 1 ORDER BY created_at DESC');
    $creators = $db->resultSet();
} catch (Exception $e) {
    $creators = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Browse Creators - TopicLaunch</title>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Playfair+Display:wght@600;700;800&display=swap" rel="stylesheet">
    
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        /* UPDATED: Consistent pink colors */
        :root {
            --hot-pink: #FF006B;
            --deep-pink: #E6005F;
            --black: #000000;
            --white: #FFFFFF;
            --gray-dark: #1A1A1A;
            --gray-med: #666666;
            --gray-light: #E5E5E5;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #f9fafb;
            color: #111827;
        }
        
        /* Navigation - Match Landing Page */
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
        
        .nav-link.active {
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
        
        /* Main Content */
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 80px 30px 100px 30px;
        }
        
        .header {
            margin-bottom: 50px;
            text-align: center;
        }
        
        .header-eyebrow {
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: var(--hot-pink);
            margin-bottom: 15px;
        }
        
        .header-title {
            font-family: 'Playfair Display', serif;
            font-size: 48px;
            margin-bottom: 15px;
            font-weight: 700;
            color: var(--black);
        }
        
        .header-subtitle {
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
        .creators-grid-landing {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 24px;
        }
        
        .creator-card-kalshi {
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
        
        .creator-card-kalshi:hover {
            transform: translateY(-8px);
            box-shadow: 0 12px 30px rgba(255, 0, 107, 0.15);
        }
        
        .creator-avatar-kalshi {
            width: 100%;
            aspect-ratio: 1;
            background: linear-gradient(135deg, var(--hot-pink) 0%, var(--deep-pink) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        
        .creator-avatar-kalshi img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .creator-initial {
            font-family: 'Playfair Display', serif;
            font-size: 64px;
            color: var(--white);
            font-weight: 700;
        }
        
        .creator-card-content {
            padding: 20px;
        }
        
        .creator-name-kalshi {
            font-family: 'Playfair Display', serif;
            font-size: 20px;
            margin-bottom: 5px;
            font-weight: 700;
            color: var(--black);
        }
        
        .creator-handle-kalshi {
            font-size: 13px;
            color: var(--hot-pink);
            font-weight: 600;
            margin-bottom: 12px;
        }
        
        .creator-bio {
            font-size: 13px;
            line-height: 1.5;
            color: var(--gray-dark);
            margin-bottom: 18px;
            min-height: 50px;
            font-weight: 400;
        }
        
        .creator-price-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 18px;
            border-top: 1px solid var(--gray-light);
        }
        
        .creator-price {
            font-family: 'Playfair Display', serif;
            font-size: 24px;
            color: var(--black);
            font-weight: 700;
        }
        
        .price-label {
            font-size: 10px;
            color: var(--gray-med);
            font-weight: 600;
            display: block;
            margin-top: 2px;
            letter-spacing: 1px;
            text-transform: uppercase;
        }
        
        .fund-topics-btn {
            background: var(--hot-pink);
            color: var(--white);
            border: none;
            padding: 9px 20px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            border-radius: 50px;
        }
        
        .fund-topics-btn:hover {
            background: var(--deep-pink);
            transform: scale(1.05);
        }
        
        @media (max-width: 1024px) {
            .creators-grid-landing {
                grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            }
        }
        
        @media (max-width: 768px) {
            .nav-center {
                display: none;
            }
            
            .creators-grid-landing {
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
            
            <!-- Center Navigation Links -->
            <div class="nav-center">
                <a href="/creators/" class="nav-link active">Browse Creators</a>
                <a href="/creators/signup.php" class="nav-link">For Creators</a>
            </div>
            
            <!-- Right Navigation Buttons -->
            <div class="nav-buttons">
                <a href="/auth/login.php" class="nav-login-btn">Log In</a>
                <a href="/creators/signup.php" class="nav-getstarted-btn">Get Started</a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-eyebrow">BROWSE</div>
            <h1 class="header-title">Creators Building Empires</h1>
            <p class="header-subtitle">Find the perfect creator for what you want to see.</p>
        </div>

        <!-- Search Section -->
        <div class="search-section">
            <div class="search-input-wrapper">
                <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="11" cy="11" r="8"></circle>
                    <path d="m21 21-4.35-4.35"></path>
                </svg>
                <input type="text" id="searchInput" class="search-input" placeholder="Search creators..." autocomplete="off">
            </div>
        </div>

        <!-- Creator Grid -->
        <div class="creators-grid-landing" id="creatorsGrid">
            <?php foreach ($creators as $creator): ?>
                <a href="/<?php echo htmlspecialchars($creator->display_name); ?>" class="creator-card-kalshi">
                    <div class="creator-avatar-kalshi">
                        <?php if ($creator->profile_image): ?>
                            <img src="../uploads/creators/<?php echo htmlspecialchars($creator->profile_image); ?>" 
                                 alt="<?php echo htmlspecialchars($creator->display_name); ?>">
                        <?php else: ?>
                            <div class="creator-initial"><?php echo strtoupper(substr($creator->display_name, 0, 1)); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="creator-card-content">
                        <div class="creator-name-kalshi">
                            <?php echo htmlspecialchars($creator->display_name); ?>
                        </div>
                        <div class="creator-handle-kalshi">
                            @<?php echo htmlspecialchars($creator->display_name); ?>
                        </div>
                        <div class="creator-bio">
                            <?php echo !empty($creator->bio) ? htmlspecialchars($creator->bio) : 'Building my empire, one post at a time'; ?>
                        </div>
                        <div class="creator-price-section">
                            <div>
                                <div class="creator-price">
                                    $<?php echo number_format($creator->minimum_topic_price ?? 100, 0); ?>
                                </div>
                                <span class="price-label">per request</span>
                            </div>
                            <button class="fund-topics-btn" onclick="event.preventDefault(); window.location.href='/<?php echo htmlspecialchars($creator->display_name); ?>'">
                                Support
                            </button>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <script>
        // Search functionality
        const searchInput = document.getElementById('searchInput');
        const creatorCards = document.querySelectorAll('.creator-card-kalshi');
        
        searchInput.addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            
            creatorCards.forEach(card => {
                const name = card.querySelector('.creator-name-kalshi').textContent.toLowerCase();
                const username = card.querySelector('.creator-handle-kalshi').textContent.toLowerCase();
                const bio = card.querySelector('.creator-bio')?.textContent.toLowerCase() || '';
                
                if (name.includes(searchTerm) || username.includes(searchTerm) || bio.includes(searchTerm)) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        });
    </script>
</body>
</html>
