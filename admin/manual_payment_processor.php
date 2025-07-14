<?php
// admin/manual_payment_processor.php - Process missing Stripe payments manually
session_start();
require_once '../config/database.php';
require_once '../config/stripe.php';
require_once '../config/funding_processor.php';

// Admin check
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_id'], [1, 2, 9])) {
    die('Admin access required');
}

$message = '';
$error = '';
$results = [];

if ($_POST && isset($_POST['payment_intent_id'])) {
    $payment_intent_id = trim($_POST['payment_intent_id']);
    
    try {
        // Retrieve the payment intent from Stripe
        $payment_intent = \Stripe\PaymentIntent::retrieve($payment_intent_id);
        
        echo "<h3>Payment Intent Details:</h3>";
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
        $db->query('SELECT id FROM contributions WHERE payment_id = :payment_id');
        $db->bind(':payment_id', $payment_intent_id);
        $existing = $db->single();
        
        if ($existing) {
            echo "<p style='color: orange;'>⚠️ This payment has already been processed (Contribution ID: {$existing->id})</p>";
        } elseif ($payment_intent->status === 'succeeded') {
            
            if (isset($_POST['process_payment'])) {
                // Manual processing
                $processor = new FundingProcessor();
                $result = $processor->handlePaymentSuccess($payment_intent_id);
                
                if ($result['success']) {
                    $message = "✅ Payment processed successfully!";
                    if (isset($result['contribution_id'])) {
                        $message .= " Contribution ID: " . $result['contribution_id'];
                    }
                } else {
                    $error = "❌ Failed to process payment: " . $result['error'];
                }
            } else {
                echo "<form method='POST' style='margin: 20px 0;'>";
                echo "<input type='hidden' name='payment_intent_id' value='" . htmlspecialchars($payment_intent_id) . "'>";
                echo "<button type='submit' name='process_payment' style='background: #28a745; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer;'>Process This Payment</button>";
                echo "</form>";
            }
        } else {
            echo "<p style='color: red;'>❌ Payment status is not 'succeeded': " . $payment_intent->status . "</p>";
        }
        
    } catch (Exception $e) {
        $error = "Error retrieving payment: " . $e->getMessage();
    }
}

// Get recent unprocessed payments
try {
    echo "<h3>Recent Payments from Stripe (Last 24 hours):</h3>";
    
    $payment_intents = \Stripe\PaymentIntent::all([
        'limit' => 20,
        'created' => [
            'gte' => time() - 86400, // Last 24 hours
        ],
    ]);
    
    $db = new Database();
    
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr><th>Payment Intent ID</th><th>Amount</th><th>Status</th><th>Created</th><th>Processed?</th><th>Action</th></tr>";
    
    foreach ($payment_intents->data as $pi) {
        // Check if processed
        $db->query('SELECT id FROM contributions WHERE payment_id = :payment_id');
        $db->bind(':payment_id', $pi->id);
        $processed = $db->single();
        
        $processed_status = $processed ? '✅ Yes' : '❌ No';
        $row_style = ($pi->status === 'succeeded' && !$processed) ? 'background: #fff3cd;' : '';
        
        echo "<tr style='{$row_style}'>";
        echo "<td>" . $pi->id . "</td>";
        echo "<td>$" . ($pi->amount / 100) . "</td>";
        echo "<td>" . $pi->status . "</td>";
        echo "<td>" . date('Y-m-d H:i:s', $pi->created) . "</td>";
        echo "<td>" . $processed_status . "</td>";
        echo "<td>";
        if ($pi->status === 'succeeded' && !$processed) {
            echo "<form method='POST' style='display: inline;'>";
            echo "<input type='hidden' name='payment_intent_id' value='" . $pi->id . "'>";
            echo "<button type='submit' style='background: #007bff; color: white; padding: 5px 10px; border: none; border-radius: 3px;'>Process</button>";
            echo "</form>";
        }
        echo "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error loading payments: " . $e->getMessage() . "</p>";
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Manual Payment Processor - TopicLaunch Admin</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .form-group { margin-bottom: 15px; }
        input[type="text"] { width: 400px; padding: 8px; }
        button { background: #007bff; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; }
        .message { background: #d4edda; color: #155724; padding: 15px; border-radius: 4px; margin: 15px 0; }
        .error { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 4px; margin: 15px 0; }
        pre { background: #f8f9fa; padding: 15px; border-radius: 4px; overflow-x: auto; }
        table { margin: 20px 0; }
        th, td { padding: 8px; text-align: left; border: 1px solid #ddd; }
        th { background: #f8f9fa; }
    </style>
</head>
<body>
    <h1>Manual Payment Processor</h1>
    
    <?php if ($message): ?>
        <div class="message"><?php echo $message; ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="error"><?php echo $error; ?></div>
    <?php endif; ?>
    
    <h2>Process Specific Payment Intent</h2>
    <form method="POST">
        <div class="form-group">
            <label>Payment Intent ID:</label><br>
            <input type="text" name="payment_intent_id" placeholder="pi_1234567890..." required>
        </div>
        <button type="submit">Lookup Payment</button>
    </form>
    
    <hr>
    
    <h2>Webhook Configuration Check</h2>
    <p><strong>Current webhook endpoint secret (first 10 chars):</strong> <?php echo substr(STRIPE_WEBHOOK_SECRET, 0, 10); ?>...</p>
    <p><strong>Webhook URL should be:</strong> https://topiclaunch.com/webhooks/stripe.php</p>
    <p><strong>Required events:</strong> payment_intent.succeeded, payment_intent.payment_failed</p>
    
    <div style="background: #e3f2fd; padding: 15px; border-radius: 4px; margin: 20px 0;">
        <h3>To fix webhook issues:</h3>
        <ol>
            <li>Go to your Stripe Dashboard → Webhooks</li>
            <li>Check if the webhook endpoint URL is correct</li>
            <li>Verify the signing secret matches your config</li>
            <li>Make sure events include: payment_intent.succeeded</li>
            <li>Test the webhook endpoint</li>
        </ol>
    </div>
    
    <p><a href="../admin/creators.php">← Back to Admin</a></p>
</body>
</html>
