<?php
// api/load-more-creators.php - Load additional creators for infinite scroll
header('Content-Type: application/json');
require_once '../config/database.php';

$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
$limit = 12;

try {
    $db = new Database();
    
    // Fetch creators with all necessary fields including bio and minimum_topic_price
    $db->query('
        SELECT 
            id,
            display_name,
            profile_image,
            bio,
            minimum_topic_price,
            platform_url
        FROM creators 
        WHERE is_active = 1 
        ORDER BY display_name ASC 
        LIMIT :limit OFFSET :offset
    ');
    
    $db->bind(':limit', $limit);
    $db->bind(':offset', $offset);
    
    $creators = $db->resultSet();
    
    // Return JSON response
    echo json_encode([
        'success' => true,
        'creators' => $creators,
        'offset' => $offset,
        'count' => count($creators)
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to load creators'
    ]);
}
