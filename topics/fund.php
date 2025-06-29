<?php
// topics/fund.php - Updated to use webhooks instead of redirects
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
$topic_id = isset($_GET['id']) ? InputSanitizer::sanitizeInt($_GET['id']) : 0;

if (!$topic_id) {
    header('Location: index.php');
    exit;
}

$topic = $helper->getTopicById($topic_id);
if (!$topic) {
    header('Location: index.php');
    exit;
}

// Check if topic is still active (can be funded)
if ($topic->status !== 'active') {
    header('Location: view.php?id=' . $topic_id);
    exit;
}

$errors = [];
$success = '';

if ($_POST) {
    // CSRF Protection
    CSRFProtection::requireValidToken();
    
    // Rate limiting - max 5 funding attempts per hour per user
    $rate_limit_key = 'funding_attempts_' . $_SESSION['user_id'];
    $current_attempts = $_SESSION[$rate_limit_key] ?? 0;
    $last_attempt_time = $_SESSION[$rate_limit_key . '_time'] ?? 0;
    
    // Reset counter if more than 1 hour has passed
    if (time() - $last_attempt_time > 3600) {
        $current_attempts = 0;
    }
    
    if ($current_attempts >= 5) {
        $errors[] = "Too many funding attempts. Please try again in an hour.";
    } else {
        $amount = InputSanitizer::sanitizeFloat($_POST['amount']);
        
        // Validation
        if ($amount < 1) {
            $errors[] = "Minimum contribution is $1";
        } elseif ($amount > 1000) {
            $errors[] = "Maximum contribution is $1,000";
        } elseif (!is_numeric($amount)) {
            $errors[] = "Please enter a valid amount";
        }
        
        // Check if user has already contributed to this topic (optional limit)
        $db = new Database();
        $db->query('SELECT COUNT(*) as count FROM contributions WHERE topic_id = :topic_id AND user_id = :user_id');
        $db->bind(':topic_id', $topic_id);
        $db->bind(':user_id', $_SESSION['user_id']);
        $existing_contributions = $db->single()->count;
        
        if ($existing_contributions >= 3) {
            $errors[] = "You can only contribute up to 3 times per topic.";
        }
        
        // Process payment if no errors
        if (empty($errors)) {
            try {
                // Update rate limiting
                $_SESSION[$rate_limit_key] = $current_attempts + 1;
                $_SESSION[$rate_limit_key . '_time'] = time();
                
                // Create Stripe Checkout Session with webhook URLs
                $session = \Stripe\Checkout\Session::create([
                    'payment_method_types' => ['card'],
                    'line_items' => [[
                        'price_data' => [
                            'currency' => STRIPE_CURRENCY,
                            'product_data' => [
                                'name' => 'Fund Topic: ' . $topic->title,
                                'description' => 'Contribution to fund this topic by ' . $topic->creator_name,
                            ],
                            'unit_amount' => $amount * 100, // Stripe expects cents
                        ],
                        'quantity' => 1,
                    ]],
                    'mode' => 'payment',
                    // Use simple success/cancel URLs that don't trigger Mod_Security
                    'success_url' => 'https://topiclaunch.com/payment_success.php?session_id={CHECKOUT_SESSION_ID}&type=topic_funding&topic_id=' . $topic_id,
                    'cancel_url' => 'https://topiclaunch.com/payment_cancelled.php?type=topic_funding&topic_id=' . $topic_id,
                    'metadata' => [
                        'type' => 'topic_funding',
                        'topic_id' => $topic_id,
                        'user_id' => $_SESSION['user_id'],
                        'amount' => $amount,
                    ],
                    'customer_email' => $_SESSION['email'] ?? null,
                ]);
                
                // Redirect to Stripe Checkout
                header('Location: ' . $session->url);
                exit;
                
            } catch (\Stripe\Exception\ApiErrorException $e) {
                $errors[] = "Payment processing error. Please try again.";
                error_log("Stripe error for user " . $_SESSION['user_id'] . ": " . $e->getMessage());
            } catch (Exception $e) {
                $errors[] = "An error occurred. Please try again.";
                error_log("Funding error for user " . $_SESSION['user_id'] . ": " . $e->getMessage());
            }
        }
    }
}

// Calculate funding progress
$progress_percent = ($topic->current_funding / $topic->funding_threshold) * 100;
$progress_percent = min($progress_percent, 100);
$remaining = max(0, $topic->funding_threshold - $topic->current_funding);

// Get recent contributions
$contributions = $helper->getTopicContributions($topic_id);

