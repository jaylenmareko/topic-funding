<?php
// api/get-topic.php - Fetch topic details and process funding
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

session_start();
header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../config/database.php';
} catch (Exception $e) {
    error_log("Failed to load database: " . $e->getMessage());
    echo json_encode(['error' => 'Configuration error: ' . $e->getMessage()]);
    exit;
}

// Handle GET request - Fetch topic details
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $topic_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if (!$topic_id) {
        echo json_encode(['error' => 'Invalid topic ID']);
        exit;
    }

    try {
        $db = new Database();

        $db->query('
            SELECT
                t.*,
                c.display_name as creator_name
            FROM topics t
            JOIN creators c ON t.creator_id = c.id
            WHERE t.id = :topic_id
        ');

        $db->bind(':topic_id', $topic_id);
        $topic = $db->single();

        if (!$topic) {
            echo json_encode(['error' => 'Topic not found']);
            exit;
        }

        echo json_encode($topic);

    } catch (Exception $e) {
        error_log("Failed to fetch topic: " . $e->getMessage());
        echo json_encode(['error' => 'Failed to fetch topic: ' . $e->getMessage()]);
    }
    exit;
}

// Handle POST request - Process funding
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        echo json_encode(['error' => 'Invalid JSON data']);
        exit;
    }

    $topic_id = isset($data['topic_id']) ? (int)$data['topic_id'] : 0;
    $amount = isset($data['amount']) ? floatval($data['amount']) : 0;
    $email = isset($data['email']) ? trim($data['email']) : '';

    if (!$topic_id) {
        echo json_encode(['error' => 'Invalid topic ID']);
        exit;
    }

    // Allow both logged-in and guest users
    $is_logged_in = isset($_SESSION['user_id']);

    // Validate amount
    if ($amount < 1) {
        echo json_encode(['error' => 'Minimum contribution is $1']);
        exit;
    } elseif ($amount > 1000) {
        echo json_encode(['error' => 'Maximum contribution is $1,000']);
        exit;
    } elseif (!is_numeric($amount)) {
        echo json_encode(['error' => 'Please enter a valid amount']);
        exit;
    }

    try {
        // Get topic details
        $db = new Database();
        $db->query('
            SELECT t.*, c.display_name as creator_name, c.id as creator_id
            FROM topics t
            JOIN creators c ON t.creator_id = c.id
            WHERE t.id = :topic_id
        ');
        $db->bind(':topic_id', $topic_id);
        $topic = $db->single();

        if (!$topic) {
            echo json_encode(['error' => 'Topic not found']);
            exit;
        }

        // Check if topic is still active (can be funded)
        if ($topic->status !== 'active') {
            echo json_encode(['error' => 'This topic is no longer accepting funding']);
            exit;
        }

        // For logged-in users, check contribution limits
        if ($is_logged_in) {
            $db->query('SELECT COUNT(*) as count FROM contributions WHERE topic_id = :topic_id AND user_id = :user_id');
            $db->bind(':topic_id', $topic_id);
            $db->bind(':user_id', $_SESSION['user_id']);
            $existing_contributions = $db->single();
            
            if ($existing_contributions && $existing_contributions->count >= 3) {
                echo json_encode(['error' => 'You can only contribute up to 3 times per topic']);
                exit;
            }
        }

        // Create PayPal order for funding existing topic
        require_once __DIR__ . '/../config/paypal.php';

        $host       = $_SERVER['HTTP_HOST'] ?? 'topiclaunch.com';
        $return_url = 'https://' . $host . '/api/paypal-capture.php';
        $cancel_url = 'https://' . $host . '/' . ($topic->creator_name ?? '') . '?payment=cancelled';

        $metadata = [
            'type'              => 'topic_funding',
            'topic_id'          => (string)$topic_id,
            'amount'            => (string)$amount,
            'user_id'           => $is_logged_in ? (string)$_SESSION['user_id'] : '',
            'email'             => $email,
            'creator_handle'    => $topic->creator_name ?? '',
        ];

        $order = paypal_create_order($amount, $metadata, $return_url, $cancel_url);

        echo json_encode(['checkout_url' => $order['approve_url'], 'order_id' => $order['id']]);

    } catch (Exception $e) {
        error_log("Funding error: " . $e->getMessage());
        echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

// Invalid request method
echo json_encode(['error' => 'Invalid request method']);
exit;
