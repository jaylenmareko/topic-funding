<?php
// api/create-topic.php
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

if ($funding_goal < 10 || $funding_goal > 10000) {
    echo json_encode(['error' => 'Funding goal must be between $10 and $10,000']);
    exit;
}

if ($initial_amount > $funding_goal) {
    echo json_encode(['error' => 'Initial amount cannot exceed funding goal']);
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
    
    // Calculate platform fee (10%)
    $platform_fee_percent = 10.00;
    $platform_fee_amount = $funding_goal * ($platform_fee_percent / 100);
    $creator_payout_amount = $funding_goal - $platform_fee_amount;
    
    // Get initiator user ID from session (if logged in)
    $initiator_user_id = $_SESSION['user_id'] ?? null;
    
    // Create the topic
    $db->query('
        INSERT INTO topics (
            creator_id, 
            initiator_user_id,
            title, 
            description, 
            funding_threshold,
            current_funding,
            platform_fee_percent,
            platform_fee_amount,
            creator_payout_amount,
            status,
            created_at
        ) VALUES (
            :creator_id,
            :initiator_user_id,
            :title,
            :description,
            :funding_threshold,
            0,
            :platform_fee_percent,
            :platform_fee_amount,
            :creator_payout_amount,
            "active",
            NOW()
        )
    ');
    
    $db->bind(':creator_id', $creator_id);
    $db->bind(':initiator_user_id', $initiator_user_id);
    $db->bind(':title', $title);
    $db->bind(':description', $description);
    $db->bind(':funding_threshold', $funding_goal);
    $db->bind(':platform_fee_percent', $platform_fee_percent);
    $db->bind(':platform_fee_amount', $platform_fee_amount);
    $db->bind(':creator_payout_amount', $creator_payout_amount);
    
    if (!$db->execute()) {
        echo json_encode(['error' => 'Failed to insert topic into database']);
        exit;
    }
    
    $topic_id = $db->lastInsertId();
    
    if (!$topic_id) {
        echo json_encode(['error' => 'Topic created but no ID returned']);
        exit;
    }
    
    // Now create Stripe checkout for initial funding
    if (!file_exists('../vendor/autoload.php')) {
        echo json_encode(['error' => 'Stripe library not found. Please install Stripe PHP library.']);
        exit;
    }
    
    require_once '../vendor/autoload.php';
    
    $stripe_key = getenv('STRIPE_SECRET_KEY');
    if (!$stripe_key || $stripe_key === 'your_stripe_secret_key_here') {
        echo json_encode(['error' => 'Stripe API key not configured']);
        exit;
    }
    
    \Stripe\Stripe::setApiKey($stripe_key);
    
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
        'success_url' => 'https://' . $_SERVER['HTTP_HOST'] . '/payment-success.php?session_id={CHECKOUT_SESSION_ID}&topic_id=' . $topic_id,
        'cancel_url' => 'https://' . $_SERVER['HTTP_HOST'] . '/' . $creator->display_name . '?topic_id=' . $topic_id,
        'metadata' => [
            'topic_id' => $topic_id,
            'creator_id' => $creator_id,
            'type' => 'topic_contribution'
        ]
    ]);
    
    echo json_encode([
        'success' => true,
        'topic_id' => $topic_id,
        'checkout_url' => $checkout_session->url
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'error' => 'Exception: ' . $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}
