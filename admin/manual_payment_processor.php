<?php
// admin/manual_payment_processor.php - COMPLETE FILE WITH STUCK PAYMENT DEBUG
session_start();
require_once '../config/database.php';
require_once '../config/stripe.php';
require_once '../config/funding_processor.php';

// === HTML HEAD AND STYLES ===
echo "<!DOCTYPE html><html><head><title>Manual Payment Processor - TopicLaunch Admin</title>";
echo "<style>
body { font-family: Arial, sans-serif; margin: 20px; line-height: 1.6; }
.debug { background: #f8f9fa; padding: 15px; border-radius: 4px; margin: 20px 0; border: 1px solid #dee2e6; }
.success { background: #d4edda; color: #155724; padding: 15px; border-radius: 4px; margin: 15px 0; border: 1px solid #c3e6cb; }
.error { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 4px; margin: 15px 0; border: 1px solid #f5c6cb; }
.warning { background: #fff3cd; color: #856404; padding: 15px; border-radius: 4px; margin: 15px 0; border: 1px solid #ffeaa7; }
.alert { background: #f8d7da; color: #721c24; padding: 20px; border-radius: 4px; margin: 20px 0; border: 2px solid #dc3545; }
pre { background: #f8f9fa; padding: 15px; border-radius: 4px; overflow-x: auto; border: 1px solid #dee2e6; }
table { margin: 20px 0; border-collapse: collapse; width: 100%; }
th, td { padding: 12px 8px; text-align: left; border: 1px solid #ddd; }
th { background: #f8f9fa; font-weight: bold; }
.form-group { margin-bottom: 15px; }
input[type='text'] { width: 400px; padding: 10px; border: 1px solid #ddd; border-radius: 4px; }
button { background: #007bff; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; }
button:hover { background: #0056b3; }
.btn-danger { background: #dc3545; }
.btn-danger:hover { background: #c82333; }
.btn-success { background: #28a745; }
.btn-success:hover { background: #218838; }
h1, h2, h3 { color: #333; }
.highlight { background: #fff3cd; }
.processed { background: #d4edda; }
code { background: #f8f9fa; padding: 2px 6px; border-radius: 3px; font-family: monospace; }
</style></head><body>";

echo "<h1>🔧 Manual Payment Processor - TopicLaunch Admin</h1>";

// === ADMIN ACCESS CHECK WITH DEBUG ===
echo "<h2>🔍 Admin Access Debug Information</h2>";
echo "<div class='debug'>";

if (isset($_SESSION['user_id'])) {
    echo "<p>✅ <strong>Session active:</strong> User ID = " . $_SESSION['user_id'] . "</p>";
    
    $admin_user_ids = [1, 2, 9, 186];
    $is_in_admin_array = in_array($_SESSION['user_id'], $admin_user_ids);
    echo "<p>" . ($is_in_admin_array ? "✅" : "❌") . " <strong>In admin array:</strong> " . ($is_in_admin_array ? "Yes" : "No") . "</p>";
    
    try {
        $db = new Database();
        $db->query('SELECT * FROM users WHERE id = :user_id');
        $db->bind(':user_id', $_SESSION['user_id']);
        $user = $db->single();
        
        if ($user) {
            echo "<p>✅ <strong>User found in database:</strong></p>";
            echo "<ul>";
            echo "<li>ID: " . $user->id . "</li>";
            echo "<li>Username: " . htmlspecialchars($user->username) . "</li>";
            echo "<li>Email: " . htmlspecialchars($user->email) . "</li>";
            echo "<li>Is Active: " . ($user->is_active ? "Yes" : "No") . "</li>";
            echo "</ul>";
        } else {
            echo "<p>❌ <strong>User NOT found in database</strong></p>";
        }
    } catch (Exception $e) {
        echo "<p>❌ <strong>Database error:</strong> " . $e->getMessage() . "</p>";
    }
} else {
    echo "<p>❌ <strong>No session:</strong> User not logged in</p>";
    echo "<p><a href='../auth/login.php' style='background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px;'>Click here to login</a></p>";
}

echo "</div>";

// ADMIN ACCESS CONTROL
if (!isset($_SESSION['user_id'])) {
    echo "<div class='error'>";
    echo "<h3>❌ Not Logged In</h3>";
    echo "<p>You need to be logged in to access the admin panel.</p>";
    echo "<p><a href='../auth/login.php' style='background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px;'>Login</a></p>";
    echo "</div></body></html>";
    exit;
}

if (!in_array($_SESSION['user_id'], [1, 2, 9])) {
    echo "<div class='error'>";
    echo "<h3>❌ Admin Access Required</h3>";
    echo "<p>Your User ID: <strong>" . $_SESSION['user_id'] . "</strong></p>";
    echo "<p>You need to be logged in as user ID 1, 2, or 9 to access this admin panel.</p>";
    echo "<p>Current allowed admin user IDs: 1, 2, 9</p>";
    echo "<p><a href='../auth/logout.php' style='background: #dc3545; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; margin-right: 10px;'>Logout</a>";
    echo "<a href='../index.php' style='background: #6c757d; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px;'>Home</a></p>";
    echo "</div></body></html>";
    exit;
}

echo "<div class='success'>";
echo "<h3>✅ Admin Access Granted</h3>";
echo "<p>Welcome to the admin panel, User ID: <strong>" . $_SESSION['user_id'] . "</strong></p>";
echo "</div>";

// === STUCK PAYMENT DETECTION ===
echo "<h2>🚨 Stuck Payment Detection & Recovery</h2>";

try {
    $recent_payments = \Stripe\PaymentIntent::all([
        'limit' => 50,
        'created' => [
            'gte' => time() - 7200, // Last 2 hours
        ],
    ]);
    
    echo "<h3>Recent Stripe Payments (Last 2 Hours):</h3>";
    echo "<table>";
    echo "<tr><th>Payment ID</th><th>Amount</th><th>Status</th><th>Type</th><th>Processed?</th><th>Action</th></tr>";
    
    $db = new Database();
    $stuck_payments = [];
    
    foreach ($recent_payments->data as $payment) {
        // Check if this payment was processed in our database
        $db->query('SELECT id FROM contributions WHERE payment_id = :payment_id');
        $db->bind(':payment_id', $payment->id);
        $processed = $db->single();
        
        // Get metadata type
        $type = 'Unknown';
        $metadata = null;
        
        if (!empty($payment->metadata->type)) {
            $type = $payment->metadata->type;
            $metadata = $payment->metadata;
        } else {
            // Check checkout session
            try {
                $sessions = \Stripe\Checkout\Session::all([
                    'payment_intent' => $payment->id,
                    'limit' => 1
                ]);
                if (!empty($sessions->data) && !empty($sessions->data[0]->metadata->type)) {
                    $type = $sessions->data[0]->metadata->type;
                    $metadata = $sessions->data[0]->metadata;
                }
            } catch (Exception $e) {
                // Ignore session lookup errors
            }
        }
        
        $is_processed = $processed ? true : false;
        $row_class = '';
        
        // Highlight unprocessed successful payments
        if ($payment->status === 'succeeded' && !$is_processed) {
            $row_class = 'highlight';
            $stuck_payments[] = $payment;
        } elseif ($is_processed) {
            $row_class = 'processed';
        }
        
        echo "<tr class='{$row_class}'>";
        echo "<td><small>" . $payment->id . "</small></td>";
        echo "<td>$" . ($payment->amount / 100) . "</td>";
        echo "<td>" . $payment->status . "</td>";
        echo "<td>" . $type . "</td>";
        echo "<td>" . ($is_processed ? '✅ Yes' : '❌ No') . "</td>";
        echo "<td>";
        
        if ($payment->status === 'succeeded' && !$is_processed) {
            echo "<form method='POST' style='display: inline;'>";
            echo "<input type='hidden' name='payment_intent_id' value='" . $payment->id . "'>";
            echo "<input type='hidden' name='process_payment' value='1'>";
            echo "<button type='submit' class='btn-danger' style='font-size: 12px; padding: 6px 12px;'>🚨 PROCESS NOW</button>";
            echo "</form>";
        } elseif ($is_processed) {
            echo "<span style='color: #28a745; font-weight: bold;'>✅ OK</span>";
        } else {
            echo "<span style='color: #6c757d;'>" . $payment->status . "</span>";
        }
        
        echo "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Show stuck payment alert
    $stuck_count = count($stuck_payments);
    if ($stuck_count > 0) {
        echo "<div class='alert'>";
        echo "<h3>🚨 URGENT: Found {$stuck_count} Stuck Payment(s)!</h3>";
        echo "<p><strong>These payments succeeded in Stripe but weren't processed by the webhook system.</strong></p>";
        echo "<p><strong>Action Required:</strong> Click the red 'PROCESS NOW' buttons above to fix them immediately.</p>";
        echo "<p>This means users paid money but didn't get their topics/contributions created.</p>";
        echo "</div>";
        
        // Show details of stuck payments
        foreach ($stuck_payments as $stuck) {
            echo "<div class='warning'>";
            echo "<h4>💰 Stuck Payment Details: " . $stuck->id . "</h4>";
            echo "<ul>";
            echo "<li><strong>Amount:</strong> $" . ($stuck->amount / 100) . "</li>";
            echo "<li><strong>Created:</strong> " . date('Y-m-d H:i:s', $stuck->created) . "</li>";
            echo "<li><strong>Status:</strong> " . $stuck->status . "</li>";
            if (!empty($stuck->metadata)) {
                echo "<li><strong>Metadata:</strong> " . json_encode($stuck->metadata) . "</li>";
            }
            echo "</ul>";
            echo "</div>";
        }
    } else {
        echo "<div class='success'>";
        echo "<h3>✅ No Stuck Payments Found</h3>";
        echo "<p>All recent successful payments have been processed correctly.</p>";
        echo "</div>";
    }
    
} catch (Exception $e) {
    echo "<div class='error'>";
    echo "<h3>❌ Error Loading Payments from Stripe</h3>";
    echo "<p><strong>Error:</strong> " . $e->getMessage() . "</p>";
    echo "<p>Check your Stripe API configuration.</p>";
    echo "</div>";
}

// === MANUAL PAYMENT PROCESSING ===
$message = '';
$error = '';

if ($_POST && isset($_POST['payment_intent_id'])) {
    $payment_intent_id = trim($_POST['payment_intent_id']);
    
    try {
        // Retrieve the payment intent from Stripe
        $payment_intent = \Stripe\PaymentIntent::retrieve($payment_intent_id);
        
        echo "<h3>💳 Payment Intent Details:</h3>";
        echo "<pre>";
        echo "ID: " . $payment_intent->id . "\n";
        echo "Status: " . $payment_intent->status . "\n";
        echo "Amount: $" . ($payment_intent->amount / 100) . "\n";
        echo "Currency: " . $payment_intent->currency . "\n";
        echo "Created: " . date('Y-m-d H:i:s', $payment_intent->created) . "\n";
        
        // Check for metadata
        if (!empty($payment_intent->metadata)) {
            echo "Metadata: " . json_encode($payment_intent->metadata, JSON_PRETTY_PRINT) . "\n";
        } else {
            echo "No metadata on payment intent\n";
            
            // Try to find checkout session
            $sessions = \Stripe\Checkout\Session::all([
                'payment_intent' => $payment_intent_id,
                'limit' => 1
            ]);
            
            if (!empty($sessions->data)) {
                $session = $sessions->data[0];
                echo "Found checkout session: " . $session->id . "\n";
                if (!empty($session->metadata)) {
                    echo "Session metadata: " . json_encode($session->metadata, JSON_PRETTY_PRINT) . "\n";
                }
            }
        }
        echo "</pre>";
        
        // Check if already processed
        $db = new Database();
        $db->query('SELECT id, topic_id FROM contributions WHERE payment_id = :payment_id');
        $db->bind(':payment_id', $payment_intent_id);
        $existing = $db->single();
        
        if ($existing) {
            echo "<div class='warning'>";
            echo "<p>⚠️ <strong>This payment has already been processed!</strong></p>";
            echo "<p>Contribution ID: <strong>{$existing->id}</strong></p>";
            if ($existing->topic_id) {
                echo "<p>Topic ID: <strong>{$existing->topic_id}</strong></p>";
                echo "<p><a href='../topics/view.php?id={$existing->topic_id}' target='_blank'>View Topic</a></p>";
            }
            echo "</div>";
        } elseif ($payment_intent->status === 'succeeded') {
            
            if (isset($_POST['process_payment'])) {
                // Manual processing
                echo "<h4>🔄 Processing Payment...</h4>";
                
                $processor = new FundingProcessor();
                $result = $processor->handlePaymentSuccess($payment_intent_id);
                
                if ($result['success']) {
                    $message = "✅ <strong>Payment processed successfully!</strong>";
                    if (isset($result['contribution_id'])) {
                        $message .= "<br>Contribution ID: <strong>" . $result['contribution_id'] . "</strong>";
                    }
                    if (isset($result['topic_id'])) {
                        $message .= "<br>Topic ID: <strong>" . $result['topic_id'] . "</strong>";
                        $message .= "<br><a href='../topics/view.php?id=" . $result['topic_id'] . "' target='_blank'>View Topic</a>";
                    }
                    if (isset($result['fully_funded']) && $result['fully_funded']) {
                        $message .= "<br>🎉 <strong>Topic is now fully funded!</strong>";
                    }
                } else {
                    $error = "❌ <strong>Failed to process payment:</strong><br>" . $result['error'];
                }
            } else {
                echo "<div style='background: #e3f2fd; padding: 15px; border-radius: 4px; margin: 20px 0;'>";
                echo "<h4>Ready to Process</h4>";
                echo "<p>This payment succeeded but hasn't been processed yet.</p>";
                echo "<form method='POST' style='margin: 15px 0;'>";
                echo "<input type='hidden' name='payment_intent_id' value='" . htmlspecialchars($payment_intent_id) . "'>";
                echo "<button type='submit' name='process_payment' class='btn-success' style='font-size: 16px; padding: 12px 24px;'>✅ Process This Payment</button>";
                echo "</form>";
                echo "</div>";
            }
        } else {
            echo "<p style='color: red;'>❌ Payment status is not 'succeeded': <strong>" . $payment_intent->status . "</strong></p>";
        }
        
    } catch (Exception $e) {
        $error = "Error retrieving payment: " . $e->getMessage();
    }
}

// Display messages
if ($message) {
    echo "<div class='success'>" . $message . "</div>";
}
if ($error) {
    echo "<div class='error'>" . $error . "</div>";
}

// === DATABASE DIAGNOSTICS ===
echo "<hr><h2>📊 Database Diagnostics</h2>";
echo "<div class='debug'>";

try {
    // Check if is_guest column exists
    $db = new Database();
    $db->query('DESCRIBE users');
    $columns = $db->resultSet();
    
    $has_is_guest = false;
    foreach ($columns as $column) {
        if ($column->Field === 'is_guest') {
            $has_is_guest = true;
            break;
        }
    }
    
    if ($has_is_guest) {
        echo "<h4>✅ Guest Users (with is_guest column):</h4>";
        $db->query('SELECT id, username, email, created_at FROM users WHERE is_guest = 1 ORDER BY created_at DESC LIMIT 5');
        $guest_users = $db->resultSet();
        
        if (empty($guest_users)) {
            echo "<p>No guest users found.</p>";
        } else {
            echo "<table>";
            echo "<tr><th>ID</th><th>Username</th><th>Email</th><th>Created</th></tr>";
            foreach ($guest_users as $user) {
                echo "<tr>";
                echo "<td>" . $user->id . "</td>";
                echo "<td>" . htmlspecialchars($user->username) . "</td>";
                echo "<td>" . htmlspecialchars($user->email) . "</td>";
                echo "<td>" . $user->created_at . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
    } else {
        echo "<h4>⚠️ Missing is_guest column</h4>";
        echo "<div class='warning'>";
        echo "<p><strong>Database Fix Required:</strong></p>";
        echo "<p>Run this SQL command:</p>";
        echo "<code>ALTER TABLE users ADD COLUMN is_guest TINYINT(1) DEFAULT 0 AFTER is_active;</code>";
        echo "</div>";
    }
    
    // Recent topics
    echo "<h4>📝 Recent Topics:</h4>";
    $db->query('SELECT id, title, creator_id, current_funding, funding_threshold, status, created_at FROM topics ORDER BY created_at DESC LIMIT 5');
    $recent_topics = $db->resultSet();
    
    if (empty($recent_topics)) {
        echo "<p>No topics found.</p>";
    } else {
        echo "<table>";
        echo "<tr><th>ID</th><th>Title</th><th>Creator</th><th>Funding</th><th>Status</th><th>Created</th></tr>";
        foreach ($recent_topics as $topic) {
            echo "<tr>";
            echo "<td>" . $topic->id . "</td>";
            echo "<td>" . htmlspecialchars(substr($topic->title, 0, 40)) . "...</td>";
            echo "<td>" . $topic->creator_id . "</td>";
            echo "<td>$" . $topic->current_funding . " / $" . $topic->funding_threshold . "</td>";
            echo "<td>" . $topic->status . "</td>";
            echo "<td>" . $topic->created_at . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // Recent contributions
    echo "<h4>💰 Recent Contributions:</h4>";
    $db->query('SELECT c.id, c.topic_id, c.amount, c.payment_status, c.payment_id, c.contributed_at, u.username FROM contributions c LEFT JOIN users u ON c.user_id = u.id ORDER BY c.contributed_at DESC LIMIT 5');
    $recent_contributions = $db->resultSet();
    
    if (empty($recent_contributions)) {
        echo "<p>No contributions found.</p>";
    } else {
        echo "<table>";
        echo "<tr><th>ID</th><th>Topic</th><th>User</th><th>Amount</th><th>Status</th><th>Payment ID</th><th>Date</th></tr>";
        foreach ($recent_contributions as $contrib) {
            echo "<tr>";
            echo "<td>" . $contrib->id . "</td>";
            echo "<td>" . ($contrib->topic_id ?: 'NULL') . "</td>";
            echo "<td>" . htmlspecialchars($contrib->username ?: 'Unknown') . "</td>";
            echo "<td>$" . number_format($contrib->amount, 2) . "</td>";
            echo "<td>" . $contrib->payment_status . "</td>";
            echo "<td><small>" . ($contrib->payment_id ?: 'NULL') . "</small></td>";
            echo "<td>" . $contrib->contributed_at . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Database error: " . $e->getMessage() . "</p>";
}

echo "</div>";
?>

<hr>
<h2>💳 Manual Payment Lookup</h2>
<form method="POST">
    <div class="form-group">
        <label><strong>Payment Intent ID:</strong></label><br>
        <input type="text" name="payment_intent_id" placeholder="pi_1234567890..." required>
        <small style="display: block; color: #666; margin-top: 5px;">Enter the Stripe payment intent ID (starts with "pi_")</small>
    </div>
    <button type="submit">🔍 Lookup & Process Payment</button>
</form>

<hr>
<h2>⚙️ Webhook Configuration</h2>
<div class="debug">
    <p><strong>Webhook endpoint secret (first 10 chars):</strong> <?php echo substr(STRIPE_WEBHOOK_SECRET, 0, 10); ?>...</p>
    <p><strong>Webhook URL should be:</strong> <code>https://topiclaunch.com/webhooks/stripe.php</code></p>
    <p><strong>Required events:</strong> <code>payment_intent.succeeded</code>, <code>payment_intent.payment_failed</code></p>
    
    <h4>🛠️ Webhook Troubleshooting:</h4>
    <ol>
        <li>Go to Stripe Dashboard → Webhooks</li>
        <li>Verify endpoint URL is correct</li>
        <li>Check that signing secret matches</li>
        <li>Ensure <code>payment_intent.succeeded</code> event is enabled</li>
        <li>Test the webhook endpoint</li>
        <li>Check server error logs for webhook failures</li>
    </ol>
</div>

<div style="margin-top: 40px; text-align: center; padding: 20px; background: #f8f9fa; border-radius: 8px;">
    <h3>🔗 Admin Links</h3>
    <a href="creators.php" style="background: #28a745; color: white; padding: 12px 20px; text-decoration: none; border-radius: 4px; margin: 0 10px;">👥 Creator Management</a>
    <a href="../index.php" style="background: #6c757d; color: white; padding: 12px 20px; text-decoration: none; border-radius: 4px; margin: 0 10px;">🏠 Home</a>
    <a href="../topics/index.php" style="background: #007bff; color: white; padding: 12px 20px; text-decoration: none; border-radius: 4px; margin: 0 10px;">📝 All Topics</a>
</div>

</body>
</html>
