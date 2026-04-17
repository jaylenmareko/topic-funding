<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../vendor/autoload.php';

$secret = $_GET['secret'] ?? '';
if ($secret !== getenv('CRON_SECRET')) {
    http_response_code(403);
    die('Forbidden');
}

header('Content-Type: text/plain');

$db = new Database();

// 1. Create Stripe test PaymentIntent
\Stripe\Stripe::setApiKey(getenv('STRIPE_SECRET_KEY'));

try {
    $intent = \Stripe\PaymentIntent::create([
        'amount' => 500,
        'currency' => 'usd',
        'confirm' => true,
        'payment_method' => 'pm_card_visa',
        'automatic_payment_methods' => [
            'enabled' => true,
            'allow_redirects' => 'never',
        ],
        'metadata' => ['test' => 'true'],
    ]);
    $pi_id = $intent->id;
    echo "PaymentIntent created: $pi_id\n";
} catch (Exception $e) {
    die("Stripe error: " . $e->getMessage() . "\n");
}

// 2. Create/find user
$db->query("INSERT INTO users (username, email, password_hash, full_name, is_verified, created_at, updated_at)
            VALUES ('j7beatss', 'j7beatss@gmail.com', 'TEST_USER_NO_LOGIN', 'Test Fan', 1, NOW(), NOW())
            ON CONFLICT (email) DO NOTHING");
$db->execute();

$db->query("SELECT id FROM users WHERE email = 'j7beatss@gmail.com'");
$user = $db->single();
$user_id = $user->id;
echo "Fan user ID: $user_id\n";

// 3. Create funded topic (deadline 2 minutes from now)
$db->query("INSERT INTO topics (
    creator_id, title, description, funding_threshold, current_funding,
    status, initiator_email, platform_fee_percent, platform_fee_amount,
    creator_payout_amount, funded_at, content_deadline, created_at
) VALUES (
    1, 'Test Topic - Refund Test',
    'Test topic to trigger the auto-refund system.',
    5.00, 5.00, 'funded', 'j7beatss@gmail.com',
    10.00, 0.50, 4.50,
    NOW() - INTERVAL '47 hours 58 minutes',
    NOW() + INTERVAL '2 minutes',
    NOW() - INTERVAL '47 hours 58 minutes'
) RETURNING id");
$db->execute();

$db->query("SELECT id FROM topics WHERE title = 'Test Topic - Refund Test' ORDER BY id DESC LIMIT 1");
$topic = $db->single();
$topic_id = $topic->id;
echo "Topic ID: $topic_id\n";

// 4. Create contribution
$db->query("INSERT INTO contributions (topic_id, user_id, amount, payment_status, stripe_payment_intent_id, contributed_at)
            VALUES (:topic_id, :user_id, 5.00, 'completed', :pi_id, NOW())");
$db->bind(':topic_id', $topic_id);
$db->bind(':user_id', $user_id);
$db->bind(':pi_id', $pi_id);
$db->execute();

echo "Contribution created.\n";
echo "\nDone! Wait 2 minutes then run: php cron/auto_refund.php\n";
echo "Or trigger via cron URL on the live site.\n";
