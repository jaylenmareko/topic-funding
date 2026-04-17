<?php
$secret = $_GET['secret'] ?? '';
if ($secret !== getenv('CRON_SECRET')) {
    http_response_code(403);
    die('Forbidden');
}

$to = $_GET['to'] ?? '';
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    die('Usage: ?secret=YOUR_CRON_SECRET&to=you@example.com');
}

header('Content-Type: text/plain');

$api_key = getenv('RESEND_API_KEY');
if (empty($api_key)) {
    die('RESEND_API_KEY not set');
}

$payload = json_encode([
    'from'    => 'TopicLaunch <' . (getenv('EMAIL_FROM_ADDRESS') ?: 'onboarding@resend.dev') . '>',
    'to'      => [$to],
    'subject' => 'TopicLaunch email test',
    'text'    => "This is a test email from TopicLaunch.\n\nIf you received this, Resend is wired up correctly!",
    'html'    => '<p>This is a test email from <strong>TopicLaunch</strong>.</p><p>If you received this, Resend is wired up correctly!</p>',
]);

$ch = curl_init('https://api.resend.com/emails');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $api_key,
        'Content-Type: application/json',
    ],
    CURLOPT_TIMEOUT => 10,
]);

$response  = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP $http_code\n";
echo $response . "\n";
