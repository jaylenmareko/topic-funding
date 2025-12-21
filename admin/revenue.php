<?php
// admin/revenue.php - Platform revenue and fee management dashboard
session_start();
require_once '../config/database.php';
require_once '../config/platform_fee_helper.php';

// Admin access check
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_id'], [1, 2, 9, 186])) {
    header('Location: ../index.php');
    exit;
}

$feeManager = new PlatformFeeManager();

// Get platform statistics
$platform_stats = $feeManager->getPlatformStats();
$monthly_revenue = $feeManager->getMonthlyRevenue();
$pending_fees = $feeManager->getPendingFees();
$top_creators = $feeManager->getTopCreators();
$recent_payouts = $feeManager->getCreatorPayouts(null, null);

// Handle payout completion
if ($_POST && isset($_POST['complete_payout'])) {
    $payout_id = (int)$_POST['payout_id'];
    $reference = trim($_POST['payout_reference']);
    
    $result = $feeManager->markPayoutCompleted($payout_id, $reference);
    if ($result['success']) {
        $message = "Payout marked as completed successfully!";
    } else {
        $error = "Failed to update payout: " . $result['error'];
    }
    
    // Refresh data
    $recent_payouts = $feeManager->getCreatorPayouts(null, null);
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Revenue Dashboard - TopicLaunch Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
        .container { max-width: 1400px; margin: 0 auto; }
        .header { background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .nav { margin-bottom: 20px; }
        .nav a { color: #007bff; text-decoration: none; margin-right: 15px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; padding: 25px; border-radius: 8px; text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .stat-number { font-size: 32px; font-weight: bold; margin-bottom: 5px; }
        .stat-label { color: #666; font-size: 14px; }
        .revenue-stat { color: #28a745; }
        .topics-stat { color: #007bff; }
        .creators-stat { color: #6f42c1; }
        .section { background: white; padding: 25px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .section h2 { margin-top: 0; color: #333; }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
        .table th { background: #f8f9fa; font-weight: bold; }
        .status-badge { padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: bold; }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-completed { background: #d4edda; color: #155724; }
        .status-processing { background: #cce5ff; color: #004085; }
        .btn { padding: 6px 12px; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-block; font-size: 12px; }
        .btn-primary { background: #007bff; color: white; }
        .btn-success { background: #28a745; color: white; }
        .message { padding: 10px; margin-bottom: 20px; border-radius: 4px; background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
        .error { padding: 10px; margin-bottom: 20px; border-radius: 4px; background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; }
        .revenue-breakdown { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
        .breakdown-item { background: #f8f9fa; padding: 15px; border-radius: 6px; }
        .breakdown-label { font-weight: bold; color: #666; font-size: 14px; }
        .breakdown-value { font-size: 20px; font-weight: bold; color: #333; }
        .payout-form { display: none; margin-top: 10px; }
        .form-inline { display: flex; gap: 10px; align-items: center; }
        .form-inline input { padding: 6px; border: 1px solid #ddd; border-radius: 4px; }
        
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: 1fr; }
            .revenue-breakdown { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="nav">
            <a href="../index.php">← Back to Home</a>
            <a href="creators.php">Creator Management</a>
            <a href="../topics/index.php">Topics</a>
            <a href="../dashboard/index.php">Dashboard</a>
        </div>

        <div class="header">
            <h1>Revenue Dashboard</h1>
            <p>Platform revenue, fees, and creator payouts</p>
        </div>

        <?php if (isset($message)): ?>
            <div class="message"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <!-- Overall Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number revenue-stat">$<?php echo number_format($platform_stats->total_platform_fees ?? 0, 0); ?></div>
                <div class="stat-label">Total Platform Revenue</div>
            </div>
            <div class="stat-card">
                <div class="stat-number topics-stat"><?php echo $platform_stats->total_funded_topics ?? 0; ?></div>
                <div class="stat-label">Topics Funded</div>
            </div>
            <div class="stat-card">
                <div class="stat-number creators-stat">$<?php echo number_format($platform_stats->total_creator_payouts ?? 0, 0); ?></div>
                <div class="stat-label">Creator Payouts</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($platform_stats->average_fee_percent ?? 10, 1); ?>%</div>
                <div class="stat-label">Average Fee Rate</div>
            </div>
        </div>

        <!-- This Month Revenue -->
        <div class="section">
            <h2>This Month (<?php echo date('F Y'); ?>)</h2>
            <div class="revenue-breakdown">
                <div class="breakdown-item">
                    <div class="breakdown-label">Topics Funded</div>
                    <div class="breakdown-value"><?php echo $monthly_revenue->topics_funded ?? 0; ?></div>
                </div>
                <div class="breakdown-item">
                    <div class="breakdown-label">Gross Revenue</div>
                    <div class="breakdown-value">$<?php echo number_format($monthly_revenue->gross_revenue ?? 0, 2); ?></div>
                </div>
                <div class="breakdown-item">
                    <div class="breakdown-label">Platform Revenue</div>
                    <div class="breakdown-value revenue-stat">$<?php echo number_format($monthly_revenue->platform_revenue ?? 0, 2); ?></div>
                </div>
                <div class="breakdown-item">
                    <div class="breakdown-label">Creator Payouts</div>
                    <div class="breakdown-value">$<?php echo number_format($monthly_revenue->creator_payouts ?? 0, 2); ?></div>
                </div>
            </div>
        </div>

        <!-- Top Earning Creators -->
        <div class="section">
            <h2>Top Earning Creators</h2>
            <?php if (empty($top_creators)): ?>
                <p>No creator earnings yet.</p>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Creator</th>
                            <th>Topics Completed</th>
                            <th>Total Gross</th>
                            <th>Fees Paid</th>
                            <th>Total Earned</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($top_creators as $creator): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($creator->display_name); ?></strong></td>
                                <td><?php echo $creator->topics_completed; ?></td>
                                <td>$<?php echo number_format($creator->total_gross, 2); ?></td>
                                <td>$<?php echo number_format($creator->total_fees_paid, 2); ?></td>
                                <td><strong>$<?php echo number_format($creator->total_earned, 2); ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Recent Creator Payouts -->
        <div class="section">
            <h2>Creator Payouts</h2>
            <?php if (empty($recent_payouts)): ?>
                <p>No payouts yet.</p>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Creator</th>
                            <th>Topic</th>
                            <th>Gross Amount</th>
                            <th>Platform Fee</th>
                            <th>Net Payout</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($recent_payouts, 0, 20) as $payout): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($payout->creator_name); ?></td>
                                <td><?php echo htmlspecialchars($payout->topic_title); ?></td>
                                <td>$<?php echo number_format($payout->gross_amount, 2); ?></td>
                                <td>$<?php echo number_format($payout->platform_fee, 2); ?></td>
                                <td><strong>$<?php echo number_format($payout->net_amount, 2); ?></strong></td>
                                <td>
                                    <span class="status-badge status-<?php echo $payout->status; ?>">
                                        <?php echo ucfirst($payout->status); ?>
                                    </span>
                                </td>
                                <td><?php echo date('M j, Y', strtotime($payout->created_at)); ?></td>
                                <td>
                                    <?php if ($payout->status === 'pending'): ?>
                                        <button onclick="showPayoutForm(<?php echo $payout->id; ?>)" class="btn btn-primary">
                                            Mark Paid
                                        </button>
                                        <div id="payout-form-<?php echo $payout->id; ?>" class="payout-form">
                                            <form method="POST" class="form-inline">
                                                <input type="hidden" name="payout_id" value="<?php echo $payout->id; ?>">
                                                <input type="text" name="payout_reference" placeholder="Payment reference/ID" required>
                                                <button type="submit" name="complete_payout" class="btn btn-success">Complete</button>
                                                <button type="button" onclick="hidePayoutForm(<?php echo $payout->id; ?>)" class="btn">Cancel</button>
                                            </form>
                                        </div>
                                    <?php elseif ($payout->payout_reference): ?>
                                        <small>Ref: <?php echo htmlspecialchars($payout->payout_reference); ?></small>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <script>
    function showPayoutForm(payoutId) {
        document.getElementById('payout-form-' + payoutId).style.display = 'block';
    }
    
    function hidePayoutForm(payoutId) {
        document.getElementById('payout-form-' + payoutId).style.display = 'none';
    }
    </script>
</body>
</html>
