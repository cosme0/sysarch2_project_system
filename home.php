<?php
require_once __DIR__ . '/auth.php';;
require_once __DIR__ . '/database.php';;
requireLogin();
requireStaff();
include("includes/logout.php");
require_once __DIR__ . '/includes/config.php';

// Fetch Arduino / bridge status (best-effort; bridge may be offline).
$arduinoStatus = null;
$bridgeUp = @file_get_contents('http://localhost:3000/status', false, stream_context_create(['http' => ['timeout' => 2]]));
if ($bridgeUp !== false) {
    $decoded = json_decode($bridgeUp, true);
    if (is_array($decoded)) $arduinoStatus = $decoded;
}

// --- Fetch live dashboard statistics from the database ---
$dashConn = db();
// Dispensed today
$qToday = $dashConn->query("SELECT COUNT(id) AS c FROM dispense_history WHERE DATE(dispensed_at) = CURDATE()");
$dispensedToday = (int)($qToday ? $qToday->fetch_assoc()['c'] : 0);
// Active prescriptions = medications currently available (in stock)
$qActive = $dashConn->query("SELECT COUNT(id) AS c FROM medications WHERE stock > 0");
$activeRx = (int)($qActive ? $qActive->fetch_assoc()['c'] : 0);
// Low stock alerts (below reorder threshold)
$qLow = $dashConn->query("SELECT COUNT(id) AS c FROM medications WHERE stock < " . LOW_STOCK_THRESHOLD);
$lowStock = (int)($qLow ? $qLow->fetch_assoc()['c'] : 0);


$pageTitle = 'Dashboard | ' . SYSTEM_NAME_FULL;
include("includes/site-header.php");
?>

<section class="hero">
    <h2><?= SYSTEM_NAME_FULL ?></h2>
    <p>Central dashboard for automated medication dispensing, inventory control, and pharmacy operations.</p>
    <a href="shop.php" class="hero-cta">Open medication inventory</a>
</section>

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

<div class="dashboard-stats">
    <div class="stat-card">
        <p class="stat-label">Dispensed today</p>
        <p class="stat-value"><?= $dispensedToday ?></p>
        <p class="stat-hint">Automated &amp; manual transactions</p>
    </div>
    <div class="stat-card">
        <p class="stat-label">Active prescriptions</p>
        <p class="stat-value"><?= $activeRx ?></p>
        <p class="stat-hint">Medications currently in stock</p>
    </div>
    <div class="stat-card">
        <p class="stat-label">Low machine stock</p>
        <p class="stat-value stat-warn"><?= $lowStock ?></p>
        <p class="stat-hint">Below reload threshold — check reserve</p>
    </div>
    <div class="stat-card">
        <p class="stat-label">System status</p>
        <p class="stat-value <?= $arduinoHealthy ? 'stat-ok' : 'stat-warn' ?>"><?= $arduinoHealthy ? 'Online' : 'Offline' ?></p>
        <p class="stat-hint"><?= $arduinoHealthy ? 'Dispenser units connected' : ($arduinoConnected ? 'Servo driver fault' : 'Dispenser bridge offline') ?></p>
    </div>
</div>

<img class="hero-image" src="https://domf5oio6qrcr.cloudfront.net/medialibrary/15900/gettyimages-1342010434.jpg" alt="Automated pharmacy dispensing equipment">

<div class="trust-row">
    <div class="trust-card">
        <div class="icon">&#9881;</div>
        <h3>Automated dispensing</h3>
        <p>Barcode-verified picking with audit trail for every dispensed item.</p>
    </div>
    <div class="trust-card">
        <div class="icon">&#128202;</div>
        <h3>Inventory management</h3>
        <p>Real-time stock levels, expiry tracking, and batch recall support.</p>
    </div>
    <div class="trust-card">
        <div class="icon">&#128274;</div>
        <h3>Secure access</h3>
        <p>Role-based login, session timeout, and account lockout protection.</p>
    </div>
</div>

<?php include("includes/site-footer.php"); ?>
