<?php
include("auth.php");
requireAdmin();
include("includes/logout.php");
require_once __DIR__ . '/includes/config.php';

// Database connection for admin operations
$conn = mysqli_connect("localhost", "root", "", "db_users");

$adminMsg = '';
$adminError = '';

// ---------- ACTION: User management ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Promote to admin
    if (isset($_POST['promote_user'])) {
        $uid = (int)$_POST['user_id'];
        $stmt = $conn->prepare("UPDATE users SET role = 'admin' WHERE id = ?");
        $stmt->bind_param("i", $uid);
        $stmt->execute();
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
                $stmt = $conn->prepare("UPDATE users SET role = 'staff' WHERE id = ?");
                $stmt->bind_param("i", $uid);
                $stmt->execute();
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
            $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
            $stmt->bind_param("i", $uid);
            $stmt->execute();
            $adminMsg = 'User deleted.';
        }
    }

    // ---------- Medication CRUD ----------
    if (isset($_POST['add_med'])) {
        $name = trim($_POST['name'] ?? '');
        $sku = trim($_POST['sku'] ?? '');
        $price = (float)($_POST['price'] ?? 0);
        $stock = (int)($_POST['stock'] ?? 0);
        $category = trim($_POST['category'] ?? 'Tablet');
        $icon = trim($_POST['icon'] ?? '&#128138;');
        $channel = (int)($_POST['channel'] ?? 0);

        if ($name !== '' && $sku !== '') {
            $stmt = $conn->prepare("INSERT INTO medications (channel, name, sku, price, stock, category, icon) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("issdiss", $channel, $name, $sku, $price, $stock, $category, $icon);
            $stmt->execute();
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
            $category = trim($_POST['category'] ?? $existing['category']);
            $icon     = trim($_POST['icon']     ?? $existing['icon']);
            $channel  = (isset($_POST['channel']) && $_POST['channel'] !== '') ? (int)$_POST['channel'] : (int)$existing['channel'];

            if ($name === '' || $sku === '') {
                $adminError = 'Medication name and SKU cannot be empty.';
            } else {
                $stmt = $conn->prepare("UPDATE medications SET channel = ?, name = ?, sku = ?, price = ?, stock = ?, category = ?, icon = ? WHERE id = ?");
                $stmt->bind_param("issdissi", $channel, $name, $sku, $price, $stock, $category, $icon, $id);
                $stmt->execute();
                $adminMsg = 'Medication updated.';
            }
        } else {
            $adminError = 'Medication not found.';
        }
    }

    if (isset($_POST['delete_med'])) {
        $id = (int)$_POST['med_id'];
        $stmt = $conn->prepare("DELETE FROM medications WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $adminMsg = 'Medication deleted.';
    }
}

// ---------- Fetch data for dashboard ----------
$stats = [
    'users'      => (int)$conn->query("SELECT COUNT(*) AS c FROM users")->fetch_assoc()['c'],
    'admins'     => (int)$conn->query("SELECT COUNT(*) AS c FROM users WHERE role = 'admin'")->fetch_assoc()['c'],
    'meds'       => (int)$conn->query("SELECT COUNT(*) AS c FROM medications")->fetch_assoc()['c'],
    'low_stock'  => (int)$conn->query("SELECT COUNT(*) AS c FROM medications WHERE stock < 20")->fetch_assoc()['c'],
    'dispenses'  => (int)$conn->query("SELECT COUNT(*) AS c FROM dispense_history")->fetch_assoc()['c'],
];

$users = $conn->query("SELECT id, fname, lname, email, role, created_at FROM users ORDER BY id ASC");
$medications = $conn->query("SELECT * FROM medications ORDER BY channel ASC, id ASC");
$logs = $conn->query("SELECT * FROM dispense_history ORDER BY dispensed_at DESC LIMIT 20");

// Fetch Arduino / bridge status (best-effort; bridge may be offline).
$arduinoStatus = null;
$bridgeUp = @file_get_contents('http://localhost:3000/status', false, stream_context_create(['http' => ['timeout' => 2]]));
if ($bridgeUp !== false) {
    $decoded = json_decode($bridgeUp, true);
    if (is_array($decoded)) $arduinoStatus = $decoded;
}

$pageTitle = 'Admin Dashboard | ' . SYSTEM_NAME_FULL;
include("includes/site-header.php");
?>

<?php
$arduinoConnected = $arduinoStatus['connected'] ?? false;
$arduinoError    = $arduinoStatus['error'] ?? null;
$arduinoPort     = $arduinoStatus['port'] ?? null;
?>
<div id="arduinoStatusMsg" class="message <?= $arduinoConnected ? 'success' : 'error' ?>" style="max-width:none;" data-port="<?= htmlspecialchars($arduinoPort ?? '') ?>">
    <strong>Dispenser hardware:</strong>
    <span id="arduinoStatusText">
    <?php if ($arduinoStatus === null): ?>
        🔌 Arduino bridge is OFFLINE (start it with <code>node server.js</code> in <code>arduino-bridge/</code>).
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
            if (data.connected) {
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

<h1 class="page-title">Admin Dashboard</h1>
<p class="page-subtitle">Manage users, medication inventory, and dispensing activity.</p>

<?php if ($adminMsg): ?><p class="message success" style="max-width:none;"><?= htmlspecialchars($adminMsg) ?></p><?php endif; ?>
<?php if ($adminError): ?><p class="message error" style="max-width:none;"><?= htmlspecialchars($adminError) ?></p><?php endif; ?>

<!-- Admin Stats -->
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
        <p class="stat-label">Low stock</p>
        <p class="stat-value stat-warn"><?= $stats['low_stock'] ?></p>
        <p class="stat-hint">Below 20 units</p>
    </div>
    <div class="stat-card">
        <p class="stat-label">Total dispensed</p>
        <p class="stat-value stat-ok"><?= $stats['dispenses'] ?></p>
        <p class="stat-hint">All-time dispensing count</p>
    </div>
</div>

<!-- User Management -->
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
                <td><?= (int)$u['id'] ?></td>
                <td><?= htmlspecialchars($u['fname'] . ' ' . $u['lname']) ?> <?= $isSelf ? '(you)' : '' ?></td>
                <td><?= htmlspecialchars($u['email']) ?></td>
                <td>
                    <span class="status-badge <?= $isAdmin ? 'badge-ok' : 'badge-warn' ?>"><?= $isAdmin ? 'Admin' : 'Staff' ?></span>
                </td>
                <td><?= htmlspecialchars($u['created_at']) ?></td>
                <td class="table-actions">
                    <?php if ($isAdmin && !$isSelf): ?>
                        <form method="post" class="inline-form">
                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                            <button type="submit" name="demote_user" class="btn-mini btn-warn">Demote</button>
                        </form>
                    <?php elseif (!$isAdmin): ?>
                        <form method="post" class="inline-form">
                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                            <button type="submit" name="promote_user" class="btn-mini btn-ok">Promote</button>
                        </form>
                    <?php endif; ?>
                    <?php if (!$isSelf): ?>
                        <form method="post" class="inline-form">
                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                            <button type="submit" name="delete_user" class="btn-mini btn-danger" onclick="return confirm('Delete this user?');">Delete</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>

<!-- Medication Management -->
<h2 class="admin-section-title">Medication Management</h2>
<div class="card">
    <h3 class="admin-sub-title">Add Medication</h3>
    <form method="post" class="admin-med-form">
        <input type="hidden" name="icon" value="&#128138;">
        <div class="form-group"><label>Channel #</label><input type="number" name="channel" value="0" min="0" max="5"></div>
        <div class="form-group"><label>Name</label><input type="text" name="name" required></div>
        <div class="form-group"><label>SKU</label><input type="text" name="sku" required></div>
        <div class="form-group"><label>Price (₱)</label><input type="number" step="0.01" name="price" value="0" min="0"></div>
        <div class="form-group"><label>Stock</label><input type="number" name="stock" value="0" min="0"></div>
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
                <th>CH</th><th>Name</th><th>SKU</th><th>Price</th><th>Stock</th><th>Category</th><th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($m = $medications->fetch_assoc()):
                $updateFormId = 'update-med-' . (int)$m['id'];
            ?>
            <tr>
                <td>
                    <input type="number" name="channel" value="<?= (int)$m['channel'] ?>" min="0" max="5" class="mini-input" title="Channel" form="<?= $updateFormId ?>">
                </td>
                <td>
                    <input type="text" name="name" value="<?= htmlspecialchars($m['name']) ?>" class="mini-input" title="Name" required form="<?= $updateFormId ?>">
                </td>
                <td>
                    <input type="text" name="sku" value="<?= htmlspecialchars($m['sku']) ?>" class="mini-input" title="SKU" required form="<?= $updateFormId ?>">
                </td>
                <td>
                    <input type="number" step="0.01" name="price" value="<?= htmlspecialchars($m['price']) ?>" min="0" class="mini-input" title="Price" form="<?= $updateFormId ?>">
                </td>
                <td>
                    <input type="number" name="stock" value="<?= (int)$m['stock'] ?>" min="0" class="mini-input" title="Stock" form="<?= $updateFormId ?>">
                </td>
                <td>
                    <select name="category" class="mini-input" title="Category" form="<?= $updateFormId ?>">
                        <?php foreach (['Tablet','Capsule','Inhaler','Supplement','Supply'] as $cat): ?>
                            <option value="<?= $cat ?>" <?= $m['category'] === $cat ? 'selected' : '' ?>><?= $cat ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td class="table-actions">
                    <form method="post" class="inline-form" id="<?= $updateFormId ?>">
                        <input type="hidden" name="med_id" value="<?= (int)$m['id'] ?>">
                        <button type="submit" name="update_med" class="btn-mini btn-ok">Update</button>
                    </form>
                    <form method="post" class="inline-form">
                        <input type="hidden" name="med_id" value="<?= (int)$m['id'] ?>">
                        <button type="submit" name="delete_med" class="btn-mini btn-danger" onclick="return confirm('Delete this medication?');">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>

<!-- Dispensing Logs -->
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
                    <td><?= htmlspecialchars($lg['dispensed_at']) ?></td>
                    <td><?= htmlspecialchars($lg['user_email'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($lg['medication_name'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($lg['medication_sku'] ?? '—') ?></td>
                    <td><?= (int)$lg['qty'] ?></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p class="cart-empty">No dispensing activity yet.</p>
    <?php endif; ?>
</div>

<?php mysqli_close($conn); ?>
<?php include("includes/site-footer.php"); ?>

