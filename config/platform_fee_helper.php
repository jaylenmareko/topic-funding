<?php
// config/platform_fee_helper.php - Platform fee management system
require_once 'database.php';

class PlatformFeeManager {
    private $db;
    private $default_fee_percent = 10.00; // 10% platform fee
    
    public function __construct() {
        $this->db = new Database();
    }
    
    /**
     * Process platform fee when topic reaches funding goal
     */
    public function processTopicFunding($topic_id) {
        try {
            $this->db->beginTransaction();
            
            // Get topic details
            $this->db->query('SELECT * FROM topics WHERE id = :topic_id');
            $this->db->bind(':topic_id', $topic_id);
            $topic = $this->db->single();
            
            if (!$topic) {
                throw new Exception("Topic not found");
            }
            
            // Check if fees already processed
            if ($topic->fee_processed) {
                return ['success' => true, 'message' => 'Fees already processed'];
            }
            
            $total_funding = $topic->current_funding;
            $fee_percent = $topic->platform_fee_percent ?: $this->default_fee_percent;
            $fee_amount = ($total_funding * $fee_percent) / 100;
            $creator_amount = $total_funding - $fee_amount;
            
            // Update topic with fee calculations
            $this->db->query('
                UPDATE topics 
                SET platform_fee_amount = :fee_amount,
                    creator_payout_amount = :creator_amount,
                    fee_processed = 1
                WHERE id = :topic_id
            ');
            $this->db->bind(':fee_amount', $fee_amount);
            $this->db->bind(':creator_amount', $creator_amount);
            $this->db->bind(':topic_id', $topic_id);
            $this->db->execute();
            
            // Record platform fee
            $this->db->query('
                INSERT INTO platform_fees (topic_id, total_funding, fee_percent, fee_amount, creator_amount, status)
                VALUES (:topic_id, :total_funding, :fee_percent, :fee_amount, :creator_amount, "processed")
            ');
            $this->db->bind(':topic_id', $topic_id);
            $this->db->bind(':total_funding', $total_funding);
            $this->db->bind(':fee_percent', $fee_percent);
            $this->db->bind(':fee_amount', $fee_amount);
            $this->db->bind(':creator_amount', $creator_amount);
            $this->db->execute();
            
            // Create creator payout record
            $this->db->query('
                INSERT INTO creator_payouts (topic_id, creator_id, gross_amount, platform_fee, net_amount)
                VALUES (:topic_id, :creator_id, :gross_amount, :platform_fee, :net_amount)
            ');
            $this->db->bind(':topic_id', $topic_id);
            $this->db->bind(':creator_id', $topic->creator_id);
            $this->db->bind(':gross_amount', $total_funding);
            $this->db->bind(':platform_fee', $fee_amount);
            $this->db->bind(':net_amount', $creator_amount);
            $this->db->execute();
            
            $this->db->endTransaction();
            
            return [
                'success' => true,
                'total_funding' => $total_funding,
                'platform_fee' => $fee_amount,
                'creator_amount' => $creator_amount,
                'fee_percent' => $fee_percent
            ];
            
        } catch (Exception $e) {
            $this->db->cancelTransaction();
            error_log("Platform fee processing error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Get platform fee statistics
     */
    public function getPlatformStats() {
        $this->db->query('
            SELECT 
                COUNT(*) as total_funded_topics,
                SUM(total_funding) as total_gross_funding,
                SUM(fee_amount) as total_platform_fees,
                SUM(creator_amount) as total_creator_payouts,
                AVG(fee_percent) as average_fee_percent
            FROM platform_fees 
        ');
        return $this->db->single();
    }
    
    /**
     * Get creator payout history
     */
    public function getCreatorPayouts($creator_id = null, $status = null) {
        $where_conditions = [];
        $params = [];
        
        if ($creator_id) {
            $where_conditions[] = 'cp.creator_id = :creator_id';
            $params[':creator_id'] = $creator_id;
        }
        
        if ($status) {
            $where_conditions[] = 'cp.status = :status';
            $params[':status'] = $status;
        }
        
        $where_clause = empty($where_conditions) ? '' : 'WHERE ' . implode(' AND ', $where_conditions);
        
        $this->db->query("
            SELECT cp.*, t.title as topic_title, c.display_name as creator_name
            FROM creator_payouts cp
            JOIN topics t ON cp.topic_id = t.id
            JOIN creators c ON cp.creator_id = c.id
            {$where_clause}
            ORDER BY cp.created_at DESC
        ");
        
        foreach ($params as $param => $value) {
            $this->db->bind($param, $value);
        }
        
        return $this->db->resultSet();
    }
    
    /**
     * Get pending platform fees
     */
    public function getPendingFees() {
        $this->db->query('
            SELECT pf.*, t.title as topic_title, c.display_name as creator_name
            FROM platform_fees pf
            JOIN topics t ON pf.topic_id = t.id
            JOIN creators c ON t.creator_id = c.id
            WHERE pf.status = "pending"
            ORDER BY pf.processed_at DESC
        ');
        return $this->db->resultSet();
    }
    
    /**
     * Mark creator payout as completed
     */
    public function markPayoutCompleted($payout_id, $payout_reference = null) {
        try {
            $this->db->query('
                UPDATE creator_payouts 
                SET status = "completed", 
                    processed_at = NOW(),
                    payout_reference = :reference
                WHERE id = :payout_id
            ');
            $this->db->bind(':reference', $payout_reference);
            $this->db->bind(':payout_id', $payout_id);
            $this->db->execute();
            
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Calculate platform fee for an amount
     */
    public function calculateFee($amount, $fee_percent = null) {
        $fee_percent = $fee_percent ?: $this->default_fee_percent;
        $fee_amount = ($amount * $fee_percent) / 100;
        $net_amount = $amount - $fee_amount;
        
        return [
            'gross_amount' => $amount,
            'fee_percent' => $fee_percent,
            'fee_amount' => round($fee_amount, 2),
            'net_amount' => round($net_amount, 2)
        ];
    }
    
    /**
     * Get monthly revenue report
     */
    public function getMonthlyRevenue($year = null, $month = null) {
        $year = $year ?: date('Y');
        $month = $month ?: date('m');
        
        $this->db->query('
            SELECT 
                COUNT(*) as topics_funded,
                SUM(total_funding) as gross_revenue,
                SUM(fee_amount) as platform_revenue,
                SUM(creator_amount) as creator_payouts,
                AVG(fee_amount) as avg_fee_per_topic
            FROM platform_fees 
            WHERE YEAR(processed_at) = :year 
            AND MONTH(processed_at) = :month
        ');
        $this->db->bind(':year', $year);
        $this->db->bind(':month', $month);
        
        return $this->db->single();
    }
    
    /**
     * Get top earning creators
     */
    public function getTopCreators($limit = 10) {
        $this->db->query('
            SELECT 
                c.display_name,
                c.id as creator_id,
                COUNT(cp.id) as topics_completed,
                SUM(cp.gross_amount) as total_gross,
                SUM(cp.platform_fee) as total_fees_paid,
                SUM(cp.net_amount) as total_earned
            FROM creator_payouts cp
            JOIN creators c ON cp.creator_id = c.id
            WHERE cp.status IN ("completed", "processing")
            GROUP BY cp.creator_id
            ORDER BY total_earned DESC
            LIMIT :limit
        ');
        $this->db->bind(':limit', $limit);
        return $this->db->resultSet();
    }
}
?>
