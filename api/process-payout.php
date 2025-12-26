<?php
// api/process-payout.php - Automatically process payout to creator when topic is completed
require_once '../config/database.php';
require_once '../config/stripe.php';

/**
 * Process payout to creator for a completed topic
 *
 * @param int $topic_id The ID of the completed topic
 * @return array Result with success status and message
 */
function processCreatorPayout($topic_id) {
    $db = new Database();

    try {
        // Get topic and creator details
        $db->query('
            SELECT
                t.*,
                c.id as creator_id,
                c.stripe_account_id,
                c.stripe_payouts_enabled,
                c.display_name as creator_name
            FROM topics t
            JOIN creators c ON t.creator_id = c.id
            WHERE t.id = :topic_id
        ');
        $db->bind(':topic_id', $topic_id);
        $topic = $db->single();

        if (!$topic) {
            return ['success' => false, 'error' => 'Topic not found'];
        }

        // Verify topic is completed
        if ($topic->status !== 'completed') {
            return ['success' => false, 'error' => 'Topic is not completed'];
        }

        // Check if creator has Stripe connected
        if (empty($topic->stripe_account_id)) {
            return ['success' => false, 'error' => 'Creator has not connected Stripe account'];
        }

        if (!$topic->stripe_payouts_enabled) {
            return ['success' => false, 'error' => 'Creator Stripe account not enabled for payouts'];
        }

        // Check if payout already processed
        $db->query('SELECT id FROM payouts WHERE topic_id = :topic_id AND status IN ("completed", "processing")');
        $db->bind(':topic_id', $topic_id);
        $existing_payout = $db->single();

        if ($existing_payout) {
            return ['success' => false, 'error' => 'Payout already processed for this topic'];
        }

        // Calculate payout amounts
        $total_funded = floatval($topic->current_funding);

        // Stripe already took their fee when fans paid
        // Now we split between platform and creator
        $platform_fee = $total_funded * (PLATFORM_FEE_PERCENT / 100);
        $creator_payout = $total_funded - $platform_fee;

        // Round to 2 decimals
        $platform_fee = round($platform_fee, 2);
        $creator_payout = round($creator_payout, 2);

        // Create payout record (pending)
        $db->query('
            INSERT INTO payouts (
                creator_id, topic_id, amount, platform_fee,
                stripe_fee, net_amount, status
            ) VALUES (
                :creator_id, :topic_id, :amount, :platform_fee,
                0, :net_amount, "processing"
            )
        ');
        $db->bind(':creator_id', $topic->creator_id);
        $db->bind(':topic_id', $topic_id);
        $db->bind(':amount', $total_funded);
        $db->bind(':platform_fee', $platform_fee);
        $db->bind(':net_amount', $creator_payout);
        $db->execute();
        $payout_id = $db->lastInsertId();

        // Create Stripe Transfer
        try {
            $transfer = \Stripe\Transfer::create([
                'amount' => intval($creator_payout * 100), // Convert to cents
                'currency' => 'usd',
                'destination' => $topic->stripe_account_id,
                'description' => 'Payout for topic: ' . $topic->title,
                'metadata' => [
                    'topic_id' => $topic_id,
                    'creator_id' => $topic->creator_id,
                    'payout_id' => $payout_id,
                    'platform' => 'topiclaunch'
                ]
            ]);

            // Update payout record with success
            $db->query('
                UPDATE payouts
                SET status = "completed",
                    stripe_transfer_id = :transfer_id,
                    paid_at = NOW()
                WHERE id = :payout_id
            ');
            $db->bind(':transfer_id', $transfer->id);
            $db->bind(':payout_id', $payout_id);
            $db->execute();

            // Update creator's total earnings
            $db->query('
                UPDATE creators
                SET total_earnings = total_earnings + :amount
                WHERE id = :creator_id
            ');
            $db->bind(':amount', $creator_payout);
            $db->bind(':creator_id', $topic->creator_id);
            $db->execute();

            // Log success
            error_log("Payout successful - Topic: $topic_id, Creator: {$topic->creator_id}, Amount: $$creator_payout");

            return [
                'success' => true,
                'payout_id' => $payout_id,
                'amount' => $creator_payout,
                'transfer_id' => $transfer->id,
                'message' => "Payout of $$creator_payout processed successfully"
            ];

        } catch (\Stripe\Exception\ApiErrorException $e) {
            // Stripe transfer failed
            $error_message = $e->getMessage();
            error_log("Stripe transfer failed - Topic: $topic_id, Error: $error_message");

            // Update payout record with failure
            $db->query('
                UPDATE payouts
                SET status = "failed",
                    failure_reason = :failure_reason
                WHERE id = :payout_id
            ');
            $db->bind(':failure_reason', $error_message);
            $db->bind(':payout_id', $payout_id);
            $db->execute();

            return [
                'success' => false,
                'error' => 'Stripe transfer failed: ' . $error_message
            ];
        }

    } catch (Exception $e) {
        error_log("Payout processing error: " . $e->getMessage());
        return [
            'success' => false,
            'error' => 'Payout processing failed: ' . $e->getMessage()
        ];
    }
}

// If called directly (for testing or manual triggers)
if (php_sapi_name() === 'cli' || (isset($_POST['topic_id']) && isset($_POST['manual_trigger']))) {
    $topic_id = isset($_POST['topic_id']) ? intval($_POST['topic_id']) : (isset($argv[1]) ? intval($argv[1]) : 0);

    if ($topic_id > 0) {
        $result = processCreatorPayout($topic_id);
        echo json_encode($result);
    } else {
        echo json_encode(['success' => false, 'error' => 'No topic ID provided']);
    }
}
?>
