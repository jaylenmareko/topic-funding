<?php
// admin/webhook_debug.php - Debug tool for webhook issues
session_start();
require_once '../config/database.php';
require_once '../config/stripe.php';

// Admin access check
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_id'], [1, 2, 9, 186])) {
    die('Admin access required');
}

echo "<!DOCTYPE html><html><head><title>Webhook Debug Tool</title>";
echo "<style>
body { font-family: Arial, sans-serif; margin: 20px; line-height: 1.6; }
.debug { background: #f8f9fa; padding: 15px; border-radius: 4px; margin: 20px 0; border: 1px solid #dee2e6; }
.success { background: #d4edda; color: #155724; padding: 15px; border-radius: 4px; margin: 15px 0; }
.error { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 4px; margin: 15px 0; }
.warning { background: #fff3cd; color: #856404; padding: 15px; border-radius: 4px; margin: 15px 0; }
pre { background: #f8f9fa; padding: 15px; border-radius: 4px; overflow-x: auto; white-space: pre-wrap; }
table { border-collapse: collapse; width: 100%; margin: 20px 0; }
th, td { border: 1px solid #ddd; padding: 12px; text-align: left; }
th { background: #f8f9fa; }
.btn { background: #007bff; color: white; padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; margin: 5px; }
.btn-danger { background: #dc3545; }
.btn-success { background: #28a745; }
</style></head><body>";

echo "<h1>🛠️ Webhook Debug Tool</h1>";

$db = new Database();

// Get recent payment intents from Stripe
echo "<h2>🔍 Recent Stripe Payment Intents (Last 24 Hours)</h2>";

try {
    $recent_payments = \Stripe\PaymentIntent::all([
        'limit' => 20,
        'created' => [
            'gte' => time() - 86400, // Last 24 hours
        ],
    ]);
    
    echo "<table>";
    echo "<tr><th>Payment ID</th><th>Amount</th><th>Status</th><th>Created</th><th>Metadata</th><th>Processed?</th><th>Actions</th></tr>";
    
    foreach ($recent_payments->data as $payment) {
        // Check if processed in database
        $db->query('SELECT * FROM contributions WHERE payment_id = :payment_id');
        $db->bind(':payment_id', $payment->id);
        $contribution = $db->single();
        
        $processed = $contribution ? 'YES' : 'NO';
        $row_class = $payment->status === 'succeeded' && !$contribution ? 'style="background: #fff3cd;"' : '';
        
        echo "<tr $row_class>";
        echo "<td><small>" . $payment->id . "</small></td>";
        echo "<td>$" . ($payment->amount / 100) . "</td>";
        echo "<td>" . $payment->status . "</td>";
        echo "<td>" . date('Y-m-d H:i:s', $payment->created) . "</td>";
        echo "<td><pre>" . json_encode($payment->metadata, JSON_PRETTY_PRINT) . "</pre></td>";
        echo "<td>" . ($processed === 'YES' ? '✅' : '❌') . " " . $processed . "</td>";
        echo "<td>";
        
        if ($payment->status === 'succeeded' && !$contribution) {
            echo "<form method='POST' style='display:inline;'>";
            echo "<input type='hidden' name='manual_process' value='" . $payment->id . "'>";
            echo "<button type='submit' class='btn btn-danger'>🚨 Process Now</button>";
            echo "</form>";
        } elseif ($contribution) {
            echo "<span style='color: #28a745;'>✅ OK</span>";
            if ($contribution->topic_id) {
                echo "<br><a href='../topics/view.php?id=" . $contribution->topic_id . "' target='_blank'>View Topic</a>";
            }
        }
        
        echo "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
} catch (Exception $e) {
    echo "<div class='error'>Error loading payments: " . $e->getMessage() . "</div>";
}

// Manual processing
if ($_POST && isset($_POST['manual_process'])) {
    $payment_id = $_POST['manual_process'];
    
    echo "<h2>🔄 Manually Processing Payment: " . $payment_id . "</h2>";
    
    try {
        require_once '../config/funding_processor.php';
        $processor = new FundingProcessor();
        
        $result = $processor->handlePaymentSuccess($payment_id);
        
        if ($result['success']) {
            echo "<div class='success'>✅ Payment processed successfully!</div>";
            echo "<pre>" . json_encode($result, JSON_PRETTY_PRINT) . "</pre>";
        } else {
            echo "<div class='error'>❌ Processing failed: " . $result['error'] . "</div>";
        }
        
    } catch (Exception $e) {
        echo "<div class='error'>❌ Exception: " . $e->getMessage() . "</div>";
    }
}

// Database status
echo "<h2>📊 Database Status</h2>";

try {
    // Recent topics
    $db->query('SELECT * FROM topics ORDER BY created_at DESC LIMIT 5');
    $topics = $db->resultSet();
    
    echo "<h3>Recent Topics</h3>";
    if (empty($topics)) {
        echo "<div class='warning'>No topics found in database</div>";
    } else {
        echo "<table>";
        echo "<tr><th>ID</th><th>Title</th><th>Creator</th><th>Status</th><th>Funding</th><th>Created</th></tr>";
        foreach ($topics as $topic) {
            echo "<tr>";
            echo "<td>" . $topic->id . "</td>";
            echo "<td>" . htmlspecialchars(substr($topic->title, 0, 50)) . "...</td>";
            echo "<td>" . $topic->creator_id . "</td>";
            echo "<td>" . $topic->status . "</td>";
            echo "<td>$" . $topic->current_funding . " / $" . $topic->funding_threshold . "</td>";
            echo "<td>" . $topic->created_at . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // Recent contributions
    $db->query('SELECT c.*, u.username FROM contributions c LEFT JOIN users u ON c.user_id = u.id ORDER BY c.contributed_at DESC LIMIT 5');
    $contributions = $db->resultSet();
    
    echo "<h3>Recent Contributions</h3>";
    if (empty($contributions)) {
        echo "<div class='warning'>No contributions found in database</div>";
    } else {
        echo "<table>";
        echo "<tr><th>ID</th><th>Topic</th><th>User</th><th>Amount</th><th>Status</th><th>Payment ID</th><th>Date</th></tr>";
        foreach ($contributions as $contrib) {
            echo "<tr>";
            echo "<td>" . $contrib->id . "</td>";
            echo "<td>" . ($contrib->topic_id ?: 'NULL') . "</td>";
            echo "<td>" . htmlspecialchars($contrib->username ?: 'Unknown') . "</td>";
            echo "<td>$" . $contrib->amount . "</td>";
            echo "<td>" . $contrib->payment_status . "</td>";
            echo "<td><small>" . ($contrib->payment_id ?: 'NULL') . "</small></td>";
            echo "<td>" . $contrib->contributed_at . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // Guest users
    $db->query('SELECT COUNT(*) as count FROM users WHERE email LIKE "%@temp.topiclaunch.com"');
    $guest_count = $db->single()->count;
    echo "<div class='debug'><strong>Guest users in database:</strong> " . $guest_count . "</div>";
    
} catch (Exception $e) {
    echo "<div class='error'>Database error: " . $e->getMessage() . "</div>";
}

// Webhook configuration
echo "<h2>⚙️ Webhook Configuration</h2>";
echo "<div class='debug'>";
echo "<p><strong>Webhook endpoint secret (first 10 chars):</strong> " . substr(STRIPE_WEBHOOK_SECRET, 0, 10) . "...</p>";
echo "<p><strong>Stripe publishable key (first 10 chars):</strong> " . substr(STRIPE_PUBLISHABLE_KEY, 0, 10) . "...</p>";
echo "<p><strong>Expected webhook URL:</strong> https://topiclaunch.com/webhooks/stripe.php</p>";
echo "<p><strong>Required events:</strong> payment_intent.succeeded, payment_intent.payment_failed</p>";
echo "</div>";

// Test webhook endpoint
echo "<h2>🧪 Test Webhook Endpoint</h2>";
echo "<div class='debug'>";
echo "<p>Test if your webhook endpoint is accessible:</p>";
echo "<p><a href='../webhooks/stripe.php' target='_blank'>../webhooks/stripe.php</a></p>";
echo "<p><em>Should return a 400 error (which is expected for manual access)</em></p>";
echo "</div>";

// Error log reader
echo "<h2>📝 Recent Error Logs</h2>";
$log_file = '../logs/php_errors.log';
if (file_exists($log_file)) {
    $logs = file_get_contents($log_file);
    $recent_logs = implode("\n", array_slice(explode("\n", $logs), -20));
    echo "<pre>" . htmlspecialchars($recent_logs) . "</pre>";
} else {
    echo "<div class='warning'>No error log file found at: " . $log_file . "</div>";
}

echo "</body></html>";
?>
