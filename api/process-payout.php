<?php
// api/process-payout.php - Send 90% of topic funding to creator via PayPal Payouts API
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/paypal.php';

/**
 * Process payout to creator for a completed topic
 * Creator must have a paypal_email on their creators record.
 */
function processCreatorPayout($topic_id) {
    $db = new Database();

    try {
        $db->query("
            SELECT t.*, c.id as creator_id, c.paypal_email, c.display_name as creator_name
            FROM topics t
            JOIN creators c ON t.creator_id = c.id
            WHERE t.id = :topic_id
        ");
        $db->bind(':topic_id', $topic_id);
        $topic = $db->single();

        if (!$topic) {
            return ['success' => false, 'error' => 'Topic not found'];
        }

        if ($topic->status !== 'completed') {
            return ['success' => false, 'error' => 'Topic is not completed'];
        }

        if (empty($topic->paypal_email)) {
            return ['success' => false, 'error' => 'Creator has no PayPal email on file'];
        }

        // Deduplicate
        $db->query("SELECT id FROM payouts WHERE topic_id = :topic_id AND status IN ('completed', 'processing')");
        $db->bind(':topic_id', $topic_id);
        if ($db->single()) {
            return ['success' => false, 'error' => 'Payout already processed for this topic'];
        }

        $total_funded  = floatval($topic->current_funding);
        $platform_fee  = round($total_funded * (PLATFORM_FEE_PERCENT / 100), 2);
        $creator_payout = round($total_funded - $platform_fee, 2);

        // Create payout record (processing)
        $db->query("
            INSERT INTO payouts (creator_id, topic_id, amount, platform_fee, stripe_fee, net_amount, status)
            VALUES (:creator_id, :topic_id, :amount, :platform_fee, 0, :net_amount, 'processing')
        ");
        $db->bind(':creator_id',   $topic->creator_id);
        $db->bind(':topic_id',     $topic_id);
        $db->bind(':amount',       $total_funded);
        $db->bind(':platform_fee', $platform_fee);
        $db->bind(':net_amount',   $creator_payout);
        $db->execute();
        $payout_id = $db->lastInsertId();

        try {
            $note   = 'Payout for completed topic: ' . $topic->title;
            $result = paypal_send_payout($topic->paypal_email, $creator_payout, $note);

            $db->query("
                UPDATE payouts
                SET status = 'completed', stripe_transfer_id = :batch_id, paid_at = NOW()
                WHERE id = :payout_id
            ");
            $db->bind(':batch_id',  $result['payout_batch_id']);
            $db->bind(':payout_id', $payout_id);
            $db->execute();

            error_log("PayPal payout sent: topic=$topic_id creator={$topic->creator_id} amount=$$creator_payout batch={$result['payout_batch_id']}");

            return [
                'success'         => true,
                'payout_id'       => $payout_id,
                'amount'          => $creator_payout,
                'payout_batch_id' => $result['payout_batch_id'],
                'message'         => "Payout of $$creator_payout sent via PayPal",
            ];

        } catch (Exception $e) {
            $db->query("UPDATE payouts SET status = 'failed', failure_reason = :reason WHERE id = :payout_id");
            $db->bind(':reason',   $e->getMessage());
            $db->bind(':payout_id', $payout_id);
            $db->execute();

            return ['success' => false, 'error' => 'PayPal payout failed: ' . $e->getMessage()];
        }

    } catch (Exception $e) {
        error_log("Payout processing error: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Direct call support
if (php_sapi_name() === 'cli' || (isset($_POST['topic_id']) && isset($_POST['manual_trigger']))) {
    $topic_id = isset($_POST['topic_id']) ? intval($_POST['topic_id']) : (isset($argv[1]) ? intval($argv[1]) : 0);
    echo json_encode($topic_id > 0 ? processCreatorPayout($topic_id) : ['success' => false, 'error' => 'No topic ID']);
}
