<?php
// api/get-topic.php - Fetch topic details for modal popup
header('Content-Type: application/json');
require_once '../config/database.php';

$topic_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$topic_id) {
    echo json_encode(['error' => 'Invalid topic ID']);
    exit;
}

try {
    $db = new Database();
    
    $db->query('
        SELECT 
            t.*,
            c.display_name as creator_name
        FROM topics t
        JOIN creators c ON t.creator_id = c.id
        WHERE t.id = :topic_id
    ');
    
    $db->bind(':topic_id', $topic_id);
    $topic = $db->single();
    
    if (!$topic) {
        echo json_encode(['error' => 'Topic not found']);
        exit;
    }
    
    echo json_encode($topic);
    
} catch (Exception $e) {
    echo json_encode(['error' => 'Failed to fetch topic']);
}
