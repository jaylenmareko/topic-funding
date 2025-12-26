<?php
// config/stripe.php - Stripe Connect Configuration
require_once __DIR__ . '/../stripe-php/init.php';

// Load Stripe API keys from separate file (not tracked in git)
require_once __DIR__ . '/stripe-keys.php';

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
