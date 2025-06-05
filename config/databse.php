<?php
// config/database.php
// Database configuration and connection

// Database credentials
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', ''); // Empty for default XAMPP
define('DB_NAME', 'crowdfunding'); // Change this to your database name

class Database {
    private $host = DB_HOST;
    private $user = DB_USER;
    private $pass = DB_PASS;
    private $dbname = DB_NAME;
    private $dbh;
    private $error;

    public function __construct() {
        // Set DSN
        $dsn = 'mysql:host=' . $this->host . ';dbname=' . $this->dbname;
        
        // Set options
        $options = array(
            PDO::ATTR_PERSISTENT => true,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        );

        // Create a new PDO instance
        try {
            $this->dbh = new PDO($dsn, $this->user, $this->pass, $options);
        } catch(PDOException $e) {
            $this->error = $e->getMessage();
            die('Database connection failed: ' . $this->error);
        }
    }

    // Prepare statement with query
    public function query($query) {
        $this->stmt = $this->dbh->prepare($query);
    }

    // Bind values
    public function bind($param, $value, $type = null) {
        if (is_null($type)) {
            switch (true) {
                case is_int($value):
                    $type = PDO::PARAM_INT;
                    break;
                case is_bool($value):
                    $type = PDO::PARAM_BOOL;
                    break;
                case is_null($value):
                    $type = PDO::PARAM_NULL;
                    break;
                default:
                    $type = PDO::PARAM_STR;
            }
        }
        $this->stmt->bindValue($param, $value, $type);
    }

    // Execute the prepared statement
    public function execute() {
        return $this->stmt->execute();
    }

    // Get result set as array of objects
    public function resultSet() {
        $this->execute();
        return $this->stmt->fetchAll(PDO::FETCH_OBJ);
    }

    // Get single record as object
    public function single() {
        $this->execute();
        return $this->stmt->fetch(PDO::FETCH_OBJ);
    }

    // Get row count
    public function rowCount() {
        return $this->stmt->rowCount();
    }

    // Get last insert ID
    public function lastInsertId() {
        return $this->dbh->lastInsertId();
    }

    // Begin transaction
    public function beginTransaction() {
        return $this->dbh->beginTransaction();
    }

    // End transaction
    public function endTransaction() {
        return $this->dbh->commit();
    }

    // Cancel transaction
    public function cancelTransaction() {
        return $this->dbh->rollback();
    }
}

// Helper functions for common database operations
class DatabaseHelper {
    private $db;

    public function __construct() {
        $this->db = new Database;
    }

    // Get all creators
    public function getAllCreators() {
        $this->db->query('SELECT * FROM creators WHERE is_active = 1 ORDER BY display_name');
        return $this->db->resultSet();
    }

    // Get creator by ID
    public function getCreatorById($id) {
        $this->db->query('SELECT * FROM creators WHERE id = :id AND is_active = 1');
        $this->db->bind(':id', $id);
        return $this->db->single();
    }

    // Get active topics for a creator
    public function getCreatorTopics($creator_id, $status = 'active') {
        $this->db->query('SELECT * FROM topics WHERE creator_id = :creator_id AND status = :status ORDER BY created_at DESC');
        $this->db->bind(':creator_id', $creator_id);
        $this->db->bind(':status', $status);
        return $this->db->resultSet();
    }

    // Get topic by ID with creator info
    public function getTopicById($id) {
        $this->db->query('
            SELECT t.*, c.display_name as creator_name, c.profile_image as creator_image 
            FROM topics t 
            JOIN creators c ON t.creator_id = c.id 
            WHERE t.id = :id
        ');
        $this->db->bind(':id', $id);
        return $this->db->single();
    }

    // Get user contributions for a topic
    public function getTopicContributions($topic_id) {
        $this->db->query('
            SELECT c.*, u.username 
            FROM contributions c 
            JOIN users u ON c.user_id = u.id 
            WHERE c.topic_id = :topic_id AND c.payment_status = "completed"
            ORDER BY c.contributed_at DESC
        ');
        $this->db->bind(':topic_id', $topic_id);
        return $this->db->resultSet();
    }

    // Add new contribution
    public function addContribution($topic_id, $user_id, $amount) {
        try {
            $this->db->beginTransaction();
            
            // Insert contribution
            $this->db->query('
                INSERT INTO contributions (topic_id, user_id, amount, payment_status) 
                VALUES (:topic_id, :user_id, :amount, "completed")
            ');
            $this->db->bind(':topic_id', $topic_id);
            $this->db->bind(':user_id', $user_id);
            $this->db->bind(':amount', $amount);
            $this->db->execute();
            
            // Update topic funding
            $this->db->query('
                UPDATE topics 
                SET current_funding = current_funding + :amount 
                WHERE id = :topic_id
            ');
            $this->db->bind(':amount', $amount);
            $this->db->bind(':topic_id', $topic_id);
            $this->db->execute();
            
            // Check if funding threshold reached
            $topic = $this->getTopicById($topic_id);
            if ($topic->current_funding >= $topic->funding_threshold) {
                $this->db->query('
                    UPDATE topics 
                    SET status = "funded", funded_at = NOW(), content_deadline = DATE_ADD(NOW(), INTERVAL 48 HOUR)
                    WHERE id = :topic_id
                ');
                $this->db->bind(':topic_id', $topic_id);
                $this->db->execute();
            }
            
            $this->db->endTransaction();
            return true;
            
        } catch (Exception $e) {
            $this->db->cancelTransaction();
            return false;
        }
    }

    // Create new topic
    public function createTopic($creator_id, $user_id, $title, $description, $funding_threshold) {
        $this->db->query('
            INSERT INTO topics (creator_id, initiator_user_id, title, description, funding_threshold) 
            VALUES (:creator_id, :user_id, :title, :description, :funding_threshold)
        ');
        $this->db->bind(':creator_id', $creator_id);
        $this->db->bind(':user_id', $user_id);
        $this->db->bind(':title', $title);
        $this->db->bind(':description', $description);
        $this->db->bind(':funding_threshold', $funding_threshold);
        
        if ($this->db->execute()) {
            return $this->db->lastInsertId();
        }
        return false;
    }

    // Get user by email (for login)
    public function getUserByEmail($email) {
        $this->db->query('SELECT * FROM users WHERE email = :email AND is_active = 1');
        $this->db->bind(':email', $email);
        return $this->db->single();
    }

    // Create new user
    public function createUser($username, $email, $password_hash, $full_name) {
        $this->db->query('
            INSERT INTO users (username, email, password_hash, full_name) 
            VALUES (:username, :email, :password_hash, :full_name)
        ');
        $this->db->bind(':username', $username);
        $this->db->bind(':email', $email);
        $this->db->bind(':password_hash', $password_hash);
        $this->db->bind(':full_name', $full_name);
        
        if ($this->db->execute()) {
            return $this->db->lastInsertId();
        }
        return false;
    }

    // Check if email exists
    public function emailExists($email) {
        $this->db->query('SELECT id FROM users WHERE email = :email');
        $this->db->bind(':email', $email);
        $this->db->single();
        return $this->db->rowCount() > 0;
    }

    // Check if username exists
    public function usernameExists($username) {
        $this->db->query('SELECT id FROM users WHERE username = :username');
        $this->db->bind(':username', $username);
        $this->db->single();
        return $this->db->rowCount() > 0;
    }
}
?>
