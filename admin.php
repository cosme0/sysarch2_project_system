<?php
require_once __DIR__ . '/auth.php';;
requireAdmin();
include("includes/logout.php");
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/stock.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Database connection for admin operations
$conn = db();

// Ensure the pending-purchases table exists (idempotent, so existing
// installs don't need a manual migration step).
$conn->query(
    "CREATE TABLE IF NOT EXISTS purchases (
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
    )"
);

$adminMsg = '';
$adminError = '';

// Ensure the reserve-stock column exists (machine stock vs. storage stock).
// Same idempotent safety net as the purchases table above.
$reserveCol = $conn->query("SHOW COLUMNS FROM medications LIKE 'reserve_stock'");
if ($reserveCol && $reserveCol->num_rows === 0) {
    $conn->query("ALTER TABLE medications ADD COLUMN reserve_stock INT NOT NULL DEFAULT 0 AFTER stock");
}

// ---------- ACTION: User management ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['_csrf'] ?? null)) {
        $adminError = 'Invalid form token. Please refresh the page and try again.';
    } else {
        // Promote to admin
        if (isset($_POST['promote_user'])) {
            $uid = (int)$_POST['user_id'];
            $urow = $conn->query("SELECT email FROM users WHERE id = $uid")->fetch_assoc();
            $stmt = $conn->prepare("UPDATE users SET role = 'admin' WHERE id = ?");
            $stmt->bind_param("i", $uid);
            $stmt->execute();
            log_activity('user_promote', 'admin', 'Promoted ' . ($urow['email'] ?? "#$uid") . ' to administrator.', 'critical', 'user', (string)$uid);
            $adminMsg = 'User promoted to administrator.';
        }
        // Demote to staff (prevent demoting yourself or the last admin)
        if (isset($_POST['demote_user'])) {
            $uid = (int)$_POST['user_id'];
            if ($uid === (int)($_SESSION['user_id'] ?? -1)) {
                $adminError = 'You cannot demote your own account.';
            } else {
                $countAdmin = $conn->query("SELECT COUNT(*) AS c FROM users WHERE role = 'admin'")->fetch_assoc()['c'];
                if ($countAdmin <= 1) {
                    $adminError = 'At least one administrator must remain.';
                } else {
                    $urow = $conn->query("SELECT email FROM users WHERE id = $uid")->fetch_assoc();
                    $stmt = $conn->prepare("UPDATE users SET role = 'staff' WHERE id = ?");
                    $stmt->bind_param("i", $uid);
                    $stmt->execute();
                    log_activity('user_demote', 'admin', 'Demoted ' . ($urow['email'] ?? "#$uid") . ' to staff.', 'warning', 'user', (string)$uid);
                    $adminMsg = 'User demoted to staff.';
                }
            }
        }
        // Delete user
        if (isset($_POST['delete_user'])) {
            $uid = (int)$_POST['user_id'];
            if ($uid === (int)($_SESSION['user_id'] ?? -1)) {
                $adminError = 'You cannot delete your own account.';
            } else {
                $urow = $conn->query("SELECT email FROM users WHERE id = $uid")->fetch_assoc();
                $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
                $stmt->bind_param("i", $uid);
                $stmt->execute();
                log_activity('user_delete', 'admin', 'Deleted user ' . ($urow['email'] ?? "#$uid") . '.', 'critical', 'user', (string)$uid);
                $adminMsg = 'User deleted.';
            }
        }

        // ---------- Medication CRUD ----------
        if (isset($_POST['add_med'])) {
            $name = trim($_POST['name'] ?? '');
            $sku = trim($_POST['sku'] ?? '');
            $price = (float)($_POST['price'] ?? 0);
            $stock = (int)($_POST['stock'] ?? 0);
            $reserve = (int)($_POST['reserve_stock'] ?? 0);
            $category = trim($_POST['category'] ?? 'Tablet');
            $icon = trim($_POST['icon'] ?? '&#128138;');
            $channel = (int)($_POST['channel'] ?? 0);
            $expiryDate = trim($_POST['expiry_date'] ?? '');
            $lotNumber = trim($_POST['lot_number'] ?? '');
            $expiryDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiryDate) ? $expiryDate : null;
            $lotNumber = $lotNumber !== '' ? $lotNumber : null;

            if ($name !== '' && $sku !== '') {
                $stmt = $conn->prepare("INSERT INTO medications (channel, name, sku, price, stock, reserve_stock, category, icon, expiry_date, lot_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("issdiissss", $channel, $name, $sku, $price, $stock, $reserve, $category, $icon, $expiryDate, $lotNumber);
                $stmt->execute();
                log_activity('med_add', 'inventory', "{$name} ({$sku}) added to inventory on channel {$channel} (stock {$stock}, reserve {$reserve}).", 'notice', 'medication', $sku);
                $adminMsg = 'Medication added.';
            } else {
                $adminError = 'Name and SKU are required.';
            }
        }

        if (isset($_POST['update_med'])) {
            $id = (int)$_POST['med_id'];

            // Load current values so any field not submitted keeps its existing value.
            $stmt = $conn->prepare("SELECT * FROM medications WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();

            if ($existing) {
                $name     = trim($_POST['name']     ?? $existing['name']);
                $sku      = trim($_POST['sku']      ?? $existing['sku']);
                $price    = (isset($_POST['price']) && $_POST['price'] !== '') ? (float)$_POST['price'] : (float)$existing['price'];
                $stock    = (isset($_POST['stock']) && $_POST['stock'] !== '') ? (int)$_POST['stock']   : (int)$existing['stock'];
                $reserve  = (isset($_POST['reserve_stock']) && $_POST['reserve_stock'] !== '') ? (int)$_POST['reserve_stock'] : (int)$existing['reserve_stock'];
                $category = trim($_POST['category'] ?? $existing['category']);
                $icon     = trim($_POST['icon']     ?? $existing['icon']);
                $channel  = (isset($_POST['channel']) && $_POST['channel'] !== '') ? (int)$_POST['channel'] : (int)$existing['channel'];
                $expiryDate = trim($_POST['expiry_date'] ?? ($existing['expiry_date'] ?? ''));
                $lotNumber  = trim($_POST['lot_number'] ?? ($existing['lot_number'] ?? ''));
                $expiryDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiryDate) ? $expiryDate : null;
                $lotNumber  = $lotNumber !== '' ? $lotNumber : null;

                if ($name === '' || $sku === '') {
                    $adminError = 'Medication name and SKU cannot be empty.';
                } else {
                    $stmt = $conn->prepare("UPDATE medications SET channel = ?, name = ?, sku = ?, price = ?, stock = ?, reserve_stock = ?, category = ?, icon = ?, expiry_date = ?, lot_number = ? WHERE id = ?");
                    $stmt->bind_param("issdiissssi", $channel, $name, $sku, $price, $stock, $reserve, $category, $icon, $expiryDate, $lotNumber, $id);
                    $stmt->execute();
                    log_activity('med_update', 'inventory', "{$name} ({$sku}) updated (channel {$channel}, stock {$stock}, reserve {$reserve}).", 'notice', 'medication', $sku);
                    $adminMsg = 'Medication updated.';
                }
            } else {
                $adminError = 'Medication not found.';
            }
        }

        if (isset($_POST['delete_med'])) {
            $id = (int)$_POST['med_id'];
            $mrow = $conn->query("SELECT name, sku FROM medications WHERE id = $id")->fetch_assoc();
            $stmt = $conn->prepare("DELETE FROM medications WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            log_activity('med_delete', 'inventory', ($mrow['name'] ?? 'Medication') . ' (' . ($mrow['sku'] ?? "id $id") . ') removed from inventory.', 'critical', 'medication', $mrow['sku'] ?? (string)$id);
            $adminMsg = 'Medication deleted.';
        }

        // ---------- Record purchase / stock-in (PENDING until confirmed) ----------
        // Purchases no longer touch stock immediately. They are stored as pending
        // and only added to reserve_stock once an admin confirms them below.
        if (isset($_POST['record_purchase'])) {
            $sku       = trim($_POST['sku'] ?? '');
            $addQty    = max(1, (int)($_POST['qty'] ?? 0));
            $supplier  = trim($_POST['supplier'] ?? '');
            $reference = trim($_POST['reference'] ?? '');
            $newExpiry = trim($_POST['expiry_date'] ?? '');
            $newLot    = trim($_POST['lot_number'] ?? '');
            $newExpiry = preg_match('/^\d{4}-\d{2}-\d{2}$/', $newExpiry) ? $newExpiry : null;
            $newLot    = $newLot !== '' ? $newLot : null;

            if ($sku === '' || $addQty < 1) {
                $adminError = 'SKU and quantity are required to record a purchase.';
            } else {
                $stmt = $conn->prepare("SELECT id, name, sku FROM medications WHERE sku = ?");
                $stmt->bind_param("s", $sku);
                $stmt->execute();
                $med = $stmt->get_result()->fetch_assoc();
                if (!$med) {
                    $adminError = "SKU {$sku} was not found in inventory.";
                } else {
                    $user = $_SESSION['email'] ?? null;
                    $ins = $conn->prepare(
                        "INSERT INTO purchases (medication_id, medication_name, sku, qty, supplier, reference, expiry_date, lot_number, requested_by, status)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
                    );
                    $ins->bind_param("issississ", $med['id'], $med['name'], $sku, $addQty, $supplier, $reference, $newExpiry, $newLot, $user);
                    $ins->execute();
                    $purchaseId = (int)$ins->insert_id;
                    log_activity('purchase_pending', 'inventory', "Purchase #{$purchaseId} recorded as pending: {$addQty} unit(s) of {$med['name']} ({$sku}); stock unchanged until confirmed.", 'info', 'purchase', (string)$purchaseId);
                    $adminMsg = "Purchase #{$purchaseId} recorded for {$addQty} unit(s) of {$med['name']}. Awaiting confirmation — stock has NOT been updated yet.";
                }
            }
        }

        // ---------- Confirm a pending purchase: NOW add the qty to reserve ----------
        if (isset($_POST['confirm_purchase'])) {
            $pid = (int)($_POST['purchase_id'] ?? 0);
            $stmt = $conn->prepare("SELECT * FROM purchases WHERE id = ? AND status = 'pending'");
            $stmt->bind_param("i", $pid);
            $stmt->execute();
            $p = $stmt->get_result()->fetch_assoc();
            if (!$p) {
                $adminError = 'Pending purchase not found — it may already be confirmed or cancelled.';
            } else {
                $stmt = $conn->prepare("SELECT id, name, sku, stock, reserve_stock, expiry_date, lot_number FROM medications WHERE id = ?");
                $stmt->bind_param("i", $p['medication_id']);
                $stmt->execute();
                $med = $stmt->get_result()->fetch_assoc();
                if (!$med) {
                    $adminError = 'The medication for this purchase no longer exists in inventory.';
                } else {
                    $addQty     = (int)$p['qty'];
                    $newReserve = (int)$med['reserve_stock'] + $addQty;
                    $totalAfter = (int)$med['stock'] + $newReserve;
                    $newExpiry  = $p['expiry_date'] ?? null;
                    $newLot     = $p['lot_number'] ?? null;
                    if ($newExpiry !== null && $newLot !== null) {
                        $upd = $conn->prepare("UPDATE medications SET reserve_stock = ?, expiry_date = ?, lot_number = ? WHERE id = ?");
                        $upd->bind_param("isss", $newReserve, $newExpiry, $newLot, $med['id']);
                    } else {
                        $upd = $conn->prepare("UPDATE medications SET reserve_stock = ? WHERE id = ?");
                        $upd->bind_param("ii", $newReserve, $med['id']);
                    }
                    $upd->execute();
                    recordStockMovement($conn, $med, 'purchase', $addQty, $totalAfter, 'Received into reserve stock', $p['reference'] ?? null, $p['supplier'] ?? null);
                    $user = $_SESSION['email'] ?? null;
                    $upd = $conn->prepare("UPDATE purchases SET status = 'confirmed', resolved_by = ?, resolved_at = NOW() WHERE id = ? AND status = 'pending'");
                    $upd->bind_param("si", $user, $pid);
                    $upd->execute();
                    log_activity('purchase_confirm', 'inventory', "Purchase #{$pid} confirmed: {$addQty} unit(s) of {$med['name']} ({$med['sku']}) added to reserve stock.", 'notice', 'purchase', (string)$pid);
                    $adminMsg = "Confirmed: {$addQty} unit(s) of {$med['name']} added to reserve stock. Use 'Load into machine' to make them available in the dispenser.";
                }
            }
        }

        // ---------- Cancel a pending purchase (no stock change) ----------
        if (isset($_POST['cancel_purchase'])) {
            $pid = (int)($_POST['purchase_id'] ?? 0);
            $user = $_SESSION['email'] ?? null;
            $upd = $conn->prepare("UPDATE purchases SET status = 'cancelled', resolved_by = ?, resolved_at = NOW() WHERE id = ? AND status = 'pending'");
            $upd->bind_param("si", $user, $pid);
            $upd->execute();
            if ($upd->affected_rows > 0) {
                log_activity('purchase_cancel', 'inventory', "Pending purchase #{$pid} cancelled; stock unchanged.", 'notice', 'purchase', (string)$pid);
                $adminMsg = 'Pending purchase cancelled. Stock was not changed.';
            } else {
                $adminError = 'Pending purchase not found — it may already be confirmed or cancelled.';
            }
        }

        // ---------- Load reserve stock into the machine ----------
        // Moves units from reserve_stock (storage) into stock (dispenser bin).
        // Only possible once the item is assigned to a physical channel 1-5.
        if (isset($_POST['load_machine'])) {
            $sku     = trim($_POST['sku'] ?? '');
            $loadQty = max(1, (int)($_POST['qty'] ?? 0));

            if ($sku === '' || $loadQty < 1) {
                $adminError = 'SKU and quantity are required to load the machine.';
            } else {
                $stmt = $conn->prepare("SELECT id, name, sku, stock, reserve_stock, channel FROM medications WHERE sku = ?");
                $stmt->bind_param("s", $sku);
                $stmt->execute();
                $med = $stmt->get_result()->fetch_assoc();
                if (!$med) {
                    $adminError = "SKU {$sku} was not found in inventory.";
                } elseif ((int)$med['channel'] < 1 || (int)$med['channel'] > 5) {
                    $adminError = "{$med['name']} has no dispenser channel assigned. Set a channel (1-5) in the Inventory tab first.";
                } elseif ((int)$med['reserve_stock'] < 1) {
                    $adminError = "{$med['name']} has no reserve stock to load.";
                } else {
                    $loadQty    = min($loadQty, (int)$med['reserve_stock']);
                    $newStock   = (int)$med['stock'] + $loadQty;
                    $newReserve = (int)$med['reserve_stock'] - $loadQty;
                    $totalAfter = $newStock + $newReserve;
                    $upd = $conn->prepare("UPDATE medications SET stock = ?, reserve_stock = ?, updated_at = NOW() WHERE id = ?");
                    $upd->bind_param("iii", $newStock, $newReserve, $med['id']);
                    $upd->execute();
                    recordStockMovement($conn, $med, 'adjustment', 0, $totalAfter, "Loaded {$loadQty} unit(s) into channel " . (int)$med['channel'] . ' from reserve', null, null);
                    log_activity('load_machine', 'inventory', "Loaded {$loadQty} unit(s) of {$med['name']} ({$sku}) into channel " . (int)$med['channel'] . ".", 'notice', 'medication', $sku);
                    $adminMsg = "Loaded {$loadQty} unit(s) of {$med['name']} into the machine (channel " . (int)$med['channel'] . ").";
                }
            }
        }

        // ---------- Manual write-off (damaged/spoiled/expired stock) ----------
        if (isset($_POST['writeoff'])) {
            $sku      = trim($_POST['sku'] ?? '');
            $writeQty = max(1, (int)($_POST['qty'] ?? 0));
            $reason   = trim($_POST['reason'] ?? '');

            if ($sku === '' || $writeQty < 1) {
                $adminError = 'SKU and quantity are required for a write-off.';
            } else {
                $stmt = $conn->prepare("SELECT id, name, sku, stock, reserve_stock FROM medications WHERE sku = ?");
                $stmt->bind_param("s", $sku);
                $stmt->execute();
                $med = $stmt->get_result()->fetch_assoc();
                if (!$med) {
                    $adminError = "SKU {$sku} was not found in inventory.";
                } elseif (((int)$med['stock'] + (int)$med['reserve_stock']) < 1) {
                    $adminError = "{$med['name']} ({$sku}) already has no stock to write off.";
                } else {
                    $totalOnHand = (int)$med['stock'] + (int)$med['reserve_stock'];
                    $writeQty    = min($writeQty, $totalOnHand);
                    $fromMachine = min($writeQty, (int)$med['stock']);
                    $fromReserve = $writeQty - $fromMachine;
                    $newStock    = (int)$med['stock'] - $fromMachine;
                    $newReserve  = (int)$med['reserve_stock'] - $fromReserve;
                    $totalAfter  = $newStock + $newReserve;
                    $upd = $conn->prepare("UPDATE medications SET stock = ?, reserve_stock = ? WHERE id = ?");
                    $upd->bind_param("iii", $newStock, $newReserve, $med['id']);
                    $upd->execute();
                    recordStockMovement($conn, $med, 'writeoff', -$writeQty, $totalAfter, $reason !== '' ? $reason : 'Write-off', null, null);
                    log_activity('writeoff', 'inventory', "Wrote off {$writeQty} unit(s) of {$med['name']} ({$sku})" . ($reason !== '' ? ": {$reason}" : '.') . ' Total stock updated.', 'warning', 'stock_movement', $sku);
                    $adminMsg = "Write-off recorded: {$writeQty} unit(s) of {$med['name']} removed (machine " . $fromMachine . ", reserve " . $fromReserve . ").";
                }
            }
        }

        // ---------- Write off the full stock of an EXPIRED item ----------
        if (isset($_POST['writeoff_expired'])) {
            $id = (int)$_POST['med_id'];
            $stmt = $conn->prepare(
                "SELECT id, name, sku, stock, reserve_stock, expiry_date FROM medications
                 WHERE id = ? AND expiry_date IS NOT NULL AND expiry_date < CURDATE()"
            );
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $med = $stmt->get_result()->fetch_assoc();
            if (!$med) {
                $adminError = 'Item not found or is not expired.';
            } elseif (((int)$med['stock'] + (int)$med['reserve_stock']) < 1) {
                $adminMsg = "{$med['name']} ({$med['sku']}) already has no stock.";
            } else {
                $writeQty = (int)$med['stock'] + (int)$med['reserve_stock'];
                $upd = $conn->prepare("UPDATE medications SET stock = 0, reserve_stock = 0 WHERE id = ?");
                $upd->bind_param("i", $id);
                $upd->execute();
                recordStockMovement($conn, $med, 'writeoff', -$writeQty, 0, "Expired on {$med['expiry_date']}", null, null);
                log_activity('writeoff_expired', 'inventory', "Wrote off {$writeQty} unit(s) of expired {$med['name']} ({$med['sku']}).", 'warning', 'stock_movement', $med['sku']);
                $adminMsg = "Expired stock written off: {$writeQty} unit(s) of {$med['name']}.";
            }
        }

        }
}

