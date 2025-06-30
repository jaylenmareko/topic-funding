<?php
// topics/create.php - Simplified topic creation
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
$creator = null;

// If creator_id provided, get creator info
if ($creator_id) {
    $creator = $helper->getCreatorById($creator_id);
    if (!$creator || $creator->platform_type !== 'youtube') {
        header('Location: ../creators/index.php');
        exit;
    }
}

$errors = [];
$success = '';

if ($_POST) {
    // CSRF Protection
    CSRFProtection::requireValidToken();
    
    $selected_creator_id = (int)$_POST['creator_id'];
    $title = InputSanitizer::sanitizeString($_POST['title']);
    $description = InputSanitizer::sanitizeString($_POST['description']);
    $funding_threshold = (float)$_POST['funding_threshold'];
    $initial_contribution = (float)$_POST['initial_contribution'];
    
    // Validation
    if (!$selected_creator_id) {
        $errors[] = "Please select a creator";
    }
    
    if (empty($title) || strlen($title) < 10) {
        $errors[] = "Topic title required (10+ characters)";
    }
    
    if (empty($description) || strlen($description) < 30) {
        $errors[] = "Description required (30+ characters)";
    }
    
    if ($funding_threshold < 10 || $funding_threshold > 1000) {
        $errors[] = "Funding goal must be $10-$1000";
    }
    
    if ($initial_contribution < 5 || $initial_contribution > $funding_threshold) {
        $errors[] = "Initial payment must be $5-" . $funding_threshold;
    } elseif ($initial_contribution < ($funding_threshold * 0.1)) {
        $errors[] = "Initial payment must be at least 10% of goal";
    }
    
    // Create Stripe payment if no errors
    if (empty($errors)) {
        try {
            $session = \Stripe\Checkout\Session::create([
                'payment_method_types' => ['card'],
                'line_items' => [[
                    'price_data' => [
                        'currency' => STRIPE_CURRENCY,
                        'product_data' => [
                            'name' => 'Create Topic: ' . $title,
                            'description' => 'Fund this topic idea',
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
                    'creator_id' => $selected_creator_id,
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
            
        } catch (Exception $e) {
            $errors[] = "Payment error. Please try again.";
            error_log("Topic creation error: " . $e->getMessage());
        }
    }
}

// Get all active YouTube creators
$db = new Database();
$db->query('SELECT * FROM creators WHERE is_active = 1 AND platform_type = "youtube" ORDER BY display_name');
$youtube_creators = $db->resultSet();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Create New Topic - TopicLaunch</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
        .container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .header { text-align: center; margin-bottom: 30px; }
        .header h1 { margin: 0 0 10px 0; color: #333; }
        .how-it-works { background: #e8f5e8; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
        .how-it-works h4 { margin-top: 0; color: #2d5f2d; }
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
        .back-link { color: #007bff; text-decoration: none; margin-bottom: 20px; display: inline-block; }
        .back-link:hover { text-decoration: underline; }
        small { color: #666; font-size: 14px; }
        
        @media (max-width: 600px) {
            .container { margin: 10px; padding: 20px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="index.php" class="back-link">← Back to Topics</a>

        <div class="header">
            <h1>💡 Create New Topic</h1>
            <p>Suggest a topic and fund it to make it happen!</p>
        </div>
        
        <div class="how-it-works">
            <h4>🚀 How it works:</h4>
            1. Choose a creator and topic<br>
            2. Make initial payment (topic goes live immediately)<br>
            3. Community funds the rest<br>
            4. Creator makes content within 48 hours
        </div>

        <?php if (!empty($errors)): ?>
            <?php foreach ($errors as $error): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if (empty($youtube_creators)): ?>
            <div style="text-align: center; padding: 40px; background: #f8f9fa; border-radius: 8px;">
                <h3>No Creators Available</h3>
                <p>We're onboarding YouTube creators. Check back soon!</p>
            </div>
        <?php else: ?>
            <form method="POST" id="topicForm">
                <?php echo CSRFProtection::getTokenField(); ?>
                
                <div class="form-group">
                    <label>Choose Creator:</label>
                    <select name="creator_id" id="creator_id" required>
                        <option value="">Select a YouTube creator</option>
                        <?php foreach ($youtube_creators as $c): ?>
                            <option value="<?php echo $c->id; ?>" 
                                    data-threshold="<?php echo $c->default_funding_threshold; ?>"
                                    <?php echo ($creator && $c->id == $creator->id) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c->display_name); ?> 
                                (<?php echo number_format($c->subscriber_count); ?> subs)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Topic Title:</label>
                    <input type="text" name="title" required minlength="10" maxlength="100" 
                           placeholder="What should they make a video about?"
                           value="<?php echo isset($_POST['title']) ? htmlspecialchars($_POST['title']) : ''; ?>">
                    <small>Be specific! Example: "How to edit like MrBeast"</small>
                </div>

                <div class="form-group">
                    <label>Description:</label>
                    <textarea name="description" required minlength="30" 
                              placeholder="Explain what you want them to cover..."><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
                    <small>What exactly do you want to see?</small>
                </div>

                <div class="form-group">
                    <label>Funding Goal ($):</label>
                    <input type="number" name="funding_threshold" id="funding_threshold" 
                           value="50" min="10" max="1000" step="0.01" required>
                    <small>How much should the community raise?</small>
                </div>

                <div class="form-group">
                    <label>Your Payment ($):</label>
                    <input type="number" name="initial_contribution" id="initial_contribution" 
                           min="5" step="0.01" required placeholder="25">
                    <small>Minimum $5 and at least 10% of goal</small>
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
                    💳 Create Topic & Pay
                </button>
            </form>
        <?php endif; ?>
    </div>

    <script>
    // Auto-fill funding threshold when creator selected
    document.getElementById('creator_id').addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        if (selectedOption.value) {
            const threshold = selectedOption.getAttribute('data-threshold');
            document.getElementById('funding_threshold').value = threshold;
            updatePreview();
        }
    });

    // Update preview in real-time
    function updatePreview() {
        const goal = parseFloat(document.getElementById('funding_threshold').value) || 0;
        const payment = parseFloat(document.getElementById('initial_contribution').value) || 0;
        const remaining = Math.max(0, goal - payment);
        const percentage = goal > 0 ? Math.round((payment / goal) * 100) : 0;

        document.getElementById('goalAmount').textContent = '$' + goal.toFixed(0);
        document.getElementById('yourPayment').textContent = '$' + payment.toFixed(2);
        document.getElementById('remaining').textContent = '$' + remaining.toFixed(2);
        document.getElementById('percentage').textContent = percentage + '%';

        // Show preview if values are entered
        if (goal > 0 && payment > 0) {
            document.getElementById('fundingPreview').style.display = 'block';
        }

        // Validate
        const submitBtn = document.getElementById('submitBtn');
        const minPayment = goal * 0.1;
        
        if (payment >= 5 && payment >= minPayment && payment <= goal) {
            submitBtn.disabled = false;
            submitBtn.style.opacity = '1';
        } else {
            submitBtn.disabled = true;
            submitBtn.style.opacity = '0.6';
        }
    }

    document.getElementById('funding_threshold').addEventListener('input', updatePreview);
    document.getElementById('initial_contribution').addEventListener('input', updatePreview);

    // Form submission
    document.getElementById('topicForm').addEventListener('submit', function(e) {
        const payment = parseFloat(document.getElementById('initial_contribution').value);
        const goal = parseFloat(document.getElementById('funding_threshold').value);

        if (!confirm(`Create topic with $${payment.toFixed(2)} payment?\n\nTopic will go live immediately!`)) {
            e.preventDefault();
            return;
        }

        document.getElementById('submitBtn').innerHTML = '⏳ Processing...';
        document.getElementById('submitBtn').disabled = true;
    });

    // Initial validation
    updatePreview();
    </script>
</body>
</html>
