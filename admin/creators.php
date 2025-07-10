<?php
// admin/creators.php - Secure admin creator management

session_start();
require_once '../config/database.php';

// Enhanced admin authorization
function isAdmin($user_id) {
    if (!$user_id) return false;
    
    $db = new Database();
    $db->query('SELECT is_admin, is_active FROM users WHERE id = :user_id');
    $db->bind(':user_id', $user_id);
    $user = $db->single();
    
    return $user && $user->is_admin == 1 && $user->is_active == 1;
}

// Rate limiting for admin actions
function checkAdminRateLimit($user_id, $action) {
    $key = "admin_action_{$action}_{$user_id}";
    $attempts = $_SESSION[$key] ?? 0;
    $last_attempt = $_SESSION[$key . '_time'] ?? 0;
    
    // Reset counter after 5 minutes
    if (time() - $last_attempt > 300) {
        $attempts = 0;
    }
    
    if ($attempts >= 10) { // Allow more actions for admin
        throw new Exception("Too many admin actions. Please wait 5 minutes.");
    }
    
    $_SESSION[$key] = $attempts + 1;
    $_SESSION[$key . '_time'] = time();
    
    return true;
}

// Enhanced logging
function logAdminAction($user_id, $action, $target_id, $details = '') {
    $db = new Database();
    $db->query('INSERT INTO admin_log (admin_user_id, action, target_id, details, ip_address, user_agent, created_at) 
                VALUES (:user_id, :action, :target_id, :details, :ip, :user_agent, NOW())');
    $db->bind(':user_id', $user_id);
    $db->bind(':action', $action);
    $db->bind(':target_id', $target_id);
    $db->bind(':details', $details);
    $db->bind(':ip', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $db->bind(':user_agent', $_SERVER['HTTP_USER_AGENT'] ?? 'unknown');
    $db->execute();
}

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || !isAdmin($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

// CSRF protection
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // CSRF protection
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception("Invalid request. Please try again.");
        }
        
        $action = $_POST['action'] ?? '';
        $application_id = intval($_POST['application_id'] ?? 0);
        
        if (!$application_id) {
            throw new Exception("Invalid application ID.");
        }
        
        // Rate limiting
        checkAdminRateLimit($_SESSION['user_id'], $action);
        
        $db = new Database();
        
        switch ($action) {
            case 'approve':
                // Get application details
                $db->query('SELECT * FROM creator_applications WHERE id = :id');
                $db->bind(':id', $application_id);
                $application = $db->single();
                
                if (!$application) {
                    throw new Exception("Application not found.");
                }
                
                if ($application->status !== 'pending') {
                    throw new Exception("Application has already been processed.");
                }
                
                // Begin transaction
                $db->beginTransaction();
                
                try {
                    // Update application status
                    $db->query('UPDATE creator_applications SET status = :status, reviewed_by = :admin_id, reviewed_at = NOW() WHERE id = :id');
                    $db->bind(':status', 'approved');
                    $db->bind(':admin_id', $_SESSION['user_id']);
                    $db->bind(':id', $application_id);
                    $db->execute();
                    
                    // Create user account for approved creator
                    $password = bin2hex(random_bytes(8)); // Generate temporary password
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    
                    $db->query('INSERT INTO users (name, email, password, role, is_active, created_at) 
                                VALUES (:name, :email, :password, :role, 1, NOW())');
                    $db->bind(':name', $application->name);
                    $db->bind(':email', $application->email);
                    $db->bind(':password', $hashed_password);
                    $db->bind(':role', 'creator');
                    $db->execute();
                    
                    $user_id = $db->lastInsertId();
                    
                    // Create creator profile
                    $db->query('INSERT INTO creator_profiles (user_id, bio, social_media, content_type, profile_image, created_at) 
                                VALUES (:user_id, :bio, :social_media, :content_type, :profile_image, NOW())');
                    $db->bind(':user_id', $user_id);
                    $db->bind(':bio', $application->bio);
                    $db->bind(':social_media', $application->social_media);
                    $db->bind(':content_type', $application->content_type);
                    $db->bind(':profile_image', $application->profile_image);
                    $db->execute();
                    
                    $db->commit();
                    
                    // Log admin action
                    logAdminAction($_SESSION['user_id'], 'approve_creator', $application_id, "Approved creator: {$application->name}");
                    
                    $message = "Creator application approved successfully. User account created.";
                    
                    // Send welcome email (optional)
                    // mail($application->email, "Welcome to Our Platform", "Your temporary password is: $password");
                    
                } catch (Exception $e) {
                    $db->rollback();
                    throw $e;
                }
                break;
                
            case 'reject':
                $rejection_reason = trim($_POST['rejection_reason'] ?? '');
                
                if (empty($rejection_reason)) {
                    throw new Exception("Please provide a rejection reason.");
                }
                
                $db->query('UPDATE creator_applications SET status = :status, reviewed_by = :admin_id, reviewed_at = NOW(), rejection_reason = :reason WHERE id = :id');
                $db->bind(':status', 'rejected');
                $db->bind(':admin_id', $_SESSION['user_id']);
                $db->bind(':reason', $rejection_reason);
                $db->bind(':id', $application_id);
                $db->execute();
                
                if ($db->rowCount() > 0) {
                    logAdminAction($_SESSION['user_id'], 'reject_creator', $application_id, "Rejected creator. Reason: {$rejection_reason}");
                    $message = "Creator application rejected.";
                } else {
                    throw new Exception("Application not found or already processed.");
                }
                break;
                
            case 'delete':
                $db->query('DELETE FROM creator_applications WHERE id = :id');
                $db->bind(':id', $application_id);
                $db->execute();
                
                if ($db->rowCount() > 0) {
                    logAdminAction($_SESSION['user_id'], 'delete_creator_application', $application_id, "Deleted creator application");
                    $message = "Creator application deleted.";
                } else {
                    throw new Exception("Application not found.");
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

// Fetch all creator applications
$db = new Database();
$db->query('SELECT ca.*, u.name as reviewed_by_name 
            FROM creator_applications ca 
            LEFT JOIN users u ON ca.reviewed_by = u.id 
            ORDER BY ca.applied_at DESC');
$applications = $db->resultSet();

// Get statistics
$db->query('SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN status = "approved" THEN 1 ELSE 0 END) as approved,
    SUM(CASE WHEN status = "rejected" THEN 1 ELSE 0 END) as rejected
    FROM creator_applications');
$stats = $db->single();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Creator Applications - Admin</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .header { background: #007bff; color: white; padding: 20px; margin-bottom: 20px; }
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
        .application-details { max-width: 300px; }
        .text-truncate { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Creator Applications Management</h1>
        <p>Review and manage creator applications</p>
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
            <div>Total Applications</div>
        </div>
        <div class="stat-box">
            <div class="stat-number"><?php echo $stats->pending; ?></div>
            <div>Pending Review</div>
        </div>
        <div class="stat-box">
            <div class="stat-number"><?php echo $stats->approved; ?></div>
            <div>Approved</div>
        </div>
        <div class="stat-box">
            <div class="stat-number"><?php echo $stats->rejected; ?></div>
            <div>Rejected</div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Email</th>
                <th>Content Type</th>
                <th>Applied Date</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($applications as $app): ?>
            <tr>
                <td><?php echo $app->id; ?></td>
                <td>
                    <?php echo htmlspecialchars($app->name); ?>
                    <?php if ($app->profile_image): ?>
                        <br><img src="../uploads/creators/<?php echo htmlspecialchars($app->profile_image); ?>" 
                                 alt="Profile" class="profile-image">
                    <?php endif; ?>
                </td>
                <td><?php echo htmlspecialchars($app->email); ?></td>
                <td><?php echo htmlspecialchars($app->content_type); ?></td>
                <td><?php echo date('M j, Y', strtotime($app->applied_at)); ?></td>
                <td>
                    <span class="status <?php echo $app->status; ?>">
                        <?php echo ucfirst($app->status); ?>
                    </span>
                    <?php if ($app->reviewed_by_name): ?>
                        <br><small>by <?php echo htmlspecialchars($app->reviewed_by_name); ?></small>
                    <?php endif; ?>
                </td>
                <td>
                    <button class="btn" onclick="viewApplication(<?php echo $app->id; ?>)">View</button>
                    
                    <?php if ($app->status === 'pending'): ?>
                        <form style="display: inline;" method="POST" onsubmit="return confirm('Are you sure you want to approve this application?')">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="action" value="approve">
                            <input type="hidden" name="application_id" value="<?php echo $app->id; ?>">
                            <button type="submit" class="btn btn-approve">Approve</button>
                        </form>
                        
                        <button class="btn btn-reject" onclick="showRejectModal(<?php echo $app->id; ?>)">Reject</button>
                    <?php endif; ?>
                    
                    <form style="display: inline;" method="POST" onsubmit="return confirm('Are you sure you want to delete this application? This action cannot be undone.')">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="application_id" value="<?php echo $app->id; ?>">
                        <button type="submit" class="btn btn-delete">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            
            <?php if (empty($applications)): ?>
            <tr>
                <td colspan="7" style="text-align: center;">No applications found.</td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Application Details Modal -->
    <div id="applicationModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <div id="applicationDetails"></div>
        </div>
    </div>

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
        // Application data for JavaScript
        const applications = <?php echo json_encode($applications); ?>;
        
        function viewApplication(id) {
            const app = applications.find(a => a.id == id);
            if (!app) return;
            
            const details = `
                <h2>Application Details</h2>
                <p><strong>Name:</strong> ${app.name}</p>
                <p><strong>Email:</strong> ${app.email}</p>
                <p><strong>Content Type:</strong> ${app.content_type}</p>
                <p><strong>Social Media:</strong> ${app.social_media || 'Not provided'}</p>
                <p><strong>Bio:</strong></p>
                <div style="background: #f8f9fa; padding: 10px; border-radius: 4px; margin: 10px 0;">
                    ${app.bio}
                </div>
                <p><strong>Experience:</strong></p>
                <div style="background: #f8f9fa; padding: 10px; border-radius: 4px; margin: 10px 0;">
                    ${app.experience || 'Not provided'}
                </div>
                <p><strong>Why Join:</strong></p>
                <div style="background: #f8f9fa; padding: 10px; border-radius: 4px; margin: 10px 0;">
                    ${app.why_join}
                </div>
                <p><strong>Applied:</strong> ${new Date(app.applied_at).toLocaleDateString()}</p>
                <p><strong>Status:</strong> <span class="status ${app.status}">${app.status}</span></p>
                ${app.rejection_reason ? `<p><strong>Rejection Reason:</strong> ${app.rejection_reason}</p>` : ''}
                ${app.profile_image ? `<p><strong>Profile Image:</strong><br><img src="../uploads/creators/${app.profile_image}" style="max-width: 200px; border-radius: 4px;"></p>` : ''}
            `;
            
            document.getElementById('applicationDetails').innerHTML = details;
            document.getElementById('applicationModal').style.display = 'block';
        }
        
        function closeModal() {
            document.getElementById('applicationModal').style.display = 'none';
        }
        
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
            const applicationModal = document.getElementById('applicationModal');
            const rejectModal = document.getElementById('rejectModal');
            if (event.target == applicationModal) {
                applicationModal.style.display = 'none';
            }
            if (event.target == rejectModal) {
                rejectModal.style.display = 'none';
            }
        }
    </script>
</body>
</html>