// ---------- Fetch data for dashboard ----------
$lowStock = LOW_STOCK_THRESHOLD;
$stats = [
    'users'      => (int)$conn->query("SELECT COUNT(*) AS c FROM users")->fetch_assoc()['c'],
    'admins'     => (int)$conn->query("SELECT COUNT(*) AS c FROM users WHERE role = 'admin'")->fetch_assoc()['c'],
    'meds'       => (int)$conn->query("SELECT COUNT(*) AS c FROM medications")->fetch_assoc()['c'],
    'low_stock'  => (int)$conn->query("SELECT COUNT(*) AS c FROM medications WHERE stock < {$lowStock}")->fetch_assoc()['c'],
    'dispenses'  => (int)$conn->query("SELECT COUNT(*) AS c FROM dispense_history")->fetch_assoc()['c'],
    'expired'    => (int)$conn->query("SELECT COUNT(*) AS c FROM medications WHERE expiry_date IS NOT NULL AND expiry_date < CURDATE()")->fetch_assoc()['c'],
    'expiring'   => (int)$conn->query("SELECT COUNT(*) AS c FROM medications WHERE expiry_date IS NOT NULL AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)")->fetch_assoc()['c'],
    'to_reorder' => (int)$conn->query("SELECT COUNT(*) AS c FROM medications WHERE (stock + reserve_stock) <= reorder_level")->fetch_assoc()['c'],
];

