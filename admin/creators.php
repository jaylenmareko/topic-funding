<?php
// admin/creators.php - Enhanced admin interface with delete and refund features
session_start();
require_once '../config/database.php';
require_once '../config/stripe.php';

// Admin access check - allow user IDs 1, 2, and 9
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_id'], [1, 2, 9])) {
    header('Location: ../index.php');
    exit;
}

$db = new Database();
$message = '';
$error = '';

// Handle actions
if ($_POST && isset($_POST['action']) && isset($_POST['creator_id'])) {
    $creator_id = (int)$_POST['creator_id'];
    $action = $_POST['action'];
    
    try {
        $db->beginTransaction();
        
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
            
        } elseif ($action === 'delete') {
            // Get creator info first
            $db->query('SELECT * FROM creators WHERE id = :id');
            $db->bind(':id', $creator_id);
            $creator = $db->single();
            
            if ($creator) {
                // Get all active topics for this creator
                $db->query('SELECT * FROM topics WHERE creator_id = :creator_id AND status IN ("active", "funded")');
                $db->bind(':creator_id', $creator_id);
                $active_topics = $db->resultSet();
                
                $refund_count = 0;
                $refund_amount = 0;
                
                // Process refunds for each active topic
                foreach ($active_topics as $topic) {
                    // Get all completed contributions for this topic
                    $db->query('
                        SELECT c.*, u.email 
                        FROM contributions c 
                        JOIN users u ON c.user_id = u.id 
                        WHERE c.topic_id = :topic_id AND c.payment_status = "completed"
                    ');
                    $db->bind(':topic_id', $topic->id);
                    $contributions = $db->resultSet();
                    
                    // Process Stripe refunds
                    foreach ($contributions as $contribution) {
                        if ($contribution->payment_id) {
                            try {
                                // Create refund in Stripe
                                $refund = \Stripe\Refund::create([
                                    'payment_intent' => $contribution->payment_id,
                                    'reason' => 'requested_by_customer',
                                    'metadata' => [
                                        'reason' => 'Creator deleted - automatic refund',
                                        'topic_id' => $topic->id,
                                        'creator_id' => $creator_id
                                    ]
                                ]);
                                
                                // Update contribution status
                                $db->query('UPDATE contributions SET payment_status = "refunded" WHERE id = :id');
                                $db->bind(':id', $contribution->id);
                                $db->execute();
                                
                                $refund_count++;
                                $refund_amount += $contribution->amount;
                                
                            } catch (Exception $e) {
                                error_log("Refund failed for contribution " . $contribution->id . ": " . $e->getMessage());
                            }
                        }
                    }
                    
                    // Cancel the topic
                    $db->query('UPDATE topics SET status = "cancelled" WHERE id = :id');
                    $db->bind(':id', $topic->id);
                    $db->execute();
                }
                
                // Delete the creator
                $db->query('DELETE FROM creators WHERE id = :id');
                $db->bind(':id', $creator_id);
                $db->execute();
                
                $message = "Creator deleted successfully! Cancelled " . count($active_topics) . " topics and processed " . $refund_count . " refunds totaling $" . number_format($refund_amount, 2) . ".";
            }
        }
        
        $db->endTransaction();
        
    } catch (Exception $e) {
        $db->cancelTransaction();
        $error = "Error processing request: " . $e->getMessage();
        error_log("Admin action error: " . $e->getMessage());
    }
}

