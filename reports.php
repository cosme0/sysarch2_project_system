<?php
include("auth.php");
include("database.php");
requireLogin();
include("includes/logout.php");
require_once __DIR__ . '/includes/config.php';

$conn = mysqli_connect("localhost", "root", "", "db_users");

// Determine which report type is active (daily, monthly, or inventory); default to daily.
$reportType = $_GET['type'] ?? ($_POST['type'] ?? 'daily');
$reportType = in_array($reportType, ['daily', 'monthly', 'inventory'], true) ? $reportType : 'daily';

// --- Daily report ---
$dailyDate = $_GET['daily_date'] ?? $_POST['daily_date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dailyDate)) {
    $dailyDate = date('Y-m-d');
}

$dailyRows = [];
$dailySummary = ['transactions' => 0, 'units' => 0, 'revenue' => 0.0];
$dailyGrouped = [];
$qDaily = $conn->prepare(
    "SELECT dl.medication_name, dl.medication_sku, dl.qty, dl.dispensed_at, dl.user_email,
            COALESCE(m.price, 0) AS price
     FROM dispense_history dl
     LEFT JOIN medications m ON m.sku = dl.medication_sku
     WHERE DATE(dl.dispensed_at) = ?
     ORDER BY dl.dispensed_at DESC"
);
$qDaily->bind_param("s", $dailyDate);
$qDaily->execute();
$dailyRes = $qDaily->get_result();
while ($row = $dailyRes->fetch_assoc()) {
    $dailyRows[] = $row;
    $dailySummary['transactions']++;
    $dailySummary['units'] += (int)$row['qty'];
    $dailySummary['revenue'] += (float)$row['price'] * (int)$row['qty'];
    $sku = $row['medication_sku'] ?? 'N/A';
    if (!isset($dailyGrouped[$sku])) {
        $dailyGrouped[$sku] = ['name' => $row['medication_name'] ?? 'N/A', 'qty' => 0, 'revenue' => 0.0];
    }
    $dailyGrouped[$sku]['qty'] += (int)$row['qty'];
    $dailyGrouped[$sku]['revenue'] += (float)$row['price'] * (int)$row['qty'];
}

// --- Monthly report ---
$monthlyMonth = $_GET['monthly_month'] ?? $_POST['monthly_month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $monthlyMonth)) {
    $monthlyMonth = date('Y-m');
}
$monthlyMonthLabel = date('F Y', strtotime($monthlyMonth . '-01'));
$monthStart = $monthlyMonth . '-01';
$monthEnd = date('Y-m-t', strtotime($monthStart));

$monthlyByDay = [];
$monthlyTotals = ['days' => 0, 'transactions' => 0, 'units' => 0, 'revenue' => 0.0];
$qMonth = $conn->prepare(
    "SELECT DATE(dl.dispensed_at) AS day, COUNT(dl.id) AS tx_count, SUM(dl.qty) AS units,
            SUM(COALESCE(m.price, 0) * dl.qty) AS revenue
     FROM dispense_history dl
     LEFT JOIN medications m ON m.sku = dl.medication_sku
     WHERE dl.dispensed_at >= ? AND dl.dispensed_at < (DATE_ADD(?, INTERVAL 1 MONTH))
     GROUP BY day
     ORDER BY day ASC"
);
$qMonth->bind_param("ss", $monthStart, $monthStart);
$qMonth->execute();
$monthRes = $qMonth->get_result();
while ($row = $monthRes->fetch_assoc()) {
    $monthlyByDay[] = $row;
    $monthlyTotals['days']++;
    $monthlyTotals['transactions'] += (int)$row['tx_count'];
    $monthlyTotals['units'] += (int)$row['units'];
    $monthlyTotals['revenue'] += (float)$row['revenue'];
}

// --- Inventory report ---
$inventoryRows = [];
$inventorySummary = ['items' => 0, 'units' => 0, 'value' => 0.0, 'low_stock' => 0];
$lowStockThreshold = 20;
$qInv = $conn->query("SELECT * FROM medications ORDER BY channel ASC, name ASC");
while ($row = $qInv->fetch_assoc()) {
    $row['stock_value'] = (float)$row['price'] * (int)$row['stock'];
    $row['is_low'] = (int)$row['stock'] < $lowStockThreshold;
    $inventoryRows[] = $row;
    $inventorySummary['items']++;
    $inventorySummary['units'] += (int)$row['stock'];
    $inventorySummary['value'] += $row['stock_value'];
    if ($row['is_low']) {
        $inventorySummary['low_stock']++;
    }
}

