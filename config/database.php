<?php
// Production configuration - disable error display, enable logging
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');

// Create logs directory if it doesn't exist
if (!is_dir(__DIR__ . '/../logs')) {
    mkdir(__DIR__ . '/../logs', 0755, true);
}
// config/database.php
// Database configuration and connection with enhanced funding logic

// Database credentials - PRODUCTION
define('DB_HOST', 'localhost');
define('DB_USER', 'uunppite_topiclaunch_user');
define('DB_PASS', '@J71c6ah8@');
define('DB_NAME', 'uunppite_topiclaunch');

class Database {
    private $host = DB_HOST;
    private $user = DB_USER;
    private $pass = DB_PASS;
    private $dbname = DB_NAME;
    private $dbh;
    private $error;
    private $stmt;

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

// Helper functions for common database operations with enhanced funding logic
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

    // Original add contribution method (kept for backward compatibility)
    public function addContribution($topic_id, $user_id, $amount) {
        $result = $this->addContributionWithTracking($topic_id, $user_id, $amount);
        return $result['success'];
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

    // ============================================================================
    // ENHANCED FUNDING LOGIC METHODS
    // ============================================================================

    // Get funding analytics for a topic
    public function getTopicFundingAnalytics($topic_id) {
        $this->db->query('
            SELECT 
                COUNT(*) as total_contributors,
                AVG(amount) as average_contribution,
                MIN(amount) as smallest_contribution,
                MAX(amount) as largest_contribution,
                SUM(CASE WHEN amount >= 50 THEN 1 ELSE 0 END) as major_contributors,
                DATE(MIN(contributed_at)) as first_contribution_date,
                DATE(MAX(contributed_at)) as latest_contribution_date
            FROM contributions 
            WHERE topic_id = :topic_id AND payment_status = "completed"
        ');
        $this->db->bind(':topic_id', $topic_id);
        return $this->db->single();
    }

    // Get funding momentum (contributions per day)
    public function getFundingMomentum($topic_id, $days = 7) {
        $this->db->query('
            SELECT 
                DATE(contributed_at) as contribution_date,
                COUNT(*) as contributions_count,
                SUM(amount) as daily_total
            FROM contributions 
            WHERE topic_id = :topic_id 
            AND payment_status = "completed"
            AND contributed_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
            GROUP BY DATE(contributed_at)
            ORDER BY contribution_date DESC
        ');
        $this->db->bind(':topic_id', $topic_id);
        $this->db->bind(':days', $days);
        return $this->db->resultSet();
    }

    // Get user's funding impact
    public function getUserFundingImpact($user_id) {
        $this->db->query('
            SELECT 
                COUNT(DISTINCT t.id) as topics_helped_fund,
                COUNT(DISTINCT CASE WHEN t.status = "funded" THEN t.id END) as topics_successfully_funded,
                COUNT(DISTINCT CASE WHEN t.status = "completed" THEN t.id END) as topics_completed,
                SUM(c.amount) as total_contributed,
                COUNT(DISTINCT t.creator_id) as creators_supported,
                AVG(c.amount) as average_contribution
            FROM contributions c
            JOIN topics t ON c.topic_id = t.id
            WHERE c.user_id = :user_id AND c.payment_status = "completed"
        ');
        $this->db->bind(':user_id', $user_id);
        return $this->db->single();
    }

    // Check if topic needs notification (milestones reached)
    public function checkFundingMilestones($topic_id) {
        $topic = $this->getTopicById($topic_id);
        if (!$topic) return [];
        
        $milestones = [];
        $progress_percent = ($topic->current_funding / $topic->funding_threshold) * 100;
        
        // Check various milestone percentages
        $milestone_checks = [25, 50, 75, 90, 95];
        
        foreach ($milestone_checks as $milestone) {
            if ($progress_percent >= $milestone) {
                // Check if we've already notified for this milestone
                $this->db->query('
                    SELECT id FROM funding_milestones 
                    WHERE topic_id = :topic_id AND milestone_percent = :milestone
                ');
                $this->db->bind(':topic_id', $topic_id);
                $this->db->bind(':milestone', $milestone);
                $this->db->execute();
                
                if ($this->db->rowCount() == 0) {
                    $milestones[] = $milestone;
                    
                    // Record that we've hit this milestone
                    $this->db->query('
                        INSERT INTO funding_milestones (topic_id, milestone_percent, reached_at)
                        VALUES (:topic_id, :milestone, NOW())
                    ');
                    $this->db->bind(':topic_id', $topic_id);
                    $this->db->bind(':milestone', $milestone);
                    $this->db->execute();
                }
            }
        }
        
        return $milestones;
    }

    // Enhanced contribution adding with milestone tracking
    public function addContributionWithTracking($topic_id, $user_id, $amount) {
        try {
            $this->db->beginTransaction();
            
            // Get topic info before contribution
            $topic_before = $this->getTopicById($topic_id);
            $old_progress = ($topic_before->current_funding / $topic_before->funding_threshold) * 100;
            
            // Insert contribution
            $this->db->query('
                INSERT INTO contributions (topic_id, user_id, amount, payment_status) 
                VALUES (:topic_id, :user_id, :amount, "completed")
            ');
            $this->db->bind(':topic_id', $topic_id);
            $this->db->bind(':user_id', $user_id);
            $this->db->bind(':amount', $amount);
            $this->db->execute();
            $contribution_id = $this->db->lastInsertId();
            
            // Update topic funding
            $this->db->query('
                UPDATE topics 
                SET current_funding = current_funding + :amount 
                WHERE id = :topic_id
            ');
            $this->db->bind(':amount', $amount);
            $this->db->bind(':topic_id', $topic_id);
            $this->db->execute();
            
            // Get updated topic info
            $topic_after = $this->getTopicById($topic_id);
            $new_progress = ($topic_after->current_funding / $topic_after->funding_threshold) * 100;
            
            // Check if funding threshold reached
            if ($topic_after->current_funding >= $topic_after->funding_threshold) {
                $this->db->query('
                    UPDATE topics 
                    SET status = "funded", funded_at = NOW(), content_deadline = DATE_ADD(NOW(), INTERVAL 48 HOUR)
                    WHERE id = :topic_id
                ');
                $this->db->bind(':topic_id', $topic_id);
                $this->db->execute();
            }
            
            // Check for milestone achievements
            $milestones = $this->checkFundingMilestones($topic_id);
            
            // Log contribution impact (only if tables exist)
            try {
                $this->db->query('
                    INSERT INTO contribution_impact (contribution_id, old_progress, new_progress, milestones_triggered)
                    VALUES (:contribution_id, :old_progress, :new_progress, :milestones)
                ');
                $this->db->bind(':contribution_id', $contribution_id);
                $this->db->bind(':old_progress', $old_progress);
                $this->db->bind(':new_progress', $new_progress);
                $this->db->bind(':milestones', json_encode($milestones));
                $this->db->execute();
            } catch (Exception $e) {
                // Table doesn't exist yet, skip logging
            }
            
            $this->db->endTransaction();
            
            return [
                'success' => true,
                'contribution_id' => $contribution_id,
                'old_progress' => $old_progress,
                'new_progress' => $new_progress,
                'milestones' => $milestones,
                'fully_funded' => $topic_after->current_funding >= $topic_after->funding_threshold
            ];
            
        } catch (Exception $e) {
            $this->db->cancelTransaction();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // Get trending topics (high funding velocity)
    public function getTrendingTopics($limit = 5) {
        $this->db->query('
            SELECT t.*, c.display_name as creator_name, c.profile_image as creator_image,
                   COUNT(cont.id) as recent_contributions,
                   SUM(cont.amount) as recent_funding
            FROM topics t
            JOIN creators c ON t.creator_id = c.id
            LEFT JOIN contributions cont ON t.id = cont.topic_id 
                AND cont.contributed_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                AND cont.payment_status = "completed"
            WHERE t.status = "active"
            GROUP BY t.id
            HAVING recent_contributions > 0
            ORDER BY recent_contributions DESC, recent_funding DESC
            LIMIT :limit
        ');
        $this->db->bind(':limit', $limit);
        return $this->db->resultSet();
    }

    // Get topics close to funding (90%+)
    public function getAlmostFundedTopics($limit = 5) {
        $this->db->query('
            SELECT t.*, c.display_name as creator_name, c.profile_image as creator_image,
                   (t.current_funding / t.funding_threshold * 100) as progress_percent
            FROM topics t
            JOIN creators c ON t.creator_id = c.id
            WHERE t.status = "active" 
            AND (t.current_funding / t.funding_threshold) >= 0.90
            ORDER BY progress_percent DESC
            LIMIT :limit
        ');
        $this->db->bind(':limit', $limit);
        return $this->db->resultSet();
    }

    // Get recommended topics for user based on their contribution history
    public function getRecommendedTopics($user_id, $limit = 5) {
        $this->db->query('
            SELECT DISTINCT t.*, c.display_name as creator_name, c.profile_image as creator_image
            FROM topics t
            JOIN creators c ON t.creator_id = c.id
            WHERE t.status = "active"
            AND t.creator_id IN (
                SELECT DISTINCT t2.creator_id 
                FROM contributions cont
                JOIN topics t2 ON cont.topic_id = t2.id
                WHERE cont.user_id = :user_id
            )
            AND t.id NOT IN (
                SELECT topic_id FROM contributions WHERE user_id = :user_id
            )
            ORDER BY t.created_at DESC
            LIMIT :limit
        ');
        $this->db->bind(':user_id', $user_id);
        $this->db->bind(':limit', $limit);
        return $this->db->resultSet();
    }
}

// Enhanced funding widget function
function renderFundingWidget($topic, $contributions = [], $analytics = null) {
    $progress_percent = ($topic->current_funding / $topic->funding_threshold) * 100;
    $progress_percent = min($progress_percent, 100);
    $remaining = max(0, $topic->funding_threshold - $topic->current_funding);
    
    // Calculate funding velocity (contributions in last 24 hours)
    $recent_contributions = array_filter($contributions, function($c) {
        return strtotime($c->contributed_at) >= strtotime('-24 hours');
    });
    $recent_funding = array_sum(array_map(function($c) { return $c->amount; }, $recent_contributions));
    
    // Estimate time to funding based on recent velocity
    $days_to_funding = null;
    if ($recent_funding > 0 && $remaining > 0) {
        $daily_rate = $recent_funding; // Last 24 hours
        $days_to_funding = ceil($remaining / $daily_rate);
    }
    
    $milestones = [25, 50, 75, 90];
    
    echo '<div class="enhanced-funding-widget" style="background: white; padding: 25px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">';
    echo '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">';
    echo '<h3 style="margin: 0; color: #333;">Funding Progress</h3>';
    
    if ($topic->status === 'active' && count($recent_contributions) > 0) {
        echo '<div style="background: #e8f5e8; color: #2d5f2d; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: bold;">';
        echo '🔥 ' . count($recent_contributions) . ' recent contributions';
        echo '</div>';
    }
    
    echo '</div>';

    // Main Progress Bar
    echo '<div style="position: relative; background: #e9ecef; height: 20px; border-radius: 10px; margin: 20px 0; overflow: hidden;">';
    echo '<div style="background: linear-gradient(90deg, #28a745, #20c997); height: 100%; border-radius: 10px; transition: width 0.5s ease; width: ' . $progress_percent . '%;"></div>';
    
    // Milestone markers
    foreach ($milestones as $milestone) {
        echo '<div style="position: absolute; top: 0; left: ' . $milestone . '%; width: 2px; height: 100%; background: #fff; opacity: 0.7;"></div>';
        echo '<div style="position: absolute; top: -25px; left: ' . $milestone . '%; transform: translateX(-50%); font-size: 10px; color: #666;">';
        echo $milestone . '%';
        echo '</div>';
    }
    
    echo '</div>';

    // Funding Stats Grid
    echo '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 15px; margin: 20px 0;">';
    
    echo '<div style="text-align: center; padding: 15px; background: #f8f9fa; border-radius: 8px;">';
    echo '<div style="font-size: 18px; font-weight: bold; color: #28a745;">$' . number_format($topic->current_funding, 0) . '</div>';
    echo '<div style="font-size: 12px; color: #666;">Raised</div>';
    echo '</div>';
    
    echo '<div style="text-align: center; padding: 15px; background: #f8f9fa; border-radius: 8px;">';
    echo '<div style="font-size: 18px; font-weight: bold; color: #dc3545;">$' . number_format($remaining, 0) . '</div>';
    echo '<div style="font-size: 12px; color: #666;">Remaining</div>';
    echo '</div>';
    
    echo '<div style="text-align: center; padding: 15px; background: #f8f9fa; border-radius: 8px;">';
    echo '<div style="font-size: 18px; font-weight: bold; color: #007bff;">' . count($contributions) . '</div>';
    echo '<div style="font-size: 12px; color: #666;">Backers</div>';
    echo '</div>';
    
    if ($analytics && $analytics->average_contribution) {
        echo '<div style="text-align: center; padding: 15px; background: #f8f9fa; border-radius: 8px;">';
        echo '<div style="font-size: 18px; font-weight: bold; color: #6f42c1;">$' . number_format($analytics->average_contribution, 0) . '</div>';
        echo '<div style="font-size: 12px; color: #666;">Average</div>';
        echo '</div>';
    }
    
    echo '</div>';

    // Velocity and Time Estimate
    if ($topic->status === 'active') {
        echo '<div style="border-top: 1px solid #eee; padding-top: 15px; margin-top: 15px;">';
        echo '<div style="display: flex; justify-content: space-between; align-items: center; font-size: 14px; color: #666;">';
        echo '<span>Last 24h: <strong style="color: #28a745;">$' . number_format($recent_funding, 0) . '</strong></span>';
        
        if ($days_to_funding && $days_to_funding <= 30) {
            echo '<span>Est. funding: <strong>' . $days_to_funding . ' days</strong></span>';
        }
        
        echo '</div>';
        echo '</div>';
    }

    // Milestone Badges
    echo '<div style="margin-top: 15px;">';
    echo '<div style="font-size: 12px; color: #666; margin-bottom: 8px;">Milestones Reached:</div>';
    echo '<div style="display: flex; gap: 8px; flex-wrap: wrap;">';
    
    foreach ($milestones as $milestone) {
        if ($progress_percent >= $milestone) {
            echo '<span style="background: #28a745; color: white; padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: bold;">';
            echo $milestone . '% ✓';
            echo '</span>';
        } else {
            echo '<span style="background: #e9ecef; color: #666; padding: 4px 8px; border-radius: 12px; font-size: 11px;">';
            echo $milestone . '%';
            echo '</span>';
        }
    }
    
    echo '</div>';
    echo '</div>';

    // Action Button
    echo '<div style="margin-top: 20px;">';
    
    if ($topic->status === 'active') {
        if (isset($_SESSION['user_id'])) {
            $fund_url = (strpos($_SERVER['REQUEST_URI'], '/topics/') !== false) ? '' : '../topics/';
            echo '<a href="' . $fund_url . 'fund.php?id=' . $topic->id . '" ';
            echo 'style="background: #28a745; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; display: block; text-align: center; font-weight: bold;">';
            echo 'Fund This Topic';
            echo '</a>';
        } else {
            $login_url = (strpos($_SERVER['REQUEST_URI'], '/auth/') !== false) ? '' : '../auth/';
            echo '<a href="' . $login_url . 'login.php" ';
            echo 'style="background: #007bff; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; display: block; text-align: center; font-weight: bold;">';
            echo 'Login to Fund';
            echo '</a>';
        }
    } elseif ($topic->status === 'funded') {
        echo '<div style="background: #d4edda; color: #155724; padding: 12px; border-radius: 6px; text-align: center; font-weight: bold;">';
        echo '✅ Fully Funded! Content coming soon...';
        echo '</div>';
    } elseif ($topic->status === 'completed') {
        echo '<div style="background: #cce5ff; color: #004085; padding: 12px; border-radius: 6px; text-align: center; font-weight: bold;">';
        echo '✅ Completed!';
        echo '</div>';
    }
    
    echo '</div>';
    echo '</div>';
}
?>
