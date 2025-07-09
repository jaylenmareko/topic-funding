<?php
// Add this at the very top of test_system.php (after <?php)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
    
}// test_system.php - TopicLaunch Production Readiness Testing Script
// Upload this to your server and run it to check if everything is working

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config/database.php';
require_once 'config/stripe.php';

echo "<!DOCTYPE html>
<html>
<head>
    <title>TopicLaunch System Test</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .test-section { background: #f8f9fa; padding: 20px; margin: 10px 0; border-radius: 8px; }
        .pass { color: #28a745; font-weight: bold; }
        .fail { color: #dc3545; font-weight: bold; }
        .warning { color: #ffc107; font-weight: bold; }
        .test-item { margin: 10px 0; padding: 10px; background: white; border-radius: 4px; }
        .config-value { background: #e9ecef; padding: 2px 6px; border-radius: 3px; font-family: monospace; }
        h2 { color: #333; border-bottom: 2px solid #667eea; padding-bottom: 10px; }
        .summary { background: #e3f2fd; padding: 20px; border-radius: 8px; margin-top: 20px; }
    </style>
</head>
<body>";

echo "<h1>🚀 TopicLaunch Production Readiness Test</h1>";
echo "<p>Testing system at: <strong>" . $_SERVER['HTTP_HOST'] . "</strong></p>";
echo "<p>Timestamp: " . date('Y-m-d H:i:s T') . "</p>";

$tests_passed = 0;
$tests_failed = 0;
$tests_warning = 0;

function test_result($name, $result, $details = '', $is_warning = false) {
    global $tests_passed, $tests_failed, $tests_warning;
    
    if ($is_warning) {
        $status = "<span class='warning'>⚠️ WARNING</span>";
        $tests_warning++;
    } else if ($result) {
        $status = "<span class='pass'>✅ PASS</span>";
        $tests_passed++;
    } else {
        $status = "<span class='fail'>❌ FAIL</span>";
        $tests_failed++;
    }
    
    echo "<div class='test-item'>";
    echo "<strong>$name:</strong> $status";
    if ($details) echo "<br><small>$details</small>";
    echo "</div>";
}

// =============================================================================
// 1. DATABASE CONNECTIVITY & STRUCTURE
// =============================================================================
echo "<div class='test-section'>";
echo "<h2>📊 Database Tests</h2>";

try {
    $db = new Database();
    test_result("Database Connection", true, "Successfully connected to database");
    
    // Check required tables
    $required_tables = [
        'users', 'creators', 'topics', 'contributions', 
        'platform_fees', 'creator_payouts', 'notifications'
    ];
    
    $db->query("SHOW TABLES");
    $existing_tables = array_column($db->resultSet(), 'Tables_in_' . DB_NAME);
    
    foreach ($required_tables as $table) {
        $exists = in_array($table, $existing_tables);
        test_result("Table: $table", $exists, $exists ? "Table exists" : "Table missing - may cause errors");
    }
    
    // Check key columns in users table
    try {
        $db->query("DESCRIBE users");
        $user_columns = array_column($db->resultSet(), 'Field');
        $required_user_cols = ['id', 'username', 'email', 'password_hash', 'is_active'];
        
        foreach ($required_user_cols as $col) {
            test_result("Users.$col column", in_array($col, $user_columns));
        }
    } catch (Exception $e) {
        test_result("Users table structure", false, "Error: " . $e->getMessage());
    }
    
    // Check creators table
    try {
        $db->query("DESCRIBE creators");
        $creator_columns = array_column($db->resultSet(), 'Field');
        $required_creator_cols = ['id', 'display_name', 'platform_type', 'is_active', 'paypal_email', 'manual_payout_threshold'];
        
        foreach ($required_creator_cols as $col) {
            test_result("Creators.$col column", in_array($col, $creator_columns));
        }
    } catch (Exception $e) {
        test_result("Creators table structure", false, "Error: " . $e->getMessage());
    }
    
    // Check topics table
    try {
        $db->query("DESCRIBE topics");
        $topic_columns = array_column($db->resultSet(), 'Field');
        $required_topic_cols = ['id', 'creator_id', 'title', 'description', 'funding_threshold', 'current_funding', 'status', 'content_deadline'];
        
        foreach ($required_topic_cols as $col) {
            test_result("Topics.$col column", in_array($col, $topic_columns));
        }
    } catch (Exception $e) {
        test_result("Topics table structure", false, "Error: " . $e->getMessage());
    }
    
} catch (Exception $e) {
    test_result("Database Connection", false, "Error: " . $e->getMessage());
}

echo "</div>";

// =============================================================================
// 2. STRIPE CONFIGURATION
// =============================================================================
echo "<div class='test-section'>";
echo "<h2>💳 Stripe Configuration</h2>";

$stripe_publishable = defined('STRIPE_PUBLISHABLE_KEY') ? STRIPE_PUBLISHABLE_KEY : 'NOT DEFINED';
$stripe_secret = defined('STRIPE_SECRET_KEY') ? STRIPE_SECRET_KEY : 'NOT DEFINED';

test_result("Stripe Publishable Key", 
    !empty($stripe_publishable) && $stripe_publishable !== 'NOT DEFINED',
    "Key: " . substr($stripe_publishable, 0, 12) . "..."
);

test_result("Stripe Secret Key", 
    !empty($stripe_secret) && $stripe_secret !== 'NOT DEFINED',
    "Key: " . substr($stripe_secret, 0, 8) . "..."
);

// Check if keys are LIVE or TEST
$is_live_publishable = strpos($stripe_publishable, 'pk_live_') === 0;
$is_live_secret = strpos($stripe_secret, 'sk_live_') === 0;

test_result("Stripe LIVE Mode (Publishable)", $is_live_publishable, 
    $is_live_publishable ? "Using LIVE keys" : "Using TEST keys", !$is_live_publishable);

test_result("Stripe LIVE Mode (Secret)", $is_live_secret, 
    $is_live_secret ? "Using LIVE keys" : "Using TEST keys", !$is_live_secret);

// Test Stripe API connection
try {
    \Stripe\Stripe::setApiKey($stripe_secret);
    $account = \Stripe\Account::retrieve();
    test_result("Stripe API Connection", true, "Account ID: " . $account->id);
    test_result("Stripe Account Type", $account->type === 'standard', "Type: " . $account->type);
} catch (Exception $e) {
    test_result("Stripe API Connection", false, "Error: " . $e->getMessage());
}

echo "</div>";

// =============================================================================
// 3. FILE SYSTEM & UPLOADS
// =============================================================================
echo "<div class='test-section'>";
echo "<h2>📁 File System Tests</h2>";

$upload_dir = 'uploads/creators/';
test_result("Upload Directory Exists", is_dir($upload_dir), "Path: $upload_dir");
test_result("Upload Directory Writable", is_writable($upload_dir), "Permissions check");

$logs_dir = 'logs/';
test_result("Logs Directory Exists", is_dir($logs_dir), "Path: $logs_dir");
test_result("Logs Directory Writable", is_writable($logs_dir), "Permissions check");

// Test file upload functionality
$test_file = $upload_dir . 'test_write.txt';
$can_write = file_put_contents($test_file, 'test') !== false;
test_result("File Write Test", $can_write, "Test file creation");

if ($can_write && file_exists($test_file)) {
    unlink($test_file); // Clean up
}

echo "</div>";

// =============================================================================
// 4. SECURITY & CONFIGURATION
// =============================================================================
echo "<div class='test-section'>";
echo "<h2>🔒 Security & Configuration</h2>";

// Check PHP configuration
test_result("PHP Version", version_compare(PHP_VERSION, '7.4.0', '>='), "Version: " . PHP_VERSION);

$error_reporting = ini_get('display_errors');
test_result("Error Display (Production)", $error_reporting === '' || $error_reporting === '0', 
    "display_errors: " . ($error_reporting ? 'ON (should be OFF)' : 'OFF'), $error_reporting !== '' && $error_reporting !== '0');

// Check SSL
$is_https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
test_result("HTTPS/SSL", $is_https, $is_https ? "Site is secure" : "Site not using HTTPS", !$is_https);

// Check for required PHP extensions
$required_extensions = ['pdo', 'pdo_mysql', 'curl', 'json', 'mbstring'];
foreach ($required_extensions as $ext) {
    test_result("PHP Extension: $ext", extension_loaded($ext));
}

// Check session configuration
test_result("Session Started", session_status() === PHP_SESSION_ACTIVE, "Session status: " . session_status());

echo "</div>";

// =============================================================================
// 5. EMAIL CONFIGURATION
// =============================================================================
echo "<div class='test-section'>";
echo "<h2>📧 Email Configuration</h2>";

// Check if notification system can be loaded
try {
    require_once 'config/notification_system.php';
    $notification_system = new NotificationSystem();
    test_result("Notification System", true, "NotificationSystem class loaded successfully");
} catch (Exception $e) {
    test_result("Notification System", false, "Error loading: " . $e->getMessage());
}

// Check SMTP settings in PHP
$smtp_host = ini_get('SMTP');
$smtp_port = ini_get('smtp_port');

test_result("SMTP Configuration", !empty($smtp_host), 
    empty($smtp_host) ? "No SMTP host configured" : "Host: $smtp_host:$smtp_port");

echo "</div>";

// =============================================================================
// 6. CORE FUNCTIONALITY TESTS
// =============================================================================
echo "<div class='test-section'>";
echo "<h2>⚙️ Core Functionality Tests</h2>";

// Check if key classes can be instantiated
try {
    $helper = new DatabaseHelper();
    test_result("DatabaseHelper Class", true, "Core database helper loaded");
} catch (Exception $e) {
    test_result("DatabaseHelper Class", false, "Error: " . $e->getMessage());
}

// Check if Stripe classes are available
try {
    $session = \Stripe\Checkout\Session::create([
        'mode' => 'payment',
        'line_items' => [],
        'success_url' => 'https://example.com/success',
        'cancel_url' => 'https://example.com/cancel',
    ]);
    test_result("Stripe Session Creation", false, "Should fail with empty line_items (this is expected)");
} catch (\Stripe\Exception\InvalidRequestException $e) {
    test_result("Stripe Session Creation", true, "Stripe API working (expected validation error)");
} catch (Exception $e) {
    test_result("Stripe Session Creation", false, "Unexpected error: " . $e->getMessage());
}

// Test webhook endpoint exists
$webhook_file = 'webhooks/stripe.php';
test_result("Stripe Webhook File", file_exists($webhook_file), "Path: $webhook_file");

echo "</div>";

// =============================================================================
// 7. SAMPLE DATA CHECK
// =============================================================================
echo "<div class='test-section'>";
echo "<h2>📊 Data Validation</h2>";

try {
    // Count records in key tables
    $db->query("SELECT COUNT(*) as count FROM users");
    $user_count = $db->single()->count;
    test_result("Users in database", $user_count > 0, "Count: $user_count", $user_count === 0);
    
    $db->query("SELECT COUNT(*) as count FROM creators WHERE is_active = 1");
    $creator_count = $db->single()->count;
    test_result("Active creators", $creator_count > 0, "Count: $creator_count", $creator_count === 0);
    
    $db->query("SELECT COUNT(*) as count FROM topics");
    $topic_count = $db->single()->count;
    test_result("Topics in database", true, "Count: $topic_count");
    
    // Check for test data that should be removed
    $db->query("SELECT COUNT(*) as count FROM users WHERE email LIKE '%test%' OR email LIKE '%example%'");
    $test_users = $db->single()->count;
    test_result("Test Users Cleaned", $test_users === 0, 
        $test_users > 0 ? "Found $test_users test users - should be removed" : "No test users found", $test_users > 0);
    
} catch (Exception $e) {
    test_result("Data Validation", false, "Error querying database: " . $e->getMessage());
}

echo "</div>";

// =============================================================================
// 8. URL & ROUTING TESTS
// =============================================================================
echo "<div class='test-section'>";
echo "<h2>🌐 URL & Routing Tests</h2>";

$key_files = [
    'index.php' => 'Main landing page',
    'auth/login.php' => 'Login page',
    'auth/register.php' => 'Registration page',
    'creators/index.php' => 'Browse creators',
    'creators/apply.php' => 'Creator application',
    'topics/create.php' => 'Topic creation',
    'topics/fund.php' => 'Topic funding',
    'dashboard/index.php' => 'User dashboard',
    'admin/creators.php' => 'Admin panel'
];

foreach ($key_files as $file => $description) {
    test_result("File exists: $file", file_exists($file), $description);
}

echo "</div>";

// =============================================================================
// SUMMARY
// =============================================================================
echo "<div class='summary'>";
echo "<h2>📋 Test Summary</h2>";
echo "<p><strong>Total Tests:</strong> " . ($tests_passed + $tests_failed + $tests_warning) . "</p>";
echo "<p><span class='pass'>✅ Passed:</span> $tests_passed</p>";
echo "<p><span class='fail'>❌ Failed:</span> $tests_failed</p>";
echo "<p><span class='warning'>⚠️ Warnings:</span> $tests_warning</p>";

$overall_status = $tests_failed === 0 ? "READY" : "NEEDS ATTENTION";
$status_color = $tests_failed === 0 ? "pass" : "fail";

echo "<h3>Overall Status: <span class='$status_color'>$overall_status</span></h3>";

if ($tests_failed > 0) {
    echo "<p><strong>Action Required:</strong> Please fix the failed tests before going live.</p>";
} else if ($tests_warning > 0) {
    echo "<p><strong>Recommendation:</strong> Review warnings and consider fixing them for optimal operation.</p>";
} else {
    echo "<p><strong>🎉 Great!</strong> Your TopicLaunch system appears to be ready for production.</p>";
}

echo "<h3>Next Steps:</h3>";
echo "<ul>";
echo "<li>✅ Test user registration and login manually</li>";
echo "<li>✅ Test creating a topic with real Stripe payment (small amount)</li>";
echo "<li>✅ Test funding a topic with real Stripe payment</li>";
echo "<li>✅ Verify webhook endpoint with Stripe CLI</li>";
echo "<li>✅ Test email notifications</li>";
echo "<li>✅ Configure monitoring and backups</li>";
echo "<li>✅ Set up SSL certificate if not already done</li>";
echo "<li>✅ Update Stripe webhook URL in Stripe dashboard</li>";
echo "</ul>";

echo "</div>";

echo "</body></html>";
?>
