<?php
require_once __DIR__ . '/auth.php';;
require_once __DIR__ . '/database.php';;
requireLogin();
include("includes/logout.php");
require_once __DIR__ . '/includes/config.php';

// Fetch Arduino / bridge status (best-effort; bridge may be offline).
$arduinoStatus = null;
$bridgeUp = @file_get_contents('http://localhost:3000/status', false, stream_context_create(['http' => ['timeout' => 2]]));
if ($bridgeUp !== false) {
    $decoded = json_decode($bridgeUp, true);
    if (is_array($decoded)) $arduinoStatus = $decoded;
}



$pageTitle = 'Medications | ' . SYSTEM_NAME_FULL;
include("includes/site-header.php");

$conn = db();

// Search + filter + sort parameters.
$searchTerm  = trim($_GET['q'] ?? '');
$catFilter   = trim($_GET['cat'] ?? '');
$lowStock = LOW_STOCK_THRESHOLD;
$statusFilter = trim($_GET['status'] ?? '');
$sort        = trim($_GET['sort'] ?? '');

// Used for highlighting that the list is being filtered.
$activeFilters = ($searchTerm !== '' || $catFilter !== '' || $statusFilter !== '');

// Searching is an activity worth recording: it shows what staff were looking for.
if ($searchTerm !== '') {
    log_activity(
        'catalogue_search',
        'shop',
        'Searched the catalogue for "' . $searchTerm . '"'
            . ($catFilter !== '' ? ' within category ' . $catFilter : '')
            . ($statusFilter !== '' ? ' filtered by ' . $statusFilter : '') . '.',
        'info',
        'search',
        $searchTerm
    );
}

$where = [];
$params = [];
$types = '';

if ($searchTerm !== '') {
    $where[] = "(name LIKE ? OR sku LIKE ? OR category LIKE ?)";
    $like = '%' . $searchTerm . '%';
    $params[] = $like; $types .= 's';
    $params[] = $like; $types .= 's';
    $params[] = $like; $types .= 's';
}
if ($catFilter !== '') {
    $where[] = "category = ?";
    $params[] = $catFilter; $types .= 's';
}
if ($statusFilter === 'in') {
    $where[] = "stock > 0";
} elseif ($statusFilter === 'low') {
    $where[] = "stock > 0 AND stock < {$lowStock}";
} elseif ($statusFilter === 'out') {
    $where[] = "stock < 1";
}

$orderBy = 'channel ASC, id ASC';
$sortWhitelist = [
    'name'       => 'name ASC',
    'name_desc'  => 'name DESC',
    'price_asc'  => 'price ASC',
    'price_desc' => 'price DESC',
    'stock_asc'  => 'stock ASC',
    'stock_desc' => 'stock DESC',
];
if (isset($sortWhitelist[$sort])) {
    $orderBy = $sortWhitelist[$sort];
}

$sql = "SELECT * FROM medications";
if (!empty($where)) {
    $sql .= " WHERE " . implode(' AND ', $where);
}
$sql .= " ORDER BY " . $orderBy;

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$medsResult = $stmt->get_result();

// Distinct categories for the filter dropdown.
$categories = [];
$catResult = $conn->query("SELECT DISTINCT category FROM medications ORDER BY category");
if ($catResult) {
    while ($c = $catResult->fetch_assoc()) $categories[] = $c['category'];
}

$products = [];
while ($m = $medsResult->fetch_assoc()) {
    $products[] = [
        'name'   => $m['name'],
        'sku'    => $m['sku'],
        'stock'  => (int)$m['stock'],
        // Numeric price for the cart maths, plus the formatted label for display.
        'price'  => (float)$m['price'],
        'unit'   => '₱' . number_format((float)$m['price'], 2),
        'tag'    => $m['category'],
        'status' => $m['stock'] > 0 ? ($m['stock'] < LOW_STOCK_THRESHOLD ? 'low' : 'ok') : 'out',
        'icon'   => $m['icon'],
        'expiry' => $m['expiry_date'] ?? null,
        'lot'    => $m['lot_number'] ?? null,
    ];
}
$conn->close();

