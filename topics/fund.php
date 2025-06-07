<?php
// topics/fund.php - Topic funding page
session_start();
require_once '../config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$helper = new DatabaseHelper();
$topic_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

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
    $amount = (float)$_POST['amount'];
    
    // Validation
    if ($amount < 1) {
        $errors[] = "Minimum contribution is $1";
    } elseif ($amount > 1000) {
        $errors[] = "Maximum contribution is $1,000";
    }
    
    // Add contribution if no errors
    if (empty($errors)) {
        if ($helper->addContribution($topic_id, $_SESSION['user_id'], $amount)) {
            $success = "Thank you for your contribution of $" . number_format($amount, 2) . "!";
            // Refresh topic data to show updated funding
            $topic = $helper->getTopicById($topic_id);
            
            // Check if topic is now funded
            if ($topic->status === 'funded') {
                $success .= " This topic is now fully funded and the creator will begin working on it!";
            }
        } else {
            $errors[] = "Failed to process contribution. Please try again.";
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
        .custom-amount input { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 16px; }
        .btn { background: #28a745; color: white; padding: 15px 30px; border: none; border-radius: 4px; cursor: pointer; font-size: 18px; width: 100%; }
        .btn:hover { background: #218838; }
        .btn:disabled { background: #6c757d; cursor: not-allowed; }
        .error { color: red; margin-bottom: 15px; padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; }
        .success { color: green; margin-bottom: 20px; padding: 15px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; }
        .payment-note { background: #e3f2fd; padding: 15px; border-radius: 4px; margin-bottom: 20px; font-size: 14px; }
        .recent-contributors { margin-top: 30px; }
        .contributor-item { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid #eee; }
        .contributor-item:last-child { border-bottom: none; }
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
                    <div class="error"><?php echo $error; ?></div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="success"><?php echo $success; ?></div>
            <?php endif; ?>

            <?php if ($topic->status === 'active'): ?>
                <form method="POST" id="fundingForm">
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
                        <strong>📝 Note:</strong> This is a demo platform. In a real implementation, this would integrate with a payment processor like Stripe or PayPal. For testing purposes, clicking "Fund Now" will simulate a successful payment.
                    </div>

                    <button type="submit" class="btn">Fund This Topic Now</button>
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
                        <div>
                            <strong><?php echo htmlspecialchars($contribution->username); ?></strong>
                            <div style="font-size: 12px; color: #666;"><?php echo date('M j, Y', strtotime($contribution->contributed_at)); ?></div>
                        </div>
                        <div style="color: #28a745; font-weight: bold;">$<?php echo number_format($contribution->amount, 2); ?></div>
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
        });
    });

    // Clear button selection when typing custom amount
    document.getElementById('customAmount').addEventListener('input', function() {
        document.querySelectorAll('.amount-btn').forEach(btn => btn.classList.remove('active'));
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
        
        // Confirm the contribution
        if (!confirm(`Are you sure you want to contribute $${amount.toFixed(2)} to this topic?`)) {
            e.preventDefault();
        }
    });
    </script>
</body>
</html>
