<?php
require_once __DIR__ . '/../config/database.php';

$secret = $_GET['secret'] ?? '';
if ($secret !== 'xK9mP2qL7nR4vT8') {
    http_response_code(403);
    die('Forbidden');
}

try {
    $db = new Database();
    $db->query("ALTER TABLE creators ADD COLUMN IF NOT EXISTS profile_image_data TEXT");
    $db->execute();
    echo "OK: column added (or already existed)";
} catch (Exception $e) {
    http_response_code(500);
    echo "Error: " . $e->getMessage();
}
