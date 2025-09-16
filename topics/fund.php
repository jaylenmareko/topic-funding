<?php
// topics/fund.php - Updated to enforce $1 minimum (no free contributions)
session_start();
require_once '../config/database.php';
require_once '../config/stripe.php';
require_once '../config/csrf.php';
require_once '../config/sanitizer.php';

// Allow both logged-in and guest users
$is_logged_in = isset($_SESSION['user_id']);
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
    // CSRF Protection - only for logged-in users
    if ($is_logged_in) {
        CSRFProtection::requireValidToken();
    }
    
    $amount = InputSanitizer::sanitizeFloat($_POST['amount']);
    
    // UPDATED: Enforce $1 minimum (no free contributions)
    if ($amount < 1) {
        $errors[] = "Minimum contribution is $1";
    } elseif ($amount > 1000) {
        $errors[] = "Maximum contribution is $1,000";
    } elseif (!is_numeric($amount)) {
        $errors[] = "Please enter a valid amount";
    }
    
    // For logged-in users, check contribution limits
    if ($is_logged_in) {
        $db = new Database();
        $db->query('SELECT COUNT(*) as count FROM contributions WHERE topic_id = :topic_id AND user_id = :user_id');
        $db->bind(':topic_id', $topic_id);
        $db->bind(':user_id', $_SESSION['user_id']);
        $existing_contributions = $db->single()->count;
        
        if ($existing_contributions >= 3) {
            $errors[] = "You can only contribute up to 3 times per topic.";
        }
    }
    
    // Process payment if no errors
    if (empty($errors)) {
        try {
            // Create metadata for both logged-in and guest users
            $metadata = [
                'type' => 'topic_funding',
                'topic_id' => $topic_id,
                'amount' => $amount,
                'is_guest' => $is_logged_in ? 'false' : 'true'
            ];
            
            // Add user ID if logged in
            if ($is_logged_in) {
                $metadata['user_id'] = $_SESSION['user_id'];
            }
            
            // Create success URL based on user status
            if ($is_logged_in) {
                $success_url = 'https://topiclaunch.com/payment_success.php?session_id={CHECKOUT_SESSION_ID}&type=topic_funding&topic_id=' . $topic_id;
            } else {
                // For guests, redirect to signup after payment
                $success_url = 'https://topiclaunch.com/auth/register.php?type=fan&topic_funded=1&session_id={CHECKOUT_SESSION_ID}&topic_id=' . $topic_id . '&amount=' . $amount;
            }
            
            // Create Stripe Checkout Session
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
                'success_url' => $success_url,
                'cancel_url' => 'https://topiclaunch.com/payment_cancelled.php?type=topic_funding&topic_id=' . $topic_id,
                'metadata' => $metadata,
                'customer_email' => $is_logged_in ? ($_SESSION['email'] ?? null) : null,
            ]);
            
            // Redirect to Stripe Checkout
            header('Location: ' . $session->url);
            exit;
            
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $errors[] = "Payment processing error. Please try again.";
            error_log("Stripe error: " . $e->getMessage());
        } catch (Exception $e) {
            $errors[] = "An error occurred. Please try again.";
            error_log("Funding error: " . $e->getMessage());
        }
    }
}

// Calculate funding progress
$progress_percent = ($topic->current_funding / $topic->funding_threshold) * 100;
$progress_percent = min($progress_percent, 100);
$remaining = max(0, $topic->funding_threshold - $topic->current_funding);

// Get recent contributions
$contributions = $helper->getTopicContributions($topic_id);

