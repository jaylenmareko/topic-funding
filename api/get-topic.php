<?php
// api/get-topic.php - Fetch topic details and process funding
session_start();
header('Content-Type: application/json');
require_once '../config/database.php';
require_once '../config/stripe.php';
require_once '../config/csrf.php';
require_once '../config/sanitizer.php';

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
        echo json_encode(['error' => 'Failed to fetch topic']);
    }
}

// Handle POST request - Process funding
elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);

    $topic_id = isset($data['topic_id']) ? InputSanitizer::sanitizeInt($data['topic_id']) : 0;
    $amount = isset($data['amount']) ? InputSanitizer::sanitizeFloat($data['amount']) : 0;

    if (!$topic_id) {
        echo json_encode(['error' => 'Invalid topic ID']);
        exit;
    }

    // Allow both logged-in and guest users
    $is_logged_in = isset($_SESSION['user_id']);

    // CSRF Protection - only for logged-in users
    if ($is_logged_in) {
        if (!isset($data['csrf_token']) || !CSRFProtection::validateToken($data['csrf_token'])) {
            echo json_encode(['error' => 'Invalid security token']);
            exit;
        }
    }

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
        $helper = new DatabaseHelper();
        $topic = $helper->getTopicById($topic_id);

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
            $db = new Database();
            $db->query('SELECT COUNT(*) as count FROM contributions WHERE topic_id = :topic_id AND user_id = :user_id');
            $db->bind(':topic_id', $topic_id);
            $db->bind(':user_id', $_SESSION['user_id']);
            $existing_contributions = $db->single()->count;

            if ($existing_contributions >= 3) {
                echo json_encode(['error' => 'You can only contribute up to 3 times per topic']);
                exit;
            }
        }

        // Create metadata for both logged-in and guest users
        $metadata = [
            'type' => 'topic_funding',
            'topic_id' => $topic_id,
            'amount' => $amount,
            'is_guest' => $is_logged_in ? 'false' : 'true'
        ];

        // Add user ID if logged in
        if ($is_logged_in) {
            $metadata['user_id'] = $_SESSION['user_id'];
        }

        // Create success URL based on user status
        if ($is_logged_in) {
            $success_url = 'https://topiclaunch.com/payment_success.php?session_id={CHECKOUT_SESSION_ID}&type=topic_funding&topic_id=' . $topic_id;
        } else {
            // For guests, redirect to signup after payment
            $success_url = 'https://topiclaunch.com/auth/register.php?type=fan&topic_funded=1&session_id={CHECKOUT_SESSION_ID}&topic_id=' . $topic_id . '&amount=' . $amount;
        }

        // Create Stripe Checkout Session
        $session = \Stripe\Checkout\Session::create([
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => STRIPE_CURRENCY,
                    'product_data' => [
                        'name' => 'Fund Topic: ' . $topic->title,
                        'description' => 'Contribution to fund this topic by ' . $topic->creator_name,
                    ],
                    'unit_amount' => $amount * 100, // Stripe expects cents
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'success_url' => $success_url,
            'cancel_url' => 'https://topiclaunch.com/payment_cancelled.php?type=topic_funding&topic_id=' . $topic_id,
            'metadata' => $metadata,
            'customer_email' => $is_logged_in ? ($_SESSION['email'] ?? null) : null,
        ]);

        // Return the Stripe Checkout URL
        echo json_encode(['checkout_url' => $session->url]);

    } catch (\Stripe\Exception\ApiErrorException $e) {
        error_log("Stripe error: " . $e->getMessage());
        // Temporarily show detailed error for debugging
        echo json_encode(['error' => 'Stripe error: ' . $e->getMessage()]);
    } catch (Exception $e) {
        error_log("Funding error: " . $e->getMessage());
        // Temporarily show detailed error for debugging
        echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
    }
}