// Items that are low in the machine, need reordering, or are expiring soon.
$alerts = $conn->query(
    "SELECT name, sku, stock, reserve_stock, expiry_date, reorder_level FROM medications
     WHERE stock < {$lowStock}
        OR (stock + reserve_stock) <= reorder_level
        OR (expiry_date IS NOT NULL AND expiry_date < DATE_ADD(CURDATE(), INTERVAL 90 DAY))
     ORDER BY ((stock + reserve_stock) <= reorder_level) DESC, (stock < {$lowStock}) DESC, expiry_date IS NULL, expiry_date ASC LIMIT 12"
);

$users = $conn->query("SELECT id, fname, lname, email, role, created_at FROM users ORDER BY id ASC");
$medications = $conn->query("SELECT * FROM medications ORDER BY channel ASC, id ASC");
$logs = $conn->query("SELECT * FROM dispense_history ORDER BY dispensed_at DESC LIMIT 20");
$auditLogs = $conn->query("SELECT * FROM audit_log ORDER BY created_at DESC LIMIT 25");

// Inventory-control datasets for the reordering / cycle count / movement tabs.
$suppliers = [];
$supResult = $conn->query("SELECT DISTINCT supplier FROM medications WHERE supplier IS NOT NULL AND supplier != '' ORDER BY supplier");
if ($supResult) { while ($s = $supResult->fetch_assoc()) $suppliers[] = $s['supplier']; }

$needsReorder = $conn->query("SELECT * FROM medications WHERE (stock + reserve_stock) <= reorder_level ORDER BY ((stock + reserve_stock) - reorder_level) ASC, name ASC");
$loadable = $conn->query("SELECT id, name, sku, channel, stock, reserve_stock FROM medications WHERE reserve_stock > 0 ORDER BY channel ASC, name ASC");
$movements = $conn->query("SELECT * FROM stock_movements ORDER BY created_at DESC, id DESC LIMIT 60");
$expiredItems = $conn->query("SELECT id, name, sku, stock, reserve_stock, expiry_date, lot_number FROM medications WHERE expiry_date IS NOT NULL AND expiry_date < CURDATE() ORDER BY expiry_date ASC");
$pendingPurchases = $conn->query("SELECT * FROM purchases WHERE status = 'pending' ORDER BY created_at ASC, id ASC");
$purchaseHistory = $conn->query("SELECT * FROM purchases WHERE status IN ('confirmed', 'cancelled') ORDER BY resolved_at DESC, id DESC LIMIT 60");

// 7-day dispensing trend for the chart.
$trend = ['dates' => [], 'counts' => [], 'units' => []];
$trendMap = [];
$qTrend = $conn->query(
    "SELECT DATE(dispensed_at) AS d, COUNT(*) AS c, COALESCE(SUM(qty), 0) AS u
     FROM dispense_history
     WHERE dispensed_at >= (CURDATE() - INTERVAL 6 DAY)
     GROUP BY DATE(dispensed_at)"
);
if ($qTrend) {
    while ($r = $qTrend->fetch_assoc()) {
        $trendMap[$r['d']] = $r;
    }
}
for ($i = 6; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-$i days"));
    $trend['dates'][] = date('M j', strtotime($day));
    $trend['counts'][] = (int)($trendMap[$day]['c'] ?? 0);
    $trend['units'][]  = (int)($trendMap[$day]['u'] ?? 0);
}

