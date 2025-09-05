<?php
// creators/dashboard.php - Simple visual dashboard with swipe interface
session_start();
require_once '../config/database.php';
require_once '../config/navigation.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$db = new Database();

// Get creator info
$db->query('
    SELECT c.*, u.email 
    FROM creators c 
    LEFT JOIN users u ON c.applicant_user_id = u.id 
    WHERE c.applicant_user_id = :user_id AND c.is_active = 1
');
$db->bind(':user_id', $_SESSION['user_id']);
$creator = $db->single();

if (!$creator) {
    // If no creator found, redirect to browse creators
    header('Location: ../creators/index.php');
    exit;
}

// Get only fully funded topics for the swipe interface
$db->query('
    SELECT t.*, 
           TIMESTAMPDIFF(HOUR, t.funded_at, NOW()) as hours_since_funded,
           (48 - TIMESTAMPDIFF(HOUR, t.funded_at, NOW())) as hours_remaining
    FROM topics t 
    WHERE t.creator_id = :creator_id 
    AND t.status = "funded"
    AND (t.content_url IS NULL OR t.content_url = "")
    ORDER BY t.funded_at ASC
');
$db->bind(':creator_id', $creator->id);
$funded_topics = $db->resultSet();

// Get all other topics for display when clicking
$db->query('
    SELECT t.*, 
           TIMESTAMPDIFF(HOUR, t.funded_at, NOW()) as hours_since_funded,
           (48 - TIMESTAMPDIFF(HOUR, t.funded_at, NOW())) as hours_remaining
    FROM topics t 
    WHERE t.creator_id = :creator_id 
    AND t.status IN ("active", "on_hold")
    ORDER BY 
        CASE 
            WHEN t.status = "on_hold" THEN 1
            WHEN t.status = "active" THEN 2
        END,
        t.created_at DESC
');
$db->bind(':creator_id', $creator->id);
$other_topics = $db->resultSet();

// Combine for display (funded topics first)
$all_topics = array_merge($funded_topics, $other_topics);

// Handle any success/error messages
$message = '';
$error = '';
if (isset($_GET['uploaded']) && $_GET['uploaded'] == '1') {
    $message = 'Content uploaded successfully! Payment processing...';
}
if (isset($_GET['success'])) {
    $message = $_GET['success'];
}
if (isset($_GET['error'])) {
    $error = $_GET['error'];
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>YouTuber Dashboard - TopicLaunch</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: white; 
            color: #333; 
            overflow-x: hidden;
        }
        
        /* Header Section */
        .dashboard-header {
            background: white;
            padding: 40px 30px;
            text-align: center;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .main-title {
            font-size: 36px;
            font-weight: 700;
            color: #333;
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }
        
        .subtitle {
            font-size: 18px;
            color: #666;
            font-weight: 400;
        }
        
        /* Messages */
        .message {
            background: #d4edda;
            color: #155724;
            padding: 15px 30px;
            text-align: center;
            border-bottom: 1px solid #c3e6cb;
        }
        
        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 15px 30px;
            text-align: center;
            border-bottom: 1px solid #f5c6cb;
        }
        
        /* Topic Swipe Container */
        .topic-swipe-section {
            padding: 40px 30px;
            max-width: 500px;
            margin: 0 auto;
            position: relative;
        }
        
        .swipe-container {
            position: relative;
            height: 400px;
            margin-bottom: 40px;
        }
        
        .topic-card {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 20px;
            padding: 40px 30px;
            color: white;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            cursor: grab;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            user-select: none;
            transform-origin: center center;
        }
        
        .topic-card:hover {
            transform: translateY(-5px) scale(1.02);
            box-shadow: 0 15px 40px rgba(0,0,0,0.15);
        }
        
        .topic-card.dragging {
            cursor: grabbing;
            transform: scale(1.05);
            z-index: 10;
        }
        
        .topic-card.swiped-left {
            transform: translateX(-150%) rotate(-30deg);
            opacity: 0;
        }
        
        .topic-card.swiped-right {
            transform: translateX(150%) rotate(30deg);
            opacity: 0;
        }
        
        .topic-status {
            background: rgba(255,255,255,0.2);
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 500;
            align-self: flex-start;
            backdrop-filter: blur(10px);
        }
        
        .topic-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            text-align: center;
            padding: 20px 0;
        }
        
        .topic-title {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 20px;
            line-height: 1.3;
            cursor: pointer;
        }
        
        .topic-funding {
            background: rgba(255,255,255,0.2);
            padding: 15px;
            border-radius: 15px;
            backdrop-filter: blur(10px);
        }
        
        .funding-amount {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .funding-label {
            font-size: 14px;
            opacity: 0.8;
        }
        
        /* Side Navigation Buttons */
        .swipe-nav {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            width: 40px;
            height: 60px;
            background: rgba(0,0,0,0.1);
            border: none;
            border-radius: 10px;
            color: rgba(255,255,255,0.7);
            font-size: 20px;
            cursor: pointer;
            z-index: 5;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }
        
        .swipe-nav:hover {
            background: rgba(0,0,0,0.2);
            color: white;
        }
        
        .swipe-nav.left {
            left: -60px;
        }
        
        .swipe-nav.right {
            right: -60px;
        }
        
        /* No Topics State */
        .no-topics {
            text-align: center;
            padding: 60px 30px;
            color: #666;
            height: 400px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }
        
        .no-topics-icon {
            font-size: 48px;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .no-topics h3 {
            font-size: 24px;
            margin-bottom: 10px;
            color: #333;
        }
        
        /* Action Button */
        .action-section {
            text-align: center;
            padding: 0 30px 40px;
        }
        
        .get-paid-button {
            background: linear-gradient(135deg, #00b894, #00a085);
            color: white;
            padding: 20px 40px;
            border: none;
            border-radius: 50px;
            font-size: 20px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 5px 20px rgba(0,184,148,0.3);
            min-width: 200px;
        }
        
        .get-paid-button:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,184,148,0.4);
            background: linear-gradient(135deg, #00a085, #008f75);
        }
        
        .get-paid-button:active:not(:disabled) {
            transform: translateY(0);
        }
        
        .get-paid-button:disabled {
            background: #ccc !important;
            cursor: not-allowed !important;
            transform: none !important;
            box-shadow: none !important;
            color: #999 !important;
        }
        
        /* Swipe Instructions */
        .swipe-instructions {
            text-align: center;
            margin-bottom: 20px;
            color: #666;
            font-size: 14px;
        }
        
        /* Upload Modal */
        .upload-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.7);
            z-index: 1000;
            backdrop-filter: blur(5px);
        }
        
        .modal-content {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: white;
            padding: 40px;
            border-radius: 20px;
            max-width: 500px;
            width: 90%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        
        .modal-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .modal-title {
            font-size: 24px;
            font-weight: 600;
            color: #333;
            margin-bottom: 10px;
        }
        
        .modal-subtitle {
            color: #666;
            font-size: 16px;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-label {
            display: block;
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
        }
        
        .form-input {
            width: 100%;
            padding: 15px;
            border: 2px solid #f0f0f0;
            border-radius: 10px;
            font-size: 16px;
            transition: border-color 0.3s ease;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
        }
        
        .modal-actions {
            display: flex;
            gap: 15px;
            margin-top: 30px;
        }
        
        .modal-button {
            flex: 1;
            padding: 15px;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .modal-button.primary {
            background: linear-gradient(135deg, #00b894, #00a085);
            color: white;
        }
        
        .modal-button.secondary {
            background: #f8f9fa;
            color: #666;
        }
        
        .modal-button:hover {
            transform: translateY(-1px);
        }
        
        /* Topic counter */
        .topic-counter {
            position: absolute;
            top: -40px;
            right: 0;
            background: rgba(102,126,234,0.1);
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 14px;
            color: #667eea;
            font-weight: 500;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .dashboard-header {
                padding: 30px 20px;
            }
            
            .main-title {
                font-size: 28px;
            }
            
            .topic-swipe-section {
                padding: 30px 20px;
            }
            
            .swipe-container {
                height: 350px;
            }
            
            .topic-card {
                padding: 30px 25px;
            }
            
            .topic-title {
                font-size: 20px;
            }
            
            .get-paid-button {
                padding: 18px 35px;
                font-size: 18px;
            }
            
            .modal-content {
                padding: 30px 25px;
            }
            
            @media (max-width: 768px) {
                .swipe-nav {
                    width: 45px;
                    height: 70px;
                    font-size: 20px;
                }
                
                .swipe-nav.left {
                    left: 10px;
                }
                
                .swipe-nav.right {
                    right: 10px;
                }
            }
        }
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

    <!-- Header -->
    <div class="dashboard-header">
        <h1 class="main-title">YouTuber Dashboard</h1>
        <p class="subtitle">Create Content, Get Paid</p>
    </div>

    <!-- Topic Swipe Section -->
    <div class="topic-swipe-section">
        <div class="swipe-container" id="swipeContainer">
            <!-- Side Navigation Buttons -->
            <button class="swipe-nav left" id="swipeLeft">‹</button>
            <button class="swipe-nav right" id="swipeRight">›</button>
            
            <?php if (!empty($all_topics)): ?>
                <?php foreach ($all_topics as $index => $topic): ?>
                    <div class="topic-card" data-topic-id="<?php echo $topic->id; ?>" style="<?php echo $index > 0 ? 'transform: scale(' . (1 - $index * 0.05) . ') translateY(' . ($index * 10) . 'px); z-index: ' . (count($all_topics) - $index) . ';' : ''; ?>">
                        <div class="topic-status">
                            <?php if ($topic->status === 'funded'): ?>
                                <?php 
                                $hours_remaining = max(0, $topic->hours_remaining ?? 0);
                                if ($hours_remaining > 0): ?>
                                    ⏰ <?php echo $hours_remaining; ?> hours left
                                <?php else: ?>
                                    🔥 Funded
                                <?php endif; ?>
                            <?php elseif ($topic->status === 'active'): ?>
                                $<?php echo number_format($topic->current_funding, 0); ?>/$<?php echo number_format($topic->funding_threshold, 0); ?>
                            <?php elseif ($topic->status === 'on_hold'): ?>
                                ⏸️ On Hold
                            <?php else: ?>
                                <?php echo ucfirst($topic->status); ?>
                            <?php endif; ?>
                        </div>
                        <div class="topic-content">
                            <h3 class="topic-title" onclick="showDescription(<?php echo $topic->id; ?>)"><?php echo htmlspecialchars($topic->title); ?></h3>
                            <div class="topic-funding">
                                <div class="funding-amount">$<?php echo number_format($topic->current_funding * 0.9, 0); ?></div>
                                <div class="funding-label">You'll earn after upload</div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="no-topics">
                    <div class="no-topics-icon">📺</div>
                    <h3>No Topics Available</h3>
                    <p>Fans haven't created any topics for you yet. Share your profile to get started!</p>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($all_topics)): ?>
        <div class="swipe-instructions">
            Tap for description • Swipe to navigate
        </div>
        <?php endif; ?>
    </div>

    <!-- Action Section -->
    <div class="action-section">
        <button class="get-paid-button" id="getPaidButton" <?php echo empty($funded_topics) ? 'disabled' : ''; ?>>
            <?php echo empty($funded_topics) ? 'No Funded Topics' : 'Get Paid 💰'; ?>
        </button>
    </div>

    <!-- Upload Modal -->
    <div class="upload-modal" id="uploadModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Upload Your Content</h2>
                <p class="modal-subtitle" id="modalTopicTitle">Topic Title</p>
            </div>
            
            <form id="uploadForm" method="POST" action="../creators/upload_content.php">
                <input type="hidden" name="topic_id" id="modalTopicId" value="">
                
                <div class="form-group">
                    <label class="form-label">Video URL *</label>
                    <input type="url" class="form-input" name="content_url" id="contentUrl" placeholder="https://youtube.com/watch?v=..." required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Quick Note (Optional)</label>
                    <input type="text" class="form-input" name="completion_notes" id="contentNote" placeholder="Hope you enjoy this detailed guide!">
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="modal-button secondary" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="modal-button primary">Upload & Get Paid</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Topic data for descriptions
        const topicData = {
            <?php if (!empty($all_topics)): ?>
                <?php foreach ($all_topics as $topic): ?>
                    '<?php echo $topic->id; ?>': {
                        title: <?php echo json_encode($topic->title); ?>,
                        description: <?php echo json_encode($topic->description); ?>,
                        status: '<?php echo $topic->status; ?>',
                        funding: <?php echo $topic->current_funding; ?>,
                        threshold: <?php echo $topic->funding_threshold; ?>
                    },
                <?php endforeach; ?>
            <?php endif; ?>
        };

        function showDescription(topicId) {
            const topic = topicData[topicId];
            if (topic) {
                alert(`${topic.title}\n\n${topic.description}\n\nStatus: ${topic.status}\nFunding: $${topic.funding}/$${topic.threshold}`);
            }
        }

        class TopicSwiper {
            constructor() {
                this.container = document.getElementById('swipeContainer');
                this.cards = Array.from(this.container.querySelectorAll('.topic-card'));
                this.currentIndex = 0;
                this.isDragging = false;
                this.startX = 0;
                this.currentX = 0;
                this.currentCard = null;
                
                this.init();
                this.updateCounter();
            }
            
            init() {
                this.cards.forEach((card, index) => {
                    card.style.zIndex = this.cards.length - index;
                    
                    // Touch events
                    card.addEventListener('touchstart', (e) => this.handleStart(e), { passive: true });
                    card.addEventListener('touchmove', (e) => this.handleMove(e), { passive: true });
                    card.addEventListener('touchend', (e) => this.handleEnd(e), { passive: true });
                    
                    // Mouse events
                    card.addEventListener('mousedown', (e) => this.handleStart(e));
                    card.addEventListener('mousemove', (e) => this.handleMove(e));
                    card.addEventListener('mouseup', (e) => this.handleEnd(e));
                    card.addEventListener('mouseleave', (e) => this.handleEnd(e));
                });
            }
            
            handleStart(e) {
                if (this.currentIndex >= this.cards.length) return;
                
                this.isDragging = true;
                this.currentCard = this.cards[this.currentIndex];
                this.currentCard.classList.add('dragging');
                
                const clientX = e.touches ? e.touches[0].clientX : e.clientX;
                this.startX = clientX;
                this.currentX = clientX;
                
                e.preventDefault();
            }
            
            handleMove(e) {
                if (!this.isDragging || !this.currentCard) return;
                
                const clientX = e.touches ? e.touches[0].clientX : e.clientX;
                this.currentX = clientX;
                
                const deltaX = this.currentX - this.startX;
                const rotation = deltaX * 0.1;
                
                this.currentCard.style.transform = `translateX(${deltaX}px) rotate(${rotation}deg)`;
                
                // Add visual feedback
                const opacity = Math.max(0.3, 1 - Math.abs(deltaX) / 200);
                this.currentCard.style.opacity = opacity;
            }
            
            handleEnd(e) {
                if (!this.isDragging || !this.currentCard) return;
                
                this.isDragging = false;
                this.currentCard.classList.remove('dragging');
                
                const deltaX = this.currentX - this.startX;
                const threshold = 100;
                
                if (Math.abs(deltaX) > threshold) {
                    // Swipe detected
                    if (deltaX > 0) {
                        this.swipeRight();
                    } else {
                        this.swipeLeft();
                    }
                } else {
                    // Return to original position
                    this.currentCard.style.transform = '';
                    this.currentCard.style.opacity = '1';
                }
                
                this.currentCard = null;
            }
            
            swipeLeft() {
                const card = this.cards[this.currentIndex];
                card.classList.add('swiped-left');
                this.nextCard();
            }
            
            swipeRight() {
                const card = this.cards[this.currentIndex];
                card.classList.add('swiped-right');
                this.nextCard();
            }
            
            nextCard() {
                setTimeout(() => {
                    this.cards[this.currentIndex].style.display = 'none';
                    this.currentIndex++;
                    this.updateCounter();
                    this.updateButton();
                    
                    // Animate remaining cards
                    this.cards.slice(this.currentIndex).forEach((card, index) => {
                        card.style.transform = `scale(${1 - index * 0.05}) translateY(${index * 10}px)`;
                        card.style.zIndex = this.cards.length - this.currentIndex - index;
                    });
                }, 300);
            }
            
            updateCounter() {
                const counter = document.getElementById('topicCounter');
                if (this.currentIndex < this.cards.length) {
                    counter.textContent = `${this.currentIndex + 1} of ${this.cards.length}`;
                } else {
                    counter.style.display = 'none';
                }
            }
            
            updateButton() {
                const button = document.getElementById('getPaidButton');
                if (this.currentIndex >= this.cards.length) {
                    button.textContent = 'No More Topics';
                    button.disabled = true;
                }
            }
            
            getCurrentTopic() {
                if (this.currentIndex < this.cards.length) {
                    return this.cards[this.currentIndex];
                }
                return null;
            }
        }
        
        // Initialize swiper
        const swiper = new TopicSwiper();
        
        // Side button handlers
        document.getElementById('swipeLeft').addEventListener('click', () => swiper.swipeLeft());
        document.getElementById('swipeRight').addEventListener('click', () => swiper.swipeRight());
        
        // Get Paid button handler - only works for funded topics
        document.getElementById('getPaidButton').addEventListener('click', function() {
            if (this.disabled) return;
            
            const currentTopic = swiper.getCurrentTopic();
            if (currentTopic) {
                // Check if current topic is funded
                const statusElement = currentTopic.querySelector('.topic-status');
                const isFunded = statusElement.textContent.includes('Funded') || statusElement.textContent.includes('hours left');
                
                if (isFunded) {
                    const topicTitle = currentTopic.querySelector('.topic-title').textContent;
                    document.getElementById('modalTopicTitle').textContent = topicTitle;
                    document.getElementById('modalTopicId').value = currentTopic.dataset.topicId;
                    document.getElementById('uploadModal').style.display = 'block';
                } else {
                    alert('This topic is not fully funded yet. You can only upload content for funded topics.');
                }
            }
        });
        
        // Form submission
        document.getElementById('uploadForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const url = document.getElementById('contentUrl').value;
            const topicId = document.getElementById('modalTopicId').value;
            
            if (!url) {
                alert('Please enter a video URL');
                return;
            }
            
            // Submit the form
            const submitBtn = this.querySelector('.modal-button.primary');
            submitBtn.textContent = 'Uploading...';
            submitBtn.disabled = true;
            
            // Create form data and submit
            const formData = new FormData(this);
            
            fetch('../creators/upload_content.php?topic=' + topicId, {
                method: 'POST',
                body: formData
            })
            .then(response => {
                // Check if redirect (successful upload usually redirects)
                if (response.redirected) {
                    window.location.href = response.url;
                    return;
                }
                return response.text();
            })
            .then(data => {
                if (data) {
                    // Check for success indicators in response
                    if (data.includes('successfully') || data.includes('completed')) {
                        alert('Content uploaded successfully! You\'ll receive payment soon.');
                        closeModal();
                        
                        // Remove current card and move to next
                        const currentTopic = swiper.getCurrentTopic();
                        if (currentTopic) {
                            currentTopic.classList.add('swiped-right');
                            swiper.nextCard();
                        }
                        
                        // Refresh after delay to show updated data
                        setTimeout(() => {
                            window.location.reload();
                        }, 1500);
                    } else if (data.includes('error') || data.includes('failed')) {
                        alert('Upload failed. Please check your video URL and try again.');
                    } else {
                        // Assume success if no clear error
                        alert('Content uploaded successfully!');
                        closeModal();
                        const currentTopic = swiper.getCurrentTopic();
                        if (currentTopic) {
                            currentTopic.classList.add('swiped-right');
                            swiper.nextCard();
                        }
                    }
                }
            })
            .catch(error => {
                console.error('Upload error:', error);
                alert('Upload failed. Please try again.');
            })
            .finally(() => {
                submitBtn.textContent = 'Upload & Get Paid';
                submitBtn.disabled = false;
            });
        });
        
        function closeModal() {
            document.getElementById('uploadModal').style.display = 'none';
            document.getElementById('uploadForm').reset();
        }
        
        // Close modal when clicking outside
        document.getElementById('uploadModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
        
        // Prevent context menu on mobile for better swipe experience
        document.addEventListener('contextmenu', function(e) {
            if (e.target.closest('.topic-card')) {
                e.preventDefault();
            }
        });
        
        // Initialize any topics that need special handling
        document.addEventListener('DOMContentLoaded', function() {
            <?php if (!empty($all_topics)): ?>
            console.log('Dashboard loaded with <?php echo count($all_topics); ?> topics');
            <?php endif; ?>
        });
    </script>
</body>
</html>
