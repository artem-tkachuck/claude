<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240101000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create initial schema for crypto investment platform';
    }

    public function up(Schema $schema): void
    {
        // Users table
        $this->addSql('CREATE TABLE users (
            id INT AUTO_INCREMENT NOT NULL,
            telegram_user_id BIGINT DEFAULT NULL,
            telegram_chat_id BIGINT DEFAULT NULL,
            username VARCHAR(180) NOT NULL,
            email VARCHAR(180) DEFAULT NULL,
            password VARCHAR(255) DEFAULT NULL,
            first_name VARCHAR(100) DEFAULT NULL,
            last_name VARCHAR(100) DEFAULT NULL,
            roles JSON NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            is_flagged TINYINT(1) NOT NULL DEFAULT 0,
            flag_reason VARCHAR(255) DEFAULT NULL,
            deposit_balance DECIMAL(20,8) NOT NULL DEFAULT 0,
            bonus_balance DECIMAL(20,8) NOT NULL DEFAULT 0,
            referral_code VARCHAR(20) NOT NULL,
            referrer_id INT DEFAULT NULL,
            deposit_address VARCHAR(255) DEFAULT NULL,
            deposit_address_private_key TEXT DEFAULT NULL,
            default_withdrawal_address VARCHAR(255) DEFAULT NULL,
            two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0,
            two_factor_secret VARCHAR(255) DEFAULT NULL,
            two_factor_backup_codes JSON DEFAULT NULL,
            notifications_enabled TINYINT(1) NOT NULL DEFAULT 1,
            email_notifications_enabled TINYINT(1) NOT NULL DEFAULT 1,
            marketing_emails_enabled TINYINT(1) NOT NULL DEFAULT 1,
            auto_withdrawal_enabled TINYINT(1) NOT NULL DEFAULT 0,
            auto_withdraw_min_amount DECIMAL(20,8) DEFAULT NULL,
            preferred_locale VARCHAR(5) NOT NULL DEFAULT "en",
            timezone VARCHAR(50) NOT NULL DEFAULT "UTC",
            last_login_at DATETIME DEFAULT NULL,
            last_login_ip VARCHAR(45) DEFAULT NULL,
            password_changed_at DATETIME DEFAULT NULL,
            email_verified TINYINT(1) NOT NULL DEFAULT 0,
            email_verified_at DATETIME DEFAULT NULL,
            registration_ip VARCHAR(45) DEFAULT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            risk_score INT NOT NULL DEFAULT 0,
            metadata JSON DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME DEFAULT NULL,
            UNIQUE INDEX UNIQ_USERS_USERNAME (username),
            UNIQUE INDEX UNIQ_USERS_EMAIL (email),
            UNIQUE INDEX UNIQ_USERS_TELEGRAM_USER_ID (telegram_user_id),
            UNIQUE INDEX UNIQ_USERS_REFERRAL_CODE (referral_code),
            INDEX IDX_USERS_REFERRER (referrer_id),
            INDEX IDX_USERS_CREATED_AT (created_at),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Deposits table
        $this->addSql('CREATE TABLE deposits (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            amount DECIMAL(20,8) NOT NULL,
            tx_hash VARCHAR(255) NOT NULL,
            from_address VARCHAR(255) NOT NULL,
            to_address VARCHAR(255) NOT NULL,
            confirmations INT NOT NULL DEFAULT 0,
            status ENUM("pending", "completed", "failed", "cancelled") NOT NULL DEFAULT "pending",
            network VARCHAR(20) NOT NULL DEFAULT "TRC20",
            currency VARCHAR(10) NOT NULL DEFAULT "USDT",
            metadata JSON DEFAULT NULL,
            created_at DATETIME NOT NULL,
            confirmed_at DATETIME DEFAULT NULL,
            INDEX IDX_DEPOSITS_USER (user_id),
            UNIQUE INDEX UNIQ_DEPOSITS_TX_HASH (tx_hash),
            INDEX IDX_DEPOSITS_STATUS (status),
            INDEX IDX_DEPOSITS_CREATED_AT (created_at),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Withdrawals table
        $this->addSql('CREATE TABLE withdrawals (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            amount DECIMAL(20,8) NOT NULL,
            address VARCHAR(255) NOT NULL,
            type ENUM("bonus", "deposit") NOT NULL,
            status ENUM("pending", "processing", "completed", "failed", "cancelled") NOT NULL DEFAULT "pending",
            tx_hash VARCHAR(255) DEFAULT NULL,
            approvals JSON DEFAULT NULL,
            requires_additional_verification TINYINT(1) NOT NULL DEFAULT 0,
            failure_reason VARCHAR(255) DEFAULT NULL,
            fee DECIMAL(20,8) DEFAULT NULL,
            metadata JSON DEFAULT NULL,
            created_at DATETIME NOT NULL,
            processed_at DATETIME DEFAULT NULL,
            INDEX IDX_WITHDRAWALS_USER (user_id),
            INDEX IDX_WITHDRAWALS_STATUS (status),
            INDEX IDX_WITHDRAWALS_TYPE (type),
            INDEX IDX_WITHDRAWALS_CREATED_AT (created_at),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Transactions table
        $this->addSql('CREATE TABLE transactions (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            type ENUM("deposit", "withdrawal", "bonus", "referral", "adjustment") NOT NULL,
            amount DECIMAL(20,8) NOT NULL,
            balance_before DECIMAL(20,8) DEFAULT NULL,
            balance_after DECIMAL(20,8) DEFAULT NULL,
            status ENUM("pending", "processing", "completed", "failed", "cancelled") NOT NULL DEFAULT "pending",
            tx_hash VARCHAR(255) DEFAULT NULL,
            reference_id INT DEFAULT NULL,
            reference_type VARCHAR(50) DEFAULT NULL,
            description VARCHAR(255) DEFAULT NULL,
            metadata JSON DEFAULT NULL,
            created_at DATETIME NOT NULL,
            INDEX IDX_TRANSACTIONS_USER (user_id),
            INDEX IDX_TRANSACTIONS_TYPE (type),
            INDEX IDX_TRANSACTIONS_STATUS (status),
            INDEX IDX_TRANSACTIONS_CREATED_AT (created_at),
            INDEX IDX_TRANSACTIONS_TX_HASH (tx_hash),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Bonuses table
        $this->addSql('CREATE TABLE bonuses (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            type VARCHAR(50) NOT NULL,
            amount DECIMAL(20,8) NOT NULL,
            description VARCHAR(255) DEFAULT NULL,
            status ENUM("pending", "completed", "cancelled") NOT NULL DEFAULT "completed",
            metadata JSON DEFAULT NULL,
            created_at DATETIME NOT NULL,
            INDEX IDX_BONUSES_USER (user_id),
            INDEX IDX_BONUSES_TYPE (type),
            INDEX IDX_BONUSES_CREATED_AT (created_at),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Event log table
        $this->addSql('CREATE TABLE event_log (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT DEFAULT NULL,
            event_type VARCHAR(100) NOT NULL,
            event_data JSON DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            INDEX IDX_EVENT_LOG_USER (user_id),
            INDEX IDX_EVENT_LOG_EVENT_TYPE (event_type),
            INDEX IDX_EVENT_LOG_CREATED_AT (created_at),
            INDEX IDX_EVENT_LOG_IP (ip_address),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // System settings table
        $this->addSql('CREATE TABLE system_settings (
            id INT AUTO_INCREMENT NOT NULL,
            `key` VARCHAR(100) NOT NULL,
            value TEXT NOT NULL,
            type VARCHAR(20) NOT NULL DEFAULT "string",
            description VARCHAR(255) DEFAULT NULL,
            updated_at DATETIME DEFAULT NULL,
            UNIQUE INDEX UNIQ_SETTINGS_KEY (`key`),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Add foreign keys
        $this->addSql('ALTER TABLE users ADD CONSTRAINT FK_USERS_REFERRER FOREIGN KEY (referrer_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE deposits ADD CONSTRAINT FK_DEPOSITS_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE withdrawals ADD CONSTRAINT FK_WITHDRAWALS_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE transactions ADD CONSTRAINT FK_TRANSACTIONS_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE bonuses ADD CONSTRAINT FK_BONUSES_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE event_log ADD CONSTRAINT FK_EVENT_LOG_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL');

        // Insert default settings
        $this->addSql('INSERT INTO system_settings (`key`, value, type, description) VALUES
            ("platform_name", "Crypto Investment Platform", "string", "Platform display name"),
            ("maintenance_mode", "0", "boolean", "Enable/disable maintenance mode"),
            ("registration_enabled", "1", "boolean", "Enable/disable new registrations"),
            ("crypto.network", "TRC20", "string", "Blockchain network"),
            ("crypto.currency", "USDT", "string", "Accepted cryptocurrency"),
            ("crypto.min_deposit", "100", "float", "Minimum deposit amount"),
            ("crypto.max_deposit", "100000", "float", "Maximum deposit amount"),
            ("crypto.withdrawal_min", "10", "float", "Minimum withdrawal amount"),
            ("crypto.withdrawal_fee", "1", "float", "Withdrawal fee"),
            ("crypto.withdrawal_daily_limit", "10000", "float", "Daily withdrawal limit"),
            ("crypto.deposit_confirmations", "19", "integer", "Required confirmations"),
            ("bonus.daily_distribution", "1", "boolean", "Enable daily bonus distribution"),
            ("bonus.distribution_time", "00:00", "string", "Daily distribution time"),
            ("bonus.company_profit_percent", "30", "float", "Company profit percentage"),
            ("referral.enabled", "1", "boolean", "Enable referral system"),
            ("referral.levels", "2", "integer", "Number of referral levels"),
            ("referral.level_1_percent", "10", "float", "Level 1 commission percentage"),
            ("referral.level_2_percent", "5", "float", "Level 2 commission percentage"),
            ("referral.min_deposit_for_bonus", "100", "float", "Minimum deposit for referral bonus")
        ');
    }

    public function down(Schema $schema): void
    {
        // Drop foreign keys first
        $this->addSql('ALTER TABLE users DROP FOREIGN KEY FK_USERS_REFERRER');
        $this->addSql('ALTER TABLE deposits DROP FOREIGN KEY FK_DEPOSITS_USER');
        $this->addSql('ALTER TABLE withdrawals DROP FOREIGN KEY FK_WITHDRAWALS_USER');
        $this->addSql('ALTER TABLE transactions DROP FOREIGN KEY FK_TRANSACTIONS_USER');
        $this->addSql('ALTER TABLE bonuses DROP FOREIGN KEY FK_BONUSES_USER');
        $this->addSql('ALTER TABLE event_log DROP FOREIGN KEY FK_EVENT_LOG_USER');

        // Drop tables
        $this->addSql('DROP TABLE event_log');
        $this->addSql('DROP TABLE system_settings');
        $this->addSql('DROP TABLE bonuses');
        $this->addSql('DROP TABLE transactions');
        $this->addSql('DROP TABLE withdrawals');
        $this->addSql('DROP TABLE deposits');
        $this->addSql('DROP TABLE users');
    }
}