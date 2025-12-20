<?php
// admin/index.php - Admin Dashboard Hub
session_start();
require_once '../config/database.php';

// Admin access check
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_id'], [1, 2, 9])) {
    header('Location: ../auth/google-oauth.php');
    exit;
}

// Get basic stats
try {
    $db = new Database();
    
    // Total creators
    $db->query('SELECT COUNT(*) as count FROM creators WHERE is_active = 1');
    $total_creators = $db->single()->count;
    
    // Total topics
    $db->query('SELECT COUNT(*) as count FROM topics');
    $total_topics = $db->single()->count;
    
    // Total funded topics
    $db->query('SELECT COUNT(*) as count FROM topics WHERE status IN ("funded", "completed")');
    $funded_topics = $db->single()->count;
    
    // Total revenue (sum of all contributions)
    $db->query('SELECT SUM(amount) as total FROM contributions WHERE payment_status = "succeeded"');
    $total_revenue = $db->single()->total ?? 0;
    
} catch (Exception $e) {
    $total_creators = 0;
    $total_topics = 0;
    $funded_topics = 0;
    $total_revenue = 0;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Admin Dashboard - TopicLaunch</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; background: #f5f5f5; }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px 20px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .header h1 {
            font-size: 36px;
            margin-bottom: 10px;
        }
        
        .header p {
            opacity: 0.9;
            font-size: 18px;
        }
        
        .container {
            max-width: 1200px;
            margin: 40px auto;
            padding: 0 20px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }
        
        .stat-card {
            background: white;
            padding: 30px;
            border-radius: 12px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 16px rgba(0,0,0,0.15);
        }
        
        .stat-number {
            font-size: 48px;
            font-weight: bold;
            margin-bottom: 10px;
        }
        
        .stat-number.green { color: #28a745; }
        .stat-number.blue { color: #007bff; }
        .stat-number.purple { color: #6f42c1; }
        .stat-number.orange { color: #fd7e14; }
        
        .stat-label {
            color: #666;
            font-size: 16px;
        }
        
        .tools-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }
        
        .tool-card {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .tool-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 16px rgba(0,0,0,0.15);
        }
        
        .tool-icon {
            font-size: 48px;
            margin-bottom: 15px;
        }
        
        .tool-title {
            font-size: 24px;
            font-weight: bold;
            color: #333;
            margin-bottom: 10px;
        }
        
        .tool-description {
            color: #666;
            margin-bottom: 20px;
            line-height: 1.6;
        }
        
        .tool-button {
            display: inline-block;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px 24px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: bold;
            transition: opacity 0.3s ease;
        }
        
        .tool-button:hover {
            opacity: 0.9;
            color: white;
            text-decoration: none;
        }
        
        .logout-btn {
            position: fixed;
            top: 20px;
            right: 20px;
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: bold;
            backdrop-filter: blur(10px);
        }
        
        .logout-btn:hover {
            background: rgba(255,255,255,0.3);
            color: white;
            text-decoration: none;
        }
        
        @media (max-width: 768px) {
            .header h1 { font-size: 28px; }
            .stat-number { font-size: 36px; }
            .tools-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <a href="../auth/logout.php" class="logout-btn">🚪 Logout</a>
    
    <div class="header">
        <h1>🎯 Admin Dashboard</h1>
        <p>TopicLaunch Platform Management</p>
    </div>
    
    <div class="container">
        <!-- Stats Overview -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number green">$<?php echo number_format($total_revenue, 0); ?></div>
                <div class="stat-label">Total Revenue</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-number blue"><?php echo $total_topics; ?></div>
                <div class="stat-label">Total Topics</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-number purple"><?php echo $funded_topics; ?></div>
                <div class="stat-label">Funded Topics</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-number orange"><?php echo $total_creators; ?></div>
                <div class="stat-label">Active Creators</div>
            </div>
        </div>
        
        <!-- Admin Tools -->
        <div class="tools-grid">
            <!-- Creator Management -->
            <div class="tool-card">
                <div class="tool-icon">👥</div>
                <div class="tool-title">Creator Management</div>
                <div class="tool-description">
                    Manage creator accounts, activate/deactivate creators, and view creator statistics.
                </div>
                <a href="creators.php" class="tool-button">Manage Creators</a>
            </div>
            
            <!-- Payment Processor -->
            <div class="tool-card">
                <div class="tool-icon">💳</div>
                <div class="tool-title">Payment Processor</div>
                <div class="tool-description">
                    Debug and manually process stuck payments, view recent Stripe transactions.
                </div>
                <a href="manual_payment_processor.php" class="tool-button">Process Payments</a>
            </div>
            
            <!-- Revenue Dashboard -->
            <div class="tool-card">
                <div class="tool-icon">💰</div>
                <div class="tool-title">Revenue Dashboard</div>
                <div class="tool-description">
                    View platform revenue, creator payouts, and financial statistics.
                </div>
                <a href="revenue.php" class="tool-button">View Revenue</a>
            </div>
            
            <!-- Webhook Debug -->
            <div class="tool-card">
                <div class="tool-icon">🔧</div>
                <div class="tool-title">Webhook Debug</div>
                <div class="tool-description">
                    Debug webhook issues, view recent events, and test webhook configuration.
                </div>
                <a href="webhook_debug.php" class="tool-button">Debug Webhooks</a>
            </div>
            
            <!-- Bulk Creator Signup -->
            <div class="tool-card">
                <div class="tool-icon">📝</div>
                <div class="tool-title">Bulk Creator Signup</div>
                <div class="tool-description">
                    Add multiple creators at once with automatic profile image downloads.
                </div>
                <a href="bulk_creator_signup_with_images.php" class="tool-button">Bulk Add Creators</a>
            </div>
            
            <!-- View Site -->
            <div class="tool-card">
                <div class="tool-icon">🏠</div>
                <div class="tool-title">View Site</div>
                <div class="tool-description">
                    Go to the main TopicLaunch landing page and browse as a visitor.
                </div>
                <a href="../index.php" class="tool-button">Go to Site</a>
            </div>
        </div>
    </div>
</body>
</html>