// Keep the physical dispenser's status LEDs in sync with the database stock.
try {
    $syncConn = db();
    if ($syncConn) {
        $stockArr = [0, 0, 0, 0, 0];
        $sr = $syncConn->query("SELECT channel, stock FROM medications WHERE channel BETWEEN 1 AND 5");
        if ($sr) {
            while ($row = $sr->fetch_assoc()) {
                $stockArr[(int)$row['channel'] - 1] = max(0, (int)$row['stock']);
            }
        }
        $syncConn->close();
        $syncBody = json_encode(['stock' => $stockArr]);
        @file_get_contents('http://localhost:3000/sync-stock', false, stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\n",
                'content' => $syncBody,
                'timeout' => 4,
            ],
        ]));
    }
} catch (Throwable $e) { /* best-effort sync; ignore */ }
?>

<h1 class="page-title">Medication inventory</h1>
<p class="page-subtitle"><?= $searchTerm !== '' ? 'Showing results for “' . htmlspecialchars($searchTerm) . '”.' : 'Manage formulary items, stock levels, and dispensing from the automated system.' ?></p>

<?php
$arduinoConnected = $arduinoStatus['connected'] ?? false;
$arduinoReady     = $arduinoStatus['ready'] ?? null; // null = not reported
$arduinoHealthy   = $arduinoConnected && $arduinoReady !== false;
$arduinoError    = $arduinoStatus['error'] ?? null;
$arduinoPort     = $arduinoStatus['port'] ?? null;
?>

<div class="message <?= $arduinoHealthy ? 'success' : 'error' ?>" style="max-width:none;margin-bottom:24px;">
    <strong>Dispenser hardware:</strong>
    <?php if ($arduinoStatus === null): ?>
        🔌 Arduino bridge is OFFLINE (start it with <code>node server.js</code> in <code>arduino-bridge/</code>).
    <?php elseif ($arduinoConnected && $arduinoReady === false): ?>
        ⚠️ Arduino is connected<?= $arduinoPort ? ' on ' . htmlspecialchars($arduinoPort) : '' ?>, but the <strong>PCA9685 servo driver is NOT responding</strong>. Nothing can be dispensed. <?= htmlspecialchars($arduinoError ?? 'Check the servo driver wiring and 5V supply.') ?>
    <?php elseif ($arduinoConnected): ?>
        ✅ Arduino is <strong>CONNECTED</strong><?= $arduinoPort ? ' on ' . htmlspecialchars($arduinoPort) : '' ?> — ready to dispense.
    <?php else: ?>
        ⚠️ Arduino is <strong>NOT connected</strong>. <?= htmlspecialchars($arduinoError ?? 'Plug in the USB cable.') ?>
    <?php endif; ?>
</div>

<!-- Filter / sort bar -->
<form method="get" action="shop.php" class="filter-bar" role="search" aria-label="Filter and sort medications">
    <?php if ($searchTerm !== ''): ?>
        <input type="hidden" name="q" value="<?= htmlspecialchars($searchTerm) ?>">
    <?php endif; ?>
    <div class="form-group report-filter-field">
        <label for="filterCat">Category</label>
        <select name="cat" id="filterCat">
            <option value="">All categories</option>
            <?php foreach ($categories as $c): ?>
                <option value="<?= htmlspecialchars($c) ?>" <?= $catFilter === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group report-filter-field">
        <label for="filterStatus">Stock status</label>
        <select name="status" id="filterStatus">
            <option value="">All stock</option>
            <option value="in" <?= $statusFilter === 'in' ? 'selected' : '' ?>>In stock</option>
            <option value="low" <?= $statusFilter === 'low' ? 'selected' : '' ?>>Low stock</option>
            <option value="out" <?= $statusFilter === 'out' ? 'selected' : '' ?>>Out of stock</option>
        </select>
    </div>
    <div class="form-group report-filter-field">
        <label for="filterSort">Sort by</label>
        <select name="sort" id="filterSort">
            <option value="">Channel</option>
            <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>Name (A–Z)</option>
            <option value="name_desc" <?= $sort === 'name_desc' ? 'selected' : '' ?>>Name (Z–A)</option>
            <option value="price_asc" <?= $sort === 'price_asc' ? 'selected' : '' ?>>Price (low to high)</option>
            <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>>Price (high to low)</option>
            <option value="stock_asc" <?= $sort === 'stock_asc' ? 'selected' : '' ?>>Stock (low to high)</option>
            <option value="stock_desc" <?= $sort === 'stock_desc' ? 'selected' : '' ?>>Stock (high to low)</option>
        </select>
    </div>
    <div class="filter-actions">
        <button type="submit" class="btn-primary btn-report">Apply</button>
        <?php if ($activeFilters): ?>
            <a href="shop.php" class="filter-clear">Clear filters</a>
        <?php endif; ?>
    </div>