// Handle topic actions
if ($_POST && isset($_POST['topic_action']) && isset($_POST['topic_id'])) {
    $topic_id = (int)$_POST['topic_id'];
    $action = $_POST['topic_action'];
    
    try {
        $db->beginTransaction();
        
        if ($action === 'cancel_topic') {
            // Get topic info
            $db->query('SELECT * FROM topics WHERE id = :id');
            $db->bind(':id', $topic_id);
            $topic = $db->single();
            
            if ($topic && $topic->status !== 'completed') {
                // Get all completed contributions
                $db->query('
                    SELECT c.*, u.email 
                    FROM contributions c 
                    JOIN users u ON c.user_id = u.id 
                    WHERE c.topic_id = :topic_id AND c.payment_status = "completed"
                ');
                $db->bind(':topic_id', $topic_id);
                $contributions = $db->resultSet();
                
                $refund_count = 0;
                $refund_amount = 0;
                
                // Process Stripe refunds
                foreach ($contributions as $contribution) {
                    if ($contribution->payment_id) {
                        try {
                            $refund = \Stripe\Refund::create([
                                'payment_intent' => $contribution->payment_id,
                                'reason' => 'requested_by_customer',
                                'metadata' => [
                                    'reason' => 'Topic cancelled by admin',
                                    'topic_id' => $topic_id
                                ]
                            ]);
                            
                            $db->query('UPDATE contributions SET payment_status = "refunded" WHERE id = :id');
                            $db->bind(':id', $contribution->id);
                            $db->execute();
                            
                            $refund_count++;
                            $refund_amount += $contribution->amount;
                            
                        } catch (Exception $e) {
                            error_log("Refund failed for contribution " . $contribution->id . ": " . $e->getMessage());
                        }
                    }
                }
                
                // Cancel the topic
                $db->query('UPDATE topics SET status = "cancelled" WHERE id = :id');
                $db->bind(':id', $topic_id);
                $db->execute();
                
                $message = "Topic cancelled successfully! Processed " . $refund_count . " refunds totaling $" . number_format($refund_amount, 2) . ".";
            }
        }
        
        $db->endTransaction();
        
    } catch (Exception $e) {
        $db->cancelTransaction();
        $error = "Error cancelling topic: " . $e->getMessage();
    }
}

// Get all creator applications
$db->query('
    SELECT c.*, u.username, u.email,
           (SELECT COUNT(*) FROM topics WHERE creator_id = c.id) as topic_count,
           (SELECT COUNT(*) FROM topics WHERE creator_id = c.id AND status = "active") as active_topics,
           (SELECT COALESCE(SUM(current_funding), 0) FROM topics WHERE creator_id = c.id) as total_funding
    FROM creators c 
    LEFT JOIN users u ON c.applicant_user_id = u.id 
    ORDER BY c.created_at DESC
');
$applications = $db->resultSet();

// Get recent topics for admin review
$db->query('
    SELECT t.*, c.display_name as creator_name,
           (SELECT COUNT(*) FROM contributions WHERE topic_id = t.id AND payment_status = "completed") as contributor_count
    FROM topics t
    JOIN creators c ON t.creator_id = c.id
    WHERE t.status IN ("active", "funded")
    ORDER BY t.created_at DESC
    LIMIT 10
');
$recent_topics = $db->resultSet();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Admin - Creator & Topic Management</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
        .container { max-width: 1400px; margin: 0 auto; }
        .header { background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .nav { margin-bottom: 20px; }
        .nav a { color: #007bff; text-decoration: none; margin-right: 15px; }
        .message { padding: 10px; margin-bottom: 20px; border-radius: 4px; background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
        .error { padding: 10px; margin-bottom: 20px; border-radius: 4px; background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; }
        .admin-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; }
        .section { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .application-card { background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .app-header { display: flex; justify-content: space-between; align-items: start; margin-bottom: 15px; }
        .app-info { flex: 1; }
        .app-actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .status-badge { padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: bold; }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-approved { background: #d4edda; color: #155724; }
        .status-rejected { background: #f8d7da; color: #721c24; }
        .btn { padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-block; font-size: 12px; }
        .btn-success { background: #28a745; color: white; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-warning { background: #ffc107; color: #212529; }
        .btn-info { background: #17a2b8; color: white; }
        .creator-details { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin: 15px 0; }
        .detail-label { font-weight: bold; color: #666; font-size: 12px; }
        .profile-image { width: 50px; height: 50px; border-radius: 50%; object-fit: cover; margin-right: 10px; }
        .stats-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .stat-card { background: white; padding: 15px; border-radius: 8px; text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .stat-number { font-size: 20px; font-weight: bold; color: #007bff; }
        .stat-label { color: #666; font-size: 12px; }
        .topic-item { padding: 15px; border: 1px solid #eee; border-radius: 6px; margin-bottom: 10px; }
        .topic-header { display: flex; justify-content: space-between; align-items: start; margin-bottom: 10px; }
        .topic-title { font-weight: bold; font-size: 14px; }
        .topic-meta { font-size: 12px; color: #666; }
        .danger-zone { border: 2px solid #dc3545; border-radius: 8px; padding: 15px; margin-top: 20px; background: #fff5f5; }
        .warning-text { color: #dc3545; font-weight: bold; margin-bottom: 10px; }
        
        @media (max-width: 768px) {
            .admin-grid { grid-template-columns: 1fr; }
            .app-header { flex-direction: column; gap: 15px; }
            .app-actions { justify-content: center; }
            .creator-details { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="nav">
            <a href="../index.php">← Back to Home</a>
            <a href="../creators/index.php">View Creators</a>
            <a href="../topics/index.php">View Topics</a>
            <a href="../dashboard/index.php">Dashboard</a>
        </div>

        <div class="header">
            <h1>Admin Control Panel</h1>
            <p>Manage creators, topics, and process refunds</p>
            <div style="color: #666; font-size: 14px;">
                Logged in as <?php echo htmlspecialchars($_SESSION['username']); ?> (User ID: <?php echo $_SESSION['user_id']; ?>)
            </div>
        </div>

        <?php if ($message): ?>
            <div class="message"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php
        // Calculate statistics
        $pending_count = 0;
        $approved_count = 0;
        $rejected_count = 0;
        $total_funding = 0;
        foreach ($applications as $app) {
            switch ($app->application_status) {
                case 'pending': $pending_count++; break;
                case 'approved': $approved_count++; break;
                case 'rejected': $rejected_count++; break;
            }
            $total_funding += $app->total_funding;
        }
        ?>

        <div class="stats-summary">
            <div class="stat-card">
                <div class="stat-number"><?php echo $pending_count; ?></div>
                <div class="stat-label">Pending Applications</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $approved_count; ?></div>
                <div class="stat-label">Active Creators</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo count($recent_topics); ?></div>
                <div class="stat-label">Active Topics</div>
            </div>
            <div class="stat-card">
                <div class="stat-number">$<?php echo number_format($total_funding, 0); ?></div>
                <div class="stat-label">Total Platform Funding</div>
            </div>
        </div>

        <div class="admin-grid">
            <div>
                <div class="section">
                    <h2>Creator Applications</h2>
                    
                    <?php if (empty($applications)): ?>
                        <div style="text-align: center; color: #666; padding: 20px;">
                            <p>No creator applications yet.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($applications as $app): ?>
                            <div class="application-card">
                                <div class="app-header">
                                    <div class="app-info">
                                        <div style="display: flex; align-items: center; margin-bottom: 10px;">
                                            <?php if ($app->profile_image && file_exists('../uploads/creators/' . $app->profile_image)): ?>
                                                <img src="../uploads/creators/<?php echo htmlspecialchars($app->profile_image); ?>" alt="Profile" class="profile-image">
                                            <?php endif; ?>
                                            <div>
                                                <h4 style="margin: 0;">
                                                    <?php echo htmlspecialchars($app->display_name); ?>
                                                    <span class="status-badge status-<?php echo $app->application_status; ?>">
                                                        <?php echo ucfirst($app->application_status); ?>
                                                    </span>
                                                </h4>
                                                <div style="font-size: 12px; color: #666;">
                                                    <?php echo ucfirst($app->platform_type); ?> • <?php echo number_format($app->subscriber_count); ?> subscribers
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <?php if ($app->is_active): ?>
                                        <div style="font-size: 12px; color: #666;">
                                            <strong><?php echo $app->topic_count; ?></strong> topics created • 
                                            <strong><?php echo $app->active_topics; ?></strong> active • 
                                            <strong>$<?php echo number_format($app->total_funding, 0); ?></strong> total funding
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="app-actions">
                                        <?php if ($app->application_status === 'pending'): ?>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="creator_id" value="<?php echo $app->id; ?>">
                                                <input type="hidden" name="action" value="approve">
                                                <button type="submit" class="btn btn-success" onclick="return confirm('Approve this creator?')">Approve</button>
                                            </form>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="creator_id" value="<?php echo $app->id; ?>">
                                                <input type="hidden" name="action" value="reject">
                                                <button type="submit" class="btn btn-warning" onclick="return confirm('Reject this application?')">Reject</button>
                                            </form>
                                        <?php endif; ?>
                                        
                                        <?php if ($app->is_active): ?>
                                            <a href="../creators/profile.php?id=<?php echo $app->id; ?>" class="btn btn-info">View Profile</a>
                                        <?php endif; ?>
                                        
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="creator_id" value="<?php echo $app->id; ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <button type="submit" class="btn btn-danger" onclick="return confirm('DELETE CREATOR?\n\nThis will:\n• Delete the creator permanently\n• Cancel ALL their topics\n• Refund ALL contributors\n• This action cannot be undone\n\nType DELETE to confirm')">Delete Creator</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div>
                <div class="section">
                    <h3>Recent Active Topics</h3>
                    
                    <?php if (empty($recent_topics)): ?>
                        <div style="text-align: center; color: #666; padding: 20px;">
                            <p>No active topics.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($recent_topics as $topic): ?>
                            <div class="topic-item">
                                <div class="topic-header">
                                    <div>
                                        <div class="topic-title"><?php echo htmlspecialchars($topic->title); ?></div>
                                        <div class="topic-meta">
                                            By <?php echo htmlspecialchars($topic->creator_name); ?> • 
                                            <?php echo $topic->contributor_count; ?> contributors • 
                                            $<?php echo number_format($topic->current_funding, 0); ?> / $<?php echo number_format($topic->funding_threshold, 0); ?>
                                        </div>
                                    </div>
                                    <span class="status-badge status-<?php echo $topic->status === 'active' ? 'pending' : 'approved'; ?>">
                                        <?php echo ucfirst($topic->status); ?>
                                    </span>
                                </div>
                                
                                <div style="margin-top: 10px;">
                                    <a href="../topics/view.php?id=<?php echo $topic->id; ?>" class="btn btn-info">View</a>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="topic_id" value="<?php echo $topic->id; ?>">
                                        <input type="hidden" name="topic_action" value="cancel_topic">
                                        <button type="submit" class="btn btn-danger" onclick="return confirm('CANCEL TOPIC?\n\nThis will:\n• Cancel the topic permanently\n• Refund ALL contributors\n• This action cannot be undone')">Cancel & Refund</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="section">
                    <h3>⚠️ Admin Actions</h3>
                    <div class="warning-text">Dangerous operations - use with caution</div>
                    
                    <div style="margin-top: 15px;">
                        <div style="font-size: 14px; margin-bottom: 10px;">
                            <strong>Delete Creator:</strong> Permanently removes creator, cancels all topics, refunds all contributors
                        </div>
                        <div style="font-size: 14px; margin-bottom: 10px;">
                            <strong>Cancel Topic:</strong> Cancels topic and refunds all contributors via Stripe
                        </div>
                        <div style="font-size: 12px; color: #666;">
                            All refunds are processed automatically through Stripe API
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    // Enhanced confirmation for dangerous actions
    document.querySelectorAll('button[onclick*="DELETE CREATOR"]').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            const userInput = prompt('This will permanently delete the creator and refund all money.\n\nType "DELETE" to confirm:');
            if (userInput === 'DELETE') {
                this.closest('form').submit();
            }
        });
    });
    </script>
</body>
</html>
