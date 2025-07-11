<?php
// admin/creators.php - Fixed version with error handling
session_start();
require_once '../config/database.php';

// Enhanced admin authorization - Check multiple admin methods
function isAdmin($user_id) {
    if (!$user_id) return false;
    
    try {
        $db = new Database();
        
        // Method 1: Check if user_id is in hardcoded admin list (most reliable)
        $admin_user_ids = [1, 2, 9]; // Your admin user IDs
        if (in_array($user_id, $admin_user_ids)) {
            return true;
        }
        
        // Method 2: Check is_admin column if it exists
        $db->query('DESCRIBE users');
        $columns = $db->resultSet();
        $has_admin_column = false;
        foreach ($columns as $column) {
            if ($column->Field === 'is_admin') {
                $has_admin_column = true;
                break;
            }
        }
        
        if ($has_admin_column) {
            $db->query('SELECT is_admin, is_active FROM users WHERE id = :user_id');
            $db->bind(':user_id', $user_id);
            $user = $db->single();
            
            if ($user && $user->is_admin == 1 && $user->is_active == 1) {
                return true;
            }
        }
        
        return false;
        
    } catch (Exception $e) {
        error_log("Admin check error: " . $e->getMessage());
        // Fallback to hardcoded admin list
        $admin_user_ids = [1, 2, 9];
        return in_array($user_id, $admin_user_ids);
    }
}

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

// Debug admin check
$user_id = $_SESSION['user_id'];
$is_admin = isAdmin($user_id);

if (!$is_admin) {
    // Show debug info instead of redirecting
    echo "<h2>Admin Access Required</h2>";
    echo "<p>Your User ID: " . $user_id . "</p>";
    echo "<p>Admin Status: " . ($is_admin ? 'Yes' : 'No') . "</p>";
    echo "<p>Allowed Admin IDs: 1, 2, 9</p>";
    echo "<p><a href='../auth/logout.php'>Logout</a> | <a href='../index.php'>Home</a></p>";
    
    // Don't exit here so you can see what's happening
    if ($user_id != 1 && $user_id != 2 && $user_id != 9) {
        echo "<p style='color: red;'>You need to be logged in as user ID 1, 2, or 9 to access admin panel.</p>";
        exit;
    }
}

// CSRF protection
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token'])) {
    // CSRF protection
    if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "Invalid request. Please try again.";
    } else {
        try {
            $action = $_POST['action'] ?? '';
            $creator_id = intval($_POST['creator_id'] ?? 0);
            
            if (!$creator_id) {
                throw new Exception("Invalid creator ID.");
            }
            
            $db = new Database();
            
            switch ($action) {
                case 'activate':
                    $db->query('UPDATE creators SET is_active = 1 WHERE id = :id');
                    $db->bind(':id', $creator_id);
                    $db->execute();
                    
                    if ($db->rowCount() > 0) {
                        $message = "Creator activated successfully.";
                    } else {
                        throw new Exception("Creator not found.");
                    }
                    break;
                    
                case 'deactivate':
                    $db->query('UPDATE creators SET is_active = 0 WHERE id = :id');
                    $db->bind(':id', $creator_id);
                    $db->execute();
                    
                    if ($db->rowCount() > 0) {
                        $message = "Creator deactivated successfully.";
                    } else {
                        throw new Exception("Creator not found.");
                    }
                    break;
                    
                case 'delete_creator':
                    // First check if creator has any topics
                    $db->query('SELECT COUNT(*) as topic_count FROM topics WHERE creator_id = :id');
                    $db->bind(':id', $creator_id);
                    $topic_count = $db->single()->topic_count;
                    
                    if ($topic_count > 0) {
                        throw new Exception("Cannot delete creator with existing topics. Deactivate instead.");
                    }
                    
                    $db->query('DELETE FROM creators WHERE id = :id');
                    $db->bind(':id', $creator_id);
                    $db->execute();
                    
                    if ($db->rowCount() > 0) {
                        $message = "Creator deleted successfully.";
                    } else {
                        throw new Exception("Creator not found.");
                    }
                    break;
                    
                default:
                    throw new Exception("Invalid action.");
            }
            
        } catch (Exception $e) {
            $error = $e->getMessage();
            error_log("Admin action error: " . $e->getMessage());
        }
    }
}

