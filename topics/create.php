<?php
// topics/create.php - Updated with approval system
session_start();
require_once '../config/database.php';
require_once '../config/notification_system.php';

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
    if (!$creator) {
        header('Location: ../creators/index.php');
        exit;
    }
}

$errors = [];
$success = '';

if ($_POST) {
    $selected_creator_id = (int)$_POST['creator_id'];
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $funding_threshold = (float)$_POST['funding_threshold'];
    
    // Validation
    if (!$selected_creator_id) {
        $errors[] = "Please select a creator";
    }
    
    if (empty($title)) {
        $errors[] = "Topic title is required";
    }
    
    if (empty($description)) {
        $errors[] = "Topic description is required";
    }
    
    if ($funding_threshold < 10) {
        $errors[] = "Minimum funding threshold is $10";
    }
    
    // Create topic if no errors (now pending approval)
    if (empty($errors)) {
        try {
            $db = new Database();
            $db->query('
                INSERT INTO topics (creator_id, initiator_user_id, title, description, funding_threshold, status, approval_status) 
                VALUES (:creator_id, :user_id, :title, :description, :funding_threshold, "pending_approval", "pending")
            ');
            $db->bind(':creator_id', $selected_creator_id);
            $db->bind(':user_id', $_SESSION['user_id']);
            $db->bind(':title', $title);
            $db->bind(':description', $description);
            $db->bind(':funding_threshold', $funding_threshold);
            
            if ($db->execute()) {
                $topic_id = $db->lastInsertId();
                
                // Send notification to creator
                $notificationSystem = new NotificationSystem();
                $notificationSystem->sendTopicProposalNotification($topic_id);
                
                $success = "Topic proposed successfully! The creator has been notified and will review your proposal.";
            } else {
                $errors[] = "Failed to create topic. Please try again.";
            }
        } catch (Exception $e) {
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}

// Get all active creators for dropdown
$all_creators = $helper->getAllCreators();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Propose New Topic - Topic Funding</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: bold; color: #333; }
        input, select, textarea { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 6px; box-sizing: border-box; font-size: 16px; }
        textarea { height: 120px; resize: vertical; }
        .btn { background: #28a745; color: white; padding: 15px 30px; border: none; border-radius: 6px; cursor: pointer; font-size: 16px; width: 100%; }
        .btn:hover { background: #218838; }
        .error { color: red; margin-bottom: 15px; padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; }
        .success { color: green; margin-bottom: 20px; padding: 15px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; }
        .nav { margin-bottom: 20px; }
        .nav a { color: #007bff; text-decoration: none; margin-right: 15px; }
        .approval-notice { background: #e3f2fd; padding: 20px; border-radius: 6px; margin-bottom: 20px; }
        .approval-notice h4 { margin-top: 0; color: #1976d2; }
    </style>
</head>
<body>
    <div class="container">
        <div class="nav">
            <a href="index.php">← Back to Topics</a>
            <a href="../creators/index.php">Browse Creators</a>
            <a href="../index.php">Home</a>
        </div>

        <h1>Propose New Topic</h1>
        
        <div class="approval-notice">
            <h4>📋 How Topic Approval Works:</h4>
            <ul style="margin: 10px 0;">
                <li><strong>1. Propose:</strong> Submit your topic idea to a creator</li>
                <li><strong>2. Review:</strong> Creator reviews and approves/declines your proposal</li>
                <li><strong>3. Funding:</strong> If approved, topic goes live for community funding</li>
                <li><strong>4. Creation:</strong> Once funded, creator has 48 hours to deliver content</li>
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

        <form method="POST">
            <div class="form-group">
                <label>Creator:</label>
                <select name="creator_id" required>
                    <option value="">Select a creator to propose to</option>
                    <?php foreach ($all_creators as $c): ?>
                        <option value="<?php echo $c->id; ?>" <?php echo ($creator && $c->id == $creator->id) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c->display_name); ?> 
                            (<?php echo ucfirst($c->platform_type); ?> • <?php echo number_format($c->subscriber_count); ?> subscribers)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Topic Title:</label>
                <input type="text" name="title" required placeholder="What specific topic do you want covered?">
            </div>

            <div class="form-group">
                <label>Detailed Description:</label>
                <textarea name="description" required placeholder="Provide details about what you want the creator to cover. Be specific about your expectations."></textarea>
            </div>

            <div class="form-group">
                <label>Suggested Funding Goal ($):</label>
                <input type="number" name="funding_threshold" value="<?php echo $creator ? $creator->default_funding_threshold : 50; ?>" min="10" step="0.01" required>
                <small style="color: #666;">How much should the community raise to fund this topic?</small>
            </div>

            <button type="submit" class="btn">Submit Topic Proposal</button>
        </form>
    </div>
</body>
</html>
