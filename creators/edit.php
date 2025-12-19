<?php
// creators/edit.php - SIMPLIFIED FOR OAUTH USERS
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$helper = new DatabaseHelper();
$creator_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$creator_id) {
    header('Location: index.php');
    exit;
}

$creator = $helper->getCreatorById($creator_id);
if (!$creator) {
    header('Location: index.php');
    exit;
}

// Verify ownership
$db = new Database();
$db->query('SELECT applicant_user_id FROM creators WHERE id = :id');
$db->bind(':id', $creator_id);
$creator_check = $db->single();

if (!$creator_check || $creator_check->applicant_user_id != $_SESSION['user_id']) {
    header('Location: ../creators/dashboard.php');
    exit;
}

$errors = [];
$success = '';

if ($_POST) {
    try {
        $db->beginTransaction();
        
        // 1. Platform handle
        $youtube_handle = trim($_POST['youtube_handle']);
        if (strpos($youtube_handle, '@') === 0) {
            $youtube_handle = substr($youtube_handle, 1);
        }
        $youtube_handle = trim($youtube_handle);
        
        if (empty($youtube_handle)) {
            $errors[] = "Platform handle is required";
        } elseif (strlen($youtube_handle) < 3) {
            $errors[] = "Platform handle must be at least 3 characters";
        } elseif (!preg_match('/^[a-zA-Z0-9_.-]+$/', $youtube_handle)) {
            $errors[] = "Invalid characters in handle";
        } elseif (preg_match('/^[0-9._-]+$/', $youtube_handle)) {
            $errors[] = "Must contain at least one letter";
        }
        
        // 2. PayPal email
        $paypal_email = trim($_POST['paypal_email']);
        if (!empty($paypal_email) && !filter_var($paypal_email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid PayPal email";
        }
        
        // 3. Profile image
        $profile_image = $creator->profile_image;
        if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../uploads/creators/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
            $file_type = $_FILES['profile_image']['type'];
            $file_size = $_FILES['profile_image']['size'];
            
            if (!in_array($file_type, $allowed_types)) {
                $errors[] = "Only JPG/PNG/GIF allowed";
            } elseif ($file_size > 2 * 1024 * 1024) {
                $errors[] = "Image must be under 2MB";
            } else {
                $file_extension = pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION);
                $new_filename = 'creator_' . $creator_id . '_' . time() . '.' . $file_extension;
                $upload_path = $upload_dir . $new_filename;
                
                if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $upload_path)) {
                    if ($creator->profile_image && file_exists($upload_dir . $creator->profile_image)) {
                        unlink($upload_dir . $creator->profile_image);
                    }
                    $profile_image = $new_filename;
                } else {
                    $errors[] = "Failed to upload image";
                }
            }
        }
        
        // UPDATE if no errors
        if (empty($errors)) {
            $platform_url = 'https://youtube.com/@' . $youtube_handle;
            
            $db->query('
                UPDATE creators 
                SET display_name = :display_name, 
                    profile_image = :profile_image, 
                    platform_url = :platform_url,
                    paypal_email = :paypal_email
                WHERE id = :id
            ');
            $db->bind(':display_name', $youtube_handle);
            $db->bind(':profile_image', $profile_image);
            $db->bind(':platform_url', $platform_url);
            $db->bind(':paypal_email', $paypal_email);
            $db->bind(':id', $creator_id);
            $db->execute();
            
            $_SESSION['username'] = $youtube_handle;
            
            $db->endTransaction();
            
            $_SESSION['profile_updated'] = "Profile updated!";
            header('Location: dashboard.php?t=' . time());
            exit;
        } else {
            $db->cancelTransaction();
        }
        
    } catch (Exception $e) {
        $db->cancelTransaction();
        $errors[] = "Update failed: " . $e->getMessage();
    }
}

