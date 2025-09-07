<?php
// creators/dashboard.php - Clean creator dashboard
session_start();
require_once '../config/database.php';
require_once '../config/navigation.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$db = new Database();

// Get creator info
$db->query('SELECT c.*, u.email FROM creators c LEFT JOIN users u ON c.applicant_user_id = u.id WHERE c.applicant_user_id = :user_id AND c.is_active = 1');
$db->bind(':user_id', $_SESSION['user_id']);
$creator = $db->single();

if (!$creator) {
    header('Location: ../creators/index.php');
    exit;
}

// Get topics
$db->query('SELECT t.*, TIMESTAMPDIFF(HOUR, t.funded_at, NOW()) as hours_since_funded, (48 - TIMESTAMPDIFF(HOUR, t.funded_at, NOW())) as hours_remaining FROM topics t WHERE t.creator_id = :creator_id AND t.status IN ("active", "funded", "on_hold") AND (t.content_url IS NULL OR t.content_url = "") ORDER BY CASE WHEN t.status = "funded" THEN 1 WHEN t.status = "active" THEN 2 WHEN t.status = "on_hold" THEN 3 END, t.funded_at ASC, t.created_at DESC');
$db->bind(':creator_id', $creator->id);
$topics = $db->resultSet();

$message = isset($_GET['success']) ? $_GET['success'] : '';
$error = isset($_GET['error']) ? $_GET['error'] : '';
?>
<!DOCTYPE html>
<html>
<head>
    <title>Creator Dashboard - TopicLaunch</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; background: white; color: #333; }
        
        .header { background: white; padding: 40px 30px; text-align: center; border-bottom: 1px solid #f0f0f0; }
        .title { font-size: 36px; font-weight: 700; color: #333; }
        
        .message { background: #d4edda; color: #155724; padding: 15px 30px; text-align: center; }
        .error { background: #f8d7da; color: #721c24; padding: 15px 30px; text-align: center; }
        
        .container { padding: 40px 30px; max-width: 500px; margin: 0 auto; position: relative; }
        .swipe-area { position: relative; height: 400px; }
        
        .card {
            position: absolute; top: 0; left: 0; width: 100%; height: 100%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 20px; padding: 40px 30px 80px 30px; color: white;
            display: flex; flex-direction: column; justify-content: flex-start;
            cursor: grab; transition: all 0.3s ease; box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .card.empty {
            background: linear-gradient(135deg, #e9ecef 0%, #dee2e6 100%);
            color: #6c757d; cursor: default;
        }
        
        .card:hover { transform: translateY(-5px) scale(1.02); box-shadow: 0 15px 40px rgba(0,0,0,0.15); }
        .card.empty:hover { transform: none; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
        
        .status {
            background: rgba(255,255,255,0.2); padding: 8px 16px; border-radius: 20px;
            font-size: 14px; font-weight: 500; align-self: flex-start; backdrop-filter: blur(10px);
        }
        .status.funded { background: rgba(40, 167, 69, 0.3); animation: pulse 2s infinite; }
        
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(40, 167, 69, 0.7); }
            70% { box-shadow: 0 0 0 10px rgba(40, 167, 69, 0); }
            100% { box-shadow: 0 0 0 0 rgba(40, 167, 69, 0); }
        }
        
        .content { flex: 1; display: flex; flex-direction: column; justify-content: center; text-align: center; padding: 20px 0; }
        
        .topic-title {
            font-size: 24px; font-weight: 600; margin-bottom: 20px; line-height: 1.3;
            cursor: pointer; min-height: 60px; display: flex; align-items: center;
            justify-content: center; text-align: center; transition: all 0.3s ease;
        }
        
        .funding-box {
            background: rgba(255,255,255,0.2); padding: 15px; border-radius: 15px; backdrop-filter: blur(10px);
        }
        
        .amount { font-size: 28px; font-weight: 700; margin-bottom: 10px; }
        
        .btn {
            border: none; padding: 12px 24px; border-radius: 25px;
            font-size: 16px; font-weight: 600; transition: all 0.3s ease;
            background: rgba(255,255,255,0.3); color: rgba(255,255,255,0.5);
            cursor: default; opacity: 0.5;
        }
        
        .btn.funded {
            background: rgba(40, 167, 69, 0.9); color: white;
            cursor: pointer; opacity: 1; animation: glow 2s infinite;
        }
        
        .btn.funded:hover {
            background: rgba(40, 167, 69, 1); transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
        }
        
        @keyframes glow {
            0% { box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
            50% { box-shadow: 0 4px 20px rgba(40, 167, 69, 0.4); }
            100% { box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        }
        
        .progress-area {
            position: absolute; bottom: 20px; left: 30px; right: 30px;
        }
        
        .funding-display {
            color: rgba(255,255,255,0.9); font-size: 14px; font-weight: 600;
            margin-bottom: 5px; text-align: center;
        }
        
        .progress-bar {
            height: 6px; background: rgba(255,255,255,0.3); border-radius: 3px; overflow: hidden;
        }
        
        .progress-fill {
            height: 100%; background: rgba(255,255,255,0.8); border-radius: 3px;
            transition: width 0.5s ease;
        }
        
        .nav-btn {
            position: absolute; top: 50%; transform: translateY(-50%);
            width: 40px; height: 60px; background: rgba(0,0,0,0.1);
            border: none; border-radius: 10px; color: rgba(255,255,255,0.7);
            font-size: 20px; cursor: pointer; backdrop-filter: blur(10px);
        }
        .nav-btn:hover { background: rgba(0,0,0,0.2); color: white; }
        .nav-btn.left { left: -60px; }
        .nav-btn.right { right: -60px; }
        
        /* Mobile Navigation Hide */
        @media (max-width: 768px) {
            .header { padding: 30px 20px; }
            .title { font-size: 28px; }
            .container { padding: 30px 20px; }
            .swipe-area { height: 350px; }
            .card { padding: 30px 25px; }
            .topic-title { font-size: 20px; }
            .nav-btn { width: 45px; height: 70px; }
            .nav-btn.left { left: 10px; }
            .nav-btn.right { right: 10px; }
        }
        
        /* Hide mobile nav for creators */
        .topiclaunch-nav .nav-mobile-toggle { display: none !important; }
    </style>
</head>
<body>
    <?php renderNavigation('dashboard'); ?>

    <?php if ($message): ?>
        <div class="message"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="header">
        <h1 class="title">Create Content, Get Paid</h1>
    </div>

    <div class="container">
        <div class="swipe-area" id="swipeArea">
            <button class="nav-btn left" onclick="swipeLeft()">‹</button>
            <button class="nav-btn right" onclick="swipeRight()">›</button>
            
            <?php if (!empty($topics)): ?>
                <?php foreach ($topics as $index => $topic): ?>
                    <div class="card" data-topic="<?php echo $topic->id; ?>" style="<?php echo $index > 0 ? 'transform: scale(' . (1 - $index * 0.05) . ') translateY(' . ($index * 10) . 'px); z-index: ' . (count($topics) - $index) . ';' : ''; ?>">
                        
                        <?php if ($topic->status === 'funded'): ?>
                            <div class="status funded">
                                🔥 Funded<?php if ($topic->hours_remaining > 0): ?> - <?php echo $topic->hours_remaining; ?>h left<?php endif; ?>
                            </div>
                        <?php elseif ($topic->status === 'on_hold'): ?>
                            <div class="status">⏸️ On Hold</div>
                        <?php endif; ?>
                        
                        <div class="content">
                            <div>
                                <div class="click-hint">Tap for details</div>
                                <h3 class="topic-title" onclick="toggle(<?php echo $topic->id; ?>)" id="title-<?php echo $topic->id; ?>">
                                    <?php echo htmlspecialchars($topic->title); ?>
                                </h3>
                            </div>
                            
                            <div class="funding-box">
                                <button class="circle-btn<?php echo $topic->status === 'funded' ? ' funded' : ''; ?>" 
                                        <?php if ($topic->status === 'funded'): ?>onclick="window.location.href='../creators/upload_content.php?topic=<?php echo $topic->id; ?>'"<?php endif; ?>>
                                    <div class="circle-amount">$<?php echo number_format($topic->current_funding * 0.9, 0); ?></div>
                                    <div class="circle-text"><?php echo $topic->status === 'funded' ? 'Get Paid' : 'Click to get paid'; ?></div>
                                </button>
                            </div>
                        </div>
                        
                        <?php if ($topic->status === 'active'): ?>
                            <div class="progress-area">
                                <div class="funding-display">
                                    $<?php echo number_format($topic->current_funding, 0); ?> / $<?php echo number_format($topic->funding_threshold, 0); ?>
                                </div>
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: <?php echo min(100, ($topic->current_funding / $topic->funding_threshold) * 100); ?>%"></div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="card empty">
                    <div class="content">
                        <h3 class="topic-title">No Active Topics</h3>
                        <div style="font-size: 16px; opacity: 0.7; margin-top: 20px;">
                            Fans haven't created any topics for you yet. Share your profile to get started!
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        const topics = {<?php foreach ($topics as $t): ?>'<?php echo $t->id; ?>': {title: <?php echo json_encode($t->title); ?>, desc: <?php echo json_encode($t->description); ?>, showing: 'title'},<?php endforeach; ?>};
        let currentIndex = 0;
        const cards = document.querySelectorAll('.card');
        const leftBtn = document.getElementById('leftBtn');
        const rightBtn = document.getElementById('rightBtn');

        function updateNavButtons() {
            if (cards.length <= 1 || currentIndex >= cards.length - 1) {
                leftBtn.classList.add('disabled');
                rightBtn.classList.add('disabled');
            } else {
                leftBtn.classList.remove('disabled');
                rightBtn.classList.remove('disabled');
            }
        }

        function toggle(id) {
            const topic = topics[id];
            const el = document.getElementById(`title-${id}`);
            if (!topic || !el) return;
            
            if (topic.showing === 'title') {
                el.textContent = topic.desc;
                el.style.opacity = '0.8';
                el.style.fontSize = '18px';
                topic.showing = 'desc';
            } else {
                el.textContent = topic.title;
                el.style.opacity = '1';
                el.style.fontSize = '24px';
                topic.showing = 'title';
            }
        }

        function swipeLeft() { 
            if (leftBtn.classList.contains('disabled')) return;
            swipe('left'); 
        }
        
        function swipeRight() { 
            if (rightBtn.classList.contains('disabled')) return;
            swipe('right'); 
        }

        function swipe(direction) {
            if (currentIndex >= cards.length) return;
            const card = cards[currentIndex];
            card.style.transform = direction === 'left' ? 'translateX(-150%) rotate(-30deg)' : 'translateX(150%) rotate(30deg)';
            card.style.opacity = '0';
            setTimeout(() => {
                card.style.display = 'none';
                currentIndex++;
                updateStack();
                updateNavButtons();
            }, 300);
        }

        function updateStack() {
            for (let i = currentIndex; i < cards.length; i++) {
                const index = i - currentIndex;
                cards[i].style.transform = `scale(${1 - index * 0.05}) translateY(${index * 10}px)`;
                cards[i].style.zIndex = cards.length - index;
            }
        }

        // Initialize nav buttons
        updateNavButtons();

        // Swipe gestures
        cards.forEach(card => {
            let startX = 0, currentX = 0, isDragging = false;
            
            card.addEventListener('touchstart', e => {
                if (e.target.closest('.btn, .topic-title')) return;
                startX = e.touches[0].clientX;
                isDragging = true;
            }, {passive: true});
            
            card.addEventListener('touchmove', e => {
                if (!isDragging) return;
                currentX = e.touches[0].clientX;
                const deltaX = currentX - startX;
                card.style.transform = `translateX(${deltaX}px) rotate(${deltaX * 0.1}deg)`;
                card.style.opacity = Math.max(0.3, 1 - Math.abs(deltaX) / 200);
            }, {passive: true});
            
            card.addEventListener('touchend', () => {
                if (!isDragging) return;
                isDragging = false;
                const deltaX = currentX - startX;
                if (Math.abs(deltaX) > 100) {
                    swipe(deltaX > 0 ? 'right' : 'left');
                } else {
                    card.style.transform = '';
                    card.style.opacity = '1';
                }
            });
        });
    </script>
</body>
</html>
