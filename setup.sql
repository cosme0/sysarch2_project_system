-- =============================================================================
-- AMDPMS - Automated Medication Dispensing & Pharmacy Management System
-- AUTHORITATIVE SCHEMA. Database: db_users
--
-- This file is the single source of truth for the AMDPMS database.
-- The older amdpms_database.sql / amdpms_full_setup.sql / amdpms_backup.sql
-- files are superseded snapshots and are no longer kept in sync.
--
--   Fresh install :  mysql -u root < setup.sql
--                    (or import this file in phpMyAdmin)
--   Existing DB   :  mysql -u root < migration_progress2.sql
--                    (safe, idempotent, adds only what is missing)
-- =============================================================================

CREATE DATABASE IF NOT EXISTS db_users;
USE db_users;

-- -----------------------------------------------------------------------------
-- users : authentication, roles and lockout state
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fname VARCHAR(50) NOT NULL,
    lname VARCHAR(50) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    `pass` VARCHAR(255) NOT NULL,
    role ENUM('admin', 'staff') NOT NULL DEFAULT 'staff',
    failed_attempts INT NOT NULL DEFAULT 0,
    last_failed DATETIME NULL,
    last_login DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- password_reset_tokens : short-lived 6-digit reset codes
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(50) NOT NULL,
    token VARCHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token (token),
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- medications : inventory. `channel` maps to one of the 5 physical dispenser
-- bins; 0 means the item is stocked but not yet assigned to a bin.
--
-- Two stock buckets:
--   stock         = units currently loaded in the dispenser bin. This is the
--                   only quantity the shop can sell and the only quantity the
--                   dispenser can physically release.
--   reserve_stock = additional units held in storage, not inside the machine.
--                   Confirmed purchases land here and are moved into `stock`
--                   with Admin -> Reordering -> "Load into machine".
-- Total on hand = stock + reserve_stock.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `medications` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    channel INT NOT NULL DEFAULT 0,
    name VARCHAR(100) NOT NULL,
    sku VARCHAR(20) NOT NULL UNIQUE,
    price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    stock INT NOT NULL DEFAULT 0,
    reserve_stock INT NOT NULL DEFAULT 0,
    category VARCHAR(50) NOT NULL DEFAULT 'Tablet',
    icon VARCHAR(16) NOT NULL DEFAULT '&#128138;',
    expiry_date DATE NULL,
    lot_number VARCHAR(50) NULL,
    reorder_level INT NOT NULL DEFAULT 20,
    reorder_qty INT NOT NULL DEFAULT 50,
    supplier VARCHAR(100) NULL,
    updated_at DATETIME NULL,
    INDEX idx_med_channel (channel),
    INDEX idx_med_category (category),
    INDEX idx_med_expiry (expiry_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- stock_movements : every single change to on-hand quantity, with the balance
-- that resulted from it. movement_type is purchase | sale | writeoff | adjustment
-- and `qty` is signed (+ inbound, - outbound). `stock_after` is the TOTAL
-- on-hand balance after the movement (medications.stock + reserve_stock), so
-- it stays consistent whether the change hit the machine bin or the reserve.
-- -----------------------------------------------------------------------------
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

-- -----------------------------------------------------------------------------
-- purchases : stock replenishment requests. Quantity is only added to
-- medications.stock once an admin confirms the purchase.
-- -----------------------------------------------------------------------------
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

-- -----------------------------------------------------------------------------
-- sales : one row per completed, paid transaction. Created by the payment
-- simulation at checkout and printed on the invoice receipt.
--
--   payment_method : cash | gcash | maya
--   reference_no    : simulated e-wallet reference (NULL for cash)
--   cash_received   : amount handed over by the customer (NULL for e-wallets)
--   change_due      : cash_received - total_amount (NULL for e-wallets)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sales` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_no VARCHAR(24) NOT NULL UNIQUE,
    user_email VARCHAR(100) NULL,
    user_id INT NULL,
    total_qty INT NOT NULL DEFAULT 0,
    subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    payment_method VARCHAR(20) NOT NULL DEFAULT 'cash',
    reference_no VARCHAR(64) NULL,
    cash_received DECIMAL(10,2) NULL,
    change_due DECIMAL(10,2) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'completed',
    batch_ref VARCHAR(64) NULL,
    sold_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    paid_at DATETIME NULL,
    INDEX idx_sold_at (sold_at),
    INDEX idx_sale_method (payment_method),
    INDEX idx_sale_batch (batch_ref),
    INDEX idx_sale_user (user_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- sale_items : line items of a sale, captured at the price actually charged so
-- historical revenue does not shift when a medication price is later edited.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sale_items` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    medication_sku VARCHAR(20) NULL,
    medication_name VARCHAR(100) NULL,
    price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    qty INT NOT NULL DEFAULT 1,
    subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    INDEX idx_sale_items_sale (sale_id),
    CONSTRAINT fk_sale_items_sale FOREIGN KEY (sale_id)
        REFERENCES sales (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- dispense_history : which unit of which medication physically left a bin.
-- sale_id links the dispense to the paid invoice it belongs to.
-- -----------------------------------------------------------------------------
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
    INDEX idx_dispensed_batch (batch_ref),
    INDEX idx_dispensed_sale (sale_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- audit_log : the comprehensive system activity log.
--
-- Every meaningful action is recorded here, not just sign-in events:
--   module   which part of the system was used (auth, shop, cart, checkout,
--            dispense, inventory, reports, admin, account, support, hardware)
--   action   the specific activity (login_success, med_update, report_export…)
--   severity info | notice | warning | critical, used for filtering and alerts
--   entity   the record type the action touched, with its identifier
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `audit_log` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_email VARCHAR(100) NULL,
    user_id INT NULL,
    user_role VARCHAR(10) NULL,
    module VARCHAR(50) NOT NULL DEFAULT 'system',
    action VARCHAR(50) NOT NULL,
    severity ENUM('info', 'notice', 'warning', 'critical') NOT NULL DEFAULT 'info',
    entity VARCHAR(50) NULL,
    entity_id VARCHAR(64) NULL,
    details VARCHAR(500) NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    request_uri VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_created (created_at),
    INDEX idx_audit_action (action),
    INDEX idx_audit_module (module),
    INDEX idx_audit_severity (severity),
    INDEX idx_audit_user (user_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- support_tickets : staff-submitted support requests
-- -----------------------------------------------------------------------------
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

-- =============================================================================
-- Seed data
--
-- No accounts are seeded on purpose. The first person to register through
-- index.php is promoted to admin automatically, so install the system, register
-- the administrator with a strong password, and then create staff accounts.
-- =============================================================================

-- 5 medications, one per dispenser channel
INSERT INTO `medications`
    (channel, name, sku, price, stock, reserve_stock, category, icon, expiry_date, lot_number,
     reorder_level, reorder_qty, supplier)
VALUES
    (1, 'Paracetamol 500mg',  'MED-001',  45.00, 420, 150, 'Tablet',    '&#128138;', DATE_ADD(CURDATE(), INTERVAL 8 MONTH),  'LOT-PCM-2601', 50, 100, 'PharmaSource, Inc.'),
    (2, 'Amoxicillin 500mg',  'MED-014',  12.00,  86,   0, 'Capsule',   '&#128138;', DATE_ADD(CURDATE(), INTERVAL 5 MONTH),  'LOT-AMX-2601', 40,  80, 'MedSupply Metro'),
    (3, 'Metformin 850mg',    'MED-032',   8.50,  24, 200, 'Tablet',    '&#128138;', DATE_ADD(CURDATE(), INTERVAL 10 MONTH), 'LOT-MTF-2509', 30,  60, 'PharmaSource, Inc.'),
    (4, 'Salbutamol Inhaler', 'MED-078', 185.00,  15,   0, 'Inhaler',   '&#129657;', DATE_ADD(CURDATE(), INTERVAL 12 MONTH), 'LOT-SLB-2506', 12,  24, 'MedSupply Metro'),
    (5, 'Vitamin B-Complex',  'MED-091',  95.00, 210, 100, 'Supplement','&#128056;', DATE_ADD(CURDATE(), INTERVAL 6 MONTH),  'LOT-BCP-2602', 60, 120, 'NutriCare Distributor')
ON DUPLICATE KEY UPDATE
    name          = VALUES(name),
    price         = VALUES(price),
    category      = VALUES(category),
    icon          = VALUES(icon),
    expiry_date   = VALUES(expiry_date),
    lot_number    = VALUES(lot_number),
    reorder_level = VALUES(reorder_level),
    reorder_qty   = VALUES(reorder_qty),
    supplier      = VALUES(supplier);
