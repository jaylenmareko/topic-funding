<?php
// api/create-topic.php - FIXED: Only creates topic after payment succeeds
ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

session_start();

try {
    require_once '../config/database.php';
} catch (Exception $e) {
    echo json_encode(['error' => 'Database config failed: ' . $e->getMessage()]);
    exit;
}

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(['error' => 'Invalid JSON: ' . json_last_error_msg()]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Invalid request method']);
    exit;
}

$creator_id = $input['creator_id'] ?? null;
$title = trim($input['title'] ?? '');
$description = trim($input['description'] ?? '');
$funding_goal = floatval($input['funding_goal'] ?? 0);
$initial_amount = floatval($input['initial_amount'] ?? 0);

// Validation
if (!$creator_id || !$title || !$description || !$funding_goal || !$initial_amount) {
    echo json_encode(['error' => 'All fields are required']);
    exit;
}

if ($initial_amount < 1 || $initial_amount > 1000) {
    echo json_encode(['error' => 'Initial amount must be between $1 and $1,000']);
    exit;
}

try {
    $db = new Database();
    
    // Verify creator exists
    $db->query('SELECT * FROM creators WHERE id = :creator_id AND is_active = 1');
    $db->bind(':creator_id', $creator_id);
    $creator = $db->single();
    
    if (!$creator) {
        echo json_encode(['error' => 'Creator not found']);
        exit;
    }
    
    // Check if funding goal meets minimum price
    if ($funding_goal < $creator->minimum_topic_price) {
        echo json_encode(['error' => 'Funding goal must be at least $' . number_format($creator->minimum_topic_price, 2)]);
        exit;
    }
    
    if ($funding_goal > 10000) {
        echo json_encode(['error' => 'Funding goal cannot exceed $10,000']);
        exit;
    }
    
    if ($initial_amount > $funding_goal) {
        echo json_encode(['error' => 'Initial amount cannot exceed funding goal']);
        exit;
    }
    
    // Get initiator user ID from session (if logged in)
    $initiator_user_id = $_SESSION['user_id'] ?? null;
    
    // Load Stripe
    if (!file_exists('../vendor/autoload.php')) {
        echo json_encode(['error' => 'Stripe library not found. Please install Stripe PHP library.']);
        exit;
    }
    
    require_once '../vendor/autoload.php';
    
    $stripe_key = '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';
    
    \Stripe\Stripe::setApiKey($stripe_key);
    
    // Create Stripe checkout FIRST (before creating topic in database)
    // Pass all topic data in metadata so webhook can create it after payment
    $checkout_session = \Stripe\Checkout\Session::create([
        'payment_method_types' => ['card'],
        'line_items' => [[
            'price_data' => [
                'currency' => 'usd',
                'product_data' => [
                    'name' => 'Fund Topic: ' . $title,
                    'description' => 'Contribution towards funding this video topic',
                ],
                'unit_amount' => intval($initial_amount * 100),
            ],
            'quantity' => 1,
        ]],
        'mode' => 'payment',
        'success_url' => 'https://' . $_SERVER['HTTP_HOST'] . '/payment-success.php?session_id={CHECKOUT_SESSION_ID}&type=topic_creation',
        'cancel_url' => 'https://' . $_SERVER['HTTP_HOST'] . '/' . $creator->display_name,
        'metadata' => [
            'type' => 'topic_creation',
            'creator_id' => $creator_id,
            'initiator_user_id' => $initiator_user_id ?? '',
            'title' => $title,
            'description' => $description,
            'funding_threshold' => $funding_goal,
            'initial_amount' => $initial_amount,
            'platform_fee_percent' => '10.00',
            'creator_display_name' => $creator->display_name
        ]
    ]);
    
    echo json_encode([
        'success' => true,
        'checkout_url' => $checkout_session->url
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'error' => 'Exception: ' . $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}
