<?php
/**
 * Reports & analytics.
 *
 * Six report types, and only the selected one is ever queried so opening the
 * page stays fast no matter how much history the pharmacy has accumulated.
 *
 *   daily     one day's sales, payment mix, hourly revenue, per-transaction log
 *   monthly   one month's day-by-day revenue and totals
 *   sales     every paid invoice in a date range, grouped by payment method
 *   inventory current stock, valuation, low stock and expiry risk
 *   audit     system activity summarised by module, action, severity and user
 *   analytics top medications, staff performance, category value, expiry outlook
 *
 * Every report can be printed or exported to CSV, and generating or exporting
 * one is itself written to the activity log.
 */
require_once __DIR__ . '/auth.php';;
require_once __DIR__ . '/database.php';;
requireLogin();
include("includes/logout.php");
require_once __DIR__ . '/includes/config.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$conn    = db();
$isAdmin = isAdmin();

// bind_param() needs real variables by reference, so the signed-in address is
// resolved once here and passed from a variable everywhere below.
$sessionEmail = (string)($_SESSION['email'] ?? '');

const REPORT_TYPES = [
    'daily'     => 'Daily Sales',
    'monthly'   => 'Monthly Sales',
    'sales'     => 'Sales Ledger',
    'inventory' => 'Inventory',
    'audit'     => 'System Activity',
    'analytics' => 'Analytics',
];

$reportType = $_GET['type'] ?? 'daily';
if (!isset(REPORT_TYPES[$reportType])) {
    $reportType = 'daily';
}
$reportName = REPORT_TYPES[$reportType];

$isExport = ($_GET['export'] ?? '') === 'csv';
$isPrint  = ($_GET['print'] ?? '') === '1';

// ---------------------------------------------------------------------------
// Report parameters
// ---------------------------------------------------------------------------
$today      = date('Y-m-d');
$dailyDate  = $_GET['date'] ?? $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dailyDate)) {
    $dailyDate = $today;
}
$dailyLabel = date('M j, Y', strtotime($dailyDate));

$month      = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$monthStart    = $month . '-01';
$monthEnd      = date('Y-m-t', strtotime($monthStart));
$monthLabel    = date('F Y', strtotime($monthStart));

$salesFrom = $_GET['from'] ?? date('Y-m-d', strtotime('-29 days'));
$salesTo   = $_GET['to']   ?? $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $salesFrom)) {
    $salesFrom = date('Y-m-d', strtotime('-29 days'));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $salesTo)) {
    $salesTo = $today;
}
if ($salesFrom > $salesTo) {
    [$salesFrom, $salesTo] = [$salesTo, $salesFrom];
}
$rangeDays = (int)floor((strtotime($salesTo) - strtotime($salesFrom)) / 86400) + 1;
$rangeLabel = date('M j, Y', strtotime($salesFrom)) . ' – ' . date('M j, Y', strtotime($salesTo));

$lowStockThreshold = LOW_STOCK_THRESHOLD;

/**
 * Streams rows to the browser as a CSV download.
 */
function report_csv(string $filename, array $header, iterable $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');

    $out = fopen('php://output', 'w');
    fputcsv($out, $header);
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
}

// ===========================================================================
// DAILY
// ===========================================================================
if ($reportType === 'daily') {
    $totals = ['invoices' => 0, 'units' => 0, 'revenue' => 0.0, 'cash' => 0.0, 'average' => 0.0];
    $byPayment = [];
    $byHour    = array_fill(0, 24, 0.0);
    $topItems  = [];
    $invoices  = [];

    $stmt = $conn->prepare(
        "SELECT id, invoice_no, user_email, sold_at, total_qty, total_amount, payment_method,
                cash_received, change_due, reference_no, batch_ref, status
         FROM sales
         WHERE DATE(sold_at) = ? AND status = 'completed'
         ORDER BY sold_at DESC"
    );
    $stmt->bind_param('s', $dailyDate);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($s = $res->fetch_assoc()) {
        $invoices[] = $s;
        $amount = (float)$s['total_amount'];
        $totals['invoices']++;
        $totals['units']   += (int)$s['total_qty'];
        $totals['revenue'] += $amount;

        $method = $s['payment_method'];
        if (!isset($byPayment[$method])) {
            $byPayment[$method] = ['count' => 0, 'revenue' => 0.0];
        }
        $byPayment[$method]['count']++;
        $byPayment[$method]['revenue'] += $amount;

        $hour = (int)date('G', strtotime($s['sold_at']));
        if ($hour >= 0 && $hour < 24) {
            $byHour[$hour] += $amount;
        }
    }
    $totals['cash']    = (float)($byPayment['cash']['revenue'] ?? 0.0);
    $totals['average'] = $totals['invoices'] > 0 ? $totals['revenue'] / $totals['invoices'] : 0.0;

    // Line items for the day, which also feeds the "most sold" table.
    $itemStmt = $conn->prepare(
        "SELECT si.medication_name, si.medication_sku, SUM(si.qty) AS units, SUM(si.subtotal) AS revenue
         FROM sale_items si
         INNER JOIN sales s ON s.id = si.sale_id
         WHERE DATE(s.sold_at) = ? AND s.status = 'completed'
         GROUP BY si.medication_sku, si.medication_name
         ORDER BY revenue DESC"
    );
    $itemStmt->bind_param('s', $dailyDate);
    $itemStmt->execute();
    $itemRes = $itemStmt->get_result();
    while ($it = $itemRes->fetch_assoc()) {
        $topItems[] = $it;
    }

    // Units physically released that day, including legacy batches with no invoice.
    $unitsStmt = $conn->prepare(
        "SELECT COALESCE(SUM(qty), 0) AS units FROM dispense_history WHERE DATE(dispensed_at) = ?"
    );
    $unitsStmt->bind_param('s', $dailyDate);
    $unitsStmt->execute();
    $unitsDispensed = (int)$unitsStmt->get_result()->fetch_assoc()['units'];

    $dailyTotals   = $totals;
    $dailyByPayment = $byPayment;
    $dailyByHour    = $byHour;
    $dailyTopItems  = $topItems;
    $dailyInvoices  = $invoices;
    $dailyUnitsOut  = $unitsDispensed;

    if ($isExport) {
        report_csv(
            'amdpms-daily-' . $dailyDate . '.csv',
            ['Invoice', 'Time', 'Cashier', 'Payment method', 'Items', 'Total', 'Cash received', 'Change', 'Reference', 'Status'],
            array_map(static fn(array $s): array => [
                $s['invoice_no'],
                $s['sold_at'],
                $s['user_email'],
                PAYMENT_LABELS[$s['payment_method']] ?? $s['payment_method'],
                $s['total_qty'],
                number_format((float)$s['total_amount'], 2, '.', ''),
                $s['cash_received'] === null ? '' : number_format((float)$s['cash_received'], 2, '.', ''),
                $s['change_due'] === null ? '' : number_format((float)$s['change_due'], 2, '.', ''),
                $s['reference_no'],
                $s['status'],
            ], $invoices)
        );
    }
}

// ===========================================================================
// MONTHLY
// ===========================================================================
elseif ($reportType === 'monthly') {
    $monthTotals = ['days' => 0, 'invoices' => 0, 'units' => 0, 'revenue' => 0.0, 'average' => 0.0, 'best' => 0.0];
    $monthByDay  = [];
    $monthMethods = [];

    $stmt = $conn->prepare(
        "SELECT DATE(sold_at) AS day, COUNT(*) AS invoices, SUM(total_qty) AS units,
                SUM(total_amount) AS revenue
         FROM sales
         WHERE sold_at >= ? AND sold_at < DATE_ADD(?, INTERVAL 1 MONTH) AND status = 'completed'
         GROUP BY day ORDER BY day ASC"
    );
    $stmt->bind_param('ss', $monthStart, $monthStart);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($d = $res->fetch_assoc()) {
        $monthByDay[$d['day']] = $d;
        $monthTotals['days']++;
        $monthTotals['invoices'] += (int)$d['invoices'];
        $monthTotals['units']    += (int)$d['units'];
        $monthTotals['revenue']  += (float)$d['revenue'];
        $monthTotals['best']      = max($monthTotals['best'], (float)$d['revenue']);
    }
    $monthTotals['average'] = $monthTotals['invoices'] > 0
        ? $monthTotals['revenue'] / $monthTotals['invoices']
        : 0.0;

    $mStmt = $conn->prepare(
        "SELECT payment_method, COUNT(*) AS invoices, SUM(total_amount) AS revenue
         FROM sales
         WHERE sold_at >= ? AND sold_at < DATE_ADD(?, INTERVAL 1 MONTH) AND status = 'completed'
         GROUP BY payment_method ORDER BY revenue DESC"
    );
    $mStmt->bind_param('ss', $monthStart, $monthStart);
    $mStmt->execute();
    $mRes = $mStmt->get_result();
    while ($m = $mRes->fetch_assoc()) {
        $monthMethods[] = $m;
    }

    // Fill in every day of the month so the chart has a continuous axis.
    $monthSeries = ['labels' => [], 'revenue' => [], 'invoices' => []];
    for ($d = strtotime($monthStart); $d <= strtotime($monthEnd); $d += 86400) {
        $key = date('Y-m-d', $d);
        $monthSeries['labels'][]   = date('j', $d);
        $monthSeries['revenue'][]  = isset($monthByDay[$key]) ? (float)$monthByDay[$key]['revenue'] : 0.0;
        $monthSeries['invoices'][] = isset($monthByDay[$key]) ? (int)$monthByDay[$key]['invoices'] : 0;
    }

    // Best sellers of the month.
    $monthTop = [];
    $topStmt = $conn->prepare(
        "SELECT si.medication_name, si.medication_sku, SUM(si.qty) AS units, SUM(si.subtotal) AS revenue
         FROM sale_items si INNER JOIN sales s ON s.id = si.sale_id
         WHERE s.sold_at >= ? AND s.sold_at < DATE_ADD(?, INTERVAL 1 MONTH) AND s.status = 'completed'
         GROUP BY si.medication_sku, si.medication_name
         ORDER BY revenue DESC LIMIT 10"
    );
    $topStmt->bind_param('ss', $monthStart, $monthStart);
    $topStmt->execute();
    // get_result() must be called once and held; calling it inside the loop
    // condition frees the result set between rows and desyncs the statement.
    $topRes = $topStmt->get_result();
    while ($t = $topRes->fetch_assoc()) {
        $monthTop[] = $t;
    }

    if ($isExport) {
        $rows = [];
        foreach ($monthByDay as $day => $d) {
            $rows[] = [
                $day,
                (int)$d['invoices'],
                (int)$d['units'],
                number_format((float)$d['revenue'], 2, '.', ''),
            ];
        }
        report_csv(
            'amdpms-monthly-' . $month . '.csv',
            ['Date', 'Invoices', 'Units', 'Revenue'],
            $rows
        );
    }
}

// ===========================================================================
// SALES LEDGER
// ===========================================================================
elseif ($reportType === 'sales') {
    $salesTotals = ['invoices' => 0, 'units' => 0, 'revenue' => 0.0, 'average' => 0.0, 'cash' => 0.0, 'change' => 0.0, 'voided' => 0, 'pending' => 0];
    $salesByMethod = [];
    $salesSeries   = ['labels' => [], 'revenue' => []];
    $salesInvoices = [];

    $stmt = $conn->prepare(
        "SELECT id, invoice_no, user_email, sold_at, total_qty, total_amount, payment_method,
                cash_received, change_due, reference_no, batch_ref, status
         FROM sales
         WHERE sold_at >= ? AND sold_at <= ?
         ORDER BY sold_at DESC LIMIT 5000"
    );
    $salesToEnd = $salesTo . ' 23:59:59';
    $stmt->bind_param('ss', $salesFrom, $salesToEnd);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($s = $res->fetch_assoc()) {
        $salesInvoices[] = $s;

        // Every invoice is listed, but only completed ones count as revenue, so a
        // voided or stuck sale never inflates the figures.
        if ($s['status'] === 'pending') {
            $salesTotals['pending']++;
            continue;
        }
        if ($s['status'] !== 'completed') {
            $salesTotals['voided']++;
            continue;
        }

        $amount = (float)$s['total_amount'];
        $salesTotals['invoices']++;
        $salesTotals['units']   += (int)$s['total_qty'];
        $salesTotals['revenue'] += $amount;

        $method = $s['payment_method'];
        if (!isset($salesByMethod[$method])) {
            $salesByMethod[$method] = ['invoices' => 0, 'units' => 0, 'revenue' => 0.0];
        }
        $salesByMethod[$method]['invoices']++;
        $salesByMethod[$method]['units']    += (int)$s['total_qty'];
        $salesByMethod[$method]['revenue']  += $amount;

        if ($method === 'cash') {
            $salesTotals['cash']   += (float)($s['cash_received'] ?? 0);
            $salesTotals['change'] += (float)($s['change_due'] ?? 0);
        }

        $day = date('Y-m-d', strtotime($s['sold_at']));
        if (!isset($salesSeries['revenue'][$day])) {
            $salesSeries['revenue'][$day] = 0.0;
        }
        $salesSeries['revenue'][$day] += $amount;
    }
    $salesTotals['average'] = $salesTotals['invoices'] > 0
        ? $salesTotals['revenue'] / $salesTotals['invoices']
        : 0.0;

    // Zero-fill the axis for the whole range.
    $seriesRevenue = [];
    $seriesLabels  = [];
    for ($d = strtotime($salesFrom); $d <= strtotime($salesTo); $d += 86400) {
        $key = date('Y-m-d', $d);
        $seriesLabels[]                          = date('M j', $d);
        $seriesRevenue[]                         = $salesSeries['revenue'][$key] ?? 0.0;
    }
    $salesSeries['labels']  = $seriesLabels;
    $salesSeries['revenue'] = $seriesRevenue;

    if ($isExport) {
        report_csv(
            'amdpms-sales-' . $salesFrom . '-to-' . $salesTo . '.csv',
            ['Invoice', 'Date & time', 'Cashier', 'Payment method', 'Items', 'Total', 'Cash received', 'Change', 'Reference', 'Batch', 'Status'],
            array_map(static fn(array $s): array => [
                $s['invoice_no'],
                $s['sold_at'],
                $s['user_email'],
                PAYMENT_LABELS[$s['payment_method']] ?? $s['payment_method'],
                $s['total_qty'],
                number_format((float)$s['total_amount'], 2, '.', ''),
                $s['cash_received'] === null ? '' : number_format((float)$s['cash_received'], 2, '.', ''),
                $s['change_due'] === null ? '' : number_format((float)$s['change_due'], 2, '.', ''),
                $s['reference_no'],
                $s['batch_ref'],
                $s['status'],
            ], $salesInvoices)
        );
    }
}

// ===========================================================================
// INVENTORY
// ===========================================================================
elseif ($reportType === 'inventory') {
    $inventoryRows    = [];
    $inventorySummary = ['items' => 0, 'units' => 0, 'value' => 0.0, 'low' => 0, 'out' => 0, 'expiring' => 0, 'expired' => 0];
    $expSoonDate      = date('Y-m-d', strtotime('+90 days'));

    $invRes = $conn->query(
        "SELECT id, channel, sku, name, category, price, stock, reserve_stock, reorder_level, expiry_date, supplier
         FROM medications ORDER BY channel ASC, name ASC"
    );
    while ($row = $invRes->fetch_assoc()) {
        $row['total_stock']  = (int)$row['stock'] + (int)$row['reserve_stock'];
        $row['stock_value']  = (float)$row['price'] * $row['total_stock'];
        $row['is_low']       = $row['total_stock'] < $lowStockThreshold;
        $row['is_out']       = $row['total_stock'] <= 0;
        $row['is_expired']   = !empty($row['expiry_date']) && $row['expiry_date'] < $today;
        $row['is_soon']      = !empty($row['expiry_date']) && !$row['is_expired'] && $row['expiry_date'] <= $expSoonDate;
        $inventoryRows[] = $row;

        $inventorySummary['items']++;
        $inventorySummary['units'] += $row['total_stock'];
        $inventorySummary['value'] += $row['stock_value'];
        if ($row['is_low'])  { $inventorySummary['low']++; }
        if ($row['is_out'])  { $inventorySummary['out']++; }
        if ($row['is_soon']) { $inventorySummary['expiring']++; }
        if ($row['is_expired']) { $inventorySummary['expired']++; }
    }

    // Movement totals for the same window used by the sales ledger.
    $movStmt = $conn->prepare(
        "SELECT reason, COUNT(*) AS events, SUM(qty) AS units
         FROM stock_movements
         WHERE created_at >= ? AND created_at <= ?
         GROUP BY reason ORDER BY events DESC"
    );
    $movements = [];
    if ($movStmt) {
        $movEnd = $salesTo . ' 23:59:59';
        $movStmt->bind_param('ss', $salesFrom, $movEnd);
        if ($movStmt->execute()) {
            $movRes = $movStmt->get_result();
            while ($m = $movRes->fetch_assoc()) {
                $movements[] = $m;
            }
        }
    }

    if ($isExport) {
        report_csv(
            'amdpms-inventory-' . $today . '.csv',
            ['Channel', 'SKU', 'Medication', 'Category', 'Supplier', 'Price', 'Machine stock', 'Reserve stock', 'Total stock', 'Reorder level', 'Stock value', 'Expiry', 'Status'],
            array_map(static fn(array $r): array => [
                $r['channel'],
                $r['sku'],
                $r['name'],
                $r['category'],
                $r['supplier'],
                number_format((float)$r['price'], 2, '.', ''),
                $r['stock'],
                $r['reserve_stock'],
                $r['total_stock'],
                $r['reorder_level'],
                number_format((float)$r['stock_value'], 2, '.', ''),
                $r['expiry_date'],
                $r['is_expired'] ? 'Expired' : ($r['is_out'] ? 'Out of stock' : ($r['is_low'] ? 'Low stock' : 'In stock')),
            ], $inventoryRows)
        );
    }
}

// ===========================================================================
// SYSTEM ACTIVITY
// ===========================================================================
elseif ($reportType === 'audit') {
    $auditTotals    = ['events' => 0, 'today' => 0, 'week' => 0, 'attention' => 0, 'users' => 0, 'critical' => 0];
    $auditByModule  = [];
    $auditByAction  = [];
    $auditBySeverity = ['info' => 0, 'notice' => 0, 'warning' => 0, 'critical' => 0];
    $auditByUser    = [];
    $auditRecent    = [];

    $totRes = $conn->query(
        "SELECT COUNT(*) AS events,
                SUM(created_at >= CURDATE()) AS today,
                SUM(created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS week,
                SUM(severity IN ('warning','critical')) AS attention,
                SUM(severity = 'critical') AS critical,
                COUNT(DISTINCT user_email) AS users
         FROM audit_log"
    );
    if ($totRes && $row = $totRes->fetch_assoc()) {
        $auditTotals = [
            'events'    => (int)$row['events'],
            'today'     => (int)$row['today'],
            'week'      => (int)$row['week'],
            'attention' => (int)$row['attention'],
            'critical'  => (int)$row['critical'],
            'users'     => (int)$row['users'],
        ];
    }

    $modRes = $conn->query(
        "SELECT module, COUNT(*) AS events, COUNT(DISTINCT user_email) AS users
         FROM audit_log GROUP BY module ORDER BY events DESC"
    );
    if ($modRes) {
        while ($m = $modRes->fetch_assoc()) {
            $auditByModule[] = $m;
        }
    }

    $actRes = $conn->query(
        "SELECT action, module, COUNT(*) AS events
         FROM audit_log GROUP BY action, module ORDER BY events DESC LIMIT 15"
    );
    if ($actRes) {
        while ($a = $actRes->fetch_assoc()) {
            $auditByAction[] = $a;
        }
    }

    $sevRes = $conn->query("SELECT severity, COUNT(*) AS events FROM audit_log GROUP BY severity");
    if ($sevRes) {
        while ($s = $sevRes->fetch_assoc()) {
            $auditBySeverity[$s['severity']] = (int)$s['events'];
        }
    }

    // Staff only ever summarise their own trail.
    if ($isAdmin) {
        $usrRes = $conn->query(
            "SELECT user_email, COUNT(*) AS events,
                    SUM(severity IN ('warning','critical')) AS attention,
                    MAX(created_at) AS last_seen
             FROM audit_log
             WHERE user_email IS NOT NULL
             GROUP BY user_email ORDER BY events DESC"
        );
    } else {
        $usrStmt = $conn->prepare(
            "SELECT user_email, COUNT(*) AS events,
                    SUM(severity IN ('warning','critical')) AS attention,
                    MAX(created_at) AS last_seen
             FROM audit_log
             WHERE user_email = ?
             GROUP BY user_email ORDER BY events DESC"
        );
        bind_stmt($usrStmt, 's', [$sessionEmail]);
        $usrStmt->execute();
        $usrRes = $usrStmt->get_result();
    }
    if ($usrRes) {
        while ($u = $usrRes->fetch_assoc()) {
            $auditByUser[] = $u;
        }
    }

    if ($isAdmin) {
        $recRes = $conn->query(
            "SELECT created_at, user_email, module, action, severity, entity, entity_id, details, ip_address
             FROM audit_log ORDER BY created_at DESC LIMIT 50"
        );
    } else {
        $recStmt = $conn->prepare(
            "SELECT created_at, user_email, module, action, severity, entity, entity_id, details, ip_address
             FROM audit_log WHERE user_email = ? ORDER BY created_at DESC LIMIT 50"
        );
        bind_stmt($recStmt, 's', [$sessionEmail]);
        $recStmt->execute();
        $recRes = $recStmt->get_result();
    }
    if ($recRes) {
        while ($r = $recRes->fetch_assoc()) {
            $auditRecent[] = $r;
        }
    }

    if ($isExport) {
        report_csv(
            'amdpms-activity-summary-' . $today . '.csv',
            ['Metric', 'Value'],
            array_merge(
                [
                    ['Total events', $auditTotals['events']],
                    ['Events today', $auditTotals['today']],
                    ['Events last 7 days', $auditTotals['week']],
                    ['Warnings and critical', $auditTotals['attention']],
                    ['Active accounts', $auditTotals['users']],
                ],
                array_map(
                    static fn(array $r): array => ['Module: ' . $r['module'], (int)$r['events']],
                    $auditByModule
                ),
                array_map(
                    static fn(array $r): array => ['Action: ' . $r['action'], (int)$r['events']],
                    $auditByAction
                )
            )
        );
    }
}

// ===========================================================================
// ANALYTICS
// ===========================================================================
elseif ($reportType === 'analytics') {
    $topMeds      = [];
    $staffPerf    = [];
    $categoryVal  = [];
    $expiryRows   = [];
    $methodMix    = [];
    $channelValue = [];

    // Revenue comes from sale_items, priced at the time of sale.
    $tmRes = $conn->query(
        "SELECT si.medication_name, si.medication_sku, m.channel,
                SUM(si.qty) AS units, SUM(si.subtotal) AS revenue
         FROM sale_items si
         INNER JOIN sales s ON s.id = si.sale_id AND s.status = 'completed'
         LEFT JOIN medications m ON m.sku = si.medication_sku
         GROUP BY si.medication_sku, si.medication_name, m.channel
         ORDER BY revenue DESC LIMIT 10"
    );
    if ($tmRes) {
        while ($r = $tmRes->fetch_assoc()) {
            $topMeds[] = $r;
        }
    }

    $spRes = $conn->query(
        "SELECT user_email, COUNT(*) AS invoices, SUM(total_qty) AS units, SUM(total_amount) AS revenue
         FROM sales
         WHERE user_email IS NOT NULL AND status = 'completed'
         GROUP BY user_email ORDER BY revenue DESC"
    );
    if ($spRes) {
        while ($r = $spRes->fetch_assoc()) {
            $staffPerf[] = $r;
        }
    }

    $cvRes = $conn->query(
        "SELECT category, SUM(stock + reserve_stock) AS units, SUM(price * (stock + reserve_stock)) AS value
         FROM medications GROUP BY category ORDER BY value DESC"
    );
    if ($cvRes) {
        while ($r = $cvRes->fetch_assoc()) {
            $categoryVal[] = $r;
        }
    }

    $chRes = $conn->query(
        "SELECT channel, COUNT(*) AS items, SUM(stock + reserve_stock) AS units, SUM(price * (stock + reserve_stock)) AS value
         FROM medications GROUP BY channel ORDER BY channel ASC"
    );
    if ($chRes) {
        while ($r = $chRes->fetch_assoc()) {
            $channelValue[] = $r;
        }
    }

    $mxRes = $conn->query(
        "SELECT payment_method, COUNT(*) AS invoices, SUM(total_amount) AS revenue
         FROM sales WHERE status = 'completed'
         GROUP BY payment_method ORDER BY revenue DESC"
    );
    if ($mxRes) {
        while ($r = $mxRes->fetch_assoc()) {
            $methodMix[] = $r;
        }
    }

    $exRes = $conn->query(
        "SELECT name, sku, stock, reserve_stock, expiry_date
         FROM medications WHERE expiry_date IS NOT NULL
         ORDER BY expiry_date ASC"
    );
    if ($exRes) {
        while ($r = $exRes->fetch_assoc()) {
            $expiryRows[] = $r;
        }
    }

    if ($isExport) {
        report_csv(
            'amdpms-analytics-' . $today . '.csv',
            ['Metric', 'Detail 1', 'Detail 2', 'Detail 3', 'Detail 4'],
            array_merge(
                array_map(
                    static fn(array $r): array => ['Top medication', $r['medication_name'], $r['medication_sku'], (int)$r['units'], number_format((float)$r['revenue'], 2, '.', '')],
                    $topMeds
                ),
                array_map(
                    static fn(array $r): array => ['Staff performance', $r['user_email'], '', (int)$r['invoices'], number_format((float)$r['revenue'], 2, '.', '')],
                    $staffPerf
                ),
                array_map(
                    static fn(array $r): array => ['Category value', $r['category'], '', (int)$r['units'], number_format((float)$r['value'], 2, '.', '')],
                    $categoryVal
                ),
                array_map(
                    static fn(array $r): array => ['Payment method', PAYMENT_LABELS[$r['payment_method']] ?? $r['payment_method'], '', (int)$r['invoices'], number_format((float)$r['revenue'], 2, '.', '')],
                    $methodMix
                )
            )
        );
    }
}

// ---------------------------------------------------------------------------
// Record that the report was produced
// ---------------------------------------------------------------------------
$reportScope = [
    'daily'     => $dailyLabel,
    'monthly'   => $monthLabel,
    'sales'     => $rangeLabel,
    'inventory' => 'as of ' . date('M j, Y'),
    'audit'     => 'all time',
    'analytics' => 'all time',
][$reportType];

if ($isExport) {
    log_activity(
        'report_exported',
        'reports',
        'Exported the ' . strtolower($reportName) . ' report to CSV (' . $reportScope . ').',
        'notice',
        'report',
        $reportType
    );
    exit;
}

log_activity(
    'report_viewed',
    'reports',
    'Generated the ' . strtolower($reportName) . ' report (' . $reportScope . ').',
    'info',
    'report',
    $reportType
);

// ---------------------------------------------------------------------------
// Chart payloads
// ---------------------------------------------------------------------------
$hourlyChart = [];
if ($reportType === 'daily' && array_sum($byHour) > 0) {
    $hourlyChart = [
        'labels' => array_map(static fn(int $h): string => sprintf('%02d:00', $h), array_keys($byHour)),
        'values' => array_values($byHour),
    ];
}
$monthChart  = $reportType === 'monthly'   ? $monthSeries : null;
$salesChart  = $reportType === 'sales'     ? ['labels' => $seriesLabels, 'values' => $seriesRevenue] : null;
$mixChart    = $reportType === 'analytics' && $methodMix
    ? [
        'labels' => array_map(static fn(array $r): string => PAYMENT_LABELS[$r['payment_method']] ?? $r['payment_method'], $methodMix),
        'values' => array_map(static fn(array $r): float => (float)$r['revenue'], $methodMix),
    ]
    : null;

$includeCharts = $hourlyChart !== [] || $monthChart !== null || $salesChart !== null || $mixChart !== null;

// The current report's parameters, reused by the Reset / Print / Export links.
$reportParams = ['type' => $reportType] + match ($reportType) {
    'daily'   => ['date' => $dailyDate],
    'monthly' => ['month' => $month],
    'sales'   => ['from' => $salesFrom, 'to' => $salesTo],
    default   => [],
};

// Suggested name for the "Save as PDF" print dialog (browsers use the document
// title as the default filename).
$pdfFilename = trim(preg_replace('/[^A-Za-z0-9]+/', '-', 'AMDPMS ' . $reportName . ' ' . $reportScope), '-');

$pageTitle = $reportName . ' | ' . SYSTEM_NAME_FULL;
include("includes/site-header.php");
?>

<div class="page-head">
    <div>
        <h1 class="page-title"><?= htmlspecialchars($reportName) ?></h1>
        <p class="page-subtitle">
            <?php
            echo match ($reportType) {
                'daily'     => 'Every sale recorded on ' . htmlspecialchars($dailyLabel) . ', with payment mix and hourly revenue.',
                'monthly'   => 'Day-by-day performance for ' . htmlspecialchars($monthLabel) . '.',
                'sales'     => 'Every paid invoice from ' . htmlspecialchars($rangeLabel) . ' (' . $rangeDays . ' day' . ($rangeDays === 1 ? '' : 's') . ').',
                'inventory' => 'Current stock, valuation, and expiry risk as of ' . htmlspecialchars(date('M j, Y', strtotime($today))) . '.',
                'audit'     => $isAdmin
                    ? 'A summary of every recorded action across the system.'
                    : 'A summary of the actions recorded on your account.',
                'analytics' => 'Operational insight across sales, staff, categories, and payment methods.',
            };
            ?>
        </p>
    </div>
    <div class="page-head-actions">
        <a class="btn-secondary" href="reports.php?<?= htmlspecialchars(http_build_query($reportParams)) ?>">Reset</a>
        <a class="btn-secondary" href="reports.php?<?= htmlspecialchars(http_build_query($reportParams + ['print' => '1'])) ?>" target="_blank" rel="noopener">Print</a>
        <button type="button" class="btn-secondary" data-print-pdf="<?= htmlspecialchars($pdfFilename) ?>">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Save as PDF
        </button>
        <a class="btn-primary" href="reports.php?<?= htmlspecialchars(http_build_query($reportParams + ['export' => 'csv'])) ?>">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Export CSV
        </a>
    </div>
</div>

<nav class="report-tabs" aria-label="Report types">
    <?php foreach (REPORT_TYPES as $key => $label): ?>
        <a href="reports.php?type=<?= $key ?>" class="report-tab <?= $reportType === $key ? 'active' : '' ?>"
           <?= $reportType === $key ? 'aria-current="page"' : '' ?>><?= htmlspecialchars($label) ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($reportType === 'daily'): ?>
    <!-- ================= DAILY ================= -->
    <form method="get" action="reports.php" class="card report-filter">
        <input type="hidden" name="type" value="daily">
        <div class="form-group report-filter-field">
            <label for="dailyDate">Select date</label>
            <input type="date" id="dailyDate" name="date" value="<?= htmlspecialchars($dailyDate) ?>" max="<?= $today ?>" required>
        </div>
        <div class="form-group report-filter-field">
            <label for="dailyQuick">Quick pick</label>
            <select id="dailyQuick" data-param="date">
                <option value="">Quick pick…</option>
                <?php
                $quickDates = [
                    'Today'           => $today,
                    'Yesterday'       => date('Y-m-d', strtotime('-1 day')),
                    'A week ago'      => date('Y-m-d', strtotime('-7 days')),
                    'First of month'  => date('Y-m-01'),
                ];
                foreach ($quickDates as $label => $value):
                    // Only pre-select when the date is one of the presets.
                    $isPreset = in_array($dailyDate, $quickDates, true);
                ?>
                    <option value="<?= htmlspecialchars($value) ?>" <?= $isPreset && $dailyDate === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group report-filter-field report-filter-submit">
            <button type="submit" class="btn-primary btn-report">Generate report</button>
        </div>
    </form>

    <div class="report-stats">
        <div class="stat-card">
            <p class="stat-label">Invoices</p>
            <p class="stat-value"><?= number_format($dailyTotals['invoices']) ?></p>
            <p class="stat-hint"><?= htmlspecialchars($dailyLabel) ?></p>
        </div>
        <div class="stat-card">
            <p class="stat-label">Revenue</p>
            <p class="stat-value stat-ok">₱<?= number_format($dailyTotals['revenue'], 2) ?></p>
            <p class="stat-hint">₱<?= number_format($dailyTotals['average'], 2) ?> avg. sale</p>
        </div>
        <div class="stat-card">
            <p class="stat-label">Units sold</p>
            <p class="stat-value"><?= number_format($dailyTotals['units']) ?></p>
            <p class="stat-hint"><?= number_format($dailyUnitsOut) ?> released to machine</p>
        </div>
        <div class="stat-card">
            <p class="stat-label">Cash payments</p>
            <p class="stat-value">₱<?= number_format($dailyTotals['cash'], 2) ?></p>
            <p class="stat-hint"><?= count($dailyByPayment) ?> method<?= count($dailyByPayment) === 1 ? '' : 's' ?> used</p>
        </div>
    </div>

    <?php if ($hourlyChart): ?>
        <div class="card chart-card">
            <h2 class="admin-section-title" style="margin-top:0;">Revenue by hour</h2>
            <p class="chart-empty" id="hourlyChartEmpty">Chart library failed to load. Connect to the internet to view charts.</p>
            <div class="chart-wrap"><canvas id="hourlyChart" height="100" role="img" aria-label="Bar chart of revenue by hour of day"></canvas></div>
        </div>
    <?php endif; ?>

    <div class="report-columns">
        <div class="card">
            <h2 class="admin-section-title" style="margin-top:0;">Payment mix</h2>
            <?php if ($dailyByPayment): ?>
                <table class="admin-table">
                    <thead><tr><th>Method</th><th>Sales</th><th>Share</th><th>Revenue</th></tr></thead>
                    <tbody>
                    <?php foreach ($dailyByPayment as $method => $agg): ?>
                        <tr>
                            <td><span class="module-chip"><?= htmlspecialchars(PAYMENT_LABELS[$method] ?? $method) ?></span></td>
                            <td><?= (int)$agg['count'] ?></td>
                            <td><?= $dailyTotals['revenue'] > 0 ? number_format($agg['revenue'] / $dailyTotals['revenue'] * 100, 1) : '0.0' ?>%</td>
                            <td>₱<?= number_format($agg['revenue'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="cart-empty">No sales on this date.</p>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2 class="admin-section-title" style="margin-top:0;">Most sold</h2>
            <?php if ($dailyTopItems): ?>
                <table class="admin-table">
                    <thead><tr><th>Medication</th><th>Units</th><th>Revenue</th></tr></thead>
                    <tbody>
                    <?php foreach ($dailyTopItems as $it): ?>
                        <tr>
                            <td>
                                <?= htmlspecialchars((string)$it['medication_name']) ?>
                                <br><small class="cell-sub"><?= htmlspecialchars((string)$it['medication_sku']) ?></small>
                            </td>
                            <td><?= (int)$it['units'] ?></td>
                            <td>₱<?= number_format((float)$it['revenue'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="cart-empty">Nothing sold on this date.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <h2 class="admin-section-title" style="margin-top:0;">Every sale on <?= htmlspecialchars($dailyLabel) ?></h2>
        <?php if ($dailyInvoices): ?>
            <div class="table-scroll">
                <table class="admin-table">
                    <thead>
                        <tr><th>Invoice</th><th>Time</th><th>Cashier</th><th>Method</th><th>Items</th><th>Total</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($dailyInvoices as $s): ?>
                        <tr>
                            <td><code class="action-code"><?= htmlspecialchars($s['invoice_no']) ?></code></td>
                            <td class="cell-nowrap"><?= htmlspecialchars(date('H:i', strtotime($s['sold_at']))) ?></td>
                            <td class="cell-wrap"><?= htmlspecialchars((string)($s['user_email'] ?? '—')) ?></td>
                            <td><span class="module-chip"><?= htmlspecialchars(PAYMENT_LABELS[$s['payment_method']] ?? $s['payment_method']) ?></span></td>
                            <td><?= (int)$s['total_qty'] ?></td>
                            <td class="peso">₱<?= number_format((float)$s['total_amount'], 2) ?></td>
                            <td>
                                <a class="link-primary" href="receipt.php?invoice=<?= urlencode($s['invoice_no']) ?>">Receipt</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="cart-empty">No sales were recorded on <?= htmlspecialchars($dailyLabel) ?>. Pick another date, or run a checkout to create one.</p>
        <?php endif; ?>
    </div>

<?php elseif ($reportType === 'monthly'): ?>
    <!-- ================= MONTHLY ================= -->
    <form method="get" action="reports.php" class="card report-filter">
        <input type="hidden" name="type" value="monthly">
        <div class="form-group report-filter-field">
            <label for="monthPick">Select month</label>
            <input type="month" id="monthPick" name="month" value="<?= htmlspecialchars($month) ?>" max="<?= date('Y-m') ?>" required>
        </div>
        <div class="form-group report-filter-field report-filter-submit">
            <button type="submit" class="btn-primary btn-report">Generate report</button>
        </div>
    </form>

    <div class="report-stats">
        <div class="stat-card">
            <p class="stat-label">Revenue</p>
            <p class="stat-value stat-ok">₱<?= number_format($monthTotals['revenue'], 2) ?></p>
            <p class="stat-hint"><?= htmlspecialchars($monthLabel) ?></p>
        </div>
        <div class="stat-card">
            <p class="stat-label">Invoices</p>
            <p class="stat-value"><?= number_format($monthTotals['invoices']) ?></p>
            <p class="stat-hint">₱<?= number_format($monthTotals['average'], 2) ?> avg.</p>
        </div>
        <div class="stat-card">
            <p class="stat-label">Units sold</p>
            <p class="stat-value"><?= number_format($monthTotals['units']) ?></p>
            <p class="stat-hint">across the month</p>
        </div>
        <div class="stat-card">
            <p class="stat-label">Trading days</p>
            <p class="stat-value"><?= number_format($monthTotals['days']) ?></p>
            <p class="stat-hint">best ₱<?= number_format($monthTotals['best'], 2) ?></p>
        </div>
    </div>

    <?php if ($monthChart): ?>
        <div class="card chart-card">
            <h2 class="admin-section-title" style="margin-top:0;">Daily revenue — <?= htmlspecialchars($monthLabel) ?></h2>
            <p class="chart-empty" id="monthChartEmpty">Chart library failed to load. Connect to the internet to view charts.</p>
            <div class="chart-wrap"><canvas id="monthChart" height="110" role="img" aria-label="Chart of daily revenue for the month"></canvas></div>
        </div>
    <?php endif; ?>

    <div class="report-columns">
        <div class="card">
            <h2 class="admin-section-title" style="margin-top:0;">Day by day</h2>
            <?php if ($monthByDay): ?>
                <div class="table-scroll">
                    <table class="admin-table">
                        <thead><tr><th>Date</th><th>Invoices</th><th>Units</th><th>Revenue</th></tr></thead>
                        <tbody>
                        <?php foreach ($monthByDay as $day => $d): ?>
                            <tr>
                                <td class="cell-nowrap"><?= htmlspecialchars(date('D, M j', strtotime($day))) ?></td>
                                <td><?= (int)$d['invoices'] ?></td>
                                <td><?= (int)$d['units'] ?></td>
                                <td class="peso">₱<?= number_format((float)$d['revenue'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="cart-empty">No sales in <?= htmlspecialchars($monthLabel) ?>.</p>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2 class="admin-section-title" style="margin-top:0;">Payment methods</h2>
            <?php if ($monthMethods): ?>
                <table class="admin-table">
                    <thead><tr><th>Method</th><th>Sales</th><th>Revenue</th></tr></thead>
                    <tbody>
                    <?php foreach ($monthMethods as $m): ?>
                        <tr>
                            <td><span class="module-chip"><?= htmlspecialchars(PAYMENT_LABELS[$m['payment_method']] ?? $m['payment_method']) ?></span></td>
                            <td><?= (int)$m['invoices'] ?></td>
                            <td class="peso">₱<?= number_format((float)$m['revenue'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <h3 class="admin-sub-title" style="margin-top:24px;">Top sellers this month</h3>
                <table class="admin-table">
                    <thead><tr><th>Medication</th><th>Units</th><th>Revenue</th></tr></thead>
                    <tbody>
                    <?php foreach ($monthTop as $t): ?>
                        <tr>
                            <td><?= htmlspecialchars((string)$t['medication_name']) ?></td>
                            <td><?= (int)$t['units'] ?></td>
                            <td class="peso">₱<?= number_format((float)$t['revenue'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="cart-empty">No sales in <?= htmlspecialchars($monthLabel) ?>.</p>
            <?php endif; ?>
        </div>
    </div>

<?php elseif ($reportType === 'sales'): ?>
    <!-- ================= SALES LEDGER ================= -->
    <form method="get" action="reports.php" class="card report-filter">
        <input type="hidden" name="type" value="sales">
        <div class="form-group report-filter-field">
            <label for="salesFrom">From</label>
            <input type="date" id="salesFrom" name="from" value="<?= htmlspecialchars($salesFrom) ?>" max="<?= $today ?>" required>
        </div>
        <div class="form-group report-filter-field">
            <label for="salesTo">To</label>
            <input type="date" id="salesTo" name="to" value="<?= htmlspecialchars($salesTo) ?>" max="<?= $today ?>" required>
        </div>
        <div class="form-group report-filter-field report-filter-submit">
            <button type="submit" class="btn-primary btn-report">Generate report</button>
        </div>
    </form>

    <div class="report-stats">
        <div class="stat-card">
            <p class="stat-label">Revenue</p>
            <p class="stat-value stat-ok">₱<?= number_format($salesTotals['revenue'], 2) ?></p>
            <p class="stat-hint"><?= htmlspecialchars($rangeLabel) ?></p>
        </div>
        <div class="stat-card">
            <p class="stat-label">Invoices</p>
            <p class="stat-value"><?= number_format($salesTotals['invoices']) ?></p>
            <p class="stat-hint">₱<?= number_format($salesTotals['average'], 2) ?> avg.</p>
        </div>
        <div class="stat-card">
            <p class="stat-label">Units sold</p>
            <p class="stat-value"><?= number_format($salesTotals['units']) ?></p>
            <p class="stat-hint"><?= number_format($rangeDays) ?> day range</p>
        </div>
        <div class="stat-card">
            <p class="stat-label">Cash on hand</p>
            <p class="stat-value">₱<?= number_format($salesTotals['cash'] - $salesTotals['change'], 2) ?></p>
            <p class="stat-hint">net of ₱<?= number_format($salesTotals['change'], 2) ?> change</p>
        </div>
    </div>

    <?php if ($salesTotals['voided'] > 0 || $salesTotals['pending'] > 0): ?>
        <div class="message warn" style="max-width:none;">
            <strong><?= (int)$salesTotals['voided'] ?> voided</strong>
            and <strong><?= (int)$salesTotals['pending'] ?> unfinished</strong>
            invoice(s) in this period are listed below but excluded from every
            revenue figure. A stuck <em>pending</em> sale means a checkout failed
            part-way; check the activity log for <code>payment_failed</code>.
        </div>
    <?php endif; ?>

    <?php if ($salesChart): ?>
        <div class="card chart-card">
            <h2 class="admin-section-title" style="margin-top:0;">Revenue over the period</h2>
            <p class="chart-empty" id="salesChartEmpty">Chart library failed to load. Connect to the internet to view charts.</p>
            <div class="chart-wrap"><canvas id="salesChart" height="100" role="img" aria-label="Line chart of revenue per day"></canvas></div>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2 class="admin-section-title" style="margin-top:0;">Revenue by payment method</h2>
        <?php if ($salesByMethod): ?>
            <table class="admin-table">
                <thead><tr><th>Method</th><th>Invoices</th><th>Units</th><th>Revenue</th><th>Share</th></tr></thead>
                <tbody>
                <?php foreach ($salesByMethod as $method => $agg): ?>
                    <tr>
                        <td><span class="module-chip"><?= htmlspecialchars(PAYMENT_LABELS[$method] ?? $method) ?></span></td>
                        <td><?= number_format($agg['invoices']) ?></td>
                        <td><?= number_format($agg['units']) ?></td>
                        <td class="peso">₱<?= number_format($agg['revenue'], 2) ?></td>
                        <td><?= $salesTotals['revenue'] > 0 ? number_format($agg['revenue'] / $salesTotals['revenue'] * 100, 1) : '0.0' ?>%</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="cart-empty">No sales in this period.</p>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2 class="admin-section-title" style="margin-top:0;">Invoice ledger</h2>
        <?php if ($salesInvoices): ?>
            <div class="table-scroll">
                <table class="admin-table">
                    <thead>
                        <tr><th>Invoice</th><th>Date &amp; time</th><th>Cashier</th><th>Method</th><th>Items</th><th>Total</th><th>Status</th><th>Reference</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($salesInvoices as $s):
                        $statusBadge = $s['status'] === 'completed' ? 'badge-ok'
                            : ($s['status'] === 'pending' ? 'badge-warn' : 'badge-out');
                    ?>
                        <tr>
                            <td><code class="action-code"><?= htmlspecialchars($s['invoice_no']) ?></code></td>
                            <td class="cell-nowrap"><?= htmlspecialchars(date('M j, H:i', strtotime($s['sold_at']))) ?></td>
                            <td class="cell-wrap"><?= htmlspecialchars((string)($s['user_email'] ?? '—')) ?></td>
                            <td><span class="module-chip"><?= htmlspecialchars(PAYMENT_LABELS[$s['payment_method']] ?? $s['payment_method']) ?></span></td>
                            <td><?= (int)$s['total_qty'] ?></td>
                            <td class="peso"><?= $s['status'] === 'completed' ? '₱' . number_format((float)$s['total_amount'], 2) : '—' ?></td>
                            <td><span class="status-badge <?= $statusBadge ?>"><?= htmlspecialchars(ucfirst((string)$s['status'])) ?></span></td>
                            <td class="cell-wrap"><small class="cell-sub"><?= htmlspecialchars((string)($s['reference_no'] ?? '—')) ?></small></td>
                            <td>
                                <?php if ($s['status'] === 'completed'): ?>
                                    <a class="link-primary" href="receipt.php?invoice=<?= urlencode($s['invoice_no']) ?>">Receipt</a>
                                <?php else: ?>
                                    <span class="cell-sub">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (count($salesInvoices) >= 5000): ?>
                <p class="pagination-note">Showing the first 5,000 invoices. Narrow the date range or export the CSV for the full list.</p>
            <?php endif; ?>
        <?php else: ?>
            <p class="cart-empty">No sales between <?= htmlspecialchars(date('M j, Y', strtotime($salesFrom))) ?> and <?= htmlspecialchars(date('M j, Y', strtotime($salesTo))) ?>.</p>
        <?php endif; ?>
    </div>

<?php elseif ($reportType === 'inventory'): ?>
    <!-- ================= INVENTORY ================= -->
    <p class="page-subtitle" style="margin-bottom:20px;">
        Stock levels and valuation as of <?= htmlspecialchars(date('M j, Y', strtotime($today))) ?>.
        <strong>Machine</strong> = units in the dispenser, <strong>Reserve</strong> = storage backup, <strong>Total</strong> = on hand.
        Anything under <?= (int)$lowStockThreshold ?> units total is flagged low, and stock expiring within 90 days is highlighted.
    </p>

    <div class="report-stats">
        <div class="stat-card">
            <p class="stat-label">Items</p>
            <p class="stat-value"><?= number_format($inventorySummary['items']) ?></p>
            <p class="stat-hint">medications tracked</p>
        </div>
        <div class="stat-card">
            <p class="stat-label">Units on hand</p>
            <p class="stat-value"><?= number_format($inventorySummary['units']) ?></p>
            <p class="stat-hint"><?= number_format($inventorySummary['out']) ?> out of stock</p>
        </div>
        <div class="stat-card">
            <p class="stat-label">Inventory value</p>
            <p class="stat-value stat-ok">₱<?= number_format($inventorySummary['value'], 2) ?></p>
            <p class="stat-hint">at current prices</p>
        </div>
        <div class="stat-card">
            <p class="stat-label">Needs reorder</p>
            <p class="stat-value <?= $inventorySummary['low'] > 0 ? 'stat-warn' : '' ?>"><?= number_format($inventorySummary['low']) ?></p>
            <p class="stat-hint"><?= number_format($inventorySummary['expiring']) ?> expiring, <?= number_format($inventorySummary['expired']) ?> expired</p>
        </div>
    </div>

    <?php if ($movements): ?>
        <div class="card">
            <h2 class="admin-section-title" style="margin-top:0;">Stock movements — <?= htmlspecialchars($rangeLabel) ?></h2>
            <table class="admin-table">
                <thead><tr><th>Reason</th><th>Events</th><th>Net units</th></tr></thead>
                <tbody>
                <?php foreach ($movements as $m): ?>
                    <tr>
                        <td><?= htmlspecialchars(ucfirst(str_replace('_', ' ', (string)$m['reason']))) ?></td>
                        <td><?= number_format((int)$m['events']) ?></td>
                        <td><?= number_format((int)$m['units']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2 class="admin-section-title" style="margin-top:0;">Stock on hand</h2>
        <?php if ($inventoryRows): ?>
            <div class="table-scroll">
                <table class="admin-table">
                    <thead>
                        <tr><th>CH</th><th>Medication</th><th>SKU</th><th>Category</th><th>Price</th><th>Machine</th><th>Reserve</th><th>Total</th><th>Value</th><th>Expiry</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($inventoryRows as $inv): ?>
                        <tr>
                            <td><?= (int)$inv['channel'] ?></td>
                            <td>
                                <?= htmlspecialchars($inv['name']) ?>
                                <?php if (!empty($inv['supplier'])): ?>
                                    <br><small class="cell-sub"><?= htmlspecialchars($inv['supplier']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><code class="action-code"><?= htmlspecialchars($inv['sku']) ?></code></td>
                            <td><?= htmlspecialchars($inv['category']) ?></td>
                            <td>₱<?= number_format((float)$inv['price'], 2) ?></td>
                            <td><?= number_format((int)$inv['stock']) ?></td>
                            <td><?= number_format((int)$inv['reserve_stock']) ?></td>
                            <td class="<?= $inv['is_low'] ? 'stat-warn' : '' ?>"><?= number_format((int)$inv['total_stock']) ?></td>
                            <td class="peso">₱<?= number_format($inv['stock_value'], 2) ?></td>
                            <td class="cell-nowrap">
                                <?php if (!empty($inv['expiry_date'])): ?>
                                    <span class="status-badge <?= $inv['is_expired'] ? 'badge-out' : ($inv['is_soon'] ? 'badge-warn' : 'badge-ok') ?>">
                                        <?= htmlspecialchars(date('M j, Y', strtotime($inv['expiry_date']))) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="cell-sub">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($inv['is_expired']): ?>
                                    <span class="status-badge badge-out">Expired</span>
                                <?php elseif ($inv['is_out']): ?>
                                    <span class="status-badge badge-out">Out of stock</span>
                                <?php elseif ($inv['is_low']): ?>
                                    <span class="status-badge badge-warn">Low stock</span>
                                <?php else: ?>
                                    <span class="status-badge badge-ok">In stock</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="cart-empty">No medications in inventory yet.</p>
        <?php endif; ?>
    </div>

<?php elseif ($reportType === 'audit'): ?>
    <!-- ================= SYSTEM ACTIVITY ================= -->
    <div class="report-stats">
        <div class="stat-card">
            <p class="stat-label">Total events</p>
            <p class="stat-value"><?= number_format($auditTotals['events']) ?></p>
            <p class="stat-hint"><?= number_format($auditTotals['today']) ?> today</p>
        </div>
        <div class="stat-card">
            <p class="stat-label">Last 7 days</p>
            <p class="stat-value"><?= number_format($auditTotals['week']) ?></p>
            <p class="stat-hint">rolling window</p>
        </div>
        <div class="stat-card">
            <p class="stat-label">Needs attention</p>
            <p class="stat-value <?= $auditTotals['attention'] > 0 ? 'stat-warn' : '' ?>"><?= number_format($auditTotals['attention']) ?></p>
            <p class="stat-hint"><?= number_format($auditTotals['critical']) ?> critical</p>
        </div>
        <div class="stat-card">
            <p class="stat-label">Active accounts</p>
            <p class="stat-value"><?= number_format($auditTotals['users']) ?></p>
            <p class="stat-hint">recorded activity</p>
        </div>
    </div>

    <div class="report-columns">
        <div class="card">
            <h2 class="admin-section-title" style="margin-top:0;">Events by module</h2>
            <?php if ($auditByModule): ?>
                <table class="admin-table">
                    <thead><tr><th>Module</th><th>Events</th><th>Users</th></tr></thead>
                    <tbody>
                    <?php foreach ($auditByModule as $m): ?>
                        <tr>
                            <td>
                                <a class="link-primary" href="audit-log.php?module=<?= urlencode((string)$m['module']) ?>">
                                    <?= htmlspecialchars(ucfirst((string)$m['module'])) ?>
                                </a>
                            </td>
                            <td><?= number_format((int)$m['events']) ?></td>
                            <td><?= number_format((int)$m['users']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="cart-empty">No activity recorded yet.</p>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2 class="admin-section-title" style="margin-top:0;">Severity breakdown</h2>
            <table class="admin-table">
                <thead><tr><th>Severity</th><th>Events</th><th>Share</th></tr></thead>
                <tbody>
                <?php foreach (AUDIT_SEVERITIES as $sev):
                    $count = $auditBySeverity[$sev] ?? 0;
                    $badge = 'badge-' . ($sev === 'critical' ? 'out' : ($sev === 'warning' ? 'warn' : ($sev === 'notice' ? 'alert' : 'ok')));
                ?>
                    <tr>
                        <td>
                            <a class="link-primary" href="audit-log.php?severity=<?= $sev ?>">
                                <span class="status-badge <?= $badge ?>"><?= htmlspecialchars(ucfirst($sev)) ?></span>
                            </a>
                        </td>
                        <td><?= number_format($count) ?></td>
                        <td><?= $auditTotals['events'] > 0 ? number_format($count / $auditTotals['events'] * 100, 1) : '0.0' ?>%</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h3 class="admin-sub-title" style="margin-top:24px;">Most frequent actions</h3>
            <?php if ($auditByAction): ?>
                <table class="admin-table">
                    <thead><tr><th>Action</th><th>Module</th><th>Events</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($auditByAction, 0, 8) as $a): ?>
                        <tr>
                            <td><code class="action-code"><?= htmlspecialchars($a['action']) ?></code></td>
                            <td><span class="module-chip"><?= htmlspecialchars(ucfirst((string)$a['module'])) ?></span></td>
                            <td><?= number_format((int)$a['events']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="cart-empty">No activity recorded yet.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <h2 class="admin-section-title" style="margin-top:0;">Activity by account</h2>
        <?php if ($auditByUser): ?>
            <table class="admin-table">
                <thead><tr><th>Account</th><th>Events</th><th>Needs attention</th><th>Last seen</th></tr></thead>
                <tbody>
                <?php foreach ($auditByUser as $u): ?>
                    <tr>
                        <td class="cell-wrap"><?= htmlspecialchars((string)$u['user_email']) ?></td>
                        <td><?= number_format((int)$u['events']) ?></td>
                        <td><?= $u['attention'] > 0
                            ? '<span class="status-badge badge-warn">' . (int)$u['attention'] . '</span>'
                            : '<span class="cell-sub">none</span>' ?></td>
                        <td class="cell-nowrap"><?= htmlspecialchars($u['last_seen'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="cart-empty">No activity recorded yet.</p>
        <?php endif; ?>
        <p class="pagination-note">
            <a class="link-primary" href="audit-log.php">Open the full activity log to filter, search, and export every event</a>
        </p>
    </div>

    <div class="card">
        <h2 class="admin-section-title" style="margin-top:0;">50 most recent events</h2>
        <?php if ($auditRecent): ?>
            <div class="table-scroll">
                <table class="admin-table">
                    <thead><tr><th>When</th><th>Who</th><th>Module</th><th>Action</th><th>Details</th><th>Severity</th></tr></thead>
                    <tbody>
                    <?php foreach ($auditRecent as $r):
                        $sev = $r['severity'] ?? 'info';
                        $badge = 'badge-' . ($sev === 'critical' ? 'out' : ($sev === 'warning' ? 'warn' : ($sev === 'notice' ? 'alert' : 'ok')));
                    ?>
                        <tr>
                            <td class="cell-nowrap">
                                <?= htmlspecialchars(date('M j, H:i', strtotime($r['created_at']))) ?>
                            </td>
                            <td class="cell-wrap"><?= htmlspecialchars((string)($r['user_email'] ?? '—')) ?></td>
                            <td><span class="module-chip"><?= htmlspecialchars(ucfirst((string)$r['module'])) ?></span></td>
                            <td><code class="action-code"><?= htmlspecialchars($r['action']) ?></code></td>
                            <td class="cell-wrap"><?= htmlspecialchars((string)($r['details'] ?? '—')) ?></td>
                            <td><span class="status-badge <?= $badge ?>"><?= htmlspecialchars(ucfirst($sev)) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="cart-empty">No activity recorded yet.</p>
        <?php endif; ?>
    </div>

<?php elseif ($reportType === 'analytics'): ?>
    <!-- ================= ANALYTICS ================= -->
    <?php if ($mixChart): ?>
        <div class="card chart-card">
            <h2 class="admin-section-title" style="margin-top:0;">Revenue by payment method</h2>
            <p class="chart-empty" id="mixChartEmpty">Chart library failed to load. Connect to the internet to view charts.</p>
            <div class="chart-wrap chart-wrap-pie"><canvas id="mixChart" height="120" role="img" aria-label="Doughnut chart of revenue by payment method"></canvas></div>
        </div>
    <?php endif; ?>

    <div class="report-columns">
        <div class="card">
            <h2 class="admin-section-title" style="margin-top:0;">Top 10 medications by revenue</h2>
            <?php if ($topMeds): ?>
                <div class="table-scroll">
                    <table class="admin-table">
                        <thead><tr><th>Medication</th><th>Channel</th><th>Units</th><th>Revenue</th></tr></thead>
                        <tbody>
                        <?php foreach ($topMeds as $t): ?>
                            <tr>
                                <td>
                                    <?= htmlspecialchars((string)$t['medication_name']) ?>
                                    <br><small class="cell-sub"><?= htmlspecialchars((string)$t['medication_sku']) ?></small>
                                </td>
                                <td><?= $t['channel'] === null ? '<span class="cell-sub">—</span>' : (int)$t['channel'] ?></td>
                                <td><?= number_format((int)$t['units']) ?></td>
                                <td class="peso">₱<?= number_format((float)$t['revenue'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="cart-empty">No sales recorded yet.</p>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2 class="admin-section-title" style="margin-top:0;">Staff performance</h2>
            <?php if ($staffPerf): ?>
                <table class="admin-table">
                    <thead><tr><th>Account</th><th>Sales</th><th>Units</th><th>Revenue</th></tr></thead>
                    <tbody>
                    <?php foreach ($staffPerf as $s): ?>
                        <tr>
                            <td class="cell-wrap"><?= htmlspecialchars((string)$s['user_email']) ?></td>
                            <td><?= number_format((int)$s['invoices']) ?></td>
                            <td><?= number_format((int)$s['units']) ?></td>
                            <td class="peso">₱<?= number_format((float)$s['revenue'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="cart-empty">No sales recorded yet.</p>
            <?php endif; ?>

            <h3 class="admin-sub-title" style="margin-top:24px;">Inventory value by channel</h3>
            <?php if ($channelValue): ?>
                <table class="admin-table">
                    <thead><tr><th>Channel</th><th>Items</th><th>Units</th><th>Value</th></tr></thead>
                    <tbody>
                    <?php foreach ($channelValue as $c): ?>
                        <tr>
                            <td>Channel <?= (int)$c['channel'] ?></td>
                            <td><?= number_format((int)$c['items']) ?></td>
                            <td><?= number_format((int)$c['units']) ?></td>
                            <td class="peso">₱<?= number_format((float)$c['value'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="cart-empty">No inventory data.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="report-columns">
        <div class="card">
            <h2 class="admin-section-title" style="margin-top:0;">Inventory value by category</h2>
            <?php if ($categoryVal): ?>
                <table class="admin-table">
                    <thead><tr><th>Category</th><th>Units</th><th>Value</th></tr></thead>
                    <tbody>
                    <?php foreach ($categoryVal as $c): ?>
                        <tr>
                            <td><?= htmlspecialchars((string)$c['category']) ?></td>
                            <td><?= number_format((int)$c['units']) ?></td>
                            <td class="peso">₱<?= number_format((float)$c['value'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="cart-empty">No inventory data.</p>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2 class="admin-section-title" style="margin-top:0;">Payment method mix</h2>
            <?php if ($methodMix): ?>
                <table class="admin-table">
                    <thead><tr><th>Method</th><th>Sales</th><th>Revenue</th><th>Share</th></tr></thead>
                    <tbody>
                    <?php
                    $totalRevenue = array_sum(array_map(static fn(array $r): float => (float)$r['revenue'], $methodMix));
                    foreach ($methodMix as $m):
                    ?>
                        <tr>
                            <td><span class="module-chip"><?= htmlspecialchars(PAYMENT_LABELS[$m['payment_method']] ?? $m['payment_method']) ?></span></td>
                            <td><?= number_format((int)$m['invoices']) ?></td>
                            <td class="peso">₱<?= number_format((float)$m['revenue'], 2) ?></td>
                            <td><?= $totalRevenue > 0 ? number_format((float)$m['revenue'] / $totalRevenue * 100, 1) : '0.0' ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="cart-empty">No sales recorded yet.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <h2 class="admin-section-title" style="margin-top:0;">Expiry outlook</h2>
        <?php if ($expiryRows):
            $nearDate = date('Y-m-d', strtotime('+30 days'));
            $soonDate = date('Y-m-d', strtotime('+90 days'));
        ?>
            <div class="table-scroll">
                <table class="admin-table">
                    <thead><tr><th>Medication</th><th>SKU</th><th>Total stock</th><th>Expiry</th><th>Days left</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($expiryRows as $e):
                        $daysLeft = (int)floor((strtotime($e['expiry_date']) - strtotime($today)) / 86400);
                    ?>
                        <tr>
                            <td><?= htmlspecialchars($e['name']) ?></td>
                            <td><code class="action-code"><?= htmlspecialchars($e['sku']) ?></code></td>
                            <td><?= number_format((int)$e['stock'] + (int)$e['reserve_stock']) ?></td>
                            <td class="cell-nowrap"><?= htmlspecialchars(date('M j, Y', strtotime($e['expiry_date']))) ?></td>
                            <td class="<?= $daysLeft < 0 ? 'stat-warn' : '' ?>"><?= $daysLeft < 0 ? abs($daysLeft) . ' overdue' : $daysLeft ?></td>
                            <td>
                                <?php if ($e['expiry_date'] < $today): ?>
                                    <span class="status-badge badge-out">Expired</span>
                                <?php elseif ($e['expiry_date'] <= $nearDate): ?>
                                    <span class="status-badge badge-alert">Near expiry</span>
                                <?php elseif ($e['expiry_date'] <= $soonDate): ?>
                                    <span class="status-badge badge-warn">Expiring soon</span>
                                <?php else: ?>
                                    <span class="status-badge badge-ok">OK</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="cart-empty">No expiry dates recorded for any medication.</p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($includeCharts): ?>
<script>
(function () {
    function showEmpty(id) {
        var el = document.getElementById(id);
        if (el) el.style.display = 'block';
    }
    function chart(id, config) {
        var canvas = document.getElementById(id);
        if (!canvas) return;
        if (typeof Chart === 'undefined') { return; }
        var empty = document.getElementById(id + 'Empty');
        if (empty) empty.style.display = 'none';
        new Chart(canvas.getContext('2d'), config);
    }

    var gridColor = 'rgba(148, 163, 184, 0.18)';
    var tickColor = '#5b7371';

    <?php if (!empty($hourlyChart)): ?>
    chart('hourlyChart', {
        type: 'bar',
        data: {
            labels: <?= json_encode($hourlyChart['labels']) ?>,
            datasets: [{
                label: 'Revenue',
                data: <?= json_encode($hourlyChart['values']) ?>,
                backgroundColor: 'rgba(12, 122, 115, 0.65)',
                borderColor: '#0c7a73',
                borderWidth: 1,
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { display: false }, ticks: { color: tickColor, maxTicksLimit: 12 } },
                y: { beginAtZero: true, grid: { color: gridColor }, ticks: { color: tickColor, callback: function (v) { return '₱' + Number(v).toLocaleString(); } } }
            }
        }
    });
    <?php endif; ?>

    <?php if ($monthChart): ?>
    chart('monthChart', {
        data: {
            labels: <?= json_encode($monthChart['labels']) ?>,
            datasets: [
                {
                    type: 'bar',
                    label: 'Revenue',
                    data: <?= json_encode($monthChart['revenue']) ?>,
                    backgroundColor: 'rgba(2, 112, 173, 0.55)',
                    borderColor: '#0270ad',
                    borderWidth: 1,
                    borderRadius: 4,
                    yAxisID: 'y'
                },
                {
                    type: 'line',
                    label: 'Invoices',
                    data: <?= json_encode($monthChart['invoices']) ?>,
                    borderColor: '#0c7a73',
                    backgroundColor: 'rgba(12, 122, 115, 0.12)',
                    fill: false,
                    tension: 0.35,
                    yAxisID: 'y1'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'top' } },
            scales: {
                x: { grid: { display: false }, ticks: { color: tickColor } },
                y: {
                    beginAtZero: true,
                    grid: { color: gridColor },
                    ticks: { color: tickColor, callback: function (v) { return '₱' + Number(v).toLocaleString(); } }
                },
                y1: { position: 'right', beginAtZero: true, grid: { display: false }, ticks: { color: tickColor, precision: 0 } }
            }
        }
    });
    <?php endif; ?>

    <?php if ($salesChart): ?>
    chart('salesChart', {
        type: 'line',
        data: {
            labels: <?= json_encode($salesChart['labels']) ?>,
            datasets: [{
                label: 'Revenue',
                data: <?= json_encode($salesChart['values']) ?>,
                borderColor: '#0c7a73',
                backgroundColor: 'rgba(12, 122, 115, 0.15)',
                fill: true,
                tension: 0.35,
                pointRadius: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { display: false }, ticks: { color: tickColor, maxTicksLimit: 14 } },
                y: { beginAtZero: true, grid: { color: gridColor }, ticks: { color: tickColor, callback: function (v) { return '₱' + Number(v).toLocaleString(); } } }
            }
        }
    });
    <?php endif; ?>

    <?php if ($mixChart): ?>
    chart('mixChart', {
        type: 'doughnut',
        data: {
            labels: <?= json_encode($mixChart['labels']) ?>,
            datasets: [{
                data: <?= json_encode($mixChart['values']) ?>,
                backgroundColor: ['#0c7a73', '#0270ad', '#f59e0b', '#8b5cf6', '#ef4444'],
                borderColor: '#ffffff',
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'right' },
                tooltip: {
                    callbacks: {
                        label: function (item) {
                            var total = item.dataset.data.reduce(function (a, b) { return a + b; }, 0);
                            var share = total > 0 ? ((item.parsed / total) * 100).toFixed(1) : '0.0';
                            return item.label + ': ₱' + Number(item.parsed).toLocaleString(undefined, { minimumFractionDigits: 2 }) + ' (' + share + '%)';
                        }
                    }
                }
            }
        }
    });
    <?php endif; ?>
})();
</script>
<?php endif; ?>

<?php if ($isPrint): ?>
<script>
    // Opened from the Print link (?print=1): send the report straight to the
    // print dialog once the page (and its charts) have finished loading.
    window.addEventListener('load', function () {
        setTimeout(function () { window.print(); }, 250);
    });
</script>
<?php endif; ?>

<?php include("includes/site-footer.php"); ?>
