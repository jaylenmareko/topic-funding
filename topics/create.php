<?php
// topics/create.php - Topic creation form
session_start();
require_once '../config/database.php';

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
    
    // Create topic if no errors
    if (empty($errors)) {
        $topic_id = $helper->createTopic($selected_creator_id, $_SESSION['user_id'], $title, $description, $funding_threshold);
        
        if ($topic_id) {
            $success = "Topic created successfully!";
        } else {
            $errors[] = "Failed to create topic. Please try again.";
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
        body { font-family: Arial, sans-serif; margin: 20px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input, select, textarea { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
        textarea { height: 100px; }
        .btn { background: #007bff; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; }
        .error { color: red; margin-bottom: 10px; }
        .success { color: green; margin-bottom: 10px; }
    </style>
</head>
<body>
    <h1>Propose New Topic</h1>
    <p><a href="index.php">← Back to Topics</a></p>

    <?php if (!empty($errors)): ?>
        <?php foreach ($errors as $error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="success"><?php echo $success; ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-group">
            <label>Creator:</label>
            <select name="creator_id" required>
                <option value="">Select a creator</option>
                <?php foreach ($all_creators as $c): ?>
                    <option value="<?php echo $c->id; ?>" <?php echo ($creator && $c->id == $creator->id) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($c->display_name); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label>Topic Title:</label>
            <input type="text" name="title" required>
        </div>

        <div class="form-group">
            <label>Description:</label>
            <textarea name="description" required></textarea>
        </div>

        <div class="form-group">
            <label>Funding Threshold ($):</label>
            <input type="number" name="funding_threshold" value="50" min="10" step="0.01" required>
        </div>

        <button type="submit" class="btn">Create Topic</button>
    </form>
</body>
</html>