$pageTitle = 'Reports | ' . SYSTEM_NAME_FULL;
include("includes/site-header.php");
?>

<h1 class="page-title">Reports</h1>
<p class="page-subtitle">Daily, monthly, and inventory reports for pharmacy operations.</p>

<!-- Report type switch -->
<div class="report-tabs">
    <a href="reports.php?type=daily" class="report-tab <?= $reportType === 'daily' ? 'active' : '' ?>">Daily Report</a>
    <a href="reports.php?type=monthly" class="report-tab <?= $reportType === 'monthly' ? 'active' : '' ?>">Monthly Report</a>
    <a href="reports.php?type=inventory" class="report-tab <?= $reportType === 'inventory' ? 'active' : '' ?>">Inventory Report</a>
</div>

<?php if ($reportType === 'daily'): ?>
<!-- ================= DAILY REPORT ================= -->
<div class="card">
    <h2 class="admin-section-title" style="margin-top:0;">Daily Report</h2>
    <form method="get" action="reports.php" class="report-filter">
        <input type="hidden" name="type" value="daily">
        <div class="form-group report-filter-field">
            <label>Select date</label>
            <input type="date" name="daily_date" value="<?= htmlspecialchars($dailyDate) ?>" max="<?= date('Y-m-d') ?>" required>
        </div>
        <div class="form-group report-filter-field report-filter-submit">
            <button type="submit" class="btn-primary btn-report">Generate</button>
        </div>
    </form>

    <div class="report-stats">
        <div class="stat-card">
            <p class="stat-label">Date</p>
            <p class="stat-value"><?= htmlspecialchars(date('M j, Y', strtotime($dailyDate))) ?></p>
        </div>
        <div class="stat-card">
            <p class="stat-label">Transactions</p>
            <p class="stat-value"><?= (int)$dailySummary['transactions'] ?></p>
        </div>
        <div class="stat-card">
            <p class="stat-label">Units dispensed</p>
            <p class="stat-value"><?= (int)$dailySummary['units'] ?></p>
        </div>
        <div class="stat-card">
            <p class="stat-label">Revenue</p>
            <p class="stat-value stat-ok">₱<?= number_format($dailySummary['revenue'], 2) ?></p>
        </div>
    </div>

    <?php if (!empty($dailyGrouped)): ?>
        <h3 class="admin-sub-title">Dispensed items (<?= date('M j, Y', strtotime($dailyDate)) ?>)</h3>
        <table class="admin-table">
            <thead>
                <tr><th>Medication</th><th>SKU</th><th>Units</th><th>Revenue</th></tr>
            </thead>
            <tbody>
                <?php foreach ($dailyGrouped as $sku => $g): ?>
                <tr>
                    <td><?= htmlspecialchars($g['name']) ?></td>
                    <td><?= htmlspecialchars($sku) ?></td>
                    <td><?= (int)$g['qty'] ?></td>
                    <td>₱<?= number_format($g['revenue'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h3 class="admin-sub-title">Transaction log</h3>
        <table class="admin-table">
            <thead>
                <tr><th>Time</th><th>User</th><th>Medication</th><th>SKU</th><th>Qty</th><th>Revenue</th></tr>
            </thead>
            <tbody>
                <?php foreach ($dailyRows as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r['dispensed_at']) ?></td>
                    <td><?= htmlspecialchars($r['user_email'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($r['medication_name'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($r['medication_sku'] ?? '—') ?></td>
                    <td><?= (int)$r['qty'] ?></td>
                    <td>₱<?= number_format((float)$r['price'] * (int)$r['qty'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p class="cart-empty">No dispensing activity on <?= htmlspecialchars(date('M j, Y', strtotime($dailyDate))) ?>.</p>
    <?php endif; ?>
</div>

<?php elseif ($reportType === 'monthly'): ?>
<!-- ================= MONTHLY REPORT ================= -->
<div class="card">
    <h2 class="admin-section-title" style="margin-top:0;">Monthly Report</h2>
    <form method="get" action="reports.php" class="report-filter">
        <input type="hidden" name="type" value="monthly">
        <div class="form-group report-filter-field">
            <label>Select month</label>
            <input type="month" name="monthly_month" value="<?= htmlspecialchars($monthlyMonth) ?>" max="<?= date('Y-m') ?>" required>
        </div>
        <div class="form-group report-filter-field report-filter-submit">
            <button type="submit" class="btn-primary btn-report">Generate</button>
        </div>
    </form>

    <div class="report-stats">
        <div class="stat-card">
            <p class="stat-label">Month</p>
            <p class="stat-value"><?= htmlspecialchars($monthlyMonthLabel) ?></p>
        </div>
        <div class="stat-card">
            <p class="stat-label">Active days</p>
            <p class="stat-value"><?= (int)$monthlyTotals['days'] ?></p>
        </div>
        <div class="stat-card">
            <p class="stat-label">Transactions</p>
            <p class="stat-value"><?= (int)$monthlyTotals['transactions'] ?></p>
        </div>
        <div class="stat-card">
            <p class="stat-label">Units dispensed</p>
            <p class="stat-value"><?= (int)$monthlyTotals['units'] ?></p>
        </div>
        <div class="stat-card">
            <p class="stat-label">Revenue</p>
            <p class="stat-value stat-ok">₱<?= number_format($monthlyTotals['revenue'], 2) ?></p>
        </div>
    </div>

    <?php if (!empty($monthlyByDay)): ?>
        <h3 class="admin-sub-title">Daily breakdown — <?= htmlspecialchars($monthlyMonthLabel) ?></h3>
        <table class="admin-table">
            <thead>
                <tr><th>Date</th><th>Transactions</th><th>Units dispensed</th><th>Revenue</th></tr>
            </thead>
            <tbody>
                <?php foreach ($monthlyByDay as $d): ?>
                <tr>
                    <td><?= htmlspecialchars(date('M j, Y', strtotime($d['day']))) ?></td>
                    <td><?= (int)$d['tx_count'] ?></td>
                    <td><?= (int)$d['units'] ?></td>
                    <td>₱<?= number_format((float)$d['revenue'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p class="cart-empty">No dispensing activity in <?= htmlspecialchars($monthlyMonthLabel) ?>.</p>
    <?php endif; ?>
</div>

<?php else: ?>
<!-- ================= INVENTORY REPORT ================= -->
<div class="card">
    <h2 class="admin-section-title" style="margin-top:0;">Inventory Report</h2>
    <p class="page-subtitle" style="margin-bottom:20px;">Current stock levels and inventory valuation. Low-stock items (below 20 units) are flagged.</p>

    <div class="report-stats">
        <div class="stat-card">
            <p class="stat-label">Total items</p>
            <p class="stat-value"><?= (int)$inventorySummary['items'] ?></p>
        </div>
        <div class="stat-card">
            <p class="stat-label">Total units</p>
            <p class="stat-value"><?= (int)$inventorySummary['units'] ?></p>
        </div>
        <div class="stat-card">
            <p class="stat-label">Inventory value</p>
            <p class="stat-value stat-ok">₱<?= number_format($inventorySummary['value'], 2) ?></p>
        </div>
        <div class="stat-card">
            <p class="stat-label">Low stock</p>
            <p class="stat-value <?= $inventorySummary['low_stock'] > 0 ? 'stat-warn' : '' ?>"><?= (int)$inventorySummary['low_stock'] ?></p>
        </div>
    </div>

    <?php if (!empty($inventoryRows)): ?>
        <table class="admin-table">
            <thead>
                <tr><th>CH</th><th>Name</th><th>SKU</th><th>Category</th><th>Price</th><th>Stock</th><th>Stock Value</th><th>Status</th></tr>
            </thead>
            <tbody>
                <?php foreach ($inventoryRows as $inv): ?>
                <tr>
                    <td><?= (int)$inv['channel'] ?></td>
                    <td><?= htmlspecialchars($inv['name']) ?></td>
                    <td><?= htmlspecialchars($inv['sku']) ?></td>
                    <td><?= htmlspecialchars($inv['category']) ?></td>
                    <td>₱<?= number_format((float)$inv['price'], 2) ?></td>
                    <td><?= (int)$inv['stock'] ?></td>
                    <td>₱<?= number_format((float)$inv['stock_value'], 2) ?></td>
                    <td>
                        <span class="status-badge <?= $inv['is_low'] ? 'badge-warn' : 'badge-ok' ?>">
                            <?= $inv['is_low'] ? 'Low stock' : 'In stock' ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p class="cart-empty">No medications in inventory.</p>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php mysqli_close($conn); ?>
<?php include("includes/site-footer.php"); ?>