</form>

<?php if ($activeFilters && count($products) === 0): ?>
    <div class="card" style="text-align:center;padding:48px 24px;">
        <p style="font-size:2.5rem;margin-bottom:12px;">🔍</p>
        <h3 style="color:var(--primary-dark);margin-bottom:8px;">No medications found</h3>
        <p style="color:var(--text-muted);">No items match your search or filters. Try adjusting them.</p>
        <p style="margin-top:20px;"><a class="link-primary" href="shop.php">Clear search &amp; filters</a></p>
    </div>
<?php elseif (count($products) === 0): ?>
    <div class="card" style="text-align:center;padding:48px 24px;">
        <p style="font-size:2.5rem;margin-bottom:12px;">📦</p>
        <h3 style="color:var(--primary-dark);margin-bottom:8px;">No medications yet</h3>
        <p style="color:var(--text-muted);">Add medications from the admin inventory panel to get started.</p>
    </div>
<?php else: ?>
<div class="product-grid">
    <?php foreach ($products as $p):
        $stock = (int)$p['stock'];
        $statusClass = $p['status'] === 'ok' ? 'badge-ok' : ($p['status'] === 'low' ? 'badge-warn' : 'badge-out');
        $statusLabel = $p['status'] === 'ok' ? 'In stock' : ($p['status'] === 'low' ? 'Low stock' : 'Out of stock');
        $barClass    = $p['status'] === 'ok' ? 'bar-ok' : ($p['status'] === 'low' ? 'bar-warn' : 'bar-out');
        $barPct      = $stock < 1 ? 8 : min(STOCK_REFERENCE, $stock);
        $barCaption  = $p['status'] === 'ok' ? 'Healthy stock' : ($p['status'] === 'low' ? 'Reorder recommended' : 'Out of stock');
        $expiryNote  = '';
        $expiryBadge = '';
        $expiredFlag = false;
        if (!empty($p['expiry'])) {
            if ($p['expiry'] < date('Y-m-d')) {
                $expiredFlag = true;
                $expiryBadge = 'badge-out';
                $expiryNote = '🚫 EXPIRED ' . htmlspecialchars($p['expiry']);
            } elseif ($p['expiry'] <= date('Y-m-d', strtotime('+90 days'))) {
                $expiryBadge = 'badge-warn';
                $expiryNote = '⏳ Expires ' . htmlspecialchars($p['expiry']);
            } else {
                $expiryNote = 'Exp: ' . htmlspecialchars($p['expiry']);
            }
        }
    ?>
    <article class="product-card" data-sku="<?= htmlspecialchars($p['sku']) ?>">
        <div class="thumb" aria-hidden="true"><?= $p['icon'] ?></div>
        <div class="body">
            <h3><?= htmlspecialchars($p['name']) ?></h3>
            <p class="product-meta">SKU: <?= htmlspecialchars($p['sku']) ?></p>
            <p class="price"><?= htmlspecialchars($p['unit']) ?> <span class="price-unit">/ unit</span></p>
            <p class="stock-line">Qty on hand: <strong><?= $stock ?></strong></p>
            <?php if ($p['lot'] || $expiryNote !== ''): ?>
            <p class="product-meta">
                <?= $p['lot'] ? 'Lot: ' . htmlspecialchars($p['lot']) : '' ?><?= $p['lot'] && $expiryNote !== '' ? ' · ' : '' ?><?= $expiryNote ?>
            </p>
            <?php endif; ?>
            <?php if ($expiryBadge !== ''): ?>
            <span class="status-badge <?= $expiryBadge ?>"><?= $expiryNote ?></span>
            <?php endif; ?>
            <div class="stock-bar" aria-hidden="true">
                <div class="stock-bar-fill <?= $barClass ?>" style="width:<?= $barPct ?>%"></div>
            </div>
            <p class="stock-bar-caption"><?= $barCaption ?></p>
            <span class="tag"><?= htmlspecialchars($p['tag']) ?></span>
            <span class="status-badge stock-status-badge <?= $statusClass ?>"><?= $statusLabel ?></span>
            <button type="button" class="btn-add" data-sku="<?= htmlspecialchars($p['sku']) ?>" data-name="<?= htmlspecialchars($p['name']) ?>" data-price="<?= htmlspecialchars($p['unit']) ?>" data-amount="<?= number_format((float)$p['price'], 2, '.', '') ?>" data-stock="<?= $stock ?>" <?= $stock < 1 || $expiredFlag ? 'disabled' : '' ?>><?= $expiredFlag ? 'Expired — not dispensable' : ($stock < 1 ? 'Out of stock' : 'Add to Cart') ?></button>
        </div>
    </article>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<script>
