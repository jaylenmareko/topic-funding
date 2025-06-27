<?php
// topics/create.php - Updated with initial payment requirement and YouTube only
session_start();
require_once '../config/database.php';
require_once '../config/stripe.php';
require_once '../config/notification_system.php';
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
        $errors[] = "Please select a YouTube creator";
    }
    
    if (empty($title)) {
        $errors[] = "Topic title is required";
    } elseif (strlen($title) < 10) {
        $errors[] = "Topic title must be at least 10 characters";
    }
    
    if (empty($description)) {
        $errors[] = "Topic description is required";
    } elseif (strlen($description) < 50) {
        $errors[] = "Description must be at least 50 characters";
    }
    
    if ($funding_threshold < 10) {
        $errors[] = "Minimum funding threshold is $10";
    } elseif ($funding_threshold > 1000) {
        $errors[] = "Maximum funding threshold is $1,000";
    }
    
    if ($initial_contribution < 5) {
        $errors[] = "Minimum initial contribution is $5";
    } elseif ($initial_contribution > $funding_threshold) {
        $errors[] = "Initial contribution cannot exceed the funding threshold";
    } elseif ($initial_contribution < ($funding_threshold * 0.1)) {
        $errors[] = "Initial contribution must be at least 10% of the funding goal";
    }
    
    // Verify creator is YouTube only
    $db = new Database();
    $db->query('SELECT platform_type FROM creators WHERE id = :creator_id AND is_active = 1');
    $db->bind(':creator_id', $selected_creator_id);
    $creator_check = $db->single();
    
    if (!$creator_check || $creator_check->platform_type !== 'youtube') {
        $errors[] = "Invalid YouTube creator selected";
    }
    
    // Create topic and process initial payment if no errors
    if (empty($errors)) {
        try {
            // Create Stripe Checkout Session for initial payment
            $session = \Stripe\Checkout\Session::create([
                'payment_method_types' => ['card'],
                'line_items' => [[
                    'price_data' => [
                        'currency' => STRIPE_CURRENCY,
                        'product_data' => [
                            'name' => 'Initial Funding: ' . $title,
                            'description' => 'Initial contribution to propose this topic',
                        ],
                        'unit_amount' => $initial_contribution * 100, // Convert to cents
                    ],
                    'quantity' => 1,
                ]],
                'mode' => 'payment',
                'success_url' => STRIPE_SUCCESS_URL . '?topic_creation=1&creator_id=' . $selected_creator_id . '&amount=' . $initial_contribution . '&session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => STRIPE_CANCEL_URL . '?topic_creation=1',
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
            
            // Store topic data in session for creation after payment
            $_SESSION['pending_topic'] = [
                'creator_id' => $selected_creator_id,
                'title' => $title,
                'description' => $description,
                'funding_threshold' => $funding_threshold,
                'initial_contribution' => $initial_contribution
            ];
            
            // Redirect to Stripe Checkout
            header('Location: ' . $session->url);
            exit;
            
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $errors[] = "Payment processing error. Please try again.";
            error_log("Stripe error for topic creation: " . $e->getMessage());
        } catch (Exception $e) {
            $errors[] = "An error occurred. Please try again.";
            error_log("Topic creation error: " . $e->getMessage());
        }
    }
}

// Get all active YouTube creators for dropdown
$db = new Database();
$db->query('SELECT * FROM creators WHERE is_active = 1 AND platform_type = "youtube" ORDER BY display_name');
$youtube_creators = $db->resultSet();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Propose New Topic - TopicLaunch</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 700px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: bold; color: #333; }
        input, select, textarea { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 6px; box-sizing: border-box; font-size: 16px; }
        textarea { height: 120px; resize: vertical; }
        .btn { background: #28a745; color: white; padding: 15px 30px; border: none; border-radius: 6px; cursor: pointer; font-size: 16px; width: 100%; }
        .btn:hover { background: #218838; }
        .btn:disabled { background: #6c757d; cursor: not-allowed; }
        .error { color: red; margin-bottom: 15px; padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; }
        .success { color: green; margin-bottom: 20px; padding: 15px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; }
        .nav { margin-bottom: 20px; }
        .nav a { color: #007bff; text-decoration: none; margin-right: 15px; }
        .how-it-works { background: #e3f2fd; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
        .how-it-works h4 { margin-top: 0; color: #1976d2; }
        .funding-preview { background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0; }
        .funding-preview h4 { margin-top: 0; color: #495057; }
        .funding-breakdown { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin: 15px 0; }
        .breakdown-item { text-align: center; padding: 15px; background: white; border-radius: 6px; }
        .breakdown-number { font-size: 18px; font-weight: bold; color: #28a745; }
        .breakdown-label { font-size: 12px; color: #666; }
        .youtube-only { background: #ff0000; color: white; padding: 8px 16px; border-radius: 20px; font-size: 14px; font-weight: bold; margin-bottom: 20px; display: inline-block; }
        .requirement { background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 6px; margin-bottom: 20px; }
        .requirement h4 { margin-top: 0; color: #856404; }
        
        @media (max-width: 600px) {
            .container { margin: 10px; padding: 20px; }
            .funding-breakdown { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="nav">
            <a href="../topics/index.php">← Back to Topics</a>
            <a href="../creators/index.php">Browse Creators</a>
            <a href="../index.php">Home</a>
        </div>

        <div class="youtube-only">📺 YouTube Creators Only</div>

        <h1>Propose New Topic</h1>
        
        <div class="how-it-works">
            <h4>🚀 How Topic Creation Works:</h4>
            <ol style="margin: 10px 0;">
                <li><strong>Propose & Pay:</strong> Submit your topic idea with an initial contribution (minimum 10% of goal)</li>
                <li><strong>Creator Review:</strong> YouTube creator reviews and approves your proposal</li>
                <li><strong>Community Funding:</strong> If approved, topic goes live for others to fund</li>
                <li><strong>Content Creation:</strong> Once funded, creator has 48 hours to deliver</li>
            </ol>
        </div>

        <div class="requirement">
            <h4>💰 Initial Payment Required</h4>
            <p><strong>You must make the first contribution to create this topic.</strong> This ensures only serious proposals get created and shows the creator there's real demand.</p>
            <ul style="margin: 10px 0;">
                <li>Minimum $5 initial contribution</li>
                <li>Must be at least 10% of the funding goal</li>
                <li>Your payment makes the topic visible to others</li>
                <li>Full refund if creator rejects your proposal</li>
            </ul>
        </div>

        <?php if (!empty($errors)): ?>
            <?php foreach ($errors as $error): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <?php if (empty($youtube_creators)): ?>
            <div style="text-align: center; padding: 40px; background: #f8f9fa; border-radius: 8px;">
                <h3>No YouTube Creators Yet</h3>
                <p>We're currently onboarding YouTube creators. Check back soon!</p>
                <a href="../creators/apply.php" style="background: #ff0000; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px;">Apply as YouTube Creator</a>
            </div>
        <?php else: ?>
            <form method="POST" id="topicForm">
                <?php echo CSRFProtection::getTokenField(); ?>
                
                <div class="form-group">
                    <label>YouTube Creator:</label>
                    <select name="creator_id" id="creator_id" required>
                        <option value="">Select a YouTube creator</option>
                        <?php foreach ($youtube_creators as $c): ?>
                            <option value="<?php echo $c->id; ?>" 
                                    data-threshold="<?php echo $c->default_funding_threshold; ?>"
                                    <?php echo ($creator && $c->id == $creator->id) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c->display_name); ?> 
                                (<?php echo number_format($c->subscriber_count); ?> subscribers)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Topic Title:</label>
                    <input type="text" name="title" id="title" required minlength="10" maxlength="100" 
                           placeholder="What specific topic do you want this creator to cover?"
                           value="<?php echo isset($_POST['title']) ? htmlspecialchars($_POST['title']) : ''; ?>">
                    <small style="color: #666;">Be specific! Good: "How to edit videos like MrBeast" Bad: "Video editing tips"</small>
                </div>

                <div class="form-group">
                    <label>Detailed Description:</label>
                    <textarea name="description" id="description" required minlength="50" 
                              placeholder="Provide specific details about what you want covered..."><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
                    <small style="color: #666;">Minimum 50 characters. Be specific about what you want to learn.</small>
                </div>

                <div class="form-group">
                    <label>Funding Goal ($):</label>
                    <input type="number" name="funding_threshold" id="funding_threshold" 
                           value="<?php echo $creator ? $creator->default_funding_threshold : 50; ?>" 
                           min="10" max="1000" step="0.01" required>
                    <small style="color: #666;">How much should the community raise to fund this topic?</small>
                </div>

                <div class="form-group">
                    <label>Your Initial Contribution ($):</label>
                    <input type="number" name="initial_contribution" id="initial_contribution" 
                           value="<?php echo isset($_POST['initial_contribution']) ? $_POST['initial_contribution'] : ''; ?>" 
                           min="5" step="0.01" required>
                    <small style="color: #666;">Minimum $5 and at least 10% of the funding goal</small>
                </div>

                <div class="funding-preview" id="fundingPreview" style="display: none;">
                    <h4>💰 Funding Breakdown</h4>
                    <div class="funding-breakdown">
                        <div class="breakdown-item">
                            <div class="breakdown-number" id="goalAmount">$0</div>
                            <div class="breakdown-label">Funding Goal</div>
                        </div>
                        <div class="breakdown-item">
                            <div class="breakdown-number" id="yourContribution">$0</div>
                            <div class="breakdown-label">Your Contribution</div>
                        </div>
                        <div class="breakdown-item">
                            <div class="breakdown-number" id="remainingNeeded">$0</div>
                            <div class="breakdown-label">Community Needs</div>
                        </div>
                        <div class="breakdown-item">
                            <div class="breakdown-number" id="percentageFunded">0%</div>
                            <div class="breakdown-label">Initially Funded</div>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn" id="submitBtn">
                    💳 Pay & Create Topic
                </button>
            </form>
        <?php endif; ?>
    </div>

    <script>
    // Auto-fill funding threshold when creator is selected
    document.getElementById('creator_id').addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        if (selectedOption.value) {
            const threshold = selectedOption.getAttribute('data-threshold');
            document.getElementById('funding_threshold').value = threshold;
            updateFundingPreview();
        }
    });

    // Update funding preview in real-time
    function updateFundingPreview() {
        const goal = parseFloat(document.getElementById('funding_threshold').value) || 0;
        const contribution = parseFloat(document.getElementById('initial_contribution').value) || 0;
        const remaining = Math.max(0, goal - contribution);
        const percentage = goal > 0 ? Math.round((contribution / goal) * 100) : 0;

        document.getElementById('goalAmount').textContent = '$' + goal.toFixed(0);
        document.getElementById('yourContribution').textContent = '$' + contribution.toFixed(2);
        document.getElementById('remainingNeeded').textContent = '$' + remaining.toFixed(2);
        document.getElementById('percentageFunded').textContent = percentage + '%';

        // Show preview if values are entered
        if (goal > 0 && contribution > 0) {
            document.getElementById('fundingPreview').style.display = 'block';
        }

        // Validate minimum contribution (10% of goal)
        const submitBtn = document.getElementById('submitBtn');
        const minContribution = goal * 0.1;
        
        if (contribution >= 5 && contribution >= minContribution && contribution <= goal) {
            submitBtn.disabled = false;
            submitBtn.style.opacity = '1';
        } else {
            submitBtn.disabled = true;
            submitBtn.style.opacity = '0.6';
        }
    }

    // Real-time validation
    document.getElementById('funding_threshold').addEventListener('input', updateFundingPreview);
    document.getElementById('initial_contribution').addEventListener('input', updateFundingPreview);

    // Form submission confirmation
    document.getElementById('topicForm').addEventListener('submit', function(e) {
        const contribution = parseFloat(document.getElementById('initial_contribution').value);
        const goal = parseFloat(document.getElementById('funding_threshold').value);
        const creator = document.getElementById('creator_id').options[document.getElementById('creator_id').selectedIndex].text;

        if (!confirm(`Create topic with $${contribution.toFixed(2)} initial payment?\n\nCreator: ${creator}\nTotal Goal: $${goal.toFixed(2)}\n\nYou'll be redirected to secure payment.`)) {
            e.preventDefault();
            return;
        }

        // Show loading state
        document.getElementById('submitBtn').innerHTML = '⏳ Processing...';
        document.getElementById('submitBtn').disabled = true;
    });

    // Initial validation
    updateFundingPreview();
    </script>
</body>
</html>
