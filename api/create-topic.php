<?php
// api/create-topic.php - Creates PayPal order for topic funding
ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods', 'POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

session_start();

try {
    require_once __DIR__ . '/../config/database.php';
} catch (Exception $e) {
    echo json_encode(['error' => 'Database config failed: ' . $e->getMessage()]); exit;
}

$rawInput = file_get_contents('php://input');
$input    = json_decode($rawInput, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(['error' => 'Invalid JSON']); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Invalid request method']); exit;
}

$creator_id     = $input['creator_id']     ?? null;
$email          = trim($input['email']         ?? '');
$title          = trim($input['title']         ?? '');
$description    = trim($input['description']   ?? '');
$funding_goal   = floatval($input['funding_goal']   ?? 0);
$initial_amount = floatval($input['initial_amount'] ?? 0);

if (!$creator_id || !$email || !$title || !$description || !$funding_goal || !$initial_amount) {
    echo json_encode(['error' => 'All fields are required']); exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['error' => 'Please enter a valid email address']); exit;
}
if ($initial_amount < 1 || $initial_amount > 1000) {
    echo json_encode(['error' => 'Initial amount must be between $1 and $1,000']); exit;
}

try {
    $db = new Database();

    $db->query('SELECT * FROM creators WHERE id = :creator_id AND is_active = 1');
    $db->bind(':creator_id', $creator_id);
    $creator = $db->single();

    if (!$creator) { echo json_encode(['error' => 'Creator not found']); exit; }

    if ($funding_goal < $creator->minimum_topic_price) {
        echo json_encode(['error' => 'Funding goal must be at least $' . number_format($creator->minimum_topic_price, 2)]); exit;
    }
    if ($funding_goal > 10000) {
        echo json_encode(['error' => 'Funding goal cannot exceed $10,000']); exit;
    }
    if ($initial_amount > $funding_goal) {
        echo json_encode(['error' => 'Initial amount cannot exceed funding goal']); exit;
    }

    require_once __DIR__ . '/../config/paypal.php';

    $host         = $_SERVER['HTTP_HOST'] ?? 'topiclaunch.com';
    $return_url   = 'https://' . $host . '/api/paypal-capture.php';
    $cancel_url   = 'https://' . $host . '/creators/';

    $metadata = [
        'type'                 => 'topic_creation',
        'creator_id'           => $creator_id,
        'initiator_user_id'    => $_SESSION['user_id'] ?? '',
        'initiator_email'      => $email,
        'title'                => $title,
        'description'          => $description,
        'funding_threshold'    => $funding_goal,
        'initial_amount'       => $initial_amount,
        'platform_fee_percent' => '10.00',
        'creator_display_name' => $creator->display_name,
        'creator_handle'       => $creator->handle,
    ];

    $order = paypal_create_order($initial_amount, $metadata, $return_url, $cancel_url);

    echo json_encode([
        'success'      => true,
        'checkout_url' => $order['approve_url'],
        'order_id'     => $order['id'],
    ]);

} catch (Exception $e) {
    echo json_encode(['error' => 'Exception: ' . $e->getMessage()]);
}
