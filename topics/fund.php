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
    header('Location: ../creators/index.php');
    exit;
}

$topic = $helper->getTopicById($topic_id);
if (!$topic) {
    header('Location: ../creators/index.php');
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

            <?php if ($topic->status === 'active'): ?>

                <div class="secure-badge">
                    <span>🔒</span>
                    <span>Secure payment processing</span>
                </div>

                <form method="POST" id="fundingForm">
                    <?php echo CSRFProtection::getTokenField(); ?>
                    
                    <div class="form-section">
                        <div class="custom-amount">
                            <input type="number" name="amount" id="customAmount" placeholder="Enter amount ($1-$1000)" min="1" max="1000" step="0.01" required>
                        </div>
                    </div>

                    <button type="submit" class="btn" id="submitBtn">Continue to Secure Payment</button>
                </form>
            <?php else: ?>
                <div class="success">
                    This topic has been fully funded! The creator is now working on the content.
                </div>
                <a href="view.php?id=<?php echo $topic->id; ?>" class="btn">View Topic Details</a>
            <?php endif; ?>
        </div>
    </div>

    <script>
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

    // Clear button selection when typing custom amount
    document.getElementById('customAmount').addEventListener('input', function() {
        validateForm();
    });

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
        submitBtn.innerHTML = 'Processing...';
        submitBtn.disabled = true;
    });
    
    // Initial validation
    validateForm();
    </script>
</body>
</html>