// UPDATED: Suggested contribution amounts (minimum $1)
$suggested_amounts = [1, 5, 10, 25];
if ($remaining > 0 && $remaining <= 500 && $remaining >= 1) {
    $suggested_amounts[] = ceil($remaining); // Add "fund the rest" option
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
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; background: #f5f5f5; }
        
        /* Guest-friendly navigation */
        .guest-nav {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 15px 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
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
            color: white;
            text-decoration: none;
        }
        .nav-logo:hover { color: white; text-decoration: none; }
        
        .container { max-width: 800px; margin: 0 auto; padding: 20px; }
        .back-link { color: #007bff; text-decoration: none; margin-bottom: 20px; display: inline-block; }
        .back-link:hover { text-decoration: underline; }
        
        .topic-summary { background: white; padding: 25px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .topic-title { font-size: 24px; font-weight: bold; margin: 0 0 15px 0; color: #333; }
        .creator-info { display: flex; align-items: center; gap: 15px; margin-bottom: 20px; }
        .creator-avatar { width: 50px; height: 50px; background: linear-gradient(135deg, #667eea, #764ba2); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 20px; color: white; font-weight: bold; }
        .funding-progress { background: #e9ecef; height: 10px; border-radius: 5px; margin: 20px 0; }
        .funding-bar { background: #28a745; height: 100%; border-radius: 5px; transition: width 0.3s; }
        .funding-stats { display: flex; justify-content: space-between; margin: 15px 0; }
        
        .funding-form { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .form-section { margin-bottom: 25px; }
        .form-section h3 { margin-top: 0; color: #333; }
        .amount-buttons { display: grid; grid-template-columns: repeat(auto-fit, minmax(80px, 1fr)); gap: 10px; margin-bottom: 20px; }
        .amount-btn { padding: 12px; border: 2px solid #007bff; background: white; color: #007bff; border-radius: 6px; cursor: pointer; text-align: center; font-weight: bold; transition: all 0.3s; }
        .amount-btn:hover, .amount-btn.active { background: #007bff; color: white; }
        .custom-amount { margin-bottom: 20px; }
        .custom-amount input { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 16px; box-sizing: border-box; }
        .btn { background: #28a745; color: white; padding: 15px 30px; border: none; border-radius: 6px; cursor: pointer; font-size: 18px; width: 100%; font-weight: bold; transition: background 0.3s; }
        .btn:hover { background: #218838; }
        .btn:disabled { background: #6c757d; cursor: not-allowed; opacity: 0.6; }
        .error { color: red; margin-bottom: 15px; padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 6px; }
        .success { color: green; margin-bottom: 20px; padding: 15px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 6px; }
        .guest-notice { background: #e3f2fd; border: 1px solid #2196f3; padding: 15px; border-radius: 6px; margin-bottom: 20px; }
        .secure-badge { display: flex; align-items: center; justify-content: center; gap: 5px; margin-bottom: 15px; color: #28a745; font-weight: bold; }
        
        @media (max-width: 768px) {
            .container { padding: 15px; }
            .topic-summary, .funding-form { padding: 20px; }
            .amount-buttons { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>
    <!-- Guest-friendly navigation -->
    <nav class="guest-nav">
        <div class="nav-container">
            <a href="../index.php" class="nav-logo">TopicLaunch</a>
            
            <?php if ($is_logged_in): ?>
                <div style="color: white;">
                    Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?>!
                    <a href="../auth/logout.php" style="color: white; margin-left: 15px;">Logout</a>
                </div>
            <?php endif; ?>
        </div>
    </nav>

    <div class="container">
        <a href="view.php?id=<?php echo $topic->id; ?>" class="back-link">← Back to Topic</a>

        <!-- Topic Summary -->
        <div class="topic-summary">
            <h1 class="topic-title"><?php echo htmlspecialchars($topic->title); ?></h1>
            
            <div class="creator-info">
                <div class="creator-avatar">
                    <?php if ($topic->creator_image && file_exists('../uploads/creators/' . $topic->creator_image)): ?>
                        <img src="../uploads/creators/<?php echo htmlspecialchars($topic->creator_image); ?>" 
                             alt="Profile" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                    <?php else: ?>
                        <?php echo strtoupper(substr($topic->creator_name, 0, 1)); ?>
                    <?php endif; ?>
                </div>
                <div>
                    <div style="font-weight: bold;">@<?php echo htmlspecialchars($topic->creator_name); ?></div>
                    <div style="color: #666; font-size: 14px;">YouTube Creator</div>
                </div>
            </div>

            <div class="funding-progress">
                <div class="funding-bar" style="width: <?php echo $progress_percent; ?>%"></div>
            </div>

            <div class="funding-stats">
                <div><strong>$<?php echo number_format($topic->current_funding, 0); ?></strong> raised</div>
                <div><strong>$<?php echo number_format($remaining, 0); ?></strong> remaining</div>
                <div><strong><?php echo count($contributions); ?></strong> backers</div>
                <div><strong><?php echo round($progress_percent); ?>%</strong> funded</div>
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

            <?php if (!$is_logged_in): ?>
                <div class="guest-notice">
                    <strong>💡 Funding as a guest?</strong><br>
                    After your payment, you'll create a free account to track your contribution and get notified when the content is ready!
                </div>
            <?php endif; ?>

            <?php if ($topic->status === 'active'): ?>

                <div class="secure-badge">
                    <span>🔒</span>
                    <span>Secure payment by Stripe</span>
                </div>

                <div class="form-section">
                    <h3>Choose Your Contribution (Minimum $1)</h3>
                    <div class="amount-buttons">
                        <?php foreach ($suggested_amounts as $amount): ?>
                            <button type="button" class="amount-btn" onclick="selectAmount(<?php echo $amount; ?>)">
                                $<?php echo $amount; ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <form method="POST" id="fundingForm">
                    <?php if ($is_logged_in): ?>
                        <?php echo CSRFProtection::getTokenField(); ?>
                    <?php endif; ?>
                    
                    <div class="form-section">
                        <div class="custom-amount">
                            <input type="number" name="amount" id="customAmount" placeholder="Enter amount ($1-$1000)" min="1" max="1000" step="1" required>
                        </div>
                    </div>

                    <button type="submit" class="btn" id="submitBtn" disabled>
                        <?php if ($is_logged_in): ?>
                            Continue to Secure Payment
                        <?php else: ?>
                            Pay & Create Account
                        <?php endif; ?>
                    </button>
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
    function selectAmount(amount) {
        // Clear active states
        document.querySelectorAll('.amount-btn').forEach(btn => {
            btn.classList.remove('active');
        });
        
        // Set active state for clicked button
        event.target.classList.add('active');
        
        // Set the custom amount input
        document.getElementById('customAmount').value = amount;
        
        // Validate form
        validateForm();
    }

    function validateForm() {
        const amount = parseFloat(document.getElementById('customAmount').value);
        const submitBtn = document.getElementById('submitBtn');
        
        // UPDATED: Minimum $1 required
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
        // Clear active states when typing
        document.querySelectorAll('.amount-btn').forEach(btn => {
            btn.classList.remove('active');
        });
        
        validateForm();
    });

    // Form validation and submission
    document.getElementById('fundingForm').addEventListener('submit', function(e) {
        const amount = parseFloat(document.getElementById('customAmount').value);
        
        // UPDATED: $1 minimum validation
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
        
        <?php if ($is_logged_in): ?>
        const confirmText = `Fund this topic with $${amount}?\n\nYou'll be notified when the content is ready!`;
        <?php else: ?>
        const confirmText = `Fund this topic with $${amount}?\n\nAfter payment, you'll create a free account to track your contribution!`;
        <?php endif; ?>
        
        if (!confirm(confirmText)) {
            e.preventDefault();
            return;
        }
        
        // Show loading state
        const submitBtn = document.getElementById('submitBtn');
        submitBtn.innerHTML = '⏳ Processing...';
        submitBtn.disabled = true;
    });
    
    // Set initial minimum value
    document.getElementById('customAmount').value = '1';
    
    // Initial validation
    validateForm();
    </script>
</body>
</html>
