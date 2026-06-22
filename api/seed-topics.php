<?php
require_once __DIR__ . '/../config/database.php';

$secret = $_GET['secret'] ?? '';
if ($secret !== 'xK9mP2qL7nR4vT8') {
    http_response_code(403);
    die('Forbidden');
}

try {
    $db = new Database();

    $db->query("SELECT id FROM creators WHERE username = 'jaylenmareko'");
    $creator = $db->single();
    if (!$creator) die('Creator not found');

    $db->query("SELECT id FROM users WHERE email = :seed_email");
    $db->bind(':seed_email', getenv('SEED_USER_EMAIL') ?: '');
    $user = $db->single();
    if (!$user) die('User not found');

    $cid = $creator->id;
    $uid = $user->id;

    $topics = [
        [
            'title'       => "How I Trained for 90 Days and What Actually Changed",
            'description' => "Full honest breakdown of my 90-day training journey — what worked, what flopped, and the before/after nobody else shows.",
            'threshold'   => 150.00, 'funding' => 87.00, 'status' => 'active',
            'fee'         => 0, 'payout' => 0,
            'expires_in'  => '12 days', 'created_ago' => '3 days',
        ],
        [
            'title'       => "Reviewing Every Camera I've Ever Owned — Worst to Best",
            'description' => "From my first \$80 point-and-shoot to my current setup. Honest takes, no sponsorships.",
            'threshold'   => 100.00, 'funding' => 43.00, 'status' => 'active',
            'fee'         => 0, 'payout' => 0,
            'expires_in'  => '8 days', 'created_ago' => '5 days',
        ],
        [
            'title'       => "Living on \$50 for a Week in NYC — Full Documentary",
            'description' => "A real week on a tight budget in the most expensive city in the US. No cheating, no hidden cash.",
            'threshold'   => 200.00, 'funding' => 120.00, 'status' => 'active',
            'fee'         => 0, 'payout' => 0,
            'expires_in'  => '18 days', 'created_ago' => '1 day',
        ],
        [
            'title'       => "My Honest Thoughts on Going Viral (What They Don't Tell You)",
            'description' => "The real side of overnight growth — the good, the overwhelming, and what I wish I knew.",
            'threshold'   => 125.00, 'funding' => 125.00, 'status' => 'funded',
            'fee'         => 12.50, 'payout' => 112.50,
            'funded_ago'  => '6 hours', 'deadline_in' => '42 hours', 'created_ago' => '4 days',
        ],
        [
            'title'       => "Full Setup Tour: Every Piece of Gear I Actually Use",
            'description' => "Complete walkthrough of my desk, camera, lighting, and audio setup with honest prices and what I'd do differently.",
            'threshold'   => 100.00, 'funding' => 100.00, 'status' => 'funded',
            'fee'         => 10.00, 'payout' => 90.00,
            'funded_ago'  => '2 hours', 'deadline_in' => '46 hours', 'created_ago' => '2 days',
        ],
    ];

    $inserted = 0;
    foreach ($topics as $t) {
        if ($t['status'] === 'active') {
            $db->query("
                INSERT INTO topics
                    (creator_id, initiator_user_id, initiator_email, title, description,
                     funding_threshold, current_funding, status, platform_fee_percent,
                     platform_fee_amount, creator_payout_amount, expires_at, created_at)
                VALUES
                    (:cid, :uid, :email, :title, :desc,
                     :threshold, :funding, 'active', 10,
                     :fee, :payout,
                     NOW() + :expires::interval, NOW() - :created::interval)
            ");
        } else {
            $db->query("
                INSERT INTO topics
                    (creator_id, initiator_user_id, initiator_email, title, description,
                     funding_threshold, current_funding, status, platform_fee_percent,
                     platform_fee_amount, creator_payout_amount,
                     funded_at, content_deadline, created_at)
                VALUES
                    (:cid, :uid, :email, :title, :desc,
                     :threshold, :funding, 'funded', 10,
                     :fee, :payout,
                     NOW() - :funded_ago::interval,
                     NOW() + :deadline_in::interval,
                     NOW() - :created::interval)
            ");
            $db->bind(':funded_ago', $t['funded_ago']);
            $db->bind(':deadline_in', $t['deadline_in']);
        }

        $db->bind(':cid',       $cid);
        $db->bind(':uid',       $uid);
        $db->bind(':email',     getenv('SEED_USER_EMAIL') ?: '');
        $db->bind(':title',     $t['title']);
        $db->bind(':desc',      $t['description']);
        $db->bind(':threshold', $t['threshold']);
        $db->bind(':funding',   $t['funding']);
        $db->bind(':fee',       $t['fee']);
        $db->bind(':payout',    $t['payout']);
        $db->bind(':created',   $t['created_ago']);

        if ($t['status'] === 'active') {
            $db->bind(':expires', $t['expires_in']);
        }

        $db->execute();
        $inserted++;
    }

    echo "OK: $inserted topics inserted (3 active, 2 funded)";
} catch (Exception $e) {
    http_response_code(500);
    echo "Error: " . $e->getMessage();
}
