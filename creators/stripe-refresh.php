<?php
// creators/stripe-refresh.php - Handle refresh when user navigates away from Stripe onboarding
session_start();
require_once '../config/database.php';
require_once '../config/stripe.php';

// Only logged-in creators can access
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'creator') {
    header('Location: ../auth/login.php');
    exit;
}

$creator_id = $_SESSION['user_id'];

try {
    // Get creator's Stripe account ID
    $db = new Database();
    $db->query('SELECT stripe_account_id FROM creators WHERE id = :id');
    $db->bind(':id', $creator_id);
    $creator = $db->single();

    if (!$creator || !$creator->stripe_account_id) {
        throw new Exception('No Stripe account found');
    }

    // Create new account onboarding link
    $accountLink = \Stripe\AccountLink::create([
        'account' => $creator->stripe_account_id,
        'refresh_url' => STRIPE_CONNECT_REFRESH_URL,
        'return_url' => STRIPE_CONNECT_RETURN_URL,
        'type' => 'account_onboarding',
    ]);

    // Redirect back to Stripe onboarding
    header('Location: ' . $accountLink->url);
    exit;

} catch (\Stripe\Exception\ApiErrorException $e) {
    error_log("Stripe refresh error: " . $e->getMessage());
    $_SESSION['error_message'] = 'Failed to restart Stripe onboarding. Please try again from your dashboard.';
    header('Location: dashboard.php');
    exit;
} catch (Exception $e) {
    error_log("Refresh error: " . $e->getMessage());
    $_SESSION['error_message'] = 'An error occurred. Please try again.';
    header('Location: dashboard.php');
    exit;
}
?>
