<?php
// config/stripe.php - Stripe Connect Configuration

// Load Stripe API keys first
require_once __DIR__ . '/stripe-keys.php';

// Try to load Stripe library from multiple possible locations
$stripe_paths = [
    __DIR__ . '/../vendor/lib/stripe/stripe-php/stripe-php-master/init.php',
    __DIR__ . '/../stripe-php/init.php',
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
];

$stripe_loaded = false;
foreach ($stripe_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $stripe_loaded = true;
        break;
    }
}

if (!$stripe_loaded) {
    throw new Exception('Stripe PHP library not found. Searched paths: ' . implode(', ', $stripe_paths));
}

// Set the API key
\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

define('STRIPE_CURRENCY', 'usd');

// Platform Configuration
define('PLATFORM_FEE_PERCENT', 10); // 10% platform fee

// Fixed URLs for topiclaunch.com
define('STRIPE_SUCCESS_URL', 'https://topiclaunch.com/topics/fund_success.php');
define('STRIPE_TOPIC_CREATION_SUCCESS_URL', 'https://topiclaunch.com/success.php');
define('STRIPE_CANCEL_URL', 'https://topiclaunch.com/topics/fund_cancel.php');

// Stripe Connect OAuth URLs
define('STRIPE_CONNECT_RETURN_URL', 'https://topiclaunch.com/creators/stripe-callback.php');
define('STRIPE_CONNECT_REFRESH_URL', 'https://topiclaunch.com/creators/stripe-refresh.php');
?>
