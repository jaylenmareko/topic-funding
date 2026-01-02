<?php
// username.php - Vanity URL handler
session_start();

$username = isset($_GET['username']) ? trim($_GET['username']) : '';

if (empty($username)) {
    header('Location: /');
    exit;
}

// Load database
if (file_exists('config/database.php')) {
    require_once 'config/database.php';
} elseif (file_exists(__DIR__ . '/config/database.php')) {
    require_once __DIR__ . '/config/database.php';
} else {
    header('Location: /');
    exit;
}

try {
    $db = new Database();
    
    // Look up creator by display_name
    $db->query('SELECT * FROM creators WHERE display_name = :username AND is_active = 1');
    $db->bind(':username', $username);
    $creator = $db->single();
    
    if (!$creator) {
        header('Location: /');
        exit;
    }
    
    // Creator found - display profile inline
    $creator_id = $creator->id;
    
    // Get creator's topics by status
    $db = new Database();

    // Active topics
    $db->query('SELECT * FROM topics WHERE creator_id = :creator_id AND status = "active" ORDER BY created_at DESC');
    $db->bind(':creator_id', $creator_id);
    $active_topics = $db->resultSet();

    // Waiting Upload topics
    $db->query('
        SELECT t.*, 
               UNIX_TIMESTAMP(t.content_deadline) as deadline_timestamp,
               TIMESTAMPDIFF(HOUR, t.funded_at, NOW()) as hours_since_funded,
               (48 - TIMESTAMPDIFF(HOUR, t.funded_at, NOW())) as hours_remaining,
               TIMESTAMPDIFF(HOUR, t.content_deadline, NOW()) as hours_past_deadline
        FROM topics t 
        WHERE t.creator_id = :creator_id 
        AND t.status = "funded" 
        AND (t.content_url IS NULL OR t.content_url = "")
        AND TIMESTAMPDIFF(HOUR, t.content_deadline, NOW()) <= 2
        ORDER BY t.funded_at ASC
    ');
    $db->bind(':creator_id', $creator_id);
    $waiting_upload_topics = $db->resultSet();

    // Completed topics
    $db->query('SELECT * FROM topics WHERE creator_id = :creator_id AND status = "completed" ORDER BY completed_at DESC');
    $db->bind(':creator_id', $creator_id);
    $completed_topics = $db->resultSet();
    
    // Now output the profile page HTML
    ?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo htmlspecialchars($creator->display_name); ?> - TopicLaunch</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', 'Helvetica', 'Arial', sans-serif;
            margin: 0; 
            padding: 0; 
            background: #fafafa;
        }
        
        /* Navigation - Rizzdem Style */
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
        
        /* Container */
        .container { 
            max-width: 1200px; 
            margin: 0 auto; 
            padding: 40px 20px; 
        }
        
        /* Profile Box */
        .profile-box {
            background: white;
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            border: 1px solid #e5e7eb;
            padding: 32px;
            margin-bottom: 30px;
            max-width: 500px;
            margin-left: auto;
            margin-right: auto;
        }
        
        .profile-header {
            flex-direction: column;
            text-align: center;
            display: inline-flex;
            align-items: flex-start;
            gap: 0px;
            margin-bottom: 0px;
        }
        
        .profile-avatar {
            margin: 0 auto 16px auto;
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: linear-gradient(135deg, #FF0000 0%, #CC0000 100%);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 40px;
            font-weight: bold;
            overflow: hidden;
            flex-shrink: 0;
        }
        
        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .profile-info {
            flex: 1;
            min-width: 0;
        }
        
        .profile-name {
            font-size: 24px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 8px;
        }
        
        .profile-handle {
            font-size: 15px;
            color: #6b7280;
            margin-bottom: 12px;
        }
        
        .profile-price {
            display: inline-flex;
            align-items: baseline;
            gap: 6px;
            background: #f3f4f6;
            padding: 8px 16px;
            border-radius: 8px;
        }
        
        .profile-price-amount {
            font-size: 24px;
            font-weight: 700;
            color: #111827;
        }
        
        .profile-price-label {
            font-size: 13px;
            color: #6b7280;
            font-weight: 500;
        }
        
        .profile-bio {
            text-align: left;
            font-size: 15px;
            color: #4b5563;
            line-height: 1.6;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
        }
        
        .create-topic-btn {
            margin-left: auto;
            margin-right: auto;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #10b981;
            color: white;
            padding: 14px 28px;
            text-decoration: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            transition: all 0.2s;
            margin-top: 20px;
        }
        
        .create-topic-btn:hover {
            background: #059669;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16,185,129,0.3);
        }
        
        .section { 
            background: white; 
            padding: 25px; 
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            margin-bottom: 20px;
            border: 1px solid #e5e7eb;
        }
        
        .section h2 { 
            margin-top: 0; 
            color: #111827;
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 20px;
        }
        
        .topic-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); 
            gap: 20px; 
        }
        
        .topic-card { 
            border: 1px solid #e5e7eb;
            padding: 20px; 
            border-radius: 12px;
            transition: all 0.2s;
            cursor: pointer;
            background: white;
        }
        
        .topic-card:hover { 
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            border-color: #FF0000;
        }
        
        .topic-title { 
            font-weight: 600;
            font-size: 18px;
            margin-bottom: 10px;
            color: #111827;
        }
        
        .topic-description { 
            color: #6b7280;
            line-height: 1.5;
            margin-bottom: 15px;
            font-size: 14px;
        }
        
        .funding-bar { 
            background: #e5e7eb;
            height: 8px;
            border-radius: 4px;
            margin: 15px 0;
            overflow: hidden;
        }
        
        .funding-progress { 
            background: linear-gradient(90deg, #10b981, #059669);
            height: 100%;
            border-radius: 4px;
            transition: width 0.3s;
        }
        
        .funding-info { 
            display: inline-flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 15px;
        }
        
        .funding-amount { 
            font-weight: 700;
            color: #10b981;
            font-size: 15px;
        }
        
        .empty-state { 
            text-align: center;
            color: #6b7280;
            padding: 60px 20px;
        }
        
        .empty-state h3 {
            color: #374151;
            margin-bottom: 8px;
            font-size: 18px;
        }
        
        .empty-state p {
            color: #9ca3af;
            font-size: 14px;
        }
        
            @media (max-width: 768px) {
            .container { padding: 20px 15px; }
            .profile-box { padding: 32px; }
            .topic-grid { grid-template-columns: 1fr; }
            .nav-center { display: none; }
        }
    </style>
</head>
<body>
    <nav class="topiclaunch-nav">
        <div class="nav-container">
            <a href="/" class="nav-logo">TopicLaunch</a>
            
            <div class="nav-center">
                <a href="/#creators" class="nav-link">Browse YouTubers</a>
                <a href="/creators/signup.php" class="nav-link">For YouTubers</a>
            </div>

            <div class="nav-buttons">
                <a href="/auth/login.php" class="nav-login-btn">Log In</a>
                <a href="/creators/signup.php" class="nav-getstarted-btn">Get Started</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="profile-box">
            <div class="profile-header">
                <div class="profile-avatar">
                    <?php if ($creator->profile_image && file_exists('../../uploads/creators/' . $creator->profile_image)): ?>
                        <img src="../../uploads/creators/<?php echo htmlspecialchars($creator->profile_image); ?>" 
                             alt="<?php echo htmlspecialchars($creator->display_name); ?>">
                    <?php else: ?>
                        <?php echo strtoupper(substr($creator->display_name, 0, 1)); ?>
                    <?php endif; ?>
                </div>
                
                <div class="profile-info">
                    <div class="profile-name">@<?php echo htmlspecialchars($creator->display_name); ?></div>
                    
                    <div class="profile-price">
                        <span class="profile-price-amount">$<?php echo number_format($creator->minimum_topic_price ?? 100, 2); ?></span>
                        <span class="profile-price-label">/ PER TOPIC</span>
                    </div>
                </div>
            </div>
            
            <?php if (!empty($creator->bio)): ?>
            <div class="profile-bio">
                <?php echo nl2br(htmlspecialchars($creator->bio)); ?>
            </div>
            <?php endif; ?>
            
            <a href="../topics/create.php?creator_id=<?php echo $creator->id; ?>" class="create-topic-btn">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="12" y1="5" x2="12" y2="19"></line>
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
                Create a Topic
            </a>
        </div>

        <div class="section">
            <h2>Active Topics</h2>
            <?php if (empty($active_topics)): ?>
                <div class="empty-state">
                    <h3>No active topics</h3>
                    <p>This creator doesn't have any topics seeking funding right now.</p>
                </div>
            <?php else: ?>
                <div class="topic-grid">
                    <?php foreach ($active_topics as $topic): ?>
                    <div class="topic-card" onclick="openTopicModal(<?php echo $topic->id; ?>)">
                        <h3 class="topic-title"><?php echo htmlspecialchars($topic->title); ?></h3>
                        <p class="topic-description"><?php echo htmlspecialchars(substr($topic->description, 0, 150)) . (strlen($topic->description) > 150 ? '...' : ''); ?></p>
                        
                        <?php 
                        $progress_percent = ($topic->current_funding / $topic->funding_threshold) * 100;
                        $progress_percent = min($progress_percent, 100);
                        ?>
                        
                        <div class="funding-bar">
                            <div class="funding-progress" style="width: <?php echo $progress_percent; ?>%"></div>
                        </div>
                        
                        <div class="funding-info">
                            <div>
                                <span class="funding-amount">$<?php echo number_format($topic->current_funding, 2); ?></span>
                                <span style="color: #9ca3af; font-size: 14px;"> of $<?php echo number_format($topic->funding_threshold, 2); ?></span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    function openTopicModal(topicId) {
        fetch(`../api/get-topic.php?id=${topicId}`)
            .then(response => response.json())
            .then(topic => {
                if (!topic || topic.error) {
                    alert('Topic not found');
                    return;
                }

                const progress = Math.min(100, (topic.current_funding / topic.funding_threshold) * 100);

                let actionHTML = '';
                if (topic.status === 'completed' && topic.content_url) {
                    actionHTML = `<a href="${topic.content_url}" target="_blank" style="display: block; background: #10b981; color: white; text-align: center; padding: 13px; border-radius: 12px; text-decoration: none; font-weight: 600; font-size: 15px; transition: all 0.2s;" onmouseover="this.style.transform='translateY(-1px)'; this.style.boxShadow='0 4px 12px rgba(16,185,129,0.4)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none'">▶️ Watch Content</a>`;
                } else if (topic.status === 'active') {
                    actionHTML = `
                        <div id="fundingFormContainer">
                            <div id="errorMessage" style="display: none; color: #dc2626; background: #fef2f2; border: 1px solid #fee2e2; padding: 12px 16px; border-radius: 12px; margin-bottom: 20px; font-size: 14px;"></div>

                            <div style="margin-bottom: 18px;">
                                <label style="display: flex; align-items: center; gap: 7px; font-weight: 400; margin-bottom: 10px; color: #111827; font-size: 14px;">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color: #6b7280;">
                                        <line x1="12" y1="1" x2="12" y2="23"></line>
                                        <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                                    </svg>
                                    Amount
                                </label>
                                <input
                                    type="number"
                                    id="fundingAmount"
                                    placeholder="10"
                                    min="1"
                                    max="1000"
                                    step="1"
                                    value="10"
                                    style="width: 100%; padding: 13px 16px; border: 2px solid #e5e7eb; border-radius: 12px; font-size: 15px; box-sizing: border-box; transition: all 0.15s; outline: none; background: white; color: #111827;"
                                    oninput="validateFundingAmount()"
                                    onfocus="this.style.borderColor='#FF0000'; this.style.boxShadow='0 0 0 3px rgba(255,0,0,0.1)'"
                                    onblur="this.style.borderColor='#e5e7eb'; this.style.boxShadow='none'"
                                >
                            </div>

                            <button
                                id="fundButton"
                                onclick="submitFunding(${topic.id})"
                                style="width: 100%; background: linear-gradient(135deg, #FF0000 0%, #CC0000 100%); color: white; padding: 13px; border: none; border-radius: 12px; font-size: 15px; font-weight: 600; cursor: pointer; transition: all 0.2s; box-shadow: 0 1px 2px rgba(0,0,0,0.05);"
                                onmouseover="this.style.transform='translateY(-1px)'; this.style.boxShadow='0 4px 12px rgba(255,0,0,0.35)'"
                                onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 1px 2px rgba(0,0,0,0.05)'"
                            >
                                Fund This Topic
                            </button>

                        </div>
                    `;
                }

                const modalHTML = `
                    <div id="topicModal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.4); z-index: 10000; display: flex; align-items: center; justify-content: center; padding: 20px; backdrop-filter: blur(3px);" onclick="closeTopicModal(event)">
                        <div style="background: white; border-radius: 16px; max-width: 500px; width: 100%; max-height: 90vh; overflow-y: auto; padding: 32px; position: relative; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04);" onclick="event.stopPropagation()">
                            <button onclick="closeTopicModal()" style="position: absolute; top: 20px; right: 20px; background: transparent; border: none; width: 24px; height: 24px; font-size: 24px; cursor: pointer; color: #9ca3af; transition: color 0.2s; padding: 0; line-height: 1;" onmouseover="this.style.color='#6b7280'" onmouseout="this.style.color='#9ca3af'">×</button>

                            <h2 style="margin: 0 0 8px 0; font-size: 20px; color: #111827; font-weight: 600; line-height: 1.4; padding-right: 30px;">${topic.title}</h2>

                            <p style="color: #6b7280; line-height: 1.6; margin-bottom: 0px; font-size: 14px;">${topic.description}</p>

                            <div style="background: #fafafa; padding: 16px; border-radius: 12px; margin-bottom: 0px;">
                                <div style="display: flex; margin-bottom: 8px; align-items: center;">
                                    <span style="font-size: 13px; color: #6b7280; font-weight: 500;">Funding Progress</span>
                                    
                                </div>
                                <div style="height: 8px; background: #e5e7eb; border-radius: 999px; overflow: hidden; margin-bottom: 12px;">
                                    <div style="height: 100%; background: linear-gradient(90deg, #FF0000, #CC0000); width: ${progress}%; transition: width 0.3s; border-radius: 999px;"></div>
                                </div>
                                <div style="font-size: 18px; font-weight: 600; color: #111827;">
                                    $${parseFloat(topic.current_funding).toFixed(2)} <span style="color: #9ca3af; font-size: 14px; font-weight: 500;">of $${parseFloat(topic.funding_threshold).toFixed(2)}</span>
                                </div>
                            </div>

                            ${actionHTML}
                        </div>
                    </div>
                `;

                document.body.insertAdjacentHTML('beforeend', modalHTML);
            })
            .catch(error => {
                console.error('Error loading topic:', error);
                alert('Failed to load topic details');
            });
    }

    function validateFundingAmount() {
        const amount = parseFloat(document.getElementById('fundingAmount').value);
        const button = document.getElementById('fundButton');

        if (amount >= 1 && amount <= 1000) {
            button.disabled = false;
            button.style.opacity = '1';
            button.style.cursor = 'pointer';
        } else {
            button.disabled = true;
            button.style.opacity = '0.6';
            button.style.cursor = 'not-allowed';
        }
    }

    function submitFunding(topicId) {
        const amount = parseFloat(document.getElementById('fundingAmount').value);
        const errorDiv = document.getElementById('errorMessage');
        const button = document.getElementById('fundButton');

        errorDiv.style.display = 'none';
        errorDiv.textContent = '';

        if (!amount || amount < 1) {
            errorDiv.textContent = 'Minimum contribution is $1';
            errorDiv.style.display = 'block';
            return;
        }

        if (amount > 1000) {
            errorDiv.textContent = 'Maximum contribution is $1,000';
            errorDiv.style.display = 'block';
            return;
        }

        button.disabled = true;
        button.innerHTML = 'Processing...';
        button.style.opacity = '0.6';

        const requestData = {
            topic_id: topicId,
            amount: amount
        };

        fetch('../api/get-topic.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(requestData)
        })
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                errorDiv.textContent = data.error;
                errorDiv.style.display = 'block';
                button.disabled = false;
                button.innerHTML = 'Fund This Topic';
                button.style.opacity = '1';
            } else if (data.checkout_url) {
                window.location.href = data.checkout_url;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            errorDiv.textContent = 'An error occurred. Please try again.';
            errorDiv.style.display = 'block';
            button.disabled = false;
            button.innerHTML = 'Fund This Topic';
            button.style.opacity = '1';
        });
    }

    function closeTopicModal(event) {
        if (event && event.target.id !== 'topicModal') return;
        const modal = document.getElementById('topicModal');
        if (modal) modal.remove();
    }
        // Mobile: Scroll to Active Topics on page load
    if (window.innerWidth <= 768) {
        window.addEventListener('load', function() {
            const activeTopicsSection = document.querySelector('.section');
            if (activeTopicsSection) {
                setTimeout(function() {
                    activeTopicsSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }, 100);
            }
        });
    }
</script>
</body>
</html>
<?php
    exit;
    
} catch (Exception $e) {
    error_log("Vanity URL error: " . $e->getMessage());
    header('Location: /');
    exit;
}