// Fetch Arduino / bridge status (best-effort; bridge may be offline).
$arduinoStatus = null;
$bridgeUp = @file_get_contents('http://localhost:3000/status', false, stream_context_create(['http' => ['timeout' => 2]]));
if ($bridgeUp !== false) {
    $decoded = json_decode($bridgeUp, true);
    if (is_array($decoded)) $arduinoStatus = $decoded;
}

// Toast for action feedback (shown via ui.js once the page loads).
$toastJson = 'null';
if ($adminError !== '') {
    $toastJson = json_encode(['type' => 'error', 'message' => $adminError]);
} elseif ($adminMsg !== '') {
    $toastJson = json_encode(['type' => 'success', 'message' => $adminMsg]);
}

$includeCharts = true;
$pageTitle = 'Admin Dashboard | ' . SYSTEM_NAME_FULL;
include("includes/site-header.php");
?>

<?php
$arduinoConnected = $arduinoStatus['connected'] ?? false;
$arduinoReady     = $arduinoStatus['ready'] ?? null; // null = not reported
$arduinoHealthy   = $arduinoConnected && $arduinoReady !== false;
$arduinoError    = $arduinoStatus['error'] ?? null;
$arduinoPort     = $arduinoStatus['port'] ?? null;
?>
<div id="arduinoStatusMsg" class="message <?= $arduinoHealthy ? 'success' : 'error' ?>" style="max-width:none;" data-port="<?= htmlspecialchars($arduinoPort ?? '') ?>">
    <strong>Dispenser hardware:</strong>
    <span id="arduinoStatusText">
    <?php if ($arduinoStatus === null): ?>
        🔌 Arduino bridge is OFFLINE (start it with <code>node server.js</code> in <code>arduino-bridge/</code>).
    <?php elseif ($arduinoConnected && $arduinoReady === false): ?>
        ⚠️ Arduino is connected<?= $arduinoPort ? ' on ' . htmlspecialchars($arduinoPort) : '' ?>, but the <strong>PCA9685 servo driver is NOT responding</strong>. Nothing can be dispensed. <?= htmlspecialchars($arduinoError ?? 'Check the servo driver wiring and 5V supply.') ?>
    <?php elseif ($arduinoConnected): ?>
        ✅ Arduino is <strong>CONNECTED</strong><?= $arduinoPort ? ' on ' . htmlspecialchars($arduinoPort) : '' ?> — ready to dispense.
    <?php else: ?>
        ⚠️ Arduino is <strong>NOT connected</strong>. <?= htmlspecialchars($arduinoError ?? 'Plug in the USB cable.') ?>
    <?php endif; ?>
    </span>
</div>
<script>
(function () {
    const statusEl = document.getElementById('arduinoStatusMsg');
    const textEl = document.getElementById('arduinoStatusText');
    async function refreshStatus() {
        try {
            const res = await fetch('arduino-status.php', { cache: 'no-store' });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const data = await res.json();
            if (data.connected && data.ready === false) {
                statusEl.className = 'message error';
                const err = data.error || 'Check the servo driver wiring and 5V supply.';
                textEl.innerHTML = '⚠️ Arduino is connected' + (data.port ? ' on ' + data.port : '')
                    + ', but the <strong>PCA9685 servo driver is NOT responding</strong>. Nothing can be dispensed. ' + err;
            } else if (data.connected) {
                statusEl.className = 'message success';
                textEl.innerHTML = '✅ Arduino is <strong>CONNECTED</strong>' + (data.port ? ' on ' + data.port : '') + ' — ready to dispense.';
            } else {
                statusEl.className = 'message error';
                const err = data.error || 'Plug in the USB cable.';
                textEl.innerHTML = '⚠️ Arduino is <strong>NOT connected</strong>. ' + err;
            }
        } catch (e) {
            statusEl.className = 'message error';
            textEl.innerHTML = '🔌 Arduino bridge is OFFLINE (start it with <code>node server.js</code> in <code>arduino-bridge/</code>).';
        }
    }
    // Poll every 4s and do an initial refresh shortly after load to keep server-side message visible first
    setInterval(refreshStatus, 4000);
    setTimeout(refreshStatus, 1500);
})();
</script>

<div class="admin-head">
    <div class="admin-title-block">
        <h1 class="page-title">Admin Dashboard</h1>
        <p class="page-subtitle">Manage users, medication inventory, and dispensing activity.</p>
    </div>
    <div class="quick-nav">
        <button type="button" class="quick-nav-toggle" id="quickNavToggle" aria-haspopup="true" aria-expanded="false">
            Quick links
            <span class="nav-drop-burger" aria-hidden="true">☰</span>
        </button>
        <div class="quick-nav-menu" role="menu" aria-label="Quick links">
            <a href="admin.php" role="menuitem" class="<?= ($currentPage ?? '') === 'admin.php' ? 'active' : '' ?>">Admin Dashboard</a>
            <a href="support-tickets.php" role="menuitem" class="<?= ($currentPage ?? '') === 'support-tickets.php' ? 'active' : '' ?>">Tickets</a>
            <a href="reports.php" role="menuitem" class="<?= ($currentPage ?? '') === 'reports.php' ? 'active' : '' ?>">Reports</a>
        </div>
    </div>
</div>

<!-- Tab navigation -->
<div class="admin-tabs" data-tabs role="tablist" aria-label="Admin sections">
    <button type="button" class="admin-tab active" data-tab-target="tab-overview" role="tab" aria-selected="true">Overview</button>
    <button type="button" class="admin-tab" data-tab-target="tab-users" role="tab" aria-selected="false">Users</button>
    <button type="button" class="admin-tab" data-tab-target="tab-inventory" role="tab" aria-selected="false">Inventory</button>
    <button type="button" class="admin-tab" data-tab-target="tab-reorder" role="tab" aria-selected="false">Reordering</button>
    <button type="button" class="admin-tab" data-tab-target="tab-movements" role="tab" aria-selected="false">Movements</button>
    <button type="button" class="admin-tab" data-tab-target="tab-logs" role="tab" aria-selected="false">Dispense Logs</button>
    <button type="button" class="admin-tab" data-tab-target="tab-audit" role="tab" aria-selected="false">Audit Log</button>
</div>

<!-- Inventory alert banner -->
<?php if ($alerts && $alerts->num_rows > 0): ?>
<div class="message warn" style="max-width:none;margin-top:18px;">
    <strong>Inventory alerts: <?= $stats['low_stock'] ?> low in machine, <?= $stats['expiring'] ?> expiring soon, <?= $stats['expired'] ?> expired.</strong>
    <ul style="margin:8px 0 0 20px;line-height:1.7;">
        <?php while ($a = $alerts->fetch_assoc()):
            $flag = '';
            $aStock   = (int)$a['stock'];
            $aReserve = (int)$a['reserve_stock'];
            if ($aStock < $lowStock) $flag .= '⚠️ Machine low (' . $aStock . ' in bin' . ($aReserve ? ", {$aReserve} in reserve" : '') . ')';
            if (($aStock + $aReserve) <= (int)$a['reorder_level']) $flag .= ($flag ? ' · ' : '') . '🛒 Reorder (total ' . ($aStock + $aReserve) . ')';
            if (!empty($a['expiry_date']) && $a['expiry_date'] < date('Y-m-d')) $flag .= ($flag ? ' · ' : '') . '🚫 EXPIRED ' . htmlspecialchars($a['expiry_date']);
            elseif (!empty($a['expiry_date']) && $a['expiry_date'] <= date('Y-m-d', strtotime('+90 days'))) $flag .= ($flag ? ' · ' : '') . '⏳ Expires ' . htmlspecialchars($a['expiry_date']);
        ?>
        <li><strong><?= htmlspecialchars($a['name']) ?></strong> (<?= htmlspecialchars($a['sku']) ?>) — <?= $flag ?: '—' ?></li>
        <?php endwhile; ?>
    </ul>
</div>
<?php endif; ?>

<!-- ============ OVERVIEW ============ -->
<section class="admin-tab-panel active" id="tab-overview" data-tab-panel role="tabpanel">
    <div class="card chart-card">
        <h3>Dispensing activity — last 7 days</h3>
        <p class="chart-empty" id="trendChartEmpty">Chart library failed to load. Connect to the internet to view charts.</p>
        <div class="chart-wrap"><canvas id="trendChart" aria-label="Line chart of dispensing activity over the last 7 days" role="img"></canvas></div>
    </div>

    <div class="admin-stats">
        <div class="stat-card">
            <p class="stat-label">Total users</p>
            <p class="stat-value"><?= $stats['users'] ?></p>
            <p class="stat-hint"><?= $stats['admins'] ?> administrator(s)</p>
        </div>
        <div class="stat-card">
            <p class="stat-label">Medications</p>
            <p class="stat-value"><?= $stats['meds'] ?></p>
            <p class="stat-hint">Inventory items</p>
        </div>
        <div class="stat-card">
            <p class="stat-label">Low in machine</p>
            <p class="stat-value stat-warn"><?= $stats['low_stock'] ?></p>
            <p class="stat-hint">Below 20 units in bin</p>
        </div>
        <div class="stat-card">
            <p class="stat-label">To reorder</p>
            <p class="stat-value <?= $stats['to_reorder'] > 0 ? 'stat-warn' : 'stat-ok' ?>"><?= $stats['to_reorder'] ?></p>
            <p class="stat-hint">Total at or below reorder level</p>
        </div>
        <div class="stat-card">
            <p class="stat-label">Expiring 90 days</p>
            <p class="stat-value <?= ($stats['expiring'] + $stats['expired']) > 0 ? 'stat-warn' : 'stat-ok' ?>"><?= $stats['expiring'] + $stats['expired'] ?></p>
            <p class="stat-hint"><?= $stats['expired'] ?> already expired</p>
        </div>
        <div class="stat-card">
            <p class="stat-label">Total dispensed</p>
            <p class="stat-value stat-ok"><?= $stats['dispenses'] ?></p>
            <p class="stat-hint">All-time dispensing count</p>
        </div>
    </div>

    <div class="card" style="margin-top:20px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
        <div>
            <h3 style="margin:0 0 4px;">Database backup</h3>
            <p style="margin:0;color:var(--text-muted);font-size:0.875rem;">Download a full SQL dump of all tables (users, inventory, logs).</p>
        </div>
        <a href="db_backup.php" class="btn-primary" style="text-decoration:none;">⬇ Download backup (.sql)</a>
    </div>
</section>

<!-- ============ USERS ============ -->
<section class="admin-tab-panel" id="tab-users" data-tab-panel role="tabpanel">
    <h2 class="admin-section-title">User Management</h2>
    <div class="card">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($u = $users->fetch_assoc()):
                    $isSelf = (int)$u['id'] === (int)($_SESSION['user_id'] ?? -1);
                    $isAdmin = $u['role'] === 'admin';
                ?>
                <tr>
                    <td data-label="ID"><?= (int)$u['id'] ?></td>
                    <td data-label="Name"><?= htmlspecialchars($u['fname'] . ' ' . $u['lname']) ?> <?= $isSelf ? '(you)' : '' ?></td>
                    <td data-label="Email"><?= htmlspecialchars($u['email']) ?></td>
                    <td data-label="Role">
                        <span class="status-badge <?= $isAdmin ? 'badge-ok' : 'badge-warn' ?>"><?= $isAdmin ? 'Admin' : 'Staff' ?></span>
                    </td>
                    <td data-label="Created"><?= htmlspecialchars($u['created_at']) ?></td>
                    <td data-label="Actions" class="table-actions">
                        <?php if ($isAdmin && !$isSelf): ?>
                            <form method="post" class="inline-form">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                <button type="submit" name="demote_user" class="btn-mini btn-warn">Demote</button>
                            </form>
                        <?php elseif (!$isAdmin): ?>
                            <form method="post" class="inline-form">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                <button type="submit" name="promote_user" class="btn-mini btn-ok">Promote</button>
                            </form>
                        <?php endif; ?>
                        <?php if (!$isSelf): ?>
                            <form method="post" class="inline-form" data-confirm="Delete this user? This action cannot be undone.">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                <button type="submit" name="delete_user" class="btn-mini btn-danger">Delete</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- ============ INVENTORY ============ -->
<section class="admin-tab-panel" id="tab-inventory" data-tab-panel role="tabpanel">
    <h2 class="admin-section-title">Medication Management</h2>
    <div class="card">
        <h3 class="admin-sub-title">Add Medication</h3>
        <form method="post" class="admin-med-form">
            <?php csrf_field(); ?>
            <input type="hidden" name="icon" value="&#128138;">
            <div class="form-group"><label>Channel #</label><input type="number" name="channel" value="0" min="0" max="5"></div>
            <div class="form-group"><label>Name</label><input type="text" name="name" required></div>
            <div class="form-group"><label>SKU</label><input type="text" name="sku" required></div>
            <div class="form-group"><label>Price (₱)</label><input type="number" step="0.01" name="price" value="0" min="0"></div>
            <div class="form-group"><label>Stock in machine</label><input type="number" name="stock" value="0" min="0"></div>
            <div class="form-group"><label>Reserve stock (storage)</label><input type="number" name="reserve_stock" value="0" min="0"></div>
            <div class="form-group"><label>Expiry date</label><input type="date" name="expiry_date"></div>
            <div class="form-group"><label>Lot number</label><input type="text" name="lot_number" placeholder="e.g. LOT-PCM-2601"></div>
            <div class="form-group"><label>Category</label>
                <select name="category">
                    <option>Tablet</option><option>Capsule</option><option>Inhaler</option><option>Supplement</option><option>Supply</option>
                </select>
            </div>
            <div class="form-group"><button type="submit" name="add_med" class="btn-primary">Add Medication</button></div>
        </form>
    </div>

    <div class="card">
        <h3 class="admin-sub-title">Inventory</h3>
        <table class="admin-table">
            <thead>
                <tr>
                    <th>CH</th><th>Name</th><th>SKU</th><th>Price</th><th>Machine stock</th><th>Reserve</th><th>Expiry</th><th>Lot #</th><th>Category</th><th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($m = $medications->fetch_assoc()):
                    $updateFormId = 'update-med-' . (int)$m['id'];
                    $mStock = (int)$m['stock'];
                    $mBarClass = $mStock < 1 ? 'bar-out' : ($mStock < $lowStock ? 'bar-warn' : 'bar-ok');
                    $mBarPct = $mStock < 1 ? 8 : min(STOCK_REFERENCE, $mStock);
                    $mExpiryClass = '';
                    if (!empty($m['expiry_date'])) {
                        if ($m['expiry_date'] < date('Y-m-d')) $mExpiryClass = 'text-danger';
                        elseif ($m['expiry_date'] <= date('Y-m-d', strtotime('+30 days'))) $mExpiryClass = 'text-danger';
                        elseif ($m['expiry_date'] <= date('Y-m-d', strtotime('+90 days'))) $mExpiryClass = 'text-warn';
                    }
                ?>
                <tr>
                    <td data-label="Channel">
                        <input type="number" name="channel" value="<?= (int)$m['channel'] ?>" min="0" max="5" class="mini-input" title="Channel" form="<?= $updateFormId ?>">
                    </td>
                    <td data-label="Name">
                        <input type="text" name="name" value="<?= htmlspecialchars($m['name']) ?>" class="mini-input" title="Name" required form="<?= $updateFormId ?>">
                    </td>
                    <td data-label="SKU">
                        <input type="text" name="sku" value="<?= htmlspecialchars($m['sku']) ?>" class="mini-input" title="SKU" required form="<?= $updateFormId ?>">
                    </td>
                    <td data-label="Price">
                        <input type="number" step="0.01" name="price" value="<?= htmlspecialchars($m['price']) ?>" min="0" class="mini-input" title="Price" form="<?= $updateFormId ?>">
                    </td>
                    <td data-label="Machine stock">
                        <input type="number" name="stock" value="<?= $mStock ?>" min="0" class="mini-input" title="Machine stock" form="<?= $updateFormId ?>">
                        <div class="mini-stock-bar" aria-hidden="true">
                            <div class="stock-bar-fill <?= $mBarClass ?>" style="width:<?= $mBarPct ?>%"></div>
                        </div>
                    </td>
                    <td data-label="Reserve">
                        <input type="number" name="reserve_stock" value="<?= (int)$m['reserve_stock'] ?>" min="0" class="mini-input" title="Reserve stock (storage)" form="<?= $updateFormId ?>">
                    </td>
                    <td data-label="Expiry">
                        <input type="date" name="expiry_date" value="<?= htmlspecialchars($m['expiry_date'] ?? '') ?>" class="mini-input <?= $mExpiryClass ?>" title="Expiry date" form="<?= $updateFormId ?>">
                    </td>
                    <td data-label="Lot #">
                        <input type="text" name="lot_number" value="<?= htmlspecialchars($m['lot_number'] ?? '') ?>" class="mini-input" title="Lot number" form="<?= $updateFormId ?>">
                    </td>
                    <td data-label="Category">
                        <select name="category" class="mini-input" title="Category" form="<?= $updateFormId ?>">
                            <?php foreach (['Tablet','Capsule','Inhaler','Supplement','Supply'] as $cat): ?>
                                <option value="<?= $cat ?>" <?= $m['category'] === $cat ? 'selected' : '' ?>><?= $cat ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td data-label="Actions" class="table-actions">
                        <form method="post" class="inline-form" id="<?= $updateFormId ?>">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="med_id" value="<?= (int)$m['id'] ?>">
                            <button type="submit" name="update_med" class="btn-mini btn-ok">Update</button>
                        </form>
                        <form method="post" class="inline-form" data-confirm="Delete this medication from inventory? This action cannot be undone.">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="med_id" value="<?= (int)$m['id'] ?>">
                            <button type="submit" name="delete_med" class="btn-mini btn-danger">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- ============ REORDERING ============ -->
