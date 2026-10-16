<?php
/**
 * Shared inventory helpers used by admin.php.
 */

// ---------------------------------------------------------------------------
// Record a stock movement for the full audit history.
// Used for purchases, sales, write-offs, and adjustments.
// ---------------------------------------------------------------------------
if (!function_exists('recordStockMovement')) {
    function recordStockMovement(mysqli $conn, array $med, string $type, int $qty, int $stockAfter, ?string $reason, ?string $reference, ?string $supplier): void
    {
        $stmt = $conn->prepare(
            "INSERT INTO stock_movements (medication_id, medication_name, sku, movement_type, qty, stock_after, reason, reference, supplier, user_email)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $user = $_SESSION['email'] ?? null;
        $stmt->bind_param("isssiissss", $med['id'], $med['name'], $med['sku'], $type, $qty, $stockAfter, $reason, $reference, $supplier, $user);
        $stmt->execute();
    }
}