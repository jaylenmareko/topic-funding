<?php
// api/stripe-connect-onboard.php
// Creates a Stripe Express account (if needed) and returns an onboarding link URL.
// Called by connectStripeAccount() JS on the creator dashboard.

session_start();

$is_relink = isset($_GET['relink']) && $_GET['relink'] === '1';

if (!$is_relink) {
    header('Content-Type: application/json');
}

require_once '../config/database.php';
require_once '../config/stripe-keys.php';

if (!isset($_SESSION['user_id'])) {
    if ($is_relink) {
        header('Location: /creators/dashboard.php');
        exit;
    }
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

if (!file_exists('../vendor/autoload.php')) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Stripe library not found']);
    exit;
}

require_once '../vendor/autoload.php';

\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

$db = new Database();
$db->query('SELECT c.*, u.email FROM creators c LEFT JOIN users u ON c.applicant_user_id = u.id WHERE c.applicant_user_id = :user_id AND c.is_active = 1');
$db->bind(':user_id', $_SESSION['user_id']);
$creator = $db->single();

if (!$creator) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Creator account not found']);
    exit;
}

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host     = $_SERVER['HTTP_HOST'];
$base_url = $protocol . '://' . $host;

try {
    $account_id = $creator->stripe_account_id;

    if (empty($account_id)) {
        $account = \Stripe\Account::create([
            'type'         => 'express',
            'email'        => $creator->email,
            'capabilities' => [
                'transfers' => ['requested' => true],
            ],
            'business_profile' => [
                'name' => $creator->display_name,
                'url'  => $base_url . '/creators/' . urlencode($creator->handle),
            ],
            'metadata' => [
                'creator_id'     => $creator->id,
                'creator_handle' => $creator->handle,
            ],
        ]);

        $account_id = $account->id;

        $db->query('UPDATE creators SET stripe_account_id = :account_id, stripe_account_status = :status, updated_at = NOW() WHERE id = :id');
        $db->bind(':account_id', $account_id);
        $db->bind(':status', 'pending');
        $db->bind(':id', $creator->id);
        $db->execute();
    }

    $account_link = \Stripe\AccountLink::create([
        'account'     => $account_id,
        'refresh_url' => $base_url . '/api/stripe-connect-onboard.php?relink=1',
        'return_url'  => $base_url . '/creators/dashboard.php?stripe_return=1',
        'type'        => 'account_onboarding',
    ]);

    if ($is_relink) {
        header('Location: ' . $account_link->url);
        exit;
    }

    echo json_encode(['success' => true, 'url' => $account_link->url]);

} catch (\Stripe\Exception\ApiErrorException $e) {
    error_log('Stripe Connect onboard error: ' . $e->getMessage());
    if ($is_relink) {
        header('Location: /creators/dashboard.php?stripe_error=1');
        exit;
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Stripe error: ' . $e->getMessage()]);
}
