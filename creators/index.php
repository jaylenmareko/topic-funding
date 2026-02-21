<?php
// creators/index.php - Browse Creators page
session_start();

if (isset($_SESSION['user_id'])) {
    require_once '../config/database.php';
    try {
        $db = new Database();
        $db->query('SELECT id FROM creators WHERE applicant_user_id = :user_id AND is_active = 1');
        $db->bind(':user_id', $_SESSION['user_id']);
        $is_creator = $db->single();
        if ($is_creator) { header('Location: /creators/dashboard.php'); exit; }
    } catch (Exception $e) { error_log("Creator redirect check error: " . $e->getMessage()); }
}

require_once '../config/database.php';

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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --hot-pink: #FF006B;
            --deep-pink: #E6005F;
            --black: #000000;
            --white: #FFFFFF;
            --gray-dark: #1A1A1A;
            --gray-med: #666666;
            --gray-light: #E5E5E5;
        }
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif; background: #f9fafb; color: #111827; }
        
        .topiclaunch-nav { background: var(--white); padding: 16px 0; border-bottom: 1px solid var(--gray-light); position: sticky; top: 0; z-index: 1000; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .nav-container { max-width: 1400px; margin: 0 auto; display: flex; justify-content: space-between; align-items: center; padding: 0 30px; }
        .nav-logo { font-family: 'Inter', sans-serif; font-size: 22px; font-weight: 700; color: var(--black); text-decoration: none; letter-spacing: -0.5px; }
        .nav-logo span { color: var(--hot-pink); }
        .nav-center { display: flex; gap: 30px; align-items: center; }
        .nav-link { color: var(--gray-dark); text-decoration: none; font-size: 14px; font-weight: 500; transition: color 0.2s; }
        .nav-link:hover, .nav-link.active { color: var(--hot-pink); }
        .nav-buttons { display: flex; gap: 15px; align-items: center; }
        .nav-login-btn { color: var(--gray-dark); text-decoration: none; font-size: 14px; font-weight: 600; transition: color 0.2s; }
        .nav-login-btn:hover { color: var(--hot-pink); }
        .nav-getstarted-btn { background: var(--hot-pink); color: var(--white); text-decoration: none; font-size: 14px; font-weight: 600; padding: 10px 24px; border-radius: 50px; transition: all 0.2s; }
        .nav-getstarted-btn:hover { background: var(--deep-pink); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(255, 0, 107, 0.3); }

        .container { max-width: 1400px; margin: 0 auto; padding: 80px 30px 100px 30px; }

        .header { margin-bottom: 50px; text-align: center; }
        .header-eyebrow { font-size: 13px; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; color: var(--hot-pink); margin-bottom: 15px; }
        .header-subtitle { font-size: 18px; color: var(--gray-med); font-weight: 400; }

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
        .no-results { text-align: center; padding: 60px 20px; color: var(--gray-med); font-size: 16px; display: none; grid-column: 1/-1; }
        .no-results strong { display: block; font-size: 20px; color: var(--gray-dark); margin-bottom: 8px; }

        /* Creator Cards - Rizzdem-style horizontal layout */
        .creators-grid-landing { display: grid; grid-template-columns: repeat(auto-fill, minmax(420px, 1fr)); gap: 16px; }
        .creator-card-kalshi {
            background: var(--white); border-radius: 16px; border: 1px solid var(--gray-light);
            padding: 24px; transition: all 0.25s; cursor: pointer; text-decoration: none; color: inherit;
            display: flex; flex-direction: column; box-shadow: 0 1px 4px rgba(0,0,0,0.05);
        }
        .creator-card-kalshi:hover { transform: translateY(-4px); box-shadow: 0 8px 24px rgba(255,0,107,0.12); border-color: rgba(255,0,107,0.3); }

        /* Top row: avatar + name/handle */
        .creator-card-top { display: flex; align-items: center; gap: 16px; margin-bottom: 14px; }
        .creator-avatar-kalshi {
            width: 72px; height: 72px; flex-shrink: 0; border-radius: 50%;
            background: linear-gradient(135deg, var(--hot-pink) 0%, var(--deep-pink) 100%);
            display: flex; align-items: center; justify-content: center; overflow: hidden;
        }
        .creator-avatar-kalshi img { width: 100%; height: 100%; object-fit: cover; }
        .creator-initial { font-size: 28px; color: var(--white); font-weight: 700; }
        .creator-card-identity { flex: 1; min-width: 0; }
        .creator-name-kalshi { font-size: 18px; font-weight: 700; color: var(--black); margin-bottom: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .creator-handle-kalshi { font-size: 13px; color: var(--gray-med); font-weight: 500; }

        .creator-bio { font-size: 13px; line-height: 1.55; color: #4B5563; margin-bottom: 14px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }

        /* Topic Tags */
        .creator-topics { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 18px; }
        .creator-topic-tag {
            font-size: 12px; font-weight: 600; padding: 5px 13px; border-radius: 50px;
            background: transparent; color: var(--gray-dark); border: 1.5px solid var(--gray-light);
        }

        /* Bottom: price + button */
        .creator-price-section { display: flex; justify-content: space-between; align-items: center; padding-top: 16px; border-top: 1px solid var(--gray-light); margin-top: auto; }
        .creator-price { font-size: 20px; color: var(--black); font-weight: 700; display: inline; }
        .price-label { font-size: 11px; color: var(--gray-med); font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px; margin-left: 6px; }
        .fund-topics-btn {
            background: var(--hot-pink); color: var(--white); border: none;
            padding: 10px 22px; font-size: 13px; font-weight: 700;
            cursor: pointer; transition: all 0.2s; border-radius: 50px;
            text-transform: uppercase; letter-spacing: 0.5px;
        }
        .fund-topics-btn:hover { background: var(--deep-pink); transform: scale(1.03); }

        @media (max-width: 900px) { .creators-grid-landing { grid-template-columns: 1fr; } }
        @media (max-width: 768px) { .nav-center { display: none; } }
    </style>
</head>
<body>
    <nav class="topiclaunch-nav">
        <div class="nav-container">
            <a href="/" class="nav-logo">Topic<span>Launch</span></a>
            <div class="nav-center">
                <a href="/creators/" class="nav-link active">Browse Creators</a>
                <a href="/creators/signup.php" class="nav-link">For Creators</a>
            </div>
            <div class="nav-buttons">
                <a href="/auth/login.php" class="nav-login-btn">Log In</a>
                <a href="/creators/signup.php" class="nav-getstarted-btn">Get Started</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="header">
            <div class="header-eyebrow">BROWSE</div>
            <p class="header-subtitle">Find the perfect creator for what you want to see.</p>
        </div>

        <div class="search-section">
            <div class="search-bar">
                <div class="search-input-wrapper">
                    <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="8"></circle>
                        <path d="m21 21-4.35-4.35"></path>
                    </svg>
                    <input type="text" id="searchInput" class="search-input" placeholder="Search creators by name or topic..." autocomplete="off">
                </div>
            </div>
            <div class="topic-filters" id="topicFilters">
                <button class="topic-filter-btn active" data-topic="all">All</button>
                <?php
                $all_topics = ['Fitness','Health','Motivation','Therapy','Dating','Business','Money','Psychology','Career','Cosmetics','Family','Technology & AI'];
                foreach ($all_topics as $t): ?>
                <button class="topic-filter-btn" data-topic="<?php echo htmlspecialchars($t); ?>"><?php echo htmlspecialchars($t); ?></button>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="creators-grid-landing" id="creatorsGrid">
            <?php foreach ($creators as $creator):
                $video_topics = [];
                if (!empty($creator->video_topics)) {
                    $decoded = json_decode($creator->video_topics, true);
                    if (is_array($decoded)) $video_topics = $decoded;
                }
            ?>
                <a href="/<?php echo htmlspecialchars($creator->display_name); ?>" class="creator-card-kalshi">
                    <div class="creator-card-top">
                        <div class="creator-avatar-kalshi">
                            <?php if ($creator->profile_image): ?>
                                <img src="../uploads/creators/<?php echo htmlspecialchars($creator->profile_image); ?>" alt="<?php echo htmlspecialchars($creator->display_name); ?>">
                            <?php else: ?>
                                <div class="creator-initial"><?php echo strtoupper(substr($creator->display_name, 0, 1)); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="creator-card-identity">
                            <div class="creator-name-kalshi"><?php echo htmlspecialchars($creator->display_name); ?></div>
                            <div class="creator-handle-kalshi">@<?php echo htmlspecialchars($creator->display_name); ?></div>
                        </div>
                    </div>
                    <div class="creator-bio"><?php echo !empty($creator->bio) ? htmlspecialchars($creator->bio) : 'Building my empire, one post at a time'; ?></div>
                    <?php if (!empty($video_topics)): ?>
                    <div class="creator-topics">
                        <?php foreach (array_slice($video_topics, 0, 5) as $tag): ?>
                            <span class="creator-topic-tag"><?php echo htmlspecialchars($tag); ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <div class="creator-price-section">
                        <div>
                            <span class="creator-price">$<?php echo number_format($creator->minimum_topic_price ?? 100, 0); ?></span>
                            <span class="price-label">/ per request</span>
                        </div>
                        <button class="fund-topics-btn" onclick="event.preventDefault(); window.location.href='/<?php echo htmlspecialchars($creator->display_name); ?>'">
                            Send Request
                        </button>
                    </div>
                </a>
            <?php endforeach; ?>
            <div class="no-results" id="noResults"><strong>No creators found</strong>Try a different search or topic.</div>
        </div>
    </div>

    <script>
        const searchInput = document.getElementById('searchInput');
        const creatorCards = document.querySelectorAll('.creator-card-kalshi');
        const filterBtns = document.querySelectorAll('.topic-filter-btn');
        const noResults = document.getElementById('noResults');
        let activeTopic = 'all';

        function filterCards() {
            const searchTerm = searchInput.value.toLowerCase();
            let visible = 0;
            creatorCards.forEach(card => {
                const name = card.querySelector('.creator-name-kalshi').textContent.toLowerCase();
                const username = card.querySelector('.creator-handle-kalshi').textContent.toLowerCase();
                const bio = card.querySelector('.creator-bio')?.textContent.toLowerCase() || '';
                const tags = Array.from(card.querySelectorAll('.creator-topic-tag')).map(t => t.textContent.trim().toLowerCase());
                const matchesSearch = !searchTerm || name.includes(searchTerm) || username.includes(searchTerm) || bio.includes(searchTerm) || tags.some(t => t.includes(searchTerm));
                const matchesTopic = activeTopic === 'all' || tags.includes(activeTopic.toLowerCase());
                const show = matchesSearch && matchesTopic;
                card.style.display = show ? 'flex' : 'none';
                if (show) visible++;
            });
            noResults.style.display = visible === 0 ? 'block' : 'none';
        }

        searchInput.addEventListener('input', filterCards);

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
