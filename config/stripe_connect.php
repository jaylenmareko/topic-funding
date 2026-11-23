<?php
// config/stripe_connect.php - DISABLED (removed creator_stripe_payouts table)
// This file is commented out since Stripe Connect is not fully implemented
// and the creator_stripe_payouts table has been removed

/*
class StripeConnectManager {
    // Stripe Connect functionality disabled
    // Use manual PayPal payouts instead via payout_requests table
    
    public function __construct() {
        throw new Exception("Stripe Connect is disabled. Use manual PayPal payouts instead.");
    }
}
*/

// Redirect any calls to use manual payout system instead
class StripeConnectManager {
    public function createConnectAccount($creator_id, $email, $display_name) {
        return [
            'success' => false,
            'error' => 'Stripe Connect disabled. Use manual PayPal payouts instead.'
        ];
    }
    
    public function createOnboardingLink($creator_id) {
        return [
            'success' => false,
            'error' => 'Stripe Connect disabled. Use manual PayPal payouts instead.'
        ];
    }
    
    public function isAccountReady($creator_id) {
        return false; // Always return false since Stripe Connect is disabled
    }
    
    public function processCreatorPayout($topic_id, $creator_id, $amount, $description) {
        return [
            'success' => false,
            'error' => 'Stripe Connect disabled. Use manual PayPal payouts instead.'
        ];
    }
    
    public function getCreatorPayouts($creator_id) {
        return []; // Return empty array since no Stripe payouts
    }
}

// Log that Stripe Connect is disabled
error_log("Stripe Connect functionality is disabled. Using manual PayPal payout system via payout_requests table.");
?>
