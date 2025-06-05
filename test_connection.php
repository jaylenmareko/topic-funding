<?php
require_once 'config/database.php';

echo "<h2>Database Connection Test</h2>";

// Test connection directly
try {
    $db = new Database();
    echo "✅ Database connection successful!<br>";
    
    $helper = new DatabaseHelper();
    $creators = $helper->getAllCreators();
    echo "✅ Found " . count($creators) . " creators in database<br>";
    
    echo "<h3>Sample Creators:</h3>";
    foreach($creators as $creator) {
        echo "- " . $creator->display_name . " (" . $creator->platform_type . ")<br>";
    }
    
} catch (Exception $e) {
    echo "❌ Database connection failed: " . $e->getMessage() . "<br>";
    echo "Make sure your database name is correct in config/database.php";
}
?>
