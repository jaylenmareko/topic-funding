<?php
require_once __DIR__ . '/config/database.php';
$pdo = $db;
$cases = [
    ['creator_dummy_1', 'creatordummy1@example.com', 'Ava Stone', 120, ['fitness', 'health', 'motivation'], 'image_1776214079960.png', 'Ava creates motivational wellness content that helps busy people build healthier routines, stay consistent, and feel better every week.'],
    ['creator_dummy_2', 'creatordummy2@example.com', 'Noah Reed', 150, ['business', 'money', 'career'], 'image_1776214145837.png', 'Noah shares practical business and money advice for ambitious people who want to grow income, build useful habits, and make smarter career decisions.'],
    ['creator_dummy_3', 'creatordummy3@example.com', 'Maya Chen', 200, ['technology & ai', 'psychology', 'cosmetics'], 'image_1776215721179.png', 'Maya explores technology, psychology, and beauty in a thoughtful way, creating detailed content that blends useful insight with creative storytelling.'],
];
foreach ($cases as $case) {
    [$username, $email, $display, $price, $topics, $imageFile, $bio] = $case;
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :u OR email = :e');
    $stmt->execute([':u' => $username, ':e' => $email]);
    $existing = $stmt->fetch(PDO::FETCH_OBJ);
    if (!$existing) {
        $stmt = $pdo->prepare('INSERT INTO users (username, email, password_hash, is_active, is_verified, verified_at, created_at) VALUES (:u, :e, :p, 1, 1, NOW(), NOW()) RETURNING id');
        $stmt->execute([':u' => $username, ':e' => $email, ':p' => password_hash('Password123!', PASSWORD_DEFAULT)]);
        $userId = $stmt->fetchColumn();
    } else {
        $userId = $existing->id;
    }
    $stmt = $pdo->prepare('SELECT id FROM creators WHERE applicant_user_id = :id');
    $stmt->execute([':id' => $userId]);
    $creator = $stmt->fetch(PDO::FETCH_OBJ);
    if (!$creator) {
        $source = __DIR__ . '/attached_assets/' . $imageFile;
        $targetDir = __DIR__ . '/uploads/creators';
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        $targetFile = 'creator_' . $userId . '_' . time() . '.png';
        copy($source, $targetDir . '/' . $targetFile);
        $stmt = $pdo->prepare('INSERT INTO creators (applicant_user_id, handle, username, display_name, profile_image, bio, minimum_topic_price, paypal_email, venmo_handle, video_topics, is_active, created_at) VALUES (:uid, :handle, :username, :display, :image, :bio, :price, :paypal, :venmo, :topics, 1, NOW())');
        $stmt->execute([
            ':uid' => $userId,
            ':handle' => $username,
            ':username' => $username,
            ':display' => $display,
            ':image' => $targetFile,
            ':bio' => $bio,
            ':price' => $price,
            ':paypal' => null,
            ':venmo' => null,
            ':topics' => json_encode($topics),
        ]);
    }
}
echo "seeded\n";
