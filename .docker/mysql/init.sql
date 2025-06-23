-- Create database if not exists
CREATE DATABASE IF NOT EXISTS crypto_investment CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE crypto_investment;

-- Create tables with proper encoding
SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;
SET character_set_connection=utf8mb4;

-- Optimize MySQL settings for better performance
SET GLOBAL innodb_buffer_pool_size = 1073741824; -- 1GB
SET GLOBAL innodb_log_file_size = 268435456; -- 256MB
SET GLOBAL innodb_flush_log_at_trx_commit = 2;
SET GLOBAL innodb_file_per_table = 1;
SET GLOBAL max_connections = 200;
SET GLOBAL query_cache_size = 67108864; -- 64MB
SET GLOBAL query_cache_type = 1;
SET GLOBAL slow_query_log = 1;
SET GLOBAL long_query_time = 2;

-- Create user for application if not exists
CREATE USER IF NOT EXISTS 'crypto_app'@'%' IDENTIFIED BY 'secure_password_here';
GRANT ALL PRIVILEGES ON crypto_investment.* TO 'crypto_app'@'%';
FLUSH PRIVILEGES;

-- Create stored procedures for common operations
DELIMITER $$

-- Procedure to calculate user statistics
CREATE PROCEDURE IF NOT EXISTS GetUserStatistics(IN userId INT)
BEGIN
SELECT
    u.id,
    u.username,
    COALESCE(SUM(CASE WHEN d.status = 'completed' THEN d.amount ELSE 0 END), 0) as total_deposits,
    COALESCE(SUM(CASE WHEN w.status = 'completed' THEN w.amount ELSE 0 END), 0) as total_withdrawals,
    COUNT(DISTINCT r.id) as referral_count,
    u.deposit_balance,
    u.bonus_balance
FROM users u
         LEFT JOIN deposits d ON u.id = d.user_id
         LEFT JOIN withdrawals w ON u.id = w.user_id
         LEFT JOIN users r ON u.id = r.referrer_id
WHERE u.id = userId
GROUP BY u.id;
END$$

-- Procedure to get daily statistics
CREATE PROCEDURE IF NOT EXISTS GetDailyStatistics(IN dateFrom DATE, IN dateTo DATE)
BEGIN
SELECT
    DATE(created_at) as date,
    COUNT(CASE WHEN type = 'deposit' THEN 1 END) as deposits_count,
    SUM(CASE WHEN type = 'deposit' AND amount > 0 THEN amount ELSE 0 END) as deposits_amount,
    COUNT(CASE WHEN type = 'withdrawal' THEN 1 END) as withdrawals_count,
    SUM(CASE WHEN type = 'withdrawal' AND amount < 0 THEN ABS(amount) ELSE 0 END) as withdrawals_amount
FROM transactions
WHERE DATE(created_at) BETWEEN dateFrom AND dateTo
GROUP BY DATE(created_at)
ORDER BY date DESC;
END$$

-- Function to calculate user level based on deposits
CREATE FUNCTION IF NOT EXISTS GetUserLevel(totalDeposits DECIMAL(20,8))
RETURNS VARCHAR(20)
DETERMINISTIC
BEGIN
RETURN CASE
           WHEN totalDeposits >= 100000 THEN 'WHALE'
           WHEN totalDeposits >= 50000 THEN 'SHARK'
           WHEN totalDeposits >= 10000 THEN 'DOLPHIN'
           WHEN totalDeposits >= 1000 THEN 'FISH'
           ELSE 'MINNOW'
    END;
END$$

DELIMITER ;

-- Create views for reporting
CREATE OR REPLACE VIEW user_statistics AS
SELECT
    u.id,
    u.username,
    u.created_at,
    COALESCE(d.total_deposits, 0) as total_deposits,
    COALESCE(d.deposit_count, 0) as deposit_count,
    COALESCE(w.total_withdrawals, 0) as total_withdrawals,
    COALESCE(w.withdrawal_count, 0) as withdrawal_count,
    COALESCE(r.referral_count, 0) as referral_count,
    u.deposit_balance + u.bonus_balance as total_balance
