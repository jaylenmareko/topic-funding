<?php
// topics/create.php - Simplified topic creation with pre-selected creator
session_start();
require_once '../config/database.php';
require_once '../config/stripe.php';
require_once '../config/csrf.php';
require_once '../config/sanitizer.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$helper = new DatabaseHelper();
$creator_id = isset($_GET['creator_id']) ? (int)$_GET['creator_id'] : 0;

// Creator ID is required - redirect if not provided
if (!$creator_id) {
    header('Location: ../creators/index.php');
    exit;
}

// Get creator info
$creator = $helper->getCreatorById($creator_id);
if (!$creator || $creator->platform_type !== 'youtube') {
    header('Location: ../creators/index.php');
    exit;
}

$errors = [];
$success = '';

if ($_POST) {
    // CSRF Protection
    CSRFProtection::requireValidToken();
    
    $title = InputSanitizer::sanitizeString($_POST['title']);
    $description = InputSanitizer::sanitizeString($_POST['description']);
    $funding_threshold = (float)$_POST['funding_threshold'];
    $initial_contribution = (float)$_POST['initial_contribution'];
    
    // Validation
    if (empty($title) || strlen($title) < 10) {
        $errors[] = "Topic title required (10+ characters)";
    }
    
    if (empty($description) || strlen($description) < 30) {
        $errors[] = "Description required (30+ characters)";
    }
    
    if ($funding_threshold < 0 || $funding_threshold > 1000) {
        $errors[] = "Funding goal must be $0-$1000 (Set $0 for free topics!)";
    }
    
    // Handle $0 topics (free topics)
    if ($funding_threshold == 0) {
        $initial_contribution = 0; // No payment needed for free topics
    } else {
        // Validation for paid topics
        if ($initial_contribution < 5 || $initial_contribution > $funding_threshold) {
            $errors[] = "Initial payment must be $5-" . $funding_threshold;
        } elseif ($initial_contribution < ($funding_threshold * 0.1)) {
            $errors[] = "Initial payment must be at least 10% of goal";
        }
    }
    
    // Process topic creation if no errors
    if (empty($errors)) {
        try {
            if ($funding_threshold == 0) {
                // Create free topic directly - no payment needed
                $db = new Database();
                $db->beginTransaction();
                
                // Create the topic (status = funded, no approval needed for free topics)
                $db->query('
                    INSERT INTO topics (creator_id, initiator_user_id, title, description, funding_threshold, status, current_funding, created_at) 
                    VALUES (:creator_id, :user_id, :title, :description, 0, "funded", 0, NOW())
                ');
                $db->bind(':creator_id', $creator_id);
                $db->bind(':user_id', $_SESSION['user_id']);
                $db->bind(':title', $title);
                $db->bind(':description', $description);
                $db->execute();
                
                $topic_id = $db->lastInsertId();
                
                // Set content deadline for free topic (48 hours)
                $db->query('
                    UPDATE topics 
                    SET funded_at = NOW(), content_deadline = DATE_ADD(NOW(), INTERVAL 48 HOUR)
                    WHERE id = :topic_id
                ');
                $db->bind(':topic_id', $topic_id);
                $db->execute();
                
                $db->endTransaction();
                
                // Redirect to creator profile to see the waiting upload topic
                header('Location: ../creators/profile.php?id=' . $creator_id);
                exit;
                
            } else {
                // Create paid topic with Stripe payment
                $session = \Stripe\Checkout\Session::create([
                    'payment_method_types' => ['card'],
                    'line_items' => [[
                        'price_data' => [
                            'currency' => STRIPE_CURRENCY,
                            'product_data' => [
                                'name' => 'Create Topic: ' . $title,
                                'description' => 'Fund this topic idea for ' . $creator->display_name,
                            ],
                            'unit_amount' => $initial_contribution * 100,
                        ],
                        'quantity' => 1,
                    ]],
                    'mode' => 'payment',
                    'success_url' => 'https://topiclaunch.com/payment_success.php?session_id={CHECKOUT_SESSION_ID}&type=topic_creation',
                    'cancel_url' => 'https://topiclaunch.com/payment_cancelled.php?type=topic_creation',
                    'metadata' => [
                        'type' => 'topic_creation',
                        'creator_id' => $creator_id,
                        'user_id' => $_SESSION['user_id'],
                        'title' => $title,
                        'description' => $description,
                        'funding_threshold' => $funding_threshold,
                        'initial_contribution' => $initial_contribution
                    ],
                    'customer_email' => $_SESSION['email'] ?? null,
                ]);
                
                header('Location: ' . $session->url);
                exit;
            }
            
        } catch (Exception $e) {
            $errors[] = "Topic creation error. Please try again.";
            error_log("Topic creation error: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Create New Topic for <?php echo htmlspecialchars($creator->display_name); ?> - TopicLaunch</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
        .container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .header { text-align: center; margin-bottom: 30px; }
        .header h1 { margin: 0 0 10px 0; color: #333; }
        .back-link { color: #007bff; text-decoration: none; margin-bottom: 20px; display: inline-block; }
        .back-link:hover { text-decoration: underline; }
        .creator-info { background: #e3f2fd; padding: 20px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 15px; }
        .creator-avatar { width: 60px; height: 60px; background: linear-gradient(135deg, #667eea, #764ba2); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 24px; color: white; font-weight: bold; }
        .creator-details h3 { margin: 0 0 5px 0; color: #1976d2; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: bold; color: #333; }
        input, select, textarea { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 6px; box-sizing: border-box; font-size: 16px; }
        textarea { height: 100px; resize: vertical; }
        .btn { background: #28a745; color: white; padding: 15px 30px; border: none; border-radius: 6px; cursor: pointer; font-size: 16px; width: 100%; font-weight: bold; }
        .btn:hover { background: #218838; }
        .btn:disabled { background: #6c757d; cursor: not-allowed; }
        .error { color: red; margin-bottom: 15px; padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 6px; }
        .funding-preview { background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0; }
        .funding-stats { display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; }
        .stat { text-align: center; padding: 15px; background: white; border-radius: 6px; }
        .stat-number { font-size: 18px; font-weight: bold; color: #28a745; }
        .stat-label { font-size: 12px; color: #666; }
        small { color: #666; font-size: 14px; }
        
        @media (max-width: 600px) {
            .container { margin: 10px; padding: 20px; }
            .creator-info { flex-direction: column; text-align: center; }
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="../creators/profile.php?id=<?php echo $creator->id; ?>" class="back-link">← Back to <?php echo htmlspecialchars($creator->display_name); ?></a>

        <div class="header">
            <h1>💡 Create New Topic</h1>
        </div>
        
        <!-- Creator Info -->
        <div class="creator-info">
            <div class="creator-avatar">
                <?php if ($creator->profile_image && file_exists('../uploads/creators/' . $creator->profile_image)): ?>
                    <img src="../uploads/creators/<?php echo htmlspecialchars($creator->profile_image); ?>" 
                         alt="Profile" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                <?php else: ?>
                    <?php echo strtoupper(substr($creator->display_name, 0, 1)); ?>
                <?php endif; ?>
            </div>
            <div class="creator-details">
                <h3>Creating topic for: <?php echo htmlspecialchars($creator->display_name); ?></h3>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <?php foreach ($errors as $error): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endforeach; ?>
        <?php endif; ?>

        <form method="POST" id="topicForm">
            <?php echo CSRFProtection::getTokenField(); ?>
            
            <!-- Hidden creator ID -->
            <input type="hidden" name="creator_id" value="<?php echo $creator->id; ?>">

            <div class="form-group">
                <label>Topic Title:</label>
                <input type="text" name="title" required minlength="10" maxlength="100" 
                       placeholder="What should they make a video about?"
                       value="<?php echo isset($_POST['title']) ? htmlspecialchars($_POST['title']) : ''; ?>">
            </div>

            <div class="form-group">
                <label>Description:</label>
                <textarea name="description" required minlength="30" 
                          placeholder="Explain what you want them to cover..."><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
            </div>

            <div class="form-group">
                <label>Funding Goal ($):</label>
                <input type="number" name="funding_threshold" id="funding_threshold" 
                       value="0" min="0" max="1000" step="1" required>
                <small>💡 Set to $0 to try the platform free! Creator gets exposure, you get content risk-free.</small>
            </div>

            <div class="form-group" id="paymentSection">
                <label>Your Payment ($):</label>
                <input type="number" name="initial_contribution" id="initial_contribution" 
                       min="0" step="1" placeholder="0 for free topic">
                <small>Minimum $5 for paid topics, or $0 for free</small>
            </div>

            <div class="funding-preview" id="fundingPreview" style="display: none;">
                <h4>💰 Funding Summary</h4>
                <div class="funding-stats">
                    <div class="stat">
                        <div class="stat-number" id="goalAmount">$0</div>
                        <div class="stat-label">Goal</div>
                    </div>
                    <div class="stat">
                        <div class="stat-number" id="yourPayment">$0</div>
                        <div class="stat-label">Your Payment</div>
                    </div>
                    <div class="stat">
                        <div class="stat-number" id="remaining">$0</div>
                        <div class="stat-label">Community Funds</div>
                    </div>
                    <div class="stat">
                        <div class="stat-number" id="percentage">0%</div>
                        <div class="stat-label">Initially Funded</div>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn" id="submitBtn">
                🆓 Create Free Topic
            </button>
        </form>
    </div>

    <script>
    // Only update preview when funding threshold changes
    document.getElementById('funding_threshold').addEventListener('input', function() {
        const goal = parseInt(this.value) || 0;
        
        // Only auto-set payment to 0 for free topics
        if (goal == 0) {
            document.getElementById('initial_contribution').value = '0';
        }
        
        updatePreview();
    });

    // Track when user types in payment field
    document.getElementById('initial_contribution').addEventListener('input', function() {
        updatePreview();
    });

    // Simple preview update function
    function updatePreview() {
        const goal = parseInt(document.getElementById('funding_threshold').value) || 0;
        const payment = parseInt(document.getElementById('initial_contribution').value) || 0;
        const remaining = Math.max(0, goal - payment);
        const percentage = goal > 0 ? Math.round((payment / goal) * 100) : 0;

        // Update display only
        document.getElementById('goalAmount').textContent = '$' + goal;
        document.getElementById('yourPayment').textContent = '$' + payment;
        document.getElementById('remaining').textContent = '$' + remaining;
        document.getElementById('percentage').textContent = percentage + '%';

        // Show/hide preview
        if (goal > 0 && payment > 0) {
            document.getElementById('fundingPreview').style.display = 'block';
        } else {
            document.getElementById('fundingPreview').style.display = 'none';
        }

        // Update button
        const submitBtn = document.getElementById('submitBtn');
        if (goal == 0) {
            submitBtn.textContent = '🆓 Create Free Topic';
            submitBtn.disabled = false;
            submitBtn.style.opacity = '1';
        } else {
            submitBtn.textContent = '💳 Create Topic & Pay';
            const minPayment = Math.max(5, goal * 0.1);
            
            if (payment >= minPayment && payment <= goal) {
                submitBtn.disabled = false;
                submitBtn.style.opacity = '1';
            } else {
                submitBtn.disabled = true;
                submitBtn.style.opacity = '0.6';
            }
        }
    }

    // Form submission
    document.getElementById('topicForm').addEventListener('submit', function(e) {
        const goal = parseInt(document.getElementById('funding_threshold').value);
        const payment = parseInt(document.getElementById('initial_contribution').value);

        if (goal == 0) {
            if (!confirm(`Create FREE topic for <?php echo addslashes($creator->display_name); ?>?\n\nNo payment needed - topic will go live immediately!`)) {
                e.preventDefault();
                return;
            }
            document.getElementById('submitBtn').innerHTML = '⏳ Creating Free Topic...';
        } else {
            if (!confirm(`Create topic for <?php echo addslashes($creator->display_name); ?> with $${payment} payment?\n\nTopic will go live immediately!`)) {
                e.preventDefault();
                return;
            }
            document.getElementById('submitBtn').innerHTML = '⏳ Processing Payment...';
        }

        document.getElementById('submitBtn').disabled = true;
    });

    // Initial update
    updatePreview();
    </script>
</body>
</html>
