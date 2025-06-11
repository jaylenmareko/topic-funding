<?php
// admin/creators.php - Fixed admin interface
session_start();
require_once '../config/database.php';

// Simple admin check - for demo, allow user_id 1, 2, or 3
$allowed_admin_ids = [1, 2, 3];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_id'], $allowed_admin_ids)) {
    die('
        <div style="font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5;">
            <div style="background: white; padding: 30px; border-radius: 8px; max-width: 500px; margin: 0 auto;">
                <h2>Access Denied</h2>
                <p>This is a demo admin interface. Currently logged in as user ID: ' . ($_SESSION['user_id'] ?? 'none') . '</p>
                <p>Allowed admin IDs: ' . implode(', ', $allowed_admin_ids) . '</p>
                <p><a href="../index.php">← Back to Home</a></p>
            </div>
        </div>
    ');
}

$db = new Database();
$message = '';

// Handle actions
if ($_POST && isset($_POST['action']) && isset($_POST['creator_id'])) {
    $creator_id = (int)$_POST['creator_id'];
    $action = $_POST['action'];
    
    if ($action === 'approve') {
        $db->query('UPDATE creators SET is_active = 1, application_status = "approved" WHERE id = :id');
        $db->bind(':id', $creator_id);
        if ($db->execute()) {
            $message = "Creator approved successfully!";
        }
    } elseif ($action === 'reject') {
        $db->query('UPDATE creators SET application_status = "rejected" WHERE id = :id');
        $db->bind(':id', $creator_id);
        if ($db->execute()) {
            $message = "Creator application rejected.";
        }
    }
}

// Get all creator applications
$db->query('
    SELECT c.*, u.username, u.email 
    FROM creators c 
    LEFT JOIN users u ON c.applicant_user_id = u.id 
    ORDER BY c.created_at DESC
');
$applications = $db->resultSet();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Admin - Creator Management</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; }
        .header { background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .nav { margin-bottom: 20px; }
        .nav a { color: #007bff; text-decoration: none; margin-right: 15px; }
        .message { padding: 10px; margin-bottom: 20px; border-radius: 4px; background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
        .application-card { background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .app-header { display: flex; justify-content: space-between; align-items: start; margin-bottom: 15px; }
        .app-info { flex: 1; }
        .app-actions { display: flex; gap: 10px; }
        .status-badge { padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: bold; }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-approved { background: #d4edda; color: #155724; }
        .status-rejected { background: #f8d7da; color: #721c24; }
        .btn { padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-success { background: #28a745; color: white; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-info { background: #17a2b8; color: white; }
        .creator-details { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin: 15px 0; }
        .detail-group { }
        .detail-label { font-weight: bold; color: #666; }
        .profile-image { width: 60px; height: 60px; border-radius: 50%; object-fit: cover; margin-right: 15px; }
        .admin-info { background: #e3f2fd; padding: 10px; margin-bottom: 20px; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="nav">
            <a href="../index.php">← Back to Home</a>
            <a href="../creators/index.php">View Creators</a>
        </div>

        <div class="header">
            <h1>Creator Management (Admin)</h1>
            <p>Review and manage creator applications</p>
        </div>

        <div class="admin-info">
            <strong>Admin Session:</strong> Logged in as <?php echo htmlspecialchars($_SESSION['username']); ?> (User ID: <?php echo $_SESSION['user_id']; ?>)
        </div>

        <?php if ($message): ?>
            <div class="message"><?php echo $message; ?></div>
        <?php endif; ?>

        <?php if (empty($applications)): ?>
            <div class="application-card">
                <p>No creator applications yet.</p>
                <p><a href="../creators/apply.php">Apply to be a creator</a> to test the system.</p>
            </div>
        <?php else: ?>
            <?php foreach ($applications as $app): ?>
                <div class="application-card">
                    <div class="app-header">
                        <div class="app-info">
                            <h3 style="margin: 0;">
                                <?php if ($app->profile_image && file_exists('../uploads/creators/' . $app->profile_image)): ?>
                                    <img src="../uploads/creators/<?php echo htmlspecialchars($app->profile_image); ?>" alt="Profile" class="profile-image" style="float: left;">
                                <?php endif; ?>
                                <?php echo htmlspecialchars($app->display_name); ?>
                                <span class="status-badge status-<?php echo $app->application_status; ?>">
                                    <?php echo ucfirst($app->application_status); ?>
                                </span>
                            </h3>
                            <p style="margin: 5px 0; color: #666;">
                                Applied: <?php echo date('M j, Y g:i A', strtotime($app->created_at)); ?>
                                <?php if ($app->username): ?>
                                    | User: <?php echo htmlspecialchars($app->username); ?> (<?php echo htmlspecialchars($app->email); ?>)
                                <?php endif; ?>
                            </p>
                        </div>
                        
                        <?php if ($app->application_status === 'pending'): ?>
                        <div class="app-actions">
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="creator_id" value="<?php echo $app->id; ?>">
                                <input type="hidden" name="action" value="approve">
                                <button type="submit" class="btn btn-success" onclick="return confirm('Approve this creator?')">Approve</button>
                            </form>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="creator_id" value="<?php echo $app->id; ?>">
                                <input type="hidden" name="action" value="reject">
                                <button type="submit" class="btn btn-danger" onclick="return confirm('Reject this application?')">Reject</button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="creator-details">
                        <div class="detail-group">
                            <div class="detail-label">Platform:</div>
                            <div><?php echo ucfirst($app->platform_type); ?></div>
                            
                            <div class="detail-label" style="margin-top: 10px;">Platform URL:</div>
                            <div><a href="<?php echo htmlspecialchars($app->platform_url); ?>" target="_blank"><?php echo htmlspecialchars($app->platform_url); ?></a></div>
                            
                            <div class="detail-label" style="margin-top: 10px;">Subscribers:</div>
                            <div><?php echo number_format($app->subscriber_count); ?></div>
                        </div>
                        
                        <div class="detail-group">
                            <div class="detail-label">Default Funding:</div>
                            <div>$<?php echo number_format($app->default_funding_threshold, 2); ?></div>
                            
                            <div class="detail-label" style="margin-top: 10px;">Verified:</div>
                            <div><?php echo $app->is_verified ? 'Yes' : 'No'; ?></div>
                            
                            <div class="detail-label" style="margin-top: 10px;">Active:</div>
                            <div><?php echo $app->is_active ? 'Yes' : 'No'; ?></div>
                        </div>
                    </div>

                    <div class="detail-label">Bio:</div>
                    <p><?php echo htmlspecialchars($app->bio); ?></p>

                    <?php if ($app->is_active): ?>
                        <a href="../creators/profile.php?id=<?php echo $app->id; ?>" class="btn btn-info">View Live Profile</a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</body>
</html>
