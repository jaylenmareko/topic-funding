<?php
require_once __DIR__ . '/config/database.php';
$pdo = new PDO('pgsql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ]);
$creators = [
    ['username' => 'creator_dummy_1', 'email' => 'creatordummy1@example.com', 'display_name' => 'Ava Stone', 'bio' => 'Dummy creator account for testing.', 'minimum_topic_price' => 120, 'video_topics' => ['fitness', 'health', 'motivation']],
    ['username' => 'creator_dummy_2', 'email' => 'creatordummy2@example.com', 'display_name' => 'Noah Reed', 'bio' => 'Dummy creator account for testing.', 'minimum_topic_price' => 150, 'video_topics' => ['business', 'money', 'career']],
    ['username' => 'creator_dummy_3', 'email' => 'creatordummy3@example.com', 'display_name' => 'Maya Chen', 'bio' => 'Dummy creator account for testing.', 'minimum_topic_price' => 200, 'video_topics' => ['technology & ai', 'psychology', 'cosmetics']],
];
foreach ($creators as $creator) {
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :username OR email = :email');
    $stmt->execute([':username' => $creator['username'], ':email' => $creator['email']]);
    $existing = $stmt->fetch();
    if (!$existing) {
        $stmt = $pdo->prepare('INSERT INTO users (username, email, password_hash, full_name, user_type, is_active, created_at) VALUES (:username, :email, :password_hash, :full_name, :user_type, 1, NOW()) RETURNING id');
        $stmt->execute([':username' => $creator['username'], ':email' => $creator['email'], ':password_hash' => password_hash('Password123!', PASSWORD_DEFAULT), ':full_name' => $creator['display_name'], ':user_type' => 'creator']);
        $user_id = $stmt->fetchColumn();
    } else {
        $user_id = $existing->id;
    }
    $stmt = $pdo->prepare('SELECT id FROM creators WHERE applicant_user_id = :user_id');
    $stmt->execute([':user_id' => $user_id]);
    $creator_row = $stmt->fetch();
    if (!$creator_row) {
        $stmt = $pdo->prepare('INSERT INTO creators (applicant_user_id, handle, username, display_name, profile_image, bio, minimum_topic_price, paypal_email, venmo_handle, video_topics, is_active, created_at) VALUES (:user_id, :handle, :username, :display_name, :profile_image, :bio, :minimum_topic_price, :paypal_email, :venmo_handle, :video_topics, 1, NOW())');
        $stmt->execute([':user_id' => $user_id, ':handle' => $creator['username'], ':username' => $creator['username'], ':display_name' => $creator['display_name'], ':profile_image' => null, ':bio' => $creator['bio'], ':minimum_topic_price' => $creator['minimum_topic_price'], ':paypal_email' => null, ':venmo_handle' => null, ':video_topics' => json_encode($creator['video_topics'])]);
    }
}
echo "done\n";
