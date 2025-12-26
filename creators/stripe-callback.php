<?php
// creators/stripe-callback.php - Handle return from Stripe Connect onboarding
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

    // Retrieve account details from Stripe
    $account = \Stripe\Account::retrieve($creator->stripe_account_id);

    // Update creator's Stripe status in database
    $db->query('
        UPDATE creators
        SET stripe_onboarding_complete = :onboarding_complete,
            stripe_details_submitted = :details_submitted,
            stripe_charges_enabled = :charges_enabled,
            stripe_payouts_enabled = :payouts_enabled
        WHERE id = :id
    ');
    $db->bind(':onboarding_complete', $account->details_submitted ? 1 : 0);
    $db->bind(':details_submitted', $account->details_submitted ? 1 : 0);
    $db->bind(':charges_enabled', $account->charges_enabled ? 1 : 0);
    $db->bind(':payouts_enabled', $account->payouts_enabled ? 1 : 0);
    $db->bind(':id', $creator_id);
    $db->execute();

    // Check if onboarding is complete
    if ($account->details_submitted && $account->charges_enabled) {
        $_SESSION['success_message'] = '🎉 Stripe account connected successfully! You can now receive payouts.';
    } else {
        $_SESSION['warning_message'] = 'Stripe onboarding incomplete. Please complete all required information.';
    }

    header('Location: dashboard.php');
    exit;

} catch (\Stripe\Exception\ApiErrorException $e) {
    error_log("Stripe callback error: " . $e->getMessage());
    $_SESSION['error_message'] = 'Failed to verify Stripe account. Please try again.';
    header('Location: dashboard.php');
    exit;
} catch (Exception $e) {
    error_log("Callback error: " . $e->getMessage());
    $_SESSION['error_message'] = 'An error occurred. Please try again.';
    header('Location: dashboard.php');
    exit;
}
?>
