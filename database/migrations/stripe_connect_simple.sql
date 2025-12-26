-- Simple Stripe Connect migration
-- Run each command separately - ignore errors for columns that already exist

-- Add columns one by one (run these separately, skip if error "Duplicate column")
ALTER TABLE creators ADD COLUMN stripe_onboarding_complete BOOLEAN DEFAULT FALSE;
ALTER TABLE creators ADD COLUMN stripe_details_submitted BOOLEAN DEFAULT FALSE;
ALTER TABLE creators ADD COLUMN stripe_charges_enabled BOOLEAN DEFAULT FALSE;
ALTER TABLE creators ADD COLUMN stripe_payouts_enabled BOOLEAN DEFAULT FALSE;
ALTER TABLE creators ADD COLUMN total_earnings DECIMAL(10,2) DEFAULT 0.00;
ALTER TABLE creators ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- Create payouts table
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
    FOREIGN KEY (topic_id) REFERENCES topics(id) ON DELETE CASCADE
);

-- Create refunds table
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
    FOREIGN KEY (topic_id) REFERENCES topics(id) ON DELETE CASCADE
);