$current_handle = $creator->display_name;
if (strpos($current_handle, '@') === 0) {
    $current_handle = substr($current_handle, 1);
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Edit Profile - TopicLaunch</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
        .container { max-width: 600px; margin: 0 auto; }
        .nav { margin-bottom: 20px; }
        .nav a { color: #007bff; text-decoration: none; }
        .header { text-align: center; margin-bottom: 30px; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .header h1 { margin: 0; color: #333; }
        .section { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .section h3 { margin-top: 0; color: #333; border-bottom: 2px solid #f1f3f4; padding-bottom: 10px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: bold; color: #333; }
        input[type="text"], input[type="email"], input[type="password"], input[type="file"] { 
            width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 6px; box-sizing: border-box; font-size: 16px;
        }
        input:disabled { background: #f8f9fa; color: #6c757d; cursor: not-allowed; }
        .current-image { margin: 15px 0; text-align: center; }
        .current-image img { max-width: 100px; height: 100px; object-fit: cover; border-radius: 50%; }
        .btn { background: #28a745; color: white; padding: 15px 30px; border: none; border-radius: 6px; cursor: pointer; font-size: 16px; width: 100%; font-weight: bold; }
        .btn:hover { background: #218838; }
        .error { color: red; margin-bottom: 15px; padding: 12px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 6px; }
        small { color: #666; font-size: 14px; }
        .locked-notice { background: #fff3cd; border: 1px solid #ffc107; padding: 12px; border-radius: 6px; margin-bottom: 15px; color: #856404; }
        .youtube-handle-group { position: relative; }
        .youtube-at-symbol { 
            position: absolute; left: 0; top: 0; background: #f8f9fa; padding: 12px; 
            border-radius: 6px 0 0 6px; border: 1px solid #ddd; border-right: none; 
            color: #666; font-weight: bold; z-index: 2; font-size: 16px;
        }
        .youtube-handle-input { padding-left: 45px !important; position: relative; z-index: 1; }
        .current-value { background: #f8f9fa; padding: 10px; border-radius: 4px; color: #666; margin-bottom: 10px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="nav">
            <a href="../creators/dashboard.php">← Back to Dashboard</a>
        </div>

        <div class="header">
            <h1>✏️ Edit Profile</h1>
        </div>

        <?php if (!empty($errors)): ?>
            <?php foreach ($errors as $error): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endforeach; ?>
        <?php endif; ?>

        <div class="section">
            <form method="POST" enctype="multipart/form-data">
                
                <h3>📺 Profile Information</h3>
                <div class="form-group">
                    <label>YouTube Handle:</label>
                    <div class="youtube-handle-group">
                        <span class="youtube-at-symbol">@</span>
                        <input type="text" name="youtube_handle" class="youtube-handle-input"
                               value="<?php echo htmlspecialchars($current_handle); ?>" 
                               required pattern="[a-zA-Z0-9_.-]*[a-zA-Z]+[a-zA-Z0-9_.-]*">
                    </div>
                    <small>Example: MrBeast, PewDiePie</small>
                </div>

                <div class="form-group">
                    <label>Profile Image:</label>
                    <?php if ($creator->profile_image): ?>
                        <div class="current-image">
                            <img src="../uploads/creators/<?php echo htmlspecialchars($creator->profile_image); ?>" alt="Current">
                        </div>
                    <?php endif; ?>
                    <input type="file" name="profile_image" accept="image/*">
                    <small>JPG, PNG, or GIF. Max 2MB</small>
                </div>

                <h3 style="margin-top: 30px;">💰 PayPal Email</h3>
                <div class="form-group">
                    <label>PayPal Email:</label>
                    <input type="email" name="paypal_email" 
                           value="<?php echo htmlspecialchars($creator->paypal_email ?: ''); ?>" 
                           placeholder="your-paypal@email.com">
                    <small>For receiving payments from completed topics</small>
                </div>

                <button type="submit" class="btn">💾 Update Profile</button>
            </form>
        </div>
    </div>
</body>
</html>
