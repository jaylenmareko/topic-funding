<?php
// config/stripe-keys.php - Stripe API Keys read from environment secrets
// Set these in Replit Secrets: STRIPE_PUBLISHABLE_KEY, STRIPE_SECRET_KEY, STRIPE_WEBHOOK_SECRET

$publishable = getenv('STRIPE_PUBLISHABLE_KEY') ?: ($_ENV['STRIPE_PUBLISHABLE_KEY'] ?? '');
$secret      = getenv('STRIPE_SECRET_KEY')      ?: ($_ENV['STRIPE_SECRET_KEY']      ?? '');
$webhook     = getenv('STRIPE_WEBHOOK_SECRET')  ?: ($_ENV['STRIPE_WEBHOOK_SECRET']  ?? '');

if ($publishable === '' || $secret === '' || $webhook === '') {
    error_log('Stripe keys not configured. Set STRIPE_PUBLISHABLE_KEY, STRIPE_SECRET_KEY, STRIPE_WEBHOOK_SECRET as environment secrets.');
}

define('STRIPE_PUBLISHABLE_KEY', $publishable);
define('STRIPE_SECRET_KEY',      $secret);
define('STRIPE_WEBHOOK_SECRET',  $webhook);