(function () {
    const POLL_MS = 5000;
    var LOW_STOCK = <?= LOW_STOCK_THRESHOLD ?>;

    function badgeFor(stock) {
        return {
            cls: stock > 0 ? (stock < LOW_STOCK ? 'badge-warn' : 'badge-ok') : 'badge-out',
            label: stock > 0 ? (stock < LOW_STOCK ? 'Low stock' : 'In stock') : 'Out of stock'
        };
    }

    function barFor(stock) {
        return {
            cls: stock > 0 ? (stock < LOW_STOCK ? 'bar-warn' : 'bar-ok') : 'bar-out',
            pct: stock < 1 ? 8 : Math.min(<?= STOCK_REFERENCE ?>, stock),
            cap: stock > 0 ? (stock < LOW_STOCK ? 'Reorder recommended' : 'Healthy stock') : 'Out of stock'
        };
    }

    function sync() {
        fetch('stock-status.php', {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        }).then(function (res) {
            if (!res.ok) throw new Error('stock-status unavailable');
            return res.json();
        }).then(function (data) {
            if (!data || !data.ok || !data.stock) return;

            document.querySelectorAll('.product-card').forEach(function (card) {
                const sku = card.getAttribute('data-sku');
                if (sku == null || !(sku in data.stock)) return;

                const stock = data.stock[sku];
                const badge = badgeFor(stock);
                const bar = barFor(stock);

                const qtyEl = card.querySelector('.stock-line strong');
                if (qtyEl) qtyEl.textContent = stock;

                const fill = card.querySelector('.stock-bar-fill');
                if (fill) {
                    fill.style.width = bar.pct + '%';
                    fill.className = 'stock-bar-fill ' + bar.cls;
                }

                const caption = card.querySelector('.stock-bar-caption');
                if (caption) caption.textContent = bar.cap;

                const badgeEl = card.querySelector('.stock-status-badge');
                if (badgeEl) {
                    badgeEl.textContent = badge.label;
                    badgeEl.className = 'status-badge stock-status-badge ' + badge.cls;
                }

                const btn = card.querySelector('.btn-add');
                if (btn) {
                    btn.setAttribute('data-stock', stock);
                    btn.disabled = stock < 1;
                    if (stock < 1 && btn.textContent !== 'Expired — not dispensable') {
                        btn.textContent = 'Out of stock';
                    } else if (stock > 0 && btn.textContent === 'Out of stock') {
                        btn.textContent = 'Add to Cart';
                    }
                }

                if (window.AMDPMS_CART && typeof window.AMDPMS_CART.syncStock === 'function') {
                    window.AMDPMS_CART.syncStock(sku, stock);
                }
            });
        }).catch(function () {
            // Bridge/page offline: keep last known values.
        });
    }

    sync();
    setInterval(sync, POLL_MS);
})();
</script>

<?php include("includes/site-footer.php"); ?>