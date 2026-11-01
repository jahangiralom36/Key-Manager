-- ====================================================================
-- KEY MANAGER — DATABASE SETUP / MIGRATION  (database.sql)
-- Code e ar kono CREATE/ALTER nei. Sob table ekhane.
--
-- phpMyAdmin > keymanag93_rakibkey > "Import" (ba SQL tab e paste) kore ekbar run korun.
-- Safe: existing DB te chalale kono data muchhe jabe na
--       (CREATE TABLE IF NOT EXISTS + ADD COLUMN IF NOT EXISTS — MariaDB 10.0.2+).
-- Run korar age ekta backup/export nin.
-- ====================================================================

SET NAMES utf8mb4;

-- --------------------------------------------------------------------
-- 1. users
-- --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(100) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `role` VARCHAR(20) NOT NULL DEFAULT 'reseller',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `api_key` VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS `api_enabled` TINYINT(1) DEFAULT 1,
  ADD COLUMN IF NOT EXISTS `profit_balance` DECIMAL(10,2) DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS `parent_granted_balance` DECIMAL(10,2) DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS `custom_bdt_rate` DECIMAL(10,2) DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS `can_add_fund` TINYINT(1) DEFAULT 1,
  ADD COLUMN IF NOT EXISTS `allowed_products` TEXT NULL,
  ADD COLUMN IF NOT EXISTS `created_by` INT NULL,
  ADD COLUMN IF NOT EXISTS `custom_tutorial_link` VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS `telegram_chat_id` BIGINT NULL,
  ADD COLUMN IF NOT EXISTS `telegram_username` VARCHAR(100) NULL,
  ADD COLUMN IF NOT EXISTS `min_deposit` DECIMAL(10,2) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `max_deposit` DECIMAL(10,2) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `status` VARCHAR(20) DEFAULT 'active';

UPDATE `users` SET `api_enabled` = 1 WHERE `api_enabled` IS NULL;
UPDATE `users` SET `api_key` = CONCAT('keymanager_live_', MD5(CONCAT(id, username, NOW(), RAND())))
  WHERE `api_key` IS NULL OR `api_key` = '';

-- --------------------------------------------------------------------
-- 2. tg_auth_sessions
-- --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tg_auth_sessions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `session_token` VARCHAR(64) NOT NULL UNIQUE,
  `telegram_chat_id` BIGINT NULL,
  `telegram_username` VARCHAR(100) NULL,
  `otp_code` VARCHAR(10) NULL,
  `otp_expires_at` DATETIME NULL,
  `telegram_message_id` BIGINT NULL,
  `attempts` INT DEFAULT 0,
  `is_tg_bound` TINYINT(1) DEFAULT 0,
  `is_otp_verified` TINYINT(1) DEFAULT 0,
  `status` ENUM('pending', 'used', 'expired') DEFAULT 'pending',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------------------
