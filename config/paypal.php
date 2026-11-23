<?php
// config/paypal.php - PayPal REST API helpers

require_once __DIR__ . '/paypal-keys.php';

/**
 * Get a PayPal access token (cached for 8h via transient file)
 */
function paypal_get_access_token() {
    $cache_file = sys_get_temp_dir() . '/paypal_token.json';
    if (file_exists($cache_file)) {
        $cached = json_decode(file_get_contents($cache_file), true);
        if ($cached && isset($cached['expires_at']) && $cached['expires_at'] > time() + 60) {
            return $cached['access_token'];
        }
    }

    $ch = curl_init(PAYPAL_BASE_URL . '/v1/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
        CURLOPT_USERPWD        => PAYPAL_CLIENT_ID . ':' . PAYPAL_SECRET,
        CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Accept-Language: en_US'],
    ]);
    $response = curl_exec($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) {
        throw new Exception('PayPal auth failed: ' . $response);
    }

    $data = json_decode($response, true);
    $data['expires_at'] = time() + ($data['expires_in'] ?? 28800);
    file_put_contents($cache_file, json_encode($data));
    return $data['access_token'];
}

/**
 * Make a PayPal API request
 */
function paypal_request($method, $endpoint, $body = null, $extra_headers = []) {
    $token = paypal_get_access_token();
    $url   = PAYPAL_BASE_URL . $endpoint;

    $headers = array_merge([
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
        'Prefer: return=representation',
    ], $extra_headers);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $response = curl_exec($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => $code, 'body' => json_decode($response, true), 'raw' => $response];
}

/**
 * Create a PayPal Order (replaces Stripe Checkout Session)
 * Returns ['id' => order_id, 'approve_url' => redirect_url]
 */
function paypal_create_order($amount_usd, $metadata, $return_url, $cancel_url) {
    $result = paypal_request('POST', '/v2/checkout/orders', [
        'intent' => 'CAPTURE',
        'purchase_units' => [[
            'amount'      => ['currency_code' => 'USD', 'value' => number_format($amount_usd, 2, '.', '')],
            'description' => $metadata['title'] ?? 'TopicLaunch contribution',
            'custom_id'   => json_encode($metadata), // passes metadata through order
        ]],
        'application_context' => [
            'return_url'          => $return_url,
            'cancel_url'          => $cancel_url,
            'brand_name'          => 'TopicLaunch',
            'landing_page'        => 'LOGIN',
            'user_action'         => 'PAY_NOW',
            'shipping_preference' => 'NO_SHIPPING',
        ],
    ]);

    if ($result['code'] !== 201 || empty($result['body']['id'])) {
        throw new Exception('PayPal create order failed: ' . $result['raw']);
    }

    $approve_url = '';
    foreach ($result['body']['links'] as $link) {
        if ($link['rel'] === 'approve') {
            $approve_url = $link['href'];
            break;
        }
    }

    return ['id' => $result['body']['id'], 'approve_url' => $approve_url];
}

/**
 * Capture a PayPal Order after buyer approves
 * Returns the captured order details
 */
function paypal_capture_order($order_id) {
    $result = paypal_request('POST', '/v2/checkout/orders/' . $order_id . '/capture');

    if ($result['code'] !== 201 && $result['code'] !== 200) {
        throw new Exception('PayPal capture failed: ' . $result['raw']);
    }

    return $result['body'];
}

/**
 * Get PayPal Order details
 */
function paypal_get_order($order_id) {
    $result = paypal_request('GET', '/v2/checkout/orders/' . $order_id);
    if ($result['code'] !== 200) {
        throw new Exception('PayPal get order failed: ' . $result['raw']);
    }
    return $result['body'];
}

/**
 * Send a payout to a PayPal email (replaces Stripe Transfer)
 * $recipient_email - creator's PayPal email
 * $amount_usd      - amount to send
 * $note            - payout note
 */
function paypal_send_payout($recipient_email, $amount_usd, $note = 'TopicLaunch creator payout') {
    $sender_batch_id = 'tl_payout_' . time() . '_' . rand(1000, 9999);

    $result = paypal_request('POST', '/v1/payments/payouts', [
        'sender_batch_header' => [
            'sender_batch_id' => $sender_batch_id,
            'email_subject'   => 'You have a payout from TopicLaunch!',
            'email_message'   => 'Your topic was completed. ' . $note,
        ],
        'items' => [[
            'recipient_type' => 'EMAIL',
            'receiver'       => $recipient_email,
            'amount'         => ['value' => number_format($amount_usd, 2, '.', ''), 'currency' => 'USD'],
            'note'           => $note,
            'sender_item_id' => $sender_batch_id,
        ]],
    ]);

    if ($result['code'] !== 201 || empty($result['body']['batch_header']['payout_batch_id'])) {
        throw new Exception('PayPal payout failed: ' . $result['raw']);
    }

    return [
        'payout_batch_id' => $result['body']['batch_header']['payout_batch_id'],
        'batch_status'    => $result['body']['batch_header']['batch_status'],
    ];
}
