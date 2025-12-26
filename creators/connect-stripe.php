<?php
// creators/connect-stripe.php - Start Stripe Connect onboarding for creators
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
    // Get creator details
    $db = new Database();
    $db->query('SELECT * FROM creators WHERE id = :id');
    $db->bind(':id', $creator_id);
    $creator = $db->single();

    if (!$creator) {
        die('Creator not found');
    }

    // If creator already has a Stripe account, check its status
    if ($creator->stripe_account_id) {
        // Get account status from Stripe
        try {
            $account = \Stripe\Account::retrieve($creator->stripe_account_id);

            // If already fully onboarded, redirect to dashboard
            if ($account->details_submitted && $account->charges_enabled) {
                $_SESSION['success_message'] = 'Your Stripe account is already connected!';
                header('Location: dashboard.php');
                exit;
            }

            // If account exists but onboarding not complete, create new onboarding link
            $accountLink = \Stripe\AccountLink::create([
                'account' => $creator->stripe_account_id,
                'refresh_url' => STRIPE_CONNECT_REFRESH_URL,
                'return_url' => STRIPE_CONNECT_RETURN_URL,
                'type' => 'account_onboarding',
            ]);

            header('Location: ' . $accountLink->url);
            exit;

        } catch (\Stripe\Exception\ApiErrorException $e) {
            // Account might have been deleted, create new one
            error_log("Stripe account error: " . $e->getMessage());
        }
    }

    // Create new Stripe Connect Express account
    $account = \Stripe\Account::create([
        'type' => 'express',
        'country' => 'US', // You can make this dynamic based on creator's country
        'email' => $creator->email,
        'capabilities' => [
            'card_payments' => ['requested' => true],
            'transfers' => ['requested' => true],
        ],
        'business_type' => 'individual',
        'metadata' => [
            'creator_id' => $creator_id,
            'platform' => 'topiclaunch'
        ]
    ]);

    // Save the account ID to database
    $db->query('UPDATE creators SET stripe_account_id = :account_id WHERE id = :id');
    $db->bind(':account_id', $account->id);
    $db->bind(':id', $creator_id);
    $db->execute();

    // Create account onboarding link
    $accountLink = \Stripe\AccountLink::create([
        'account' => $account->id,
        'refresh_url' => STRIPE_CONNECT_REFRESH_URL,
        'return_url' => STRIPE_CONNECT_RETURN_URL,
        'type' => 'account_onboarding',
    ]);

    // Redirect creator to Stripe onboarding
    header('Location: ' . $accountLink->url);
    exit;

} catch (\Stripe\Exception\ApiErrorException $e) {
    error_log("Stripe Connect error: " . $e->getMessage());
    $_SESSION['error_message'] = 'Failed to start Stripe onboarding. Please try again.';
    header('Location: dashboard.php');
    exit;
} catch (Exception $e) {
    error_log("Connect error: " . $e->getMessage());
    $_SESSION['error_message'] = 'An error occurred. Please try again.';
    header('Location: dashboard.php');
    exit;
}
?>
