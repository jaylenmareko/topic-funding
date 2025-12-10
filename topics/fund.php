<?php
// topics/fund.php - Simplified payment page
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

// Get recent contributions count
$contributions = $helper->getTopicContributions($topic_id);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Fund Topic - TopicLaunch</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { 
            font-family: Arial, sans-serif; 
            margin: 0; 
            padding: 0; 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .container { 
            max-width: 500px; 
            width: 100%;
            padding: 20px;
        }
        
        .back-link { 
            color: white; 
            text-decoration: none; 
            margin-bottom: 20px; 
            display: inline-block;
            font-weight: 500;
            opacity: 0.9;
        }
        .back-link:hover { 
            opacity: 1;
            text-decoration: underline; 
        }
        
        .funding-form { 
            background: white; 
            padding: 40px; 
            border-radius: 12px; 
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        
        .funding-progress-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
        }
        
        .progress-bar-container {
            background: #e9ecef;
            height: 8px;
            border-radius: 4px;
            overflow: hidden;
            margin-bottom: 12px;
        }
        
        .progress-bar-fill {
            background: linear-gradient(90deg, #28a745, #20c997);
            height: 100%;
            border-radius: 4px;
            transition: width 0.5s ease;
        }
        
        .funding-stats {
            display: flex;
            justify-content: space-between;
            font-size: 14px;
            color: #666;
        }
        
        .funding-stat strong {
            color: #333;
            font-weight: 600;
        }
        
        .form-subtitle {
            font-size: 16px;
            color: #666;
            margin: 0 0 20px 0;
            text-align: center;
        }
        
        .custom-amount input { 
            width: 100%; 
            padding: 15px; 
            border: 2px solid #e0e0e0; 
            border-radius: 8px; 
            font-size: 18px; 
            box-sizing: border-box;
            transition: border-color 0.3s;
        }
        
        .custom-amount input:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .btn { 
            background: #28a745; 
            color: white; 
            padding: 15px 30px; 
            border: none; 
            border-radius: 8px; 
            cursor: pointer; 
            font-size: 18px; 
            width: 100%; 
            font-weight: bold; 
            transition: background 0.3s;
            margin-top: 20px;
        }
        .btn:hover { background: #218838; }
        .btn:disabled { 
            background: #6c757d; 
            cursor: not-allowed; 
            opacity: 0.6; 
        }
        
        .error { 
            color: #721c24;
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        
        .secure-badge { 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            gap: 8px; 
            margin-top: 20px;
            color: #28a745; 
            font-weight: 600;
            font-size: 14px;
        }
        
        @media (max-width: 768px) {
            .funding-form { padding: 30px 25px; }
            .funding-stats { font-size: 13px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="view.php?id=<?php echo $topic->id; ?>" class="back-link">← Back to Topic</a>

        <div class="funding-form">
            <?php if (!empty($errors)): ?>
                <?php foreach ($errors as $error): ?>
                    <div class="error"><?php echo htmlspecialchars($error); ?></div>
                <?php endforeach; ?>
            <?php endif; ?>

            <!-- Funding Progress -->
            <div class="funding-progress-section">
                <div class="progress-bar-container">
                    <div class="progress-bar-fill" style="width: <?php echo $progress_percent; ?>%"></div>
                </div>
                <div class="funding-stats">
                    <span><strong>$<?php echo number_format($topic->current_funding, 0); ?></strong> raised</span>
                    <span><strong>$<?php echo number_format($remaining, 0); ?></strong> to go</span>
                    <span><strong><?php echo round($progress_percent); ?>%</strong> funded</span>
                </div>
            </div>

            <p class="form-subtitle">Enter your contribution amount</p>

            <form method="POST" id="fundingForm">
                <?php if ($is_logged_in): ?>
                    <?php echo CSRFProtection::getTokenField(); ?>
                <?php endif; ?>
                
                <div class="custom-amount">
                    <input 
                        type="number" 
                        name="amount" 
                        id="customAmount" 
                        placeholder="$1 - $1000" 
                        min="1" 
                        max="1000" 
                        step="1" 
                        required
                        autofocus>
                </div>

                <button type="submit" class="btn" id="submitBtn" disabled>
                    Fund
                </button>
                
                <div class="secure-badge">
                    <span>🔒</span>
                    <span>Secure payment by Stripe</span>
                </div>
            </form>
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

    // Validate on input
    document.getElementById('customAmount').addEventListener('input', function() {
        validateForm();
    });

    // Form validation and submission
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