<section class="admin-tab-panel" id="tab-reorder" data-tab-panel role="tabpanel">
    <h2 class="admin-section-title">Reordering &amp; Purchasing</h2>
    <p class="page-subtitle">Set per-item reorder levels, view suggested orders, and record received stock. Purchases are saved as pending and added to <strong>reserve stock</strong> after you confirm them — load units into the machine right below.</p>

    <div class="card" id="recordPurchaseCard">
        <h3 class="admin-sub-title">Record purchase / stock-in</h3>
        <p class="page-subtitle">New purchases are stored as <strong>pending</strong> — the units are added to <strong>reserve stock</strong> after you confirm them, then loaded into the machine.</p>
        <form method="post" class="admin-med-form">
            <?php csrf_field(); ?>
            <div class="form-group">
                <label>SKU</label>
                <input type="text" name="sku" id="purchaseSku" list="skuList" required placeholder="e.g. MED-001">
                <datalist id="skuList">
                    <?php $skuList = $conn->query("SELECT sku, name FROM medications ORDER BY channel ASC, id ASC");
                          if ($skuList) { while ($sk = $skuList->fetch_assoc()): ?>
                        <option value="<?= htmlspecialchars($sk['sku']) ?>"><?= htmlspecialchars($sk['name']) ?></option>
                    <?php endwhile; } ?>
                </datalist>
            </div>
            <div class="form-group"><label>Qty received</label><input type="number" name="qty" id="purchaseQty" value="0" min="1" required></div>
            <div class="form-group">
                <label>Supplier</label>
                <input type="text" name="supplier" id="purchaseSupplier" list="supplierList" placeholder="e.g. PharmaSource, Inc.">
                <datalist id="supplierList">
                    <?php foreach ($suppliers as $sp): ?>
                        <option value="<?= htmlspecialchars($sp) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div class="form-group"><label>Reference (PO / invoice)</label><input type="text" name="reference" placeholder="e.g. PO-20260921"></div>
            <div class="form-group"><label>New expiry (optional)</label><input type="date" name="expiry_date"></div>
            <div class="form-group"><label>New lot number (optional)</label><input type="text" name="lot_number" placeholder="e.g. LOT-PCM-2602"></div>
            <div class="form-group"><button type="submit" name="record_purchase" class="btn-primary">Record Purchase (pending)</button></div>
        </form>
    </div>

    <div class="card" id="loadMachineCard">
        <h3 class="admin-sub-title">Load reserve stock into the machine</h3>
        <p class="page-subtitle">Move units from storage (<strong>reserve</strong>) into the dispenser bin (<strong>machine stock</strong>). The item must already have a channel (1–5).</p>
        <form method="post" class="admin-med-form">
            <?php csrf_field(); ?>
            <div class="form-group">
                <label>SKU</label>
                <input type="text" name="sku" id="loadSku" list="skuList" required placeholder="e.g. MED-001">
            </div>
            <div class="form-group"><label>Qty to load</label><input type="number" name="qty" id="loadQty" value="1" min="1" required></div>
            <div class="form-group"><button type="submit" name="load_machine" class="btn-primary">Load into machine</button></div>
        </form>
        <?php if ($loadable && $loadable->num_rows > 0): ?>
            <table class="admin-table" style="margin-top:14px;">
                <thead>
                    <tr><th>CH</th><th>Name</th><th>SKU</th><th>Machine</th><th>Reserve</th><th>Load</th></tr>
                </thead>
                <tbody>
                    <?php while ($ld = $loadable->fetch_assoc()):
                        $ldChannel = (int)$ld['channel'];
                        $ldValid   = $ldChannel >= 1 && $ldChannel <= 5;
                    ?>
                    <tr>
                        <td><?= $ldChannel > 0 ? $ldChannel : '<span class="status-badge badge-out">—</span>' ?></td>
                        <td><?= htmlspecialchars($ld['name']) ?></td>
                        <td><code style="font-size:0.75rem;color:var(--text-muted);"><?= htmlspecialchars($ld['sku']) ?></code></td>
                        <td><?= (int)$ld['stock'] ?></td>
                        <td><strong><?= (int)$ld['reserve_stock'] ?></strong></td>
                        <td>
                            <button type="button" class="btn-mini btn-ok" data-load-sku="<?= htmlspecialchars($ld['sku']) ?>" data-load-qty="<?= (int)$ld['reserve_stock'] ?>" <?= $ldValid ? '' : 'disabled title="Assign a channel (1-5) first"' ?>>Load all</button>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="cart-empty">No reserve stock to load. Confirm a pending purchase to top up the reserve.</p>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3 class="admin-sub-title">Pending purchases — awaiting confirmation</h3>
        <?php if ($pendingPurchases && $pendingPurchases->num_rows > 0): ?>
            <div style="overflow-x:auto;">
            <table class="admin-table">
                <thead>
                    <tr><th>#</th><th>Item</th><th>Qty</th><th>Supplier</th><th>Reference</th><th>Expiry / Lot</th><th>Requested</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php while ($pp = $pendingPurchases->fetch_assoc()): ?>
                    <tr>
                        <td><?= (int)$pp['id'] ?></td>
                        <td>
                            <?= htmlspecialchars($pp['medication_name']) ?>
                            <br><code style="font-size:0.75rem;color:var(--text-muted);"><?= htmlspecialchars($pp['sku']) ?></code>
                        </td>
                        <td><strong><?= (int)$pp['qty'] ?></strong></td>
                        <td><?= htmlspecialchars($pp['supplier'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($pp['reference'] ?? '—') ?></td>
                        <td>
                            <?= $pp['expiry_date'] ? 'Exp ' . htmlspecialchars($pp['expiry_date']) : '' ?>
                            <?= $pp['lot_number'] ? (($pp['expiry_date'] ? ' · ' : '') . 'Lot ' . htmlspecialchars($pp['lot_number'])) : '' ?>
                            <?= (!$pp['expiry_date'] && !$pp['lot_number']) ? '—' : '' ?>
                        </td>
                        <td>
                            <?= htmlspecialchars($pp['requested_by'] ?? '—') ?>
                            <?php if (!empty($pp['created_at'])): ?><span style="display:block;font-size:0.75rem;color:var(--text-muted);"><?= date('M j, g:i A', strtotime($pp['created_at'])) ?></span><?php endif; ?>
                        </td>
                        <td class="table-actions">
                            <form method="post" class="inline-form" data-confirm="Add <?= (int)$pp['qty'] ?> unit(s) of <?= htmlspecialchars($pp['medication_name']) ?> to reserve stock now? Use 'Load into machine' afterwards to make them available in the dispenser.">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="purchase_id" value="<?= (int)$pp['id'] ?>">
                                <button type="submit" name="confirm_purchase" class="btn-mini btn-ok">Confirm &amp; add to reserve</button>
                            </form>
                            <form method="post" class="inline-form" data-confirm="Cancel this pending purchase? Stock will not be changed.">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="purchase_id" value="<?= (int)$pp['id'] ?>">
                                <button type="submit" name="cancel_purchase" class="btn-mini btn-danger">Cancel</button>
                            </form>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
            </div>
        <?php else: ?>
            <p class="cart-empty">No pending purchases. New stock-ins appear here for confirmation before they are added to reserve inventory.</p>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3 class="admin-sub-title">Suggested orders — at or below reorder level</h3>
        <?php if ($needsReorder && $needsReorder->num_rows > 0): ?>
            <table class="admin-table">
                <thead>
                    <tr><th>Name</th><th>SKU</th><th>Machine</th><th>Reserve</th><th>Total</th><th>Reorder level</th><th>Suggested qty</th><th>Supplier</th><th>Fill form</th></tr>
                </thead>
                <tbody>
                    <?php while ($nr = $needsReorder->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars($nr['name']) ?></td>
                        <td><?= htmlspecialchars($nr['sku']) ?></td>
                        <td><?= (int)$nr['stock'] ?></td>
                        <td><?= (int)$nr['reserve_stock'] ?></td>
                        <td><span class="status-badge badge-warn"><?= (int)$nr['stock'] + (int)$nr['reserve_stock'] ?> on hand</span></td>
                        <td><?= (int)$nr['reorder_level'] ?></td>
                        <td><strong><?= (int)$nr['reorder_qty'] ?></strong></td>
                        <td><?= htmlspecialchars($nr['supplier'] ?? '—') ?></td>
                        <td>
                            <button type="button" class="btn-mini btn-ok" data-fill-sku="<?= htmlspecialchars($nr['sku']) ?>" data-fill-qty="<?= (int)$nr['reorder_qty'] ?>" data-fill-supplier="<?= htmlspecialchars($nr['supplier'] ?? '') ?>">Use</button>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="cart-empty">✅ All items are above their reorder levels.</p>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3 class="admin-sub-title">Order history — confirmed &amp; cancelled</h3>
        <?php if ($purchaseHistory && $purchaseHistory->num_rows > 0): ?>
            <div style="overflow-x:auto;">
            <table class="admin-table">
                <thead>
                    <tr><th>#</th><th>Item</th><th>Qty</th><th>Supplier</th><th>Reference</th><th>Status</th><th>Requested</th><th>Resolved</th></tr>
                </thead>
                <tbody>
                    <?php while ($ph = $purchaseHistory->fetch_assoc()): ?>
                    <tr>
                        <td><?= (int)$ph['id'] ?></td>
                        <td>
                            <?= htmlspecialchars($ph['medication_name']) ?>
                            <br><code style="font-size:0.75rem;color:var(--text-muted);"><?= htmlspecialchars($ph['sku']) ?></code>
                        </td>
                        <td><strong><?= (int)$ph['qty'] ?></strong></td>
                        <td><?= htmlspecialchars($ph['supplier'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($ph['reference'] ?? '—') ?></td>
                        <td><span class="status-badge <?= $ph['status'] === 'confirmed' ? 'badge-ok' : 'badge-out' ?>"><?= htmlspecialchars($ph['status']) ?></span></td>
                        <td>
                            <?= htmlspecialchars($ph['requested_by'] ?? '—') ?>
                            <?php if (!empty($ph['created_at'])): ?><span style="display:block;font-size:0.75rem;color:var(--text-muted);"><?= date('M j, g:i A', strtotime($ph['created_at'])) ?></span><?php endif; ?>
                        </td>
                        <td>
                            <?= htmlspecialchars($ph['resolved_by'] ?? '—') ?>
                            <?php if (!empty($ph['resolved_at'])): ?><span style="display:block;font-size:0.75rem;color:var(--text-muted);"><?= date('M j, g:i A', strtotime($ph['resolved_at'])) ?></span><?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
            </div>
        <?php else: ?>
            <p class="cart-empty">No confirmed or cancelled orders yet. Confirm a pending purchase to see it here.</p>
        <?php endif; ?>
    </div>
</section>

<!-- ============ STOCK MOVEMENTS / WRITE-OFFS ============ -->
<section class="admin-tab-panel" id="tab-movements" data-tab-panel role="tabpanel">
    <h2 class="admin-section-title">Stock Movements &amp; Write-offs</h2>
    <p class="page-subtitle">The full audit trail of every stock change: purchases coming in, sales and write-offs going out, and cycle-count adjustments.</p>

    <div class="card">
        <h3 class="admin-sub-title">Manual write-off (damaged / spoiled / returned)</h3>
        <form method="post" class="admin-med-form">
            <?php csrf_field(); ?>
            <div class="form-group">
                <label>SKU</label>
                <input type="text" name="sku" list="skuListMv" required placeholder="e.g. MED-001">
                <datalist id="skuListMv">
                    <?php $mvSkuList = $conn->query("SELECT sku, name FROM medications ORDER BY channel ASC, id ASC");
                          if ($mvSkuList) { while ($mvSk = $mvSkuList->fetch_assoc()): ?>
                        <option value="<?= htmlspecialchars($mvSk['sku']) ?>"><?= htmlspecialchars($mvSk['name']) ?></option>
                    <?php endwhile; } ?>
                </datalist>
            </div>
            <div class="form-group"><label>Qty to write off</label><input type="number" name="qty" value="1" min="1" required></div>
            <div class="form-group write-off-reason"><label>Reason</label><input type="text" name="reason" placeholder="e.g. damaged packaging" required></div>
            <div class="form-group"><button type="submit" name="writeoff" class="btn-primary btn-warn-solid">Record Write-off</button></div>
        </form>
    </div>

    <div class="card">
        <h3 class="admin-sub-title">Expired stock — remove from shelf</h3>
        <?php if ($expiredItems && $expiredItems->num_rows > 0): ?>
            <table class="admin-table">
                <thead>
                    <tr><th>Name</th><th>SKU</th><th>Total stock</th><th>Lot #</th><th>Expired on</th><th>Action</th></tr>
                </thead>
                <tbody>
                    <?php while ($ex = $expiredItems->fetch_assoc()):
                        $exTotal = (int)$ex['stock'] + (int)$ex['reserve_stock'];
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($ex['name']) ?></td>
                        <td><?= htmlspecialchars($ex['sku']) ?></td>
                        <td><span class="status-badge badge-out"><?= $exTotal ?></span></td>
                        <td><?= htmlspecialchars($ex['lot_number'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($ex['expiry_date']) ?></td>
                        <td class="table-actions">
                            <form method="post" class="inline-form" data-confirm="Write off ALL stock of this expired medication? This cannot be undone.">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="med_id" value="<?= (int)$ex['id'] ?>">
                                <button type="submit" name="writeoff_expired" class="btn-mini btn-danger" <?= $exTotal < 1 ? 'disabled' : '' ?>>Write off stock</button>
                            </form>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="cart-empty">✅ No expired medications in inventory.</p>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3 class="admin-sub-title">Movement history</h3>
        <?php if ($movements && $movements->num_rows > 0): ?>
            <table class="admin-table">
                <thead>
                    <tr><th>Time</th><th>Type</th><th>Medication</th><th>SKU</th><th>Qty</th><th>Total after</th><th>Reference / Supplier</th><th>Reason</th><th>By</th></tr>
                </thead>
                <tbody>
                    <?php while ($mv = $movements->fetch_assoc()):
                        $mvType = $mv['movement_type'];
                        $badgeCls = $mvType === 'purchase' ? 'badge-ok' : ($mvType === 'writeoff' ? 'badge-out' : ($mvType === 'adjustment' ? 'badge-warn' : ''));
                        $mvQty = (int)$mv['qty'];
                        $mvQtyLabel = $mvQty > 0 ? '+ ' . $mvQty : '− ' . abs($mvQty);
                        $mvQtyCls = $mvQty > 0 ? 'text-ok' : 'text-danger';
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($mv['created_at']) ?></td>
                        <td><span class="status-badge <?= $badgeCls ?>"><?= htmlspecialchars($mvType) ?></span></td>
                        <td><?= htmlspecialchars($mv['medication_name'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($mv['sku'] ?? '—') ?></td>
                        <td class="<?= $mvQtyCls ?>"><?= $mvQtyLabel ?></td>
                        <td><?= (int)$mv['stock_after'] ?></td>
                        <td>
                            <?= htmlspecialchars($mv['reference'] ?? '') ?><?= ($mv['reference'] ?? '') && ($mv['supplier'] ?? '') ? ' · ' : '' ?><?= htmlspecialchars($mv['supplier'] ?? '') ?: '—' ?>
                        </td>
                        <td><?= htmlspecialchars($mv['reason'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($mv['user_email'] ?? '—') ?></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="cart-empty">No stock movements recorded yet. Sales are logged here automatically as items are dispensed.</p>
        <?php endif; ?>
    </div>
</section>

<!-- ============ DISPENSE LOGS ============ -->
<section class="admin-tab-panel" id="tab-logs" data-tab-panel role="tabpanel">
    <h2 class="admin-section-title">Recent Dispensing Log</h2>
    <div class="card">
        <?php if ($logs && $logs->num_rows > 0): ?>
            <table class="admin-table">
                <thead>
                    <tr><th>Time</th><th>User</th><th>Medication</th><th>SKU</th><th>Qty</th></tr>
                </thead>
                <tbody>
                    <?php while ($lg = $logs->fetch_assoc()): ?>
                    <tr>
                        <td data-label="Time"><?= htmlspecialchars($lg['dispensed_at']) ?></td>
                        <td data-label="User"><?= htmlspecialchars($lg['user_email'] ?? '—') ?></td>
                        <td data-label="Medication"><?= htmlspecialchars($lg['medication_name'] ?? '—') ?></td>
                        <td data-label="SKU"><?= htmlspecialchars($lg['medication_sku'] ?? '—') ?></td>
                        <td data-label="Qty"><?= (int)$lg['qty'] ?></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="cart-empty">No dispensing activity yet.</p>
        <?php endif; ?>
    </div>
</section>

<!-- ============ AUDIT LOG ============ -->
<section class="admin-tab-panel" id="tab-audit" data-tab-panel role="tabpanel">
    <div class="page-head">
        <div>
            <h2 class="admin-section-title" style="margin:0;">System Activity</h2>
            <p class="page-subtitle" style="margin:4px 0 0;">The 25 most recent actions across every module. The full log can be filtered, searched and exported.</p>
        </div>
        <a class="btn-primary" href="audit-log.php">Open full activity log</a>
    </div>
    <div class="card">
        <?php if ($auditLogs && $auditLogs->num_rows > 0): ?>
            <table class="admin-table">
                <thead>
                    <tr><th>Time</th><th>User</th><th>Module</th><th>Action</th><th>Details</th><th>Severity</th></tr>
                </thead>
                <tbody>
                    <?php while ($al = $auditLogs->fetch_assoc()):
                        $sev = $al['severity'] ?? 'info';
                        $sevClass = 'badge-' . ($sev === 'critical' ? 'out' : ($sev === 'warning' ? 'warn' : ($sev === 'notice' ? 'alert' : 'ok')));
                    ?>
                    <tr>
                        <td data-label="Time"><?= htmlspecialchars($al['created_at']) ?></td>
                        <td data-label="User"><?= htmlspecialchars($al['user_email'] ?? '—') ?></td>
                        <td data-label="Module"><span class="module-chip"><?= htmlspecialchars(ucfirst($al['module'] ?? 'system')) ?></span></td>
                        <td data-label="Action"><code class="action-code"><?= htmlspecialchars($al['action']) ?></code></td>
                        <td data-label="Details" class="cell-wrap"><?= htmlspecialchars($al['details'] ?? '—') ?></td>
                        <td data-label="Severity"><span class="status-badge <?= $sevClass ?>"><?= htmlspecialchars(ucfirst($sev)) ?></span></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="cart-empty">No activity logged yet.</p>
        <?php endif; ?>
    </div>
</section>

<script>
// Reordering: "Use" buttons pre-fill the purchase form from a suggested order.
(function () {
    function fillForm(sku, qty, supplier) {
        var skuInput = document.getElementById('purchaseSku');
        var qtyInput = document.getElementById('purchaseQty');
        var supInput = document.getElementById('purchaseSupplier');
        if (skuInput) skuInput.value = sku;
        if (qtyInput) qtyInput.value = qty;
        if (supInput && supplier) supInput.value = supplier;

        var card = document.getElementById('recordPurchaseCard');
        if (card) card.scrollIntoView({ behavior: 'smooth', block: 'start' });
        if (skuInput) {
            skuInput.classList.add('filled-flash');
            skuInput.focus();
            setTimeout(function () { skuInput.classList.remove('filled-flash'); }, 1600);
        }
    }
    document.querySelectorAll('[data-fill-sku]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            fillForm(
                btn.getAttribute('data-fill-sku') || '',
                btn.getAttribute('data-fill-qty') || '0',
                btn.getAttribute('data-fill-supplier') || ''
            );
        });
    });
})();
</script>

<script>
// Reordering: "Load all" buttons pre-fill the load-machine form from the reserve table.
(function () {
    document.querySelectorAll('[data-load-sku]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var skuInput = document.getElementById('loadSku');
            var qtyInput = document.getElementById('loadQty');
            if (skuInput) skuInput.value = btn.getAttribute('data-load-sku') || '';
            if (qtyInput) qtyInput.value = btn.getAttribute('data-load-qty') || '1';
            var card = document.getElementById('loadMachineCard');
            if (card) card.scrollIntoView({ behavior: 'smooth', block: 'start' });
            if (qtyInput) qtyInput.focus();
        });
    });
})();
</script>

<script>
window.APP_TOAST = <?= $toastJson ?>;
window.TREND_DATA = <?= json_encode($trend) ?>;
(function () {
    var ctx = document.getElementById('trendChart');
    if (!ctx || typeof Chart === 'undefined') {
        var empty = document.getElementById('trendChartEmpty');
        if (empty) empty.style.display = 'block';
        return;
    }
    new Chart(ctx.getContext('2d'), {
        type: 'line',
        data: {
            labels: window.TREND_DATA.dates,
            datasets: [
                {
                    label: 'Transactions',
                    data: window.TREND_DATA.counts,
                    borderColor: '#0c7a73',
                    backgroundColor: 'rgba(12, 122, 115, 0.15)',
                    fill: true,
                    tension: 0.35
                },
                {
                    label: 'Units dispensed',
                    data: window.TREND_DATA.units,
                    borderColor: '#0270ad',
                    backgroundColor: 'rgba(2, 112, 173, 0.12)',
                    fill: true,
                    tension: 0.35
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'top' }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { precision: 0 }
                }
            }
        }
    });
})();
</script>


<?php include("includes/site-footer.php"); ?>