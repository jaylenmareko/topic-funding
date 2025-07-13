<?php
// config/stripe.php - Updated for topiclaunch.com
require_once __DIR__ . '/../stripe-php/init.php';

// LIVE keys for topiclaunch.com
define('STRIPE_PUBLISHABLE_KEY', 'pk_live_51QbKG8Kg5kxyA3jRmQrkUtkB4OKH0xwvAlpm5huKGKKh1G9QbLJYP8ZMijil8f3oQWHuDWofc0FRmapwzfqhw9Ne00eDEcgBQ3');
define('STRIPE_SECRET_KEY', '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');

// ADD THIS LINE - Get webhook secret from Stripe Dashboard > Webhooks > Your endpoint > Signing secret
define('STRIPE_WEBHOOK_SECRET', 'whsec_kDLBRQ8VwuhVYPr3nreQOQKZJXaBpytv');

\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

define('STRIPE_CURRENCY', 'usd');

// Fixed URLs for topiclaunch.com
define('STRIPE_SUCCESS_URL', 'https://topiclaunch.com/topics/fund_success.php');
define('STRIPE_TOPIC_CREATION_SUCCESS_URL', 'https://topiclaunch.com/success.php');
define('STRIPE_CANCEL_URL', 'https://topiclaunch.com/topics/fund_cancel.php');
?>
