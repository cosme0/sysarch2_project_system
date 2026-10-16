-- ==============================================================
-- AMDPMS - Pending purchase confirmation migration.
-- Recording a purchase now creates a PENDING request; the stock
-- quantity is only added to inventory when an admin CONFIRMS it.
-- Run against an EXISTING db_users install (phpMyAdmin or MySQL).
-- ==============================================================

USE db_users;

CREATE TABLE IF NOT EXISTS `purchases` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `medication_id` INT NOT NULL,
    `medication_name` VARCHAR(100) NOT NULL,
    `sku` VARCHAR(20) NOT NULL,
    `qty` INT NOT NULL,
    `supplier` VARCHAR(100) NULL,
    `reference` VARCHAR(64) NULL,
    `expiry_date` DATE NULL,
    `lot_number` VARCHAR(50) NULL,
    `requested_by` VARCHAR(100) NULL,
    `status` ENUM('pending', 'confirmed', 'cancelled') NOT NULL DEFAULT 'pending',
    `resolved_by` VARCHAR(100) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `resolved_at` DATETIME NULL,
    INDEX idx_purchase_status (`status`),
    INDEX idx_purchase_created (`created_at`)
);