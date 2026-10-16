-- =============================================================================
-- Migration: separate machine stock from reserve (storage) stock
--
-- Adds `medications.reserve_stock`. After this:
--   stock         = units loaded in the dispenser bin (sellable / dispensable)
--   reserve_stock = backup units in storage, NOT inside the machine
--
-- Confirmed purchases top up reserve_stock; Admin -> Reordering ->
-- "Load into machine" moves units from reserve_stock into stock.
--
-- Safe to run repeatedly: it only adds the column if it is missing, and never
-- drops tables or rewrites existing rows. Existing rows default to 0 reserve.
-- =============================================================================

SET @col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'medications'
      AND COLUMN_NAME  = 'reserve_stock'
);

SET @ddl := IF(
    @col_exists = 0,
    'ALTER TABLE medications ADD COLUMN reserve_stock INT NOT NULL DEFAULT 0 AFTER stock',
    'SELECT ''reserve_stock already present'' AS note'
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