FROM users u
         LEFT JOIN (
    SELECT user_id,
           SUM(amount) as total_deposits,
           COUNT(*) as deposit_count
    FROM deposits
    WHERE status = 'completed'
    GROUP BY user_id
) d ON u.id = d.user_id
         LEFT JOIN (
    SELECT user_id,
           SUM(amount) as total_withdrawals,
           COUNT(*) as withdrawal_count
    FROM withdrawals
    WHERE status = 'completed'
    GROUP BY user_id
) w ON u.id = w.user_id
         LEFT JOIN (
    SELECT referrer_id,
           COUNT(*) as referral_count
    FROM users
    WHERE referrer_id IS NOT NULL
    GROUP BY referrer_id
) r ON u.id = r.referrer_id;

-- Create triggers for audit
DELIMITER $$

CREATE TRIGGER IF NOT EXISTS before_deposit_update
BEFORE UPDATE ON deposits
                             FOR EACH ROW
BEGIN
    IF OLD.status = 'completed' AND NEW.status != OLD.status THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Cannot change status of completed deposit';
END IF;
END$$

CREATE TRIGGER IF NOT EXISTS before_withdrawal_update
BEFORE UPDATE ON withdrawals
                             FOR EACH ROW
BEGIN
    IF OLD.status IN ('completed', 'cancelled') AND NEW.status != OLD.status THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Cannot change status of completed or cancelled withdrawal';
END IF;
END$$

DELIMITER ;

-- Create indexes for performance
CREATE INDEX IF NOT EXISTS idx_users_telegram_user_id ON users(telegram_user_id);
CREATE INDEX IF NOT EXISTS idx_users_referral_code ON users(referral_code);
CREATE INDEX IF NOT EXISTS idx_users_email ON users(email);
CREATE INDEX IF NOT EXISTS idx_users_referrer_id ON users(referrer_id);
CREATE INDEX IF NOT EXISTS idx_users_created_at ON users(created_at);

CREATE INDEX IF NOT EXISTS idx_deposits_user_id ON deposits(user_id);
CREATE INDEX IF NOT EXISTS idx_deposits_tx_hash ON deposits(tx_hash);
CREATE INDEX IF NOT EXISTS idx_deposits_status ON deposits(status);
CREATE INDEX IF NOT EXISTS idx_deposits_created_at ON deposits(created_at);

CREATE INDEX IF NOT EXISTS idx_withdrawals_user_id ON withdrawals(user_id);
CREATE INDEX IF NOT EXISTS idx_withdrawals_status ON withdrawals(status);
CREATE INDEX IF NOT EXISTS idx_withdrawals_type ON withdrawals(type);
CREATE INDEX IF NOT EXISTS idx_withdrawals_created_at ON withdrawals(created_at);

CREATE INDEX IF NOT EXISTS idx_transactions_user_id ON transactions(user_id);
CREATE INDEX IF NOT EXISTS idx_transactions_type ON transactions(type);
CREATE INDEX IF NOT EXISTS idx_transactions_status ON transactions(status);
CREATE INDEX IF NOT EXISTS idx_transactions_created_at ON transactions(created_at);

CREATE INDEX IF NOT EXISTS idx_bonuses_user_id ON bonuses(user_id);
CREATE INDEX IF NOT EXISTS idx_bonuses_type ON bonuses(type);
CREATE INDEX IF NOT EXISTS idx_bonuses_created_at ON bonuses(created_at);

CREATE INDEX IF NOT EXISTS idx_event_log_user_id ON event_log(user_id);
CREATE INDEX IF NOT EXISTS idx_event_log_event_type ON event_log(event_type);
CREATE INDEX IF NOT EXISTS idx_event_log_created_at ON event_log(created_at);

-- Create full-text indexes for search
CREATE FULLTEXT INDEX IF NOT EXISTS idx_users_search ON users(username, email, first_name, last_name);
CREATE FULLTEXT INDEX IF NOT EXISTS idx_event_log_search ON event_log(event_type, event_data);