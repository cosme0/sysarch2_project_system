<?php
/**
 * Activity Log - the comprehensive record of what happened in the system.
 *
 * Every meaningful action is stored by log_activity() in auth.php: sign-in and
 * sign-out, which module was opened, catalogue searches, checkouts and payments,
 * dispensing, inventory changes, purchases, write-offs, ticket handling,
 * password resets, report generation and database backups.
 *
 * Administrators see the whole system. Staff see their own activity only.
 */
require_once __DIR__ . '/auth.php';;
require_once __DIR__ . '/database.php';;
requireLogin();
include("includes/logout.php");
require_once __DIR__ . '/includes/config.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$conn      = db();
$isAdmin   = isAdmin();
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = 40;
$export    = ($_GET['export'] ?? '') === 'csv';

// ---------------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------------
$filters = [
    'module'   => trim($_GET['module'] ?? ''),
    'action'   => trim($_GET['action'] ?? ''),
    'severity' => trim($_GET['severity'] ?? ''),
    'user'     => trim($_GET['user'] ?? ''),
    'from'     => trim($_GET['from'] ?? ''),
    'to'       => trim($_GET['to'] ?? ''),
    'q'        => trim($_GET['q'] ?? ''),
];

// Module tallies are needed up front: they build the filter drop-down and, if the
// log holds a module this file has never seen, it must still be filterable.
$moduleCounts = [];
$modRes = $conn->query("SELECT module, COUNT(*) AS c FROM audit_log GROUP BY module ORDER BY c DESC");
if ($modRes) {
    while ($m = $modRes->fetch_assoc()) {
        $moduleCounts[$m['module']] = (int)$m['c'];
    }
}

// The modules the application can record, unioned with anything actually present
// in the log so a newly added module is still filterable.
$validModules = array_values(array_unique(array_merge([
    'auth', 'dashboard', 'shop', 'checkout', 'dispense', 'inventory',
    'reports', 'audit', 'admin', 'account', 'support', 'system',
], array_keys($moduleCounts))));

if ($filters['module'] !== '' && !in_array($filters['module'], $validModules, true)) {
    $filters['module'] = '';
}
if (!in_array($filters['severity'], ['info', 'notice', 'warning', 'critical'], true)) {
    $filters['severity'] = '';
}
foreach (['from', 'to'] as $k) {
    if ($filters[$k] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters[$k])) {
        $filters[$k] = '';
    }
}
if ($filters['from'] === '' && $filters['to'] === '') {
    $filters['from'] = date('Y-m-d', strtotime('-30 days'));
}

$isFiltered = $filters['module'] !== ''
    || $filters['action'] !== ''
    || $filters['severity'] !== ''
    || $filters['user'] !== ''
    || $filters['q'] !== ''
    || $filters['to'] !== ''
    || $filters['from'] !== date('Y-m-d', strtotime('-30 days'));

// ---------------------------------------------------------------------------
// WHERE clause
// ---------------------------------------------------------------------------
$where  = [];
$types  = '';
$values = [];

if (!$isAdmin) {
    // Staff are only ever shown their own trail.
    $where[]  = 'user_email = ?';
    $types   .= 's';
    $values[] = $_SESSION['email'] ?? '';
} elseif ($filters['user'] !== '') {
    $where[]  = 'user_email = ?';
    $types   .= 's';
    $values[] = $filters['user'];
}

if ($filters['module'] !== '') {
    $where[]  = 'module = ?';
    $types   .= 's';
    $values[] = $filters['module'];
}
if ($filters['action'] !== '') {
    $where[]  = 'action = ?';
    $types   .= 's';
    $values[] = $filters['action'];
}
if ($filters['severity'] !== '') {
    $where[]  = 'severity = ?';
    $types   .= 's';
    $values[] = $filters['severity'];
}
if ($filters['from'] !== '') {
    $where[]  = 'created_at >= ?';
    $types   .= 's';
    $values[] = $filters['from'] . ' 00:00:00';
}
if ($filters['to'] !== '') {
    $where[]  = 'created_at <= ?';
    $types   .= 's';
    $values[] = $filters['to'] . ' 23:59:59';
}
if ($filters['q'] !== '') {
    $where[]  = '(details LIKE ? OR action LIKE ? OR entity_id LIKE ? OR request_uri LIKE ?)';
    $like     = '%' . $filters['q'] . '%';
    $types   .= 'ssss';
    array_push($values, $like, $like, $like, $like);
}

$whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

// ---------------------------------------------------------------------------
// CSV export
// ---------------------------------------------------------------------------
if ($export) {
    $stmt = $conn->prepare("SELECT * FROM audit_log{$whereSql} ORDER BY created_at DESC LIMIT 20000");
    if ($types !== '') {
        bind_stmt($stmt, $types, $values);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    $filename = 'amdpms-activity-' . date('Ymd-His') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Timestamp', 'User', 'Role', 'Module', 'Action', 'Severity', 'Entity', 'Entity ID', 'Details', 'IP address', 'Request URI']);

    while ($row = $res->fetch_assoc()) {
        fputcsv($out, [
            $row['created_at'],
            $row['user_email'],
            $row['user_role'],
            $row['module'],
            $row['action'],
            $row['severity'],
            $row['entity'],
            $row['entity_id'],
            $row['details'],
            $row['ip_address'],
            $row['request_uri'],
        ]);
    }
    fclose($out);

    log_activity(
        'activity_exported',
        'audit',
        'Exported ' . $res->num_rows . ' activity record(s) to CSV.',
        'notice',
        'report',
        'activity-log'
    );
    exit;
}

// ---------------------------------------------------------------------------
// Summary statistics (always over the whole database, not the current page)
// ---------------------------------------------------------------------------
$stats = ['total' => 0, 'today' => 0, 'attention' => 0, 'users' => 0, 'modules' => 0];
$statRes = $conn->query(
    "SELECT COUNT(*) AS total,
            SUM(created_at >= CURDATE()) AS today,
            SUM(severity IN ('warning','critical')) AS attention,
            COUNT(DISTINCT user_email) AS users,
            COUNT(DISTINCT module) AS modules
     FROM audit_log"
);
if ($statRes && $row = $statRes->fetch_assoc()) {
    $stats = [
        'total'     => (int)$row['total'],
        'today'     => (int)$row['today'],
        'attention' => (int)$row['attention'],
        'users'     => (int)$row['users'],
        'modules'   => (int)$row['modules'],
    ];
}

// ---------------------------------------------------------------------------
// Filter option lists
// ---------------------------------------------------------------------------
$actionOptions = [];
$actRes = $conn->query("SELECT DISTINCT action FROM audit_log ORDER BY action ASC");
if ($actRes) {
    while ($a = $actRes->fetch_assoc()) {
        $actionOptions[] = $a['action'];
    }
}

$userOptions = [];
$usrRes = $conn->query(
    "SELECT DISTINCT user_email FROM audit_log WHERE user_email IS NOT NULL ORDER BY user_email ASC"
);
if ($usrRes) {
    while ($u = $usrRes->fetch_assoc()) {
        $userOptions[] = $u['user_email'];
    }
}

// ---------------------------------------------------------------------------
// Paged results
// ---------------------------------------------------------------------------
$countSql = "SELECT COUNT(*) AS c FROM audit_log{$whereSql}";
$countStmt = $conn->prepare($countSql);
if ($types !== '') {
    bind_stmt($countStmt, $types, $values);
}
$countStmt->execute();
$totalRows = (int)$countStmt->get_result()->fetch_assoc()['c'];
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$rowsSql   = "SELECT * FROM audit_log{$whereSql} ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?";
$rowsStmt  = $conn->prepare($rowsSql);
$rowValues = $values;
$rowValues[] = $perPage;
$rowValues[] = $offset;
bind_stmt($rowsStmt, $types . 'ii', $rowValues);
$rowsStmt->execute();
$rowsRes = $rowsStmt->get_result();

$rows = [];
while ($r = $rowsRes->fetch_assoc()) {
    $rows[] = $r;
}

// ---------------------------------------------------------------------------
// Activity-by-day chart data
// ---------------------------------------------------------------------------
$chartFrom = $filters['from'] !== '' ? $filters['from'] : date('Y-m-d', strtotime('-30 days'));
$chartTo   = $filters['to']   !== '' ? $filters['to']   : date('Y-m-d');

$chartStmt = $conn->prepare(
    "SELECT DATE(created_at) AS day, COUNT(*) AS c
     FROM audit_log
     WHERE created_at >= ? AND created_at <= DATE_ADD(?, INTERVAL 1 DAY)
     GROUP BY day ORDER BY day ASC"
);
$chartStmt->bind_param('ss', $chartFrom, $chartTo);
$chartStmt->execute();
$chartRes = $chartStmt->get_result();

$chartLabels = [];
$chartValues = [];
while ($c = $chartRes->fetch_assoc()) {
    $chartLabels[] = date('M j', strtotime($c['day']));
    $chartValues[] = (int)$c['c'];
}

$pageTitle  = 'Activity Log | ' . SYSTEM_NAME_FULL;
$includeCharts = !empty($chartValues);
include("includes/site-header.php");

// Preserve the current filters when paging.
$baseQuery = $_GET;
unset($baseQuery['page'], $baseQuery['export']);
$baseQs = http_build_query($baseQuery);
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Activity Log</h1>
        <p class="page-subtitle">
            <?= $isAdmin
                ? 'Every action performed in the system, by whom, in which module, and with what result.'
                : 'A record of the actions performed on your account.' ?>
        </p>
    </div>
    <div class="page-head-actions">
        <?php if ($isFiltered): ?>
            <a class="btn-secondary" href="audit-log.php">Clear filters</a>
        <?php endif; ?>
        <a class="btn-primary" href="audit-log.php?<?= htmlspecialchars($baseQs . '&export=csv') ?>">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Export CSV
        </a>
    </div>
</div>

<div class="report-stats">
    <div class="stat-card">
        <p class="stat-label">Total events</p>
        <p class="stat-value"><?= number_format($stats['total']) ?></p>
        <p class="stat-hint">all time</p>
    </div>
    <div class="stat-card">
        <p class="stat-label">Today</p>
        <p class="stat-value"><?= number_format($stats['today']) ?></p>
        <p class="stat-hint">since midnight</p>
    </div>
    <div class="stat-card">
        <p class="stat-label">Needs attention</p>
        <p class="stat-value <?= $stats['attention'] > 0 ? 'stat-warn' : '' ?>"><?= number_format($stats['attention']) ?></p>
        <p class="stat-hint">warnings &amp; critical</p>
    </div>
    <div class="stat-card">
        <p class="stat-label">Modules used</p>
        <p class="stat-value"><?= number_format($stats['modules']) ?></p>
        <p class="stat-hint">of <?= count($validModules) ?> possible</p>
    </div>
    <div class="stat-card">
        <p class="stat-label">Active accounts</p>
        <p class="stat-value"><?= number_format($stats['users']) ?></p>
        <p class="stat-hint">distinct users</p>
    </div>
</div>

<form method="get" action="audit-log.php" class="card filter-bar audit-filters">
    <div class="form-group">
        <label for="fModule">Module</label>
        <select id="fModule" name="module">
            <option value="">All modules</option>
            <?php foreach ($moduleCounts as $modName => $count): ?>
                <option value="<?= htmlspecialchars($modName) ?>" <?= $filters['module'] === $modName ? 'selected' : '' ?>>
                    <?= htmlspecialchars(ucfirst($modName)) ?> (<?= (int)$count ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="form-group">
        <label for="fAction">Action</label>
        <select id="fAction" name="action">
            <option value="">All actions</option>
            <?php foreach ($actionOptions as $act): ?>
                <option value="<?= htmlspecialchars($act) ?>" <?= $filters['action'] === $act ? 'selected' : '' ?>>
                    <?= htmlspecialchars(audit_action_label($act)) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="form-group">
        <label for="fSeverity">Severity</label>
        <select id="fSeverity" name="severity">
            <option value="">Any severity</option>
            <?php foreach (AUDIT_SEVERITIES as $sev): ?>
                <option value="<?= $sev ?>" <?= $filters['severity'] === $sev ? 'selected' : '' ?>>
                    <?= htmlspecialchars(ucfirst($sev)) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <?php if ($isAdmin): ?>
    <div class="form-group">
        <label for="fUser">User</label>
        <select id="fUser" name="user">
            <option value="">All users</option>
            <?php foreach ($userOptions as $u): ?>
                <option value="<?= htmlspecialchars($u) ?>" <?= $filters['user'] === $u ? 'selected' : '' ?>>
                    <?= htmlspecialchars($u) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>

    <div class="form-group">
        <label for="fFrom">From</label>
        <input type="date" id="fFrom" name="from" value="<?= htmlspecialchars($filters['from']) ?>">
    </div>

    <div class="form-group">
        <label for="fTo">To</label>
        <input type="date" id="fTo" name="to" value="<?= htmlspecialchars($filters['to']) ?>" max="<?= date('Y-m-d') ?>">
    </div>

    <div class="form-group">
        <label for="fSearch">Search</label>
        <input type="search" id="fSearch" name="q" value="<?= htmlspecialchars($filters['q']) ?>" placeholder="Details, SKU, invoice…">
    </div>

    <div class="form-group filter-actions">
        <button type="submit" class="btn-primary">Apply filters</button>
    </div>
</form>

<?php if (!empty($chartValues)): ?>
<div class="card chart-card">
    <h2 class="admin-section-title" style="margin-top:0;">Activity volume</h2>
    <p class="page-subtitle" style="margin-bottom:16px;">
        Events recorded per day from <?= htmlspecialchars(date('M j, Y', strtotime($chartFrom))) ?>
        to <?= htmlspecialchars(date('M j, Y', strtotime($chartTo))) ?>.
    </p>
    <div class="chart-wrap">
        <p class="chart-empty" id="activityChartEmpty">Chart library failed to load. Connect to the internet to view charts.</p>
        <canvas id="activityChart" height="90" aria-label="Bar chart of activity per day" role="img"></canvas>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-head">
        <h2 class="admin-section-title" style="margin:0;">
            <?= number_format($totalRows) ?> event<?= $totalRows === 1 ? '' : 's' ?>
        </h2>
        <span class="page-subtitle" style="margin:0;">
            <?php if ($filters['from'] !== '' || $filters['to'] !== ''): ?>
                <?= htmlspecialchars($filters['from'] ?: 'start') ?> → <?= htmlspecialchars($filters['to'] ?: 'today') ?>
            <?php endif; ?>
        </span>
    </div>

    <?php if (!empty($rows)): ?>
        <div class="table-scroll">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Who</th>
                        <th>Module</th>
                        <th>Action</th>
                        <th>What happened</th>
                        <th>Severity</th>
                        <th>Source</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r):
                    $sev      = $r['severity'] ?? 'info';
                    $sevClass = 'badge-' . ($sev === 'critical' ? 'out' : ($sev === 'warning' ? 'warn' : ($sev === 'notice' ? 'alert' : 'ok')));
                ?>
                    <tr>
                        <td data-label="When" class="cell-nowrap">
                            <?= htmlspecialchars(date('M j, Y', strtotime($r['created_at']))) ?><br>
                            <small class="cell-sub"><?= htmlspecialchars(date('H:i:s', strtotime($r['created_at']))) ?></small>
                        </td>
                        <td data-label="Who">
                            <?= htmlspecialchars($r['user_email'] ?? '—') ?>
                            <?php if (!empty($r['user_role'])): ?>
                                <br><small class="cell-sub"><?= htmlspecialchars($r['user_role']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td data-label="Module"><span class="module-chip"><?= htmlspecialchars(ucfirst($r['module'] ?? 'system')) ?></span></td>
                        <td data-label="Action">
                            <code class="action-code"><?= htmlspecialchars($r['action']) ?></code>
                            <?php if (!empty($r['entity'])): ?>
                                <br><small class="cell-sub"><?= htmlspecialchars($r['entity']) ?><?= $r['entity_id'] ? ': ' . htmlspecialchars($r['entity_id']) : '' ?></small>
                            <?php endif; ?>
                        </td>
                        <td data-label="What happened" class="cell-wrap"><?= htmlspecialchars($r['details'] ?? '—') ?></td>
                        <td data-label="Severity"><span class="status-badge <?= $sevClass ?>"><?= htmlspecialchars(ucfirst($sev)) ?></span></td>
                        <td data-label="Source" class="cell-nowrap">
                            <small class="cell-sub"><?= htmlspecialchars($r['ip_address'] ?? '—') ?></small>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav class="pagination" aria-label="Activity log pages">
                <?php
                $qsPage = $baseQs === '' ? '?' : '?' . $baseQs . '&';
                $window = 2;
                $start  = max(1, $page - $window);
                $end    = min($totalPages, $page + $window);
                ?>
                <?php if ($page > 1): ?>
                    <a class="page-btn" href="<?= htmlspecialchars($qsPage) ?>page=1" aria-label="First page">&laquo;</a>
                    <a class="page-btn" href="<?= htmlspecialchars($qsPage) ?>page=<?= $page - 1 ?>" rel="prev">Previous</a>
                <?php endif; ?>

                <?php if ($start > 1): ?>
                    <span class="page-gap">…</span>
                <?php endif; ?>

                <?php for ($p = $start; $p <= $end; $p++): ?>
                    <?php if ($p === $page): ?>
                        <span class="page-btn active" aria-current="page"><?= $p ?></span>
                    <?php else: ?>
                        <a class="page-btn" href="<?= htmlspecialchars($qsPage) ?>page=<?= $p ?>"><?= $p ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($end < $totalPages): ?>
                    <span class="page-gap">…</span>
                <?php endif; ?>

                <?php if ($page < $totalPages): ?>
                    <a class="page-btn" href="<?= htmlspecialchars($qsPage) ?>page=<?= $page + 1 ?>" rel="next">Next</a>
                    <a class="page-btn" href="<?= htmlspecialchars($qsPage) ?>page=<?= $totalPages ?>" aria-label="Last page">&raquo;</a>
                <?php endif; ?>
            </nav>
            <p class="pagination-note">Page <?= $page ?> of <?= $totalPages ?></p>
        <?php endif; ?>
    <?php else: ?>
        <p class="cart-empty">No activity matches these filters.</p>
    <?php endif; ?>
</div>

<script>
(function () {
    var data = <?= json_encode([
        'labels' => $chartLabels,
        'values' => $chartValues,
    ]) ?>;
    window.ACTIVITY_DATA = data;

    var canvas = document.getElementById('activityChart');
    if (!canvas || typeof Chart === 'undefined') {
        var empty = document.getElementById('activityChartEmpty');
        if (empty) empty.style.display = 'block';
        return;
    }
    var empty = document.getElementById('activityChartEmpty');
    if (empty) empty.style.display = 'none';

    new Chart(canvas.getContext('2d'), {
        type: 'bar',
        data: {
            labels: data.labels,
            datasets: [{
                label: 'Events recorded',
                data: data.values,
                backgroundColor: 'rgba(12, 122, 115, 0.65)',
                borderColor: '#0c7a73',
                borderWidth: 1,
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        title: function (items) { return 'Week of ' + items[0].label; }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { precision: 0 },
                    title: { display: true, text: 'Events' }
                }
            }
        }
    });
})();
</script>

<?php include("includes/site-footer.php"); ?>
