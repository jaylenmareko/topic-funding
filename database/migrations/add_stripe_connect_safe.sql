-- Safe database migration for Stripe Connect functionality
-- This adds only the columns that don't already exist

-- Add Stripe Connect fields to creators table (SAFE - only if they don't exist)
ALTER TABLE creators
ADD COLUMN IF NOT EXISTS stripe_onboarding_complete BOOLEAN DEFAULT FALSE,
ADD COLUMN IF NOT EXISTS stripe_details_submitted BOOLEAN DEFAULT FALSE,
ADD COLUMN IF NOT EXISTS stripe_charges_enabled BOOLEAN DEFAULT FALSE,
ADD COLUMN IF NOT EXISTS stripe_payouts_enabled BOOLEAN DEFAULT FALSE,
ADD COLUMN IF NOT EXISTS total_earnings DECIMAL(10,2) DEFAULT 0.00,
ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- Create payouts table to track all creator payouts
CREATE TABLE IF NOT EXISTS payouts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    creator_id INT NOT NULL,
    topic_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    platform_fee DECIMAL(10,2) NOT NULL,
    stripe_fee DECIMAL(10,2) NOT NULL,
    net_amount DECIMAL(10,2) NOT NULL,
    stripe_transfer_id VARCHAR(255) DEFAULT NULL,
    status ENUM('pending', 'processing', 'completed', 'failed', 'cancelled') DEFAULT 'pending',
    failure_reason TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    paid_at TIMESTAMP NULL,
    FOREIGN KEY (creator_id) REFERENCES creators(id) ON DELETE CASCADE,
    FOREIGN KEY (topic_id) REFERENCES topics(id) ON DELETE CASCADE,
    INDEX idx_creator_id (creator_id),
    INDEX idx_topic_id (topic_id),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
);

-- Create refunds table to track all refunds
CREATE TABLE IF NOT EXISTS refunds (
    id INT PRIMARY KEY AUTO_INCREMENT,
    contribution_id INT NOT NULL,
    topic_id INT NOT NULL,
    user_id INT DEFAULT NULL,
    amount DECIMAL(10,2) NOT NULL,
    refund_amount DECIMAL(10,2) NOT NULL,
    platform_fee_kept DECIMAL(10,2) NOT NULL,
    stripe_refund_id VARCHAR(255) DEFAULT NULL,
    status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
    reason ENUM('deadline_missed', 'creator_cancelled', 'admin_refund', 'disputed') DEFAULT 'deadline_missed',
    failure_reason TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL,
    FOREIGN KEY (topic_id) REFERENCES topics(id) ON DELETE CASCADE,
    INDEX idx_topic_id (topic_id),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
);

-- Add indexes on topics for faster queries (IF NOT EXISTS)
CREATE INDEX IF NOT EXISTS idx_status ON topics(status);
CREATE INDEX IF NOT EXISTS idx_creator_id ON topics(creator_id);
