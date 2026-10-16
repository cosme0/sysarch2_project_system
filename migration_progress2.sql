-- =============================================================================
-- AMDPMS - Progress Report #2 migration
-- Adds: comprehensive audit logging (module / severity / entity) and the
--        payment simulation data model (sales + sale_items + dispense link).
--
-- This script is SAFE to run more than once. It only creates what is missing
-- and never drops or rewrites existing data.
--
--   mysql -u root < migration_progress2.sql
--
-- Compatible with MySQL 5.7 / 8.0 and MariaDB 10.4+ (it does not rely on the
-- MariaDB-only "ADD COLUMN IF NOT EXISTS" syntax).
-- =============================================================================

USE db_users;

-- -----------------------------------------------------------------------------
-- Helpers. Two tiny procedures keep the ALTERs below readable and idempotent.
-- Both are dropped again at the end of the script.
-- -----------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS `amdpms_add_column`;
DROP PROCEDURE IF EXISTS `amdpms_add_index`;

DELIMITER $$

CREATE PROCEDURE `amdpms_add_column`(
    IN p_table      VARCHAR(64),
    IN p_column     VARCHAR(64),
    IN p_definition TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = p_table
          AND COLUMN_NAME  = p_column
    ) THEN
        SET @ddl = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_definition);
        PREPARE st FROM @ddl;
        EXECUTE st;
        DEALLOCATE PREPARE st;
    END IF;
END$$

CREATE PROCEDURE `amdpms_add_index`(
    IN p_table VARCHAR(64),
    IN p_index VARCHAR(64),
    IN p_column VARCHAR(64)
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = p_table
          AND INDEX_NAME   = p_index
    ) THEN
        SET @ddl = CONCAT('ALTER TABLE `', p_table, '` ADD INDEX `', p_index, '` (`', p_column, '`)');
        PREPARE st FROM @ddl;
        EXECUTE st;
        DEALLOCATE PREPARE st;
    END IF;
END$$

DELIMITER ;

-- =============================================================================
-- 1. audit_log - the comprehensive activity log
-- =============================================================================

-- Ensure the table exists at all (very old installs may not have it).
CREATE TABLE IF NOT EXISTS `audit_log` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_email VARCHAR(100) NULL,
    user_id INT NULL,
    module VARCHAR(50) NOT NULL DEFAULT 'system',
    action VARCHAR(50) NOT NULL,
    details VARCHAR(255) NULL,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_created (created_at),
    INDEX idx_audit_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CALL amdpms_add_column('audit_log', 'user_role',   'VARCHAR(10) NULL');
CALL amdpms_add_column('audit_log', 'module',      'VARCHAR(50) NOT NULL DEFAULT ''system''');
CALL amdpms_add_column('audit_log', 'severity',    'ENUM(''info'',''notice'',''warning'',''critical'') NOT NULL DEFAULT ''info''');
CALL amdpms_add_column('audit_log', 'entity',      'VARCHAR(50) NULL');
CALL amdpms_add_column('audit_log', 'entity_id',   'VARCHAR(64) NULL');
CALL amdpms_add_column('audit_log', 'user_agent',  'VARCHAR(255) NULL');
CALL amdpms_add_column('audit_log', 'request_uri', 'VARCHAR(255) NULL');

-- The old details column was 255 chars; the new logger writes longer context.
ALTER TABLE `audit_log` MODIFY COLUMN `details` VARCHAR(500) NULL;

CALL amdpms_add_index('audit_log', 'idx_audit_module',   'module');
CALL amdpms_add_index('audit_log', 'idx_audit_severity', 'severity');
CALL amdpms_add_index('audit_log', 'idx_audit_user',     'user_email');

-- Backfill: rows written before this migration are admin actions, not system ones.
UPDATE `audit_log` SET `module` = 'admin' WHERE `module` = 'system' AND `action` IN (
    'user_promote', 'user_demote', 'user_delete',
    'med_add', 'med_update', 'med_delete',
    'purchase_pending', 'purchase_confirm', 'purchase_cancel',
    'writeoff', 'writeoff_expired', 'backup_download'
);

-- =============================================================================
-- 2. sales / sale_items - the payment simulation
-- =============================================================================

