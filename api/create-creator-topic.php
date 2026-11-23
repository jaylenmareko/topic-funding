<?php
// api/create-creator-topic.php - Creator creates their own topic
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Check if creator is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

require_once __DIR__ . '/../config/database.php';

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(['error' => 'Invalid JSON']);
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
$creator_initiated = $input['creator_initiated'] ?? false;

// Validation
if (!$creator_id || !$title || !$description || !$funding_goal) {
    echo json_encode(['error' => 'All fields are required']);
    exit;
}

if (!$creator_initiated) {
    echo json_encode(['error' => 'Invalid request']);
    exit;
}

try {
    $db = new Database();
    
    // Verify this user owns this creator account
    $db->query('SELECT * FROM creators WHERE id = :creator_id AND applicant_user_id = :user_id AND is_active = 1');
    $db->bind(':creator_id', $creator_id);
    $db->bind(':user_id', $_SESSION['user_id']);
    $creator = $db->single();
    
    if (!$creator) {
        echo json_encode(['error' => 'Unauthorized']);
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
    
    // Calculate platform fee (10%)
    $platform_fee_percent = 10.00;
    $platform_fee_amount = $funding_goal * ($platform_fee_percent / 100);
    $creator_payout_amount = $funding_goal - $platform_fee_amount;
    
    // Create the topic
    $db->query("
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
            NULL,
            :title,
            :description,
            :funding_threshold,
            0,
            :platform_fee_percent,
            :platform_fee_amount,
            :creator_payout_amount,
            'active',
            NOW()
        )
    ");
    
    $db->bind(':creator_id', $creator_id);
    $db->bind(':title', $title);
    $db->bind(':description', $description);
    $db->bind(':funding_threshold', $funding_goal);
    $db->bind(':platform_fee_percent', $platform_fee_percent);
    $db->bind(':platform_fee_amount', $platform_fee_amount);
    $db->bind(':creator_payout_amount', $creator_payout_amount);
    
    if (!$db->execute()) {
        echo json_encode(['error' => 'Failed to create topic']);
        exit;
    }
    
    $topic_id = $db->lastInsertId();
    
    echo json_encode([
        'success' => true,
        'topic_id' => $topic_id
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'error' => 'Exception: ' . $e->getMessage()
    ]);
}
