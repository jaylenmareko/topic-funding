<?php
// creators/profile.php - Creators can only access dashboard/edit, not other profiles
session_start();
require_once '../config/database.php';

// If creator is logged in, redirect to dashboard (they shouldn't view any profiles)
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

// Only guests (fans) can view creator profiles
$helper = new DatabaseHelper();
$creator_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$creator_id) {
    header('Location: index.php');
    exit;
}

$creator = $helper->getCreatorById($creator_id);
if (!$creator) {
    header('Location: index.php');
    exit;
}

// Get creator's topics by status
$db = new Database();

// Active topics
$db->query('SELECT * FROM topics WHERE creator_id = :creator_id AND status = "active" ORDER BY created_at DESC');
$db->bind(':creator_id', $creator_id);
$active_topics = $db->resultSet();

// Waiting Upload topics (funded but no content uploaded yet) - with deadline timestamp
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
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo htmlspecialchars($creator->display_name); ?> - TopicLaunch</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0; 
            padding: 0; 
            background: #fafafa;
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
        
        /* Profile Box - Rizzdem Style */
        .profile-box {
            background: white;
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            border: 1px solid #e5e7eb;
            padding: 32px;
            margin-bottom: 30px;
            max-width: 700px;
            margin-left: auto;
            margin-right: auto;
        }
        
        .profile-header {
            display: flex;
            align-items: flex-start;
            gap: 24px;
            margin-bottom: 24px;
        }
        
        .profile-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: linear-gradient(135deg, #FF0000 0%, #CC0000 100%);
            display: flex;
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
            font-size: 28px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 4px;
        }
        
        .profile-handle {
            font-size: 16px;
            color: #6b7280;
            margin-bottom: 16px;
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
            font-size: 15px;
            color: #4b5563;
            line-height: 1.6;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
        }
        
        .create-topic-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #10b981;
            color: white;
            padding: 14px 28px;
            text-decoration: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            transition: all 0.2s;
            margin-top: 24px;
        }
        
        .create-topic-btn:hover {
            background: #059669;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16,185,129,0.3);
        }
        
        /* Topics sections */
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
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 15px;
        }
        
        .funding-amount { 
            font-weight: 700;
            color: #10b981;
            font-size: 16px;
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
        
        /* Countdown timer styles */
        .countdown-timer { 
            background: #10b981;
            color: white; 
            padding: 8px 16px; 
            border-radius: 8px;
            font-weight: 600;
            font-size: 13px;
            font-family: monospace;
            display: inline-block;
        }
        
        .countdown-timer.warning { 
            background: #f59e0b;
            color: white;
        }
        
        .countdown-timer.expired { 
            background: #ef4444;
            color: white;
        }
        
        .refund-message {
            background: #fee2e2;
            color: #991b1b;
            padding: 12px 16px;
            border-radius: 8px;
            margin-top: 12px;
            font-size: 13px;
            border-left: 4px solid #ef4444;
        }
        
        @media (max-width: 768px) {
            .container { padding: 20px 15px; }
            .profile-box { padding: 24px; }
            .profile-header { flex-direction: column; text-align: center; }
            .profile-avatar { margin: 0 auto; }
            .topic-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="topiclaunch-nav">
        <div class="nav-container">
            <a href="../index.php" class="nav-logo">TopicLaunch</a>
            <div class="nav-buttons">
                <a href="../auth/login.php" class="nav-login-btn">Log In</a>
                <a href="../creators/signup.php" class="nav-getstarted-btn">Get Started</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <!-- Profile Box - Rizzdem Style -->
        <div class="profile-box">
            <div class="profile-header">
                <div class="profile-avatar">
                    <?php if ($creator->profile_image && file_exists('../uploads/creators/' . $creator->profile_image)): ?>
                        <img src="../uploads/creators/<?php echo htmlspecialchars($creator->profile_image); ?>" 
                             alt="<?php echo htmlspecialchars($creator->display_name); ?>">
                    <?php else: ?>
                        <?php echo strtoupper(substr($creator->display_name, 0, 1)); ?>
                    <?php endif; ?>
                </div>
                
                <div class="profile-info">
                    <div class="profile-name"><?php echo htmlspecialchars($creator->display_name); ?></div>
                    <div class="profile-handle">@<?php echo htmlspecialchars($creator->display_name); ?></div>
                    
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

        <!-- Active Topics -->
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

        <!-- Waiting Upload Topics -->
        <?php if (!empty($waiting_upload_topics)): ?>
        <div class="section">
            <h2>⏰ Waiting Upload (<?php echo count($waiting_upload_topics); ?>)</h2>
            <div class="topic-grid">
                <?php foreach ($waiting_upload_topics as $topic): ?>
                    <div class="topic-card" onclick="openTopicModal(<?php echo $topic->id; ?>)">
                        <div style="margin-bottom: 15px;">
                            <div class="countdown-timer" 
                                 data-deadline="<?php echo $topic->deadline_timestamp; ?>"
                                 id="countdown-<?php echo $topic->id; ?>">
                                00:00:00
                            </div>
                        </div>
                        
                        <div class="refund-message" id="refund-message-<?php echo $topic->id; ?>" style="display: none;">
                            💰 <strong>Deadline expired.</strong> 90% refunds are being processed.
                        </div>
                        
                        <h3 class="topic-title"><?php echo htmlspecialchars($topic->title); ?></h3>
                        <p class="topic-description"><?php echo htmlspecialchars(substr($topic->description, 0, 150)) . (strlen($topic->description) > 150 ? '...' : ''); ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Completed Topics -->
        <?php if (!empty($completed_topics)): ?>
        <div class="section">
            <h2>✅ Completed Topics (<?php echo count($completed_topics); ?>)</h2>
            <div class="topic-grid">
                <?php foreach (array_slice($completed_topics, 0, 6) as $topic): ?>
                    <div class="topic-card" onclick="openTopicModal(<?php echo $topic->id; ?>)">
                        <h3 class="topic-title"><?php echo htmlspecialchars($topic->title); ?></h3>
                        <p class="topic-description"><?php echo htmlspecialchars(substr($topic->description, 0, 150)) . (strlen($topic->description) > 150 ? '...' : ''); ?></p>
                        
                        <?php if ($topic->content_url): ?>
                            <div style="margin-top: 15px;">
                                <span style="background: #10b981; color: white; padding: 6px 12px; border-radius: 8px; font-size: 12px; font-weight: 600;">
                                    🎬 Content Available
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
    // Live countdown timer functionality
    function updateCountdowns() {
        const countdownElements = document.querySelectorAll('.countdown-timer[data-deadline]');
        countdownElements.forEach(element => {
            const deadline = parseInt(element.getAttribute('data-deadline')) * 1000;
            const now = new Date().getTime();
            const timeLeft = deadline - now;
            const topicId = element.id.replace('countdown-', '');
            const refundMessage = document.getElementById(`refund-message-${topicId}`);

            if (timeLeft > 0) {
                const hours = Math.floor(timeLeft / (1000 * 60 * 60));
                const minutes = Math.floor((timeLeft % (1000 * 60 * 60)) / (1000 * 60));
                const seconds = Math.floor((timeLeft % (1000 * 60)) / 1000);
                const formattedTime = String(hours).padStart(2, '0') + ':' + String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
                element.textContent = formattedTime;
                if (refundMessage) refundMessage.style.display = 'none';
                element.classList.remove('expired', 'warning');
                if (hours <= 1) element.classList.add('expired');
                else if (hours <= 6) element.classList.add('warning');
            } else {
                element.textContent = 'Expired';
                element.classList.add('expired');
                if (refundMessage) refundMessage.style.display = 'block';
            }
        });
    }
    updateCountdowns();
    setInterval(updateCountdowns, 1000);

    function openTopicModal(topicId) {
        fetch(`../api/get-topic.php?id=${topicId}`)
            .then(response => response.json())
            .then(topic => {
                if (!topic || topic.error) {
                    alert('Topic not found');
                    return;
                }

                const progress = Math.min(100, (topic.current_funding / topic.funding_threshold) * 100);

                // Build funding form HTML (only for active topics)
                let actionHTML = '';
                if (topic.status === 'completed' && topic.content_url) {
                    actionHTML = `<a href="${topic.content_url}" target="_blank" style="display: block; background: #10b981; color: white; text-align: center; padding: 15px; border-radius: 8px; text-decoration: none; font-weight: bold; margin-bottom: 15px;">▶️ Watch Content</a>`;
                } else if (topic.status === 'active') {
                    actionHTML = `
                        <div id="fundingFormContainer">
                            <div id="errorMessage" style="display: none; color: #721c24; background: #f8d7da; border: 1px solid #f5c6cb; padding: 12px; border-radius: 6px; margin-bottom: 20px; font-size: 14px;"></div>

                            <div style="margin-bottom: 20px;">
                                <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #333;">Enter Amount ($1 - $1,000)</label>
                                <input
                                    type="number"
                                    id="fundingAmount"
                                    placeholder="$1 - $1000"
                                    min="1"
                                    max="1000"
                                    step="1"
                                    value="1"
                                    style="width: 100%; padding: 15px; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 18px; box-sizing: border-box; transition: border-color 0.3s;"
                                    oninput="validateFundingAmount()"
                                    onfocus="this.style.borderColor='#FF0000'"
                                    onblur="this.style.borderColor='#e0e0e0'"
                                >
                            </div>

                            <button
                                id="fundButton"
                                onclick="submitFunding(${topic.id})"
                                style="width: 100%; background: linear-gradient(135deg, #FF0000 0%, #CC0000 100%); color: white; padding: 15px; border: none; border-radius: 8px; font-size: 18px; font-weight: bold; cursor: pointer; transition: opacity 0.3s;"
                                onmouseover="this.style.opacity='0.9'"
                                onmouseout="this.style.opacity='1'"
                            >
                                💰 Fund This Topic
                            </button>

                            <div style="display: flex; align-items: center; justify-content: center; gap: 8px; margin-top: 15px; color: #10b981; font-weight: 600; font-size: 14px;">
                                <span>🔒</span>
                                <span>Secure payment by Stripe</span>
                            </div>
                        </div>
                    `;
                }

                const modalHTML = `
                    <div id="topicModal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 10000; display: flex; align-items: center; justify-content: center; padding: 20px;" onclick="closeTopicModal(event)">
                        <div style="background: white; border-radius: 12px; max-width: 600px; width: 100%; max-height: 90vh; overflow-y: auto; padding: 40px; position: relative;" onclick="event.stopPropagation()">
                            <button onclick="closeTopicModal()" style="position: absolute; top: 20px; right: 20px; background: none; border: none; font-size: 28px; cursor: pointer; color: #999;">×</button>

                            <h2 style="margin: 0 0 20px 0; font-size: 28px; color: #333;">${topic.title}</h2>

                            <p style="color: #666; line-height: 1.6; margin-bottom: 30px; font-size: 16px;">${topic.description}</p>

                            <div style="background: #f9f9f9; padding: 20px; border-radius: 8px; margin-bottom: 30px;">
                                <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                                    <span style="font-size: 14px; color: #666;">Funding Progress</span>
                                    <span style="font-size: 14px; font-weight: bold; color: #FF0000;">${Math.round(progress)}%</span>
                                </div>
                                <div style="height: 8px; background: #e0e0e0; border-radius: 4px; overflow: hidden; margin-bottom: 15px;">
                                    <div style="height: 100%; background: linear-gradient(135deg, #FF0000 0%, #CC0000 100%); width: ${progress}%;"></div>
                                </div>
                                <div style="font-size: 20px; font-weight: bold; color: #333;">
                                    $${parseFloat(topic.current_funding).toFixed(2)} <span style="color: #999; font-size: 16px;">of $${parseFloat(topic.funding_threshold).toFixed(2)}</span>
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

        // Clear previous errors
        errorDiv.style.display = 'none';
        errorDiv.textContent = '';

        // Validate amount
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

        // Show loading state
        button.disabled = true;
        button.innerHTML = '⏳ Processing...';
        button.style.opacity = '0.6';

        // Prepare request data
        const requestData = {
            topic_id: topicId,
            amount: amount
        };

        // Submit to API
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
                button.innerHTML = '💰 Fund This Topic';
                button.style.opacity = '1';
            } else if (data.checkout_url) {
                // Redirect to Stripe checkout
                window.location.href = data.checkout_url;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            errorDiv.textContent = 'An error occurred. Please try again.';
            errorDiv.style.display = 'block';
            button.disabled = false;
            button.innerHTML = '💰 Fund This Topic';
            button.style.opacity = '1';
        });
    }

    function closeTopicModal(event) {
        if (event && event.target.id !== 'topicModal') return;
        const modal = document.getElementById('topicModal');
        if (modal) modal.remove();
    }
    </script>
</body>
</html>
