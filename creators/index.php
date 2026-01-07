<?php
// creators/index.php - Browse YouTubers page
session_start();

// RESTRICT LOGGED-IN CREATORS
require_once '../config/check_creator_access.php';

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
    <title>Browse YouTubers - TopicLaunch</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #f9fafb;
            color: #111827;
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

        /* Nav Center Links */
        .nav-center {
            display: flex;
            gap: 30px;
            align-items: center;
        }
        
        .nav-link {
            color: #6b7280;
            text-decoration: none;
            font-size: 15px;
            font-weight: 500;
            transition: color 0.2s;
        }
        
        .nav-link:hover {
            color: #FF0000;
        }
        
        .nav-link.active {
            color: #FF0000;
        }

        /* Nav Right Buttons */
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
        }
        
        /* Main Content */
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 48px 24px;
        }
        
        .header {
            margin-bottom: 32px;
        }
        
        .header-title {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 12px;
        }
        
        .header-icon {
            width: 32px;
            height: 32px;
            color: #FF0000;
        }
        
        .header-subtitle {
            font-size: 16px;
            color: #6b7280;
        }
        
        /* Search Section */
        .search-section {
            background: white;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 32px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .search-row {
            display: flex;
            gap: 16px;
            align-items: center;
        }
        
        .search-input-wrapper {
            flex: 1;
            position: relative;
        }
        
        .search-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
            width: 20px;
            height: 20px;
        }
        
        .search-input {
            width: 100%;
            padding: 14px 16px 14px 48px;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            font-size: 15px;
            transition: all 0.2s;
            outline: none;
        }
        
        .search-input:focus {
            border-color: #FF0000;
            box-shadow: 0 0 0 3px rgba(255,0,0,0.1);
        }
        
        /* Results Count */
        .results-count {
            color: #6b7280;
            font-size: 14px;
            margin-bottom: 24px;
        }
        
        /* Creator Grid */
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
            min-height: 42px;
            max-height: 42px;
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
            <a href="/" class="nav-logo">TopicLaunch</a>
            
            <!-- Center Navigation Links -->
            <div class="nav-center">
                <a href="/creators/" class="nav-link active">Browse YouTubers</a>
                <a href="/creators/signup.php" class="nav-link">For YouTubers</a>
            </div>
            
            <!-- Right Navigation Buttons -->
            <div class="nav-buttons">
                <?php if (isset($_SESSION['user_id'])): ?>
                    <a href="/dashboard.php" class="nav-login-btn">Dashboard</a>
                <?php else: ?>
                    <a href="/auth/login.php" class="nav-login-btn">Log In</a>
                    <a href="/creators/signup.php" class="nav-getstarted-btn">Get Started</a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1 class="header-title">
                <svg class="header-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                    <circle cx="9" cy="7" r="4"></circle>
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                </svg>
                Browse YouTubers
            </h1>
            <p class="header-subtitle">Find the perfect YouTuber to create content on topics you care about</p>
        </div>

        <!-- Search Section -->
        <div class="search-section">
            <div class="search-row">
                <div class="search-input-wrapper">
                    <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="8"></circle>
                        <path d="m21 21-4.35-4.35"></path>
                    </svg>
                    <input type="text" id="searchInput" class="search-input" placeholder="Search by name, username, or topic...">
                </div>
            </div>
        </div>

        <!-- Results Count -->
        <div class="results-count"><?php echo count($creators); ?> YouTubers found</div>

        <!-- Creator Grid -->
        <div class="creators-grid-landing" id="creatorsGrid">
            <?php foreach ($creators as $creator): ?>
                <a href="/<?php echo htmlspecialchars($creator->display_name); ?>" class="creator-card-kalshi">
                    <div class="creator-card-header">
                        <div class="creator-avatar-kalshi">
                            <?php if ($creator->profile_image): ?>
                                <img src="../uploads/creators/<?php echo htmlspecialchars($creator->profile_image); ?>" 
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
                    
                    <div class="creator-bio">
                        <?php echo !empty($creator->bio) ? htmlspecialchars($creator->bio) : '&nbsp;'; ?>
                    </div>
                    
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
            
            // Update count
            const visibleCards = Array.from(creatorCards).filter(card => card.style.display !== 'none');
            document.querySelector('.results-count').textContent = `${visibleCards.length} YouTubers found`;
        });
    </script>
</body>
</html>