// Suggested contribution amounts
$suggested_amounts = [5, 10, 25, 50];
if ($remaining > 0 && $remaining <= 500) {
    $suggested_amounts[] = $remaining; // Add "fund the rest" option
}
$suggested_amounts = array_unique($suggested_amounts);
sort($suggested_amounts);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Fund Topic: <?php echo htmlspecialchars($topic->title); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; }
        .nav { margin-bottom: 20px; }
        .nav a { color: #007bff; text-decoration: none; margin-right: 15px; }
        .nav a:hover { text-decoration: underline; }
        .topic-summary { background: white; padding: 25px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .topic-title { font-size: 24px; font-weight: bold; margin: 0 0 15px 0; color: #333; }
        .creator-info { display: flex; align-items: center; gap: 15px; margin-bottom: 20px; }
        .creator-avatar { width: 50px; height: 50px; background: #ddd; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 20px; color: #666; }
        .funding-progress { background: #e9ecef; height: 10px; border-radius: 5px; margin: 20px 0; }
        .funding-bar { background: #28a745; height: 100%; border-radius: 5px; transition: width 0.3s; }
        .funding-stats { display: flex; justify-content: space-between; margin: 15px 0; }
        .funding-form { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .form-section { margin-bottom: 25px; }
        .form-section h3 { margin-top: 0; color: #333; }
        .amount-buttons { display: grid; grid-template-columns: repeat(auto-fit, minmax(80px, 1fr)); gap: 10px; margin-bottom: 20px; }
        .amount-btn { padding: 12px; border: 2px solid #007bff; background: white; color: #007bff; border-radius: 4px; cursor: pointer; text-align: center; font-weight: bold; }
        .amount-btn:hover, .amount-btn.active { background: #007bff; color: white; }
        .custom-amount { margin-bottom: 20px; }
        .custom-amount input { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 16px; box-sizing: border-box; }
        .btn { background: #28a745; color: white; padding: 15px 30px; border: none; border-radius: 4px; cursor: pointer; font-size: 18px; width: 100%; }
        .btn:hover { background: #218838; }
        .btn:disabled { background: #6c757d; cursor: not-allowed; }
        .error { color: red; margin-bottom: 15px; padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; }
        .success { color: green; margin-bottom: 20px; padding: 15px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; }
        .payment-note { background: #e3f2fd; padding: 15px; border-radius: 4px; margin-bottom: 20px; font-size: 14px; }
        .recent-contributors { margin-top: 30px; }
        .contributor-item { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid #eee; }
        .contributor-item:last-child { border-bottom: none; }
        .contributor-info { display: flex; align-items: center; gap: 10px; }
        .contributor-avatar { width: 30px; height: 30px; background: #667eea; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; color: white; font-weight: bold; }
        .contributor-name { font-weight: bold; }
        .contribution-amount { color: #28a745; font-weight: bold; }
        .contribution-date { font-size: 12px; color: #666; }
        .empty-contributions { text-align: center; color: #666; padding: 20px; }
        .stripe-powered { text-align: center; margin-top: 15px; color: #666; font-size: 12px; }
        .secure-badge { display: flex; align-items: center; justify-content: center; gap: 5px; margin-bottom: 15px; color: #28a745; }
        .security-features { background: #f8f9fa; padding: 15px; border-radius: 4px; margin-bottom: 20px; font-size: 14px; }
        .rate-limit-warning { background: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; border-radius: 4px; margin-bottom: 15px; font-size: 14px; }
        .webhook-info { background: #e8f5e8; padding: 15px; border-radius: 6px; margin-bottom: 20px; font-size: 14px; }
        
        @media (max-width: 768px) {
            .container { padding: 10px; }
            .topic-summary, .funding-form { padding: 20px; }
            .amount-buttons { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="nav">
            <a href="view.php?id=<?php echo $topic->id; ?>">← Back to Topic</a>
            <a href="index.php">All Topics</a>
            <a href="../index.php">Home</a>
        </div>

        <div class="topic-summary">
            <h1 class="topic-title"><?php echo htmlspecialchars($topic->title); ?></h1>
            
            <div class="creator-info">
                <div class="creator-avatar">
                    <?php if ($topic->creator_image): ?>
                        <img src="../uploads/creators/<?php echo htmlspecialchars($topic->creator_image); ?>" alt="Creator" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                    <?php else: ?>
                        <?php echo strtoupper(substr($topic->creator_name, 0, 1)); ?>
                    <?php endif; ?>
                </div>
                <div>
                    <strong><?php echo htmlspecialchars($topic->creator_name); ?></strong>
                    <div style="color: #666; font-size: 14px;">Content Creator</div>
                </div>
            </div>

            <div class="funding-progress">
                <div class="funding-bar" style="width: <?php echo $progress_percent; ?>%"></div>
            </div>

            <div class="funding-stats">
                <div><strong>$<?php echo number_format($topic->current_funding, 2); ?></strong> raised</div>
                <div><strong>$<?php echo number_format($remaining, 2); ?></strong> remaining</div>
                <div><strong><?php echo round($progress_percent); ?>%</strong> complete</div>
            </div>
        </div>

        <div class="funding-form">
            <?php if (!empty($errors)): ?>
                <?php foreach ($errors as $error): ?>
                    <div class="error"><?php echo htmlspecialchars($error); ?></div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <?php
            // Show rate limit warning if approaching limit
            $rate_limit_key = 'funding_attempts_' . $_SESSION['user_id'];
            $current_attempts = $_SESSION[$rate_limit_key] ?? 0;
            if ($current_attempts >= 3):
            ?>
            <div class="rate-limit-warning">
                ⚠️ You have <?php echo 5 - $current_attempts; ?> funding attempts remaining this hour.
            </div>
            <?php endif; ?>

            <?php if ($topic->status === 'active'): ?>
                <div class="webhook-info">
                    <strong>🔧 Enhanced Payment System:</strong> Now using webhook-based payment processing for improved reliability and bypassing server restrictions.
                </div>

                <div class="security-features">
                    🛡️ <strong>Security Features:</strong> CSRF protection, rate limiting, input validation, and secure webhook payment processing.
                </div>

                <div class="secure-badge">
                    <span>🔒</span>
                    <span>Secure payment powered by Stripe + Webhooks</span>
                </div>

                <form method="POST" id="fundingForm">
                    <?php echo CSRFProtection::getTokenField(); ?>
                    
                    <div class="form-section">
                        <h3>Choose Your Contribution Amount</h3>
                        
                        <div class="amount-buttons">
                            <?php foreach ($suggested_amounts as $amount): ?>
                                <button type="button" class="amount-btn" data-amount="<?php echo $amount; ?>">
                                    $<?php echo $amount; ?>
                                    <?php if ($amount == $remaining && $remaining > 0): ?>
                                        <br><small>Fund it!</small>
                                    <?php endif; ?>
                                </button>
                            <?php endforeach; ?>
                        </div>

                        <div class="custom-amount">
                            <input type="number" name="amount" id="customAmount" placeholder="Or enter custom amount ($1-$1000)" min="1" max="1000" step="0.01" required>
                        </div>
                    </div>

                    <div class="payment-note">
                        <strong>💳 Secure Webhook Payment:</strong> Your payment will be processed securely through Stripe with webhook confirmation. You will be redirected to complete your payment on Stripe's secure checkout page.
                    </div>

                    <button type="submit" class="btn" id="submitBtn">Continue to Secure Payment (Webhook)</button>
                    
                    <div class="stripe-powered">
                        Payments securely processed by <strong>Stripe + Webhook System</strong>
                    </div>
                </form>
            <?php else: ?>
                <div class="success">
                    This topic has been fully funded! The creator is now working on the content.
                </div>
                <a href="view.php?id=<?php echo $topic->id; ?>" class="btn">View Topic Details</a>
            <?php endif; ?>

            <?php if (!empty($contributions)): ?>
            <div class="recent-contributors">
                <h3>Recent Contributors (<?php echo count($contributions); ?>)</h3>
                <?php foreach (array_slice($contributions, 0, 5) as $contribution): ?>
                    <div class="contributor-item">
                        <div class="contributor-info">
                            <div class="contributor-avatar">
                                <?php echo strtoupper(substr($contribution->username, 0, 1)); ?>
                            </div>
                            <div>
                                <div class="contributor-name"><?php echo htmlspecialchars($contribution->username); ?></div>
                                <div class="contribution-date"><?php echo date('M j, Y', strtotime($contribution->contributed_at)); ?></div>
                            </div>
                        </div>
                        <div class="contribution-amount">$<?php echo number_format($contribution->amount, 2); ?></div>
                    </div>
                <?php endforeach; ?>
                <?php if (count($contributions) > 5): ?>
                    <div style="text-align: center; margin-top: 15px;">
                        <a href="view.php?id=<?php echo $topic->id; ?>" style="color: #007bff;">View all contributors</a>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    // Handle amount button clicks
    document.querySelectorAll('.amount-btn').forEach(button => {
        button.addEventListener('click', function() {
            // Remove active class from all buttons
            document.querySelectorAll('.amount-btn').forEach(btn => btn.classList.remove('active'));
            
            // Add active class to clicked button
            this.classList.add('active');
            
            // Set the custom amount input
            document.getElementById('customAmount').value = this.dataset.amount;
            validateForm();
        });
    });

    // Clear button selection when typing custom amount
    document.getElementById('customAmount').addEventListener('input', function() {
        document.querySelectorAll('.amount-btn').forEach(btn => btn.classList.remove('active'));
        validateForm();
    });

    function validateForm() {
        const amount = parseFloat(document.getElementById('customAmount').value);
        const submitBtn = document.getElementById('submitBtn');
        
        if (amount >= 1 && amount <= 1000) {
            submitBtn.disabled = false;
            submitBtn.style.opacity = '1';
        } else {
            submitBtn.disabled = true;
            submitBtn.style.opacity = '0.6';
        }
    }

    // Form validation
    document.getElementById('fundingForm').addEventListener('submit', function(e) {
        const amount = parseFloat(document.getElementById('customAmount').value);
        
        if (!amount || amount < 1) {
            e.preventDefault();
            alert('Please enter a valid amount ($1 minimum)');
            return;
        }
        
        if (amount > 1000) {
            e.preventDefault();
            alert('Maximum contribution is $1,000');
            return;
        }
        
        // Show loading state
        const submitBtn = document.getElementById('submitBtn');
        submitBtn.innerHTML = 'Processing with Webhooks...';
        submitBtn.disabled = true;
    });
    
    // Initial validation
    validateForm();
    </script>
</body>
</html>
