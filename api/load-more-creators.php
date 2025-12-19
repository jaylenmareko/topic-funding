<?php
// api/load-more-creators.php - Load more creators for infinite scroll
header('Content-Type: application/json');
require_once '../config/database.php';

$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
$limit = 12;

$db = new Database();
$db->query('SELECT * FROM creators WHERE is_active = 1 ORDER BY display_name ASC LIMIT :limit OFFSET :offset');
$db->bind(':limit', $limit);
$db->bind(':offset', $offset);

$creators = $db->resultSet();

echo json_encode([
    'creators' => $creators,
    'count' => count($creators)
]);