-- 3. system_settings   (Bangla broadcast notice er jonno utf8mb4)
-- --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `system_settings` (
  `setting_key` VARCHAR(64) PRIMARY KEY,
  `setting_value` TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `system_settings` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- --------------------------------------------------------------------
-- 4. products
-- --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `products` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(191) NOT NULL,
  `code` VARCHAR(100) NOT NULL,
  `sort_order` INT DEFAULT 0,
  `is_active` TINYINT(1) DEFAULT 1,
  `discount_percent` INT DEFAULT 0,
  `reset_api_id` INT DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `products`
  ADD COLUMN IF NOT EXISTS `sort_order` INT DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `is_active` TINYINT(1) DEFAULT 1,
  ADD COLUMN IF NOT EXISTS `discount_percent` INT DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `reset_api_id` INT DEFAULT 0;

-- --------------------------------------------------------------------
-- 5. product_plans
-- --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `product_plans` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `product_id` INT NOT NULL,
  `duration_days` INT DEFAULT 1,
  `plan_title` VARCHAR(100) NOT NULL,
  `reseller_price` DECIMAL(10,2) DEFAULT 0.00,
  `user_price` DECIMAL(10,2) DEFAULT 0.00,
  `reseller_discount_percent` DECIMAL(5,2) DEFAULT 0.00,
  `discount_percent` INT DEFAULT 0,
  `duration_unit` VARCHAR(20) DEFAULT 'days',
  `duration_value` INT DEFAULT 1,
  `api_plan_id` INT DEFAULT 0,
  `reset_api_id` INT DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `product_plans`
  ADD COLUMN IF NOT EXISTS `api_plan_id` INT DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `reset_api_id` INT DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `user_price` DECIMAL(10,2) DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS `reseller_discount_percent` DECIMAL(5,2) DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS `discount_percent` INT DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `duration_unit` VARCHAR(20) DEFAULT 'days',
  ADD COLUMN IF NOT EXISTS `duration_value` INT DEFAULT 1;

-- --------------------------------------------------------------------
-- 6. user_plan_pricing
-- --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_plan_pricing` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `plan_id` INT NOT NULL,
  `custom_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `custom_discount` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `usr_pln_unique` (`user_id`, `plan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------------------
-- 7. stock_keys
-- --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `stock_keys` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `product_id` INT NOT NULL,
  `plan_id` INT NOT NULL,
  `api_plan_id` INT DEFAULT 0,
  `license_key` VARCHAR(255) NOT NULL,
  `status` ENUM('available', 'used') DEFAULT 'available',
  `used_by` INT NULL,
  `used_at` DATETIME NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `stock_keys`
  ADD COLUMN IF NOT EXISTS `api_plan_id` INT DEFAULT 0;

-- --------------------------------------------------------------------
-- 8. licenses   (reset system ei table er license_key dhore kaj kore)
-- --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `licenses` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `product_id` INT DEFAULT 0,
  `plan_id` INT DEFAULT 0,
  `reset_api_id` INT DEFAULT 0,
  `license_key` VARCHAR(100) NOT NULL,
  `validity` VARCHAR(255) NOT NULL,
  `price` DECIMAL(10,2) DEFAULT 0.00,
  `source` VARCHAR(20) DEFAULT 'PANEL',
  `status` VARCHAR(20) DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `licenses`
  ADD COLUMN IF NOT EXISTS `price` DECIMAL(10,2) DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS `source` VARCHAR(20) DEFAULT 'PANEL',
  ADD COLUMN IF NOT EXISTS `product_name` VARCHAR(150) NULL,
  ADD COLUMN IF NOT EXISTS `reset_api_id` INT DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `buy_source` VARCHAR(30) DEFAULT 'PORTAL';

-- Reset page / API te key khujte druto (index na thakle ekhon add hobe)
ALTER TABLE `licenses` ADD INDEX IF NOT EXISTS `idx_license_key` (`license_key`);
ALTER TABLE `licenses` ADD INDEX IF NOT EXISTS `idx_license_user` (`user_id`);

-- --------------------------------------------------------------------
-- 9. transactions
-- --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `transactions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `method` VARCHAR(50) NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `sender_num` VARCHAR(100) NULL,
  `trx_id` VARCHAR(100) NOT NULL,
  `status` ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------------------
-- 10. profit_withdrawals
-- --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `profit_withdrawals` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `bkash_number` VARCHAR(50) NULL,
  `status` VARCHAR(20) DEFAULT 'pending',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------------------
-- 11. deposit_bonuses
-- --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `deposit_bonuses` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `min_amount` DECIMAL(10,2) NOT NULL,
  `bonus_amount` DECIMAL(10,2) NOT NULL,
  `bonus_type` ENUM('fixed', 'percent') DEFAULT 'fixed',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------------------
-- 12. activity_logs   (KEY_RESET log ekhane jomay — admin "Key Resets" tab)
-- --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `activity_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NULL,
  `username` VARCHAR(100) NOT NULL,
  `action` VARCHAR(50) NOT NULL,
  `details` TEXT NOT NULL,
  `ip_address` VARCHAR(45) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `activity_logs` ADD INDEX IF NOT EXISTS `idx_action_ip_time` (`action`, `ip_address`, `created_at`);

-- --------------------------------------------------------------------
-- 13. bot_admins
-- --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bot_admins` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `telegram_id` BIGINT NOT NULL UNIQUE,
  `username` VARCHAR(100) NULL,
  `added_by` BIGINT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------------------
-- 14. user_login_logs
-- --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_login_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NULL,
  `username` VARCHAR(100) NOT NULL,
  `telegram_id` BIGINT NULL,
  `telegram_username` VARCHAR(100) NULL,
  `telegram_name` VARCHAR(150) NULL,
  `ip_address` VARCHAR(45) NOT NULL,
  `city` VARCHAR(100) NULL,
  `region` VARCHAR(100) NULL,
  `country` VARCHAR(100) NULL,
  `isp` VARCHAR(150) NULL,
  `network_type` VARCHAR(50) NULL,
  `login_time` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------------------
-- 15. ONE-TIME DATA FIX: reset API mapping (khali/0 thaka plan gulo)
--     Product name dekhe reset api id bosay. Admin je plan e ager theke
--     mapping disechhe oi gulo (0 na) touch kora hobe na.
--     (Code o runtime e ekoi logic e auto-detect kore, ata admin e dekhar jonno.)
-- --------------------------------------------------------------------
UPDATE `product_plans` pl
JOIN `products` p ON p.id = pl.product_id
SET pl.reset_api_id = CASE
    WHEN LOWER(CONCAT(p.name, ' ', pl.plan_title)) REGEXP 'pinxt|angry'  THEN 99
    WHEN LOWER(CONCAT(p.name, ' ', pl.plan_title)) LIKE '%drip%'         THEN 2
    WHEN LOWER(CONCAT(p.name, ' ', pl.plan_title)) LIKE '%fluorite%'     THEN 4
    WHEN LOWER(CONCAT(p.name, ' ', pl.plan_title)) LIKE '%haxx%'         THEN 9
    WHEN LOWER(CONCAT(p.name, ' ', pl.plan_title)) LIKE '%hgcheat%'      THEN 3
    WHEN LOWER(CONCAT(p.name, ' ', pl.plan_title)) LIKE '%silent%root%'  THEN 12
    WHEN LOWER(CONCAT(p.name, ' ', pl.plan_title)) LIKE '%silent%'       THEN 11
    ELSE 0 END
WHERE pl.reset_api_id IS NULL OR pl.reset_api_id = 0;
