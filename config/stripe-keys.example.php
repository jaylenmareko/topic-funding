<?php
// config/stripe-keys.example.php - Template for Stripe API Keys
// Copy this file to stripe-keys.php and add your actual Stripe keys

// Stripe API Keys - Get these from https://dashboard.stripe.com/apikeys
define('STRIPE_PUBLISHABLE_KEY', 'pk_live_YOUR_PUBLISHABLE_KEY_HERE');
define('STRIPE_SECRET_KEY', 'sk_live_YOUR_SECRET_KEY_HERE');

// Webhook Secret - Get this after creating webhook endpoint
define('STRIPE_WEBHOOK_SECRET', 'whsec_YOUR_WEBHOOK_SECRET_HERE');
?>