// Fetch all creators instead of creator_applications since that table doesn't exist
try {
    $db = new Database();
    
    // Use the existing creators table instead
    $db->query('SELECT c.*, u.username as applicant_username, u.email as applicant_email 
                FROM creators c 
                LEFT JOIN users u ON c.applicant_user_id = u.id 
                ORDER BY c.created_at DESC');
    $applications = $db->resultSet();
    
    // Get statistics from creators table
    $db->query('SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as approved,
        0 as rejected
        FROM creators');
    $stats = $db->single();
    
} catch (Exception $e) {
    error_log("Database error in admin/creators.php: " . $e->getMessage());
    $error = "Database error: " . $e->getMessage();
    $applications = [];
    $stats = (object)['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Creator Applications - Admin</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .header { background: #007bff; color: white; padding: 20px; margin-bottom: 20px; border-radius: 8px; }
        .stats { display: flex; gap: 20px; margin-bottom: 20px; }
        .stat-box { background: #f8f9fa; padding: 15px; border-radius: 5px; text-align: center; }
        .stat-number { font-size: 24px; font-weight: bold; color: #007bff; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { border: 1px solid #ddd; padding: 12px; text-align: left; }
        th { background: #f8f9fa; }
        .status { padding: 4px 8px; border-radius: 4px; color: white; }
        .status.pending { background: #ffc107; }
        .status.approved { background: #28a745; }
        .status.rejected { background: #dc3545; }
        .btn { padding: 8px 16px; margin: 2px; border: none; border-radius: 4px; cursor: pointer; }
        .btn-approve { background: #28a745; color: white; }
        .btn-reject { background: #dc3545; color: white; }
        .btn-delete { background: #6c757d; color: white; }
        .btn:hover { opacity: 0.8; }
        .message { padding: 10px; margin: 10px 0; border-radius: 4px; }
        .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.4); }
        .modal-content { background-color: #fefefe; margin: 15% auto; padding: 20px; border: 1px solid #888; width: 50%; border-radius: 5px; }
        .close { color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer; }
        .close:hover { color: black; }
        .profile-image { width: 50px; height: 50px; border-radius: 50%; object-fit: cover; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Creator Management</h1>
        <p>Manage existing creators on the platform</p>
        <a href="../index.php" style="color: white;">← Back to Home</a>
    </div>

    <?php if ($message): ?>
        <div class="message success"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="message error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="stats">
        <div class="stat-box">
            <div class="stat-number"><?php echo $stats->total; ?></div>
            <div>Total Creators</div>
        </div>
        <div class="stat-box">
            <div class="stat-number"><?php echo $stats->approved; ?></div>
            <div>Active Creators</div>
        </div>
        <div class="stat-box">
            <div class="stat-number"><?php echo $stats->pending; ?></div>
            <div>Inactive Creators</div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Display Name</th>
                <th>Email</th>
                <th>Platform</th>
                <th>Created Date</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($applications)): ?>
            <tr>
                <td colspan="7" style="text-align: center;">No creators found.</td>
            </tr>
            <?php else: ?>
                <?php foreach ($applications as $creator): ?>
                <tr>
                    <td><?php echo $creator->id; ?></td>
                    <td><?php echo htmlspecialchars($creator->display_name ?? 'N/A'); ?></td>
                    <td><?php echo htmlspecialchars($creator->email ?? $creator->applicant_email ?? 'N/A'); ?></td>
                    <td><?php echo htmlspecialchars($creator->platform_type ?? 'youtube'); ?></td>
                    <td><?php echo date('M j, Y', strtotime($creator->created_at)); ?></td>
                    <td>
                        <span class="status <?php echo $creator->is_active ? 'approved' : 'pending'; ?>">
                            <?php echo $creator->is_active ? 'Active' : 'Inactive'; ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($creator->is_active): ?>
                            <form style="display: inline;" method="POST" onsubmit="return confirm('Deactivate this creator?')">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <input type="hidden" name="action" value="deactivate">
                                <input type="hidden" name="creator_id" value="<?php echo $creator->id; ?>">
                                <button type="submit" class="btn btn-reject">Deactivate</button>
                            </form>
                        <?php else: ?>
                            <form style="display: inline;" method="POST" onsubmit="return confirm('Activate this creator?')">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <input type="hidden" name="action" value="activate">
                                <input type="hidden" name="creator_id" value="<?php echo $creator->id; ?>">
                                <button type="submit" class="btn btn-approve">Activate</button>
                            </form>
                        <?php endif; ?>
                        
                        <form style="display: inline;" method="POST" onsubmit="return confirm('Delete this creator? This cannot be undone.')">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="action" value="delete_creator">
                            <input type="hidden" name="creator_id" value="<?php echo $creator->id; ?>">
                            <button type="submit" class="btn btn-delete">Delete</button>
                        </form>
                        
                        <?php if ($creator->platform_url): ?>
                            <a href="<?php echo htmlspecialchars($creator->platform_url); ?>" target="_blank" class="btn" style="background: #007bff; color: white;">View Channel</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Reject Modal -->
    <div id="rejectModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeRejectModal()">&times;</span>
            <h2>Reject Application</h2>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="application_id" id="rejectApplicationId">
                
                <label for="rejection_reason">Rejection Reason:</label><br>
                <textarea name="rejection_reason" id="rejection_reason" rows="4" style="width: 100%; margin: 10px 0;" required placeholder="Please provide a reason for rejection..."></textarea><br>
                
                <button type="submit" class="btn btn-reject">Reject Application</button>
                <button type="button" class="btn" onclick="closeRejectModal()">Cancel</button>
            </form>
        </div>
    </div>

    <script>
        function showRejectModal(id) {
            document.getElementById('rejectApplicationId').value = id;
            document.getElementById('rejectModal').style.display = 'block';
        }
        
        function closeRejectModal() {
            document.getElementById('rejectModal').style.display = 'none';
            document.getElementById('rejection_reason').value = '';
        }
        
        // Close modals when clicking outside
        window.onclick = function(event) {
            const rejectModal = document.getElementById('rejectModal');
            if (event.target == rejectModal) {
                rejectModal.style.display = 'none';
            }
        }
    </script>
</body>
</html>
