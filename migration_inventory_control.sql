-- ==============================================================
-- AMDPMS - Inventory control migration (reordering, purchases,
-- cycle counts, write-offs, stock movement audit).
-- Run against an EXISTING db_users install.
-- ==============================================================

USE db_users;

-- Extend medications with reorder settings and supplier info.
ALTER TABLE `medications`
    ADD COLUMN IF NOT EXISTS `reorder_level` INT NOT NULL DEFAULT 20 AFTER `lot_number`,
    ADD COLUMN IF NOT EXISTS `reorder_qty`   INT NOT NULL DEFAULT 50 AFTER `reorder_level`,
    ADD COLUMN IF NOT EXISTS `supplier`      VARCHAR(100) NULL AFTER `reorder_qty`;

-- Full history of every stock change: purchases (in), sales, write-offs,
-- and cycle-count adjustments. Positive qty = stock in, negative = stock out.
CREATE TABLE IF NOT EXISTS `stock_movements` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `medication_id` INT NULL,
    `medication_name` VARCHAR(100) NULL,
    `sku` VARCHAR(20) NULL,
    `movement_type` ENUM('purchase', 'sale', 'writeoff', 'adjustment') NOT NULL,
    `qty` INT NOT NULL,
    `stock_after` INT NOT NULL DEFAULT 0,
    `reason` VARCHAR(255) NULL,
    `reference` VARCHAR(64) NULL,
    `supplier` VARCHAR(100) NULL,
    `user_email` VARCHAR(100) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_movement_created (created_at),
    INDEX idx_movement_type (movement_type),
    INDEX idx_movement_sku (sku)
);