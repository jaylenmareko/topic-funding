<?php
// topics/create.php - Modern topic creation page
session_start();
require_once '../config/database.php';

// Get creator from URL
$creator_id = isset($_GET['creator_id']) ? (int)$_GET['creator_id'] : 0;

if (!$creator_id) {
    header('Location: ../index.php');
    exit;
}

// Fetch creator data
$helper = new DatabaseHelper();
$creator = $helper->getCreatorById($creator_id);

if (!$creator) {
    header('Location: ../index.php');
    exit;
}

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $funding_amount = trim($_POST['funding_amount'] ?? '');
    
    // Validation
    if (empty($title)) {
        $error = 'Topic title is required';
    } elseif (strlen($title) < 10) {
        $error = 'Title must be at least 10 characters';
    } elseif (strlen($title) > 200) {
        $error = 'Title must be less than 200 characters';
    } elseif (empty($description)) {
        $error = 'Description is required';
    } elseif (strlen($description) < 20) {
        $error = 'Description must be at least 20 characters';
    } elseif (strlen($description) > 2000) {
        $error = 'Description must be less than 2000 characters';
    } elseif (empty($funding_amount) || !is_numeric($funding_amount)) {
        $error = 'Valid funding amount is required';
    } elseif ($funding_amount < ($creator->minimum_topic_price ?? 100)) {
        $error = 'Funding amount must be at least $' . number_format($creator->minimum_topic_price ?? 100, 2);
    } elseif ($funding_amount > 10000) {
        $error = 'Maximum funding amount is $10,000';
    } else {
        try {
            $db = new Database();
            
            // Insert topic
            $db->query('
                INSERT INTO topics (
                    creator_id, 
                    title, 
                    description, 
                    funding_threshold, 
                    current_funding,
                    status,
                    created_at
                ) VALUES (
                    :creator_id,
                    :title,
                    :description,
                    :funding_threshold,
                    0,
                    "active",
                    NOW()
                )
            ');
            
            $db->bind(':creator_id', $creator_id);
            $db->bind(':title', $title);
            $db->bind(':description', $description);
            $db->bind(':funding_threshold', floatval($funding_amount));
            $db->execute();
            
            $topic_id = $db->lastInsertId();
            
            // Redirect to profile page with success message
            header('Location: ../creators/profile.php?id=' . $creator_id . '&topic_created=1');
            exit;
            
        } catch (Exception $e) {
            $error = 'Failed to create topic. Please try again.';
            error_log('Topic creation error: ' . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Create Topic for <?php echo htmlspecialchars($creator->display_name); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #fafafa;
            min-height: 100vh;
        }
        
        /* Navigation */
        .topiclaunch-nav {
            background: white;
            padding: 15px 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border-bottom: 1px solid #f0f0f0;
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
            color: #FF0000;
            text-decoration: none;
            cursor: pointer;
            transition: opacity 0.2s;
        }
        
        .nav-logo:hover {
            opacity: 0.8;
        }
        
        /* Creator Info Badge */
        .creator-badge {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #f3f4f6;
            padding: 8px 16px;
            border-radius: 50px;
        }
        
        .creator-badge-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: linear-gradient(135deg, #FF0000, #CC0000);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 14px;
            font-weight: bold;
            overflow: hidden;
        }
        
        .creator-badge-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .creator-badge-name {
            font-size: 14px;
            font-weight: 600;
            color: #374151;
        }
        
        /* Main Container */
        .container {
            max-width: 600px;
            margin: 40px auto;
            padding: 0 20px;
        }
        
        /* Page Header */
        .page-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .page-title {
            font-size: 28px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 6px;
        }
        
        .page-subtitle {
            font-size: 15px;
            color: #6b7280;
        }
        
        /* Form Card */
        .form-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            border: 1px solid #e5e7eb;
            padding: 32px;
        }
        
        /* Alert Messages */
        .alert {
            padding: 16px 20px;
            border-radius: 8px;
            margin-bottom: 24px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid #ef4444;
        }
        
        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border-left: 4px solid #10b981;
        }
        
        /* Form Groups */
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 6px;
        }
        
        .form-label .required {
            color: #ef4444;
            margin-left: 2px;
        }
        
        .form-input,
        .form-textarea {
            width: 100%;
            padding: 10px 14px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            transition: all 0.2s;
        }
        
        .form-input:focus,
        .form-textarea:focus {
            outline: none;
            border-color: #FF0000;
            box-shadow: 0 0 0 3px rgba(255,0,0,0.1);
        }
        
        .form-textarea {
            min-height: 120px;
            resize: vertical;
        }
        
        .form-help {
            font-size: 13px;
            color: #6b7280;
            margin-top: 6px;
        }
        
        .char-counter {
            font-size: 13px;
            color: #9ca3af;
            text-align: right;
            margin-top: 4px;
        }
        
        .char-counter.warning {
            color: #f59e0b;
        }
        
        .char-counter.error {
            color: #ef4444;
        }
        
        /* Funding Amount Input */
        .funding-input-wrapper {
            position: relative;
        }
        
        .currency-symbol {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 18px;
            font-weight: 600;
            color: #6b7280;
            pointer-events: none;
        }
        
        .funding-input {
            padding-left: 36px;
            font-size: 18px;
            font-weight: 600;
        }
        
        .funding-breakdown {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 16px;
            margin-top: 12px;
        }
        
        .breakdown-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        .breakdown-row:last-child {
            margin-bottom: 0;
            padding-top: 8px;
            border-top: 1px solid #e5e7eb;
            font-weight: 600;
        }
        
        .breakdown-label {
            color: #6b7280;
        }
        
        .breakdown-value {
            color: #111827;
            font-weight: 600;
        }
        
        .breakdown-value.highlight {
            color: #10b981;
        }
        
        /* Submit Button */
        .submit-btn {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #FF0000 0%, #CC0000 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            margin-top: 24px;
        }
        
        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(255,0,0,0.3);
        }
        
        .submit-btn:active {
            transform: translateY(0);
        }
        
        .submit-btn:disabled {
            background: #9ca3af;
            cursor: not-allowed;
            transform: none;
        }
        
        /* 3-Step Process */
        .steps-container {
            display: flex;
            gap: 10px;
            margin-bottom: 24px;
        }
        
        .step-item {
            flex: 1;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 14px 10px;
            text-align: center;
        }
        
        .step-number {
            width: 26px;
            height: 26px;
            background: #FF0000;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 13px;
            margin: 0 auto 8px;
        }
        
        .step-text {
            font-size: 12px;
            color: #374151;
            line-height: 1.3;
        }
        
        /* Info Box */
        .info-box {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 24px;
        }
        
        .info-box-title {
            font-size: 14px;
            font-weight: 600;
            color: #1e40af;
            margin-bottom: 8px;
        }
        
        .info-box-text {
            font-size: 13px;
            color: #1e40af;
            line-height: 1.5;
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 20px 15px;
                margin: 30px auto;
            }
            
            .form-card {
                padding: 24px;
            }
            
            .page-title {
                font-size: 24px;
            }
            
            .page-subtitle {
                font-size: 14px;
            }
            
            .creator-badge {
                display: none;
            }
            
            .steps-container {
                flex-direction: column;
                gap: 8px;
            }
            
            .step-item {
                padding: 12px;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="topiclaunch-nav">
        <div class="nav-container">
            <a href="../creators/profile.php?id=<?php echo $creator_id; ?>" class="nav-logo">TopicLaunch</a>
            
            <div class="creator-badge">
                <div class="creator-badge-avatar">
                    <?php if ($creator->profile_image): ?>
                        <img src="../uploads/creators/<?php echo htmlspecialchars($creator->profile_image); ?>" 
                             alt="<?php echo htmlspecialchars($creator->display_name); ?>">
                    <?php else: ?>
                        <?php echo strtoupper(substr($creator->display_name, 0, 1)); ?>
                    <?php endif; ?>
                </div>
                <span class="creator-badge-name">@<?php echo htmlspecialchars($creator->display_name); ?></span>
            </div>
        </div>
    </nav>

    <div class="container">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">Create a Topic</h1>
            <p class="page-subtitle">Request a video from @<?php echo htmlspecialchars($creator->display_name); ?></p>
        </div>

        <!-- Form Card -->
        <div class="form-card">
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <span>⚠️</span>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <span>✓</span>
                    <span><?php echo htmlspecialchars($success); ?></span>
                </div>
            <?php endif; ?>
            
            <!-- 3-Step Process -->
            <div class="steps-container">
                <div class="step-item">
                    <div class="step-number">1</div>
                    <div class="step-text">Set goal & make first contribution</div>
                </div>
                <div class="step-item">
                    <div class="step-number">2</div>
                    <div class="step-text">Others fund until goal is reached</div>
                </div>
                <div class="step-item">
                    <div class="step-number">3</div>
                    <div class="step-text">Creator delivers in 48hrs or refund</div>
                </div>
            </div>

            <form method="POST" action="" id="createTopicForm">
                <!-- Title -->
                <div class="form-group">
                    <label class="form-label">
                        Topic Title <span class="required">*</span>
                    </label>
                    <input 
                        type="text" 
                        name="title" 
                        id="title"
                        class="form-input"
                        placeholder="e.g., How to start a successful YouTube channel in 2025"
                        maxlength="200"
                        value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>"
                        required>
                    <div class="char-counter" id="titleCounter">0 / 200</div>
                </div>

                <!-- Description -->
                <div class="form-group">
                    <label class="form-label">
                        Description <span class="required">*</span>
                    </label>
                    <textarea 
                        name="description" 
                        id="description"
                        class="form-textarea"
                        placeholder="Describe in detail what you'd like to see in this video. The more specific you are, the better!"
                        maxlength="2000"
                        required><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                    <div class="char-counter" id="descCounter">0 / 2000</div>
                </div>

                <!-- Set Funding Goal -->
                <div class="form-group">
                    <label class="form-label">
                        Set Funding Goal <span class="required">*</span>
                    </label>
                    <div class="funding-input-wrapper">
                        <span class="currency-symbol">$</span>
                        <input 
                            type="number" 
                            name="funding_amount" 
                            id="fundingAmount"
                            class="form-input funding-input"
                            placeholder="<?php echo number_format($creator->minimum_topic_price ?? 100, 2); ?>"
                            min="<?php echo $creator->minimum_topic_price ?? 100; ?>"
                            max="10000"
                            step="1"
                            value="<?php echo htmlspecialchars($_POST['funding_amount'] ?? ''); ?>"
                            required>
                    </div>
                    <div class="form-help">
                        Enter funding goal • Minimum: $<?php echo number_format($creator->minimum_topic_price ?? 100, 2); ?> | Maximum: $10,000
                    </div>
                    
                    <div class="funding-breakdown" id="fundingBreakdown" style="display: none;">
                        <div class="breakdown-row">
                            <span class="breakdown-label">Total Funding Goal</span>
                            <span class="breakdown-value" id="totalAmount">$0.00</span>
                        </div>
                        <div class="breakdown-row">
                            <span class="breakdown-label">Platform Fee (10%)</span>
                            <span class="breakdown-value" id="platformFee">$0.00</span>
                        </div>
                        <div class="breakdown-row">
                            <span class="breakdown-label">Creator Receives</span>
                            <span class="breakdown-value highlight" id="creatorReceives">$0.00</span>
                        </div>
                    </div>
                </div>

                <!-- Your Funding Amount -->
                <div class="form-group">
                    <label class="form-label">
                        Your Funding Amount <span class="required">*</span>
                    </label>
                    <div class="funding-input-wrapper">
                        <span class="currency-symbol">$</span>
                        <input 
                            type="number" 
                            name="initial_contribution" 
                            id="initialContribution"
                            class="form-input funding-input"
                            placeholder="1.00"
                            min="1"
                            max="1000"
                            step="1"
                            value="<?php echo htmlspecialchars($_POST['initial_contribution'] ?? ''); ?>"
                            required>
                    </div>
                    <div class="form-help">
                        Your contribution to start this topic • $1 - $1,000
                    </div>
                </div>

                <!-- Submit Button -->
                <button type="submit" class="submit-btn">
                    Create Topic
                </button>
            </form>
        </div>
    </div>

    <script>
        // Character counters
        const titleInput = document.getElementById('title');
        const descInput = document.getElementById('description');
        const titleCounter = document.getElementById('titleCounter');
        const descCounter = document.getElementById('descCounter');
        
        function updateCounter(input, counter, max) {
            const count = input.value.length;
            counter.textContent = count + ' / ' + max;
            
            if (count > max * 0.9) {
                counter.classList.add('warning');
                counter.classList.remove('error');
            }
            if (count >= max) {
                counter.classList.add('error');
                counter.classList.remove('warning');
            }
            if (count < max * 0.9) {
                counter.classList.remove('warning', 'error');
            }
        }
        
        titleInput.addEventListener('input', () => updateCounter(titleInput, titleCounter, 200));
        descInput.addEventListener('input', () => updateCounter(descInput, descCounter, 2000));
        
        // Update counters on load
        updateCounter(titleInput, titleCounter, 200);
        updateCounter(descInput, descCounter, 2000);
        
        // Funding breakdown calculator
        const fundingInput = document.getElementById('fundingAmount');
        const fundingBreakdown = document.getElementById('fundingBreakdown');
        const totalAmount = document.getElementById('totalAmount');
        const platformFee = document.getElementById('platformFee');
        const creatorReceives = document.getElementById('creatorReceives');
        
        fundingInput.addEventListener('input', function() {
            const amount = parseFloat(this.value);
            
            if (amount && amount > 0) {
                fundingBreakdown.style.display = 'block';
                const fee = amount * 0.10;
                const creatorAmount = amount * 0.90;
                
                totalAmount.textContent = '$' + amount.toFixed(2);
                platformFee.textContent = '$' + fee.toFixed(2);
                creatorReceives.textContent = '$' + creatorAmount.toFixed(2);
            } else {
                fundingBreakdown.style.display = 'none';
            }
        });
        
        // Trigger on load if value exists
        if (fundingInput.value) {
            fundingInput.dispatchEvent(new Event('input'));
        }
    </script>
</body>
</html>
