<?php
include("auth.php");
include("database.php");
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

$conn = mysqli_connect("localhost", "root", "", "db_users");

// Search filter (from the header search bar or ?q=).
$searchTerm = trim($_GET['q'] ?? '');
if ($searchTerm !== '') {
    $like = '%' . $conn->real_escape_string($searchTerm) . '%';
    $stmt = $conn->prepare("SELECT * FROM medications WHERE name LIKE ? OR sku LIKE ? OR category LIKE ? ORDER BY channel ASC, id ASC");
    $stmt->bind_param("sss", $like, $like, $like);
    $stmt->execute();
    $medsResult = $stmt->get_result();
} else {
    $medsResult = $conn->query("SELECT * FROM medications ORDER BY channel ASC, id ASC");
}

$products = [];
while ($m = $medsResult->fetch_assoc()) {
    $products[] = [
        'name'   => $m['name'],
        'sku'    => $m['sku'],
        'stock'  => (int)$m['stock'],
        'unit'   => '₱' . number_format((float)$m['price'], 2),
        'tag'    => $m['category'],
        'status' => $m['stock'] > 0 ? ($m['stock'] < 20 ? 'low' : 'ok') : 'out',
        'icon'   => $m['icon'],
    ];
}
$conn->close();

// Keep the physical dispenser's status LEDs in sync with the database stock.
try {
    $syncConn = mysqli_connect("localhost", "root", "", "db_users");
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
$arduinoError    = $arduinoStatus['error'] ?? null;
$arduinoPort     = $arduinoStatus['port'] ?? null;
?>

<div class="message <?= $arduinoConnected ? 'success' : 'error' ?>" style="max-width:none;margin-bottom:24px;">
    <strong>Dispenser hardware:</strong>
    <?php if ($arduinoStatus === null): ?>
        🔌 Arduino bridge is OFFLINE (start it with <code>node server.js</code> in <code>arduino-bridge/</code>).
    <?php elseif ($arduinoConnected): ?>
        ✅ Arduino is <strong>CONNECTED</strong><?= $arduinoPort ? ' on ' . htmlspecialchars($arduinoPort) : '' ?> — ready to dispense.
    <?php else: ?>
        ⚠️ Arduino is <strong>NOT connected</strong>. <?= htmlspecialchars($arduinoError ?? 'Plug in the USB cable.') ?>
    <?php endif; ?>
</div>

<?php if ($searchTerm !== '' && count($products) === 0): ?>
    <div class="card" style="text-align:center;padding:48px 24px;">
        <p style="font-size:2.5rem;margin-bottom:12px;">🔍</p>
        <h3 style="color:var(--primary-dark);margin-bottom:8px;">No medications found</h3>
        <p style="color:var(--text-muted);">No items match “<?= htmlspecialchars($searchTerm) ?>”. Try a different name, SKU, or category.</p>
        <p style="margin-top:20px;"><a class="link-primary" href="shop.php">Clear search</a></p>
    </div>
<?php else: ?>
<div class="product-grid">
    <?php foreach ($products as $p):
        $statusClass = $p['status'] === 'ok' ? 'badge-ok' : ($p['status'] === 'low' ? 'badge-warn' : 'badge-out');
        $statusLabel = $p['status'] === 'ok' ? 'In stock' : ($p['status'] === 'low' ? 'Low stock' : 'Out of stock');
    ?>
    <article class="product-card">
        <div class="thumb" aria-hidden="true"><?= $p['icon'] ?></div>
        <div class="body">
            <h3><?= htmlspecialchars($p['name']) ?></h3>
            <p class="product-meta">SKU: <?= htmlspecialchars($p['sku']) ?></p>
            <p class="price"><?= htmlspecialchars($p['unit']) ?> <span class="price-unit">/ unit</span></p>
            <p class="stock-line">Qty on hand: <strong><?= (int) $p['stock'] ?></strong></p>
            <span class="tag"><?= htmlspecialchars($p['tag']) ?></span>
            <span class="status-badge <?= $statusClass ?>"><?= $statusLabel ?></span>
            <button type="button" class="btn-add" data-sku="<?= htmlspecialchars($p['sku']) ?>" data-name="<?= htmlspecialchars($p['name']) ?>" data-price="<?= htmlspecialchars($p['unit']) ?>" data-stock="<?= (int) $p['stock'] ?>" <?= $p['stock'] < 1 ? 'disabled' : '' ?>>Add to Cart</button>
        </div>
    </article>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php include("includes/site-footer.php"); ?>
