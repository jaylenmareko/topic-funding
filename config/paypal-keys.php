<?php
// config/paypal-keys.php - PayPal API Keys from environment secrets
// Set in Replit Secrets: PAYPAL_CLIENT_ID, PAYPAL_SECRET

$paypal_client_id = getenv('PAYPAL_CLIENT_ID') ?: ($_ENV['PAYPAL_CLIENT_ID'] ?? '');
$paypal_secret    = getenv('PAYPAL_SECRET')    ?: ($_ENV['PAYPAL_SECRET']    ?? '');

if ($paypal_client_id === '' || $paypal_secret === '') {
    error_log('PayPal keys not configured. Set PAYPAL_CLIENT_ID and PAYPAL_SECRET as environment secrets.');
}

define('PAYPAL_CLIENT_ID', $paypal_client_id);
define('PAYPAL_SECRET',    $paypal_secret);
define('PAYPAL_BASE_URL',  'https://api-m.paypal.com'); // live
define('PLATFORM_FEE_PERCENT', 10);