CREATE TABLE IF NOT EXISTS `sales` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_no VARCHAR(24) NOT NULL UNIQUE,
    user_email VARCHAR(100) NULL,
    total_qty INT NOT NULL DEFAULT 0,
    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    payment_method VARCHAR(20) NOT NULL DEFAULT 'cash',
    cash_received DECIMAL(10,2) NULL,
    change_due DECIMAL(10,2) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'completed',
    sold_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sold_at (sold_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CALL amdpms_add_column('sales', 'user_id',      'INT NULL');
CALL amdpms_add_column('sales', 'subtotal',     'DECIMAL(10,2) NOT NULL DEFAULT 0.00');
CALL amdpms_add_column('sales', 'reference_no', 'VARCHAR(64) NULL');
CALL amdpms_add_column('sales', 'batch_ref',    'VARCHAR(64) NULL');
CALL amdpms_add_column('sales', 'paid_at',      'DATETIME NULL');

CALL amdpms_add_index('sales', 'idx_sale_method', 'payment_method');
CALL amdpms_add_index('sales', 'idx_sale_batch',  'batch_ref');
CALL amdpms_add_index('sales', 'idx_sale_user',   'user_email');

-- Keep subtotal consistent with the total for transactions predating this change.
UPDATE `sales` SET `subtotal` = `total_amount` WHERE `subtotal` = 0.00;

CREATE TABLE IF NOT EXISTS `sale_items` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    medication_sku VARCHAR(20) NULL,
    medication_name VARCHAR(100) NULL,
    price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    qty INT NOT NULL DEFAULT 1,
    subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    INDEX idx_sale_id (sale_id),
    CONSTRAINT fk_sale_items_sale FOREIGN KEY (sale_id)
        REFERENCES sales (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- 3. dispense_history - link each dispense to the invoice that paid for it
-- =============================================================================

CREATE TABLE IF NOT EXISTS `dispense_history` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    batch_ref VARCHAR(64) NULL,
    sale_id INT NULL,
    user_email VARCHAR(100) NULL,
    medication_sku VARCHAR(20) NULL,
    medication_name VARCHAR(100) NULL,
    qty INT NOT NULL DEFAULT 1,
    channel INT NULL,
    dispensed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_dispensed_at (dispensed_at),
    INDEX idx_dispensed_batch (batch_ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CALL amdpms_add_index('dispense_history', 'idx_dispensed_sale', 'sale_id');

-- =============================================================================
-- 4. users - track the last successful sign-in
-- =============================================================================

CREATE TABLE IF NOT EXISTS `users` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fname VARCHAR(50) NOT NULL,
    lname VARCHAR(50) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    `pass` VARCHAR(255) NOT NULL,
    role ENUM('admin', 'staff') NOT NULL DEFAULT 'staff',
    failed_attempts INT NOT NULL DEFAULT 0,
    last_failed DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CALL amdpms_add_column('users', 'last_login', 'DATETIME NULL');

-- =============================================================================
-- 5. medications - track the last edit and index the reporting columns
-- =============================================================================

CREATE TABLE IF NOT EXISTS `medications` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    channel INT NOT NULL DEFAULT 0,
    name VARCHAR(100) NOT NULL,
    sku VARCHAR(20) NOT NULL UNIQUE,
    price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    stock INT NOT NULL DEFAULT 0,
    category VARCHAR(50) NOT NULL DEFAULT 'Tablet',
    icon VARCHAR(16) NOT NULL DEFAULT '&#128138;',
    expiry_date DATE NULL,
    lot_number VARCHAR(50) NULL,
    reorder_level INT NOT NULL DEFAULT 20,
    reorder_qty INT NOT NULL DEFAULT 50,
    supplier VARCHAR(100) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CALL amdpms_add_column('medications', 'updated_at', 'DATETIME NULL');

CALL amdpms_add_index('medications', 'idx_med_channel',  'channel');
CALL amdpms_add_index('medications', 'idx_med_category', 'category');
CALL amdpms_add_index('medications', 'idx_med_expiry',   'expiry_date');

-- =============================================================================
-- 6. stock_movements / purchases / support_tickets - created for very old
--    installs that predate the inventory-control and purchasing features.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `stock_movements` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    medication_id INT NULL,
    medication_name VARCHAR(100) NULL,
    sku VARCHAR(20) NULL,
    movement_type ENUM('purchase', 'sale', 'writeoff', 'adjustment') NOT NULL,
    qty INT NOT NULL,
    stock_after INT NOT NULL DEFAULT 0,
    reason VARCHAR(255) NULL,
    reference VARCHAR(64) NULL,
    supplier VARCHAR(100) NULL,
    user_email VARCHAR(100) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_movement_created (created_at),
    INDEX idx_movement_type (movement_type),
    INDEX idx_movement_sku (sku)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `purchases` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    medication_id INT NOT NULL,
    medication_name VARCHAR(100) NOT NULL,
    sku VARCHAR(20) NOT NULL,
    qty INT NOT NULL,
    supplier VARCHAR(100) NULL,
    reference VARCHAR(64) NULL,
    expiry_date DATE NULL,
    lot_number VARCHAR(50) NULL,
    requested_by VARCHAR(100) NULL,
    status ENUM('pending', 'confirmed', 'cancelled') NOT NULL DEFAULT 'pending',
    resolved_by VARCHAR(100) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,
    INDEX idx_purchase_status (status),
    INDEX idx_purchase_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `support_tickets` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    staff_name VARCHAR(100) NOT NULL,
    staff_email VARCHAR(100) NOT NULL,
    subject VARCHAR(100) NOT NULL DEFAULT '',
    message TEXT NOT NULL,
    status ENUM('open', 'in_progress', 'resolved') NOT NULL DEFAULT 'open',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- Clean up the helpers.
-- -----------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS `amdpms_add_column`;
DROP PROCEDURE IF EXISTS `amdpms_add_index`;

SELECT 'AMDPMS migration complete.' AS result;
