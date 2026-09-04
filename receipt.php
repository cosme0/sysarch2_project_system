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

// If requested, show a recent receipt for the currently logged-in user.
$recent = isset($_GET['recent']) ? max(0, (int)$_GET['recent']) : 0;
$userEmail = $_SESSION['email'] ?? null;
if ($recent > 0 && $userEmail) {
    $stmt = $conn->prepare("SELECT medication_name, medication_sku, qty, dispensed_at FROM dispense_history WHERE user_email = ? ORDER BY dispensed_at DESC LIMIT ?");
    $stmt->bind_param("si", $userEmail, $recent);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $conn->close();

    // Notify the dispenser (3 long buzzes) when the receipt is shown.
    if (count($rows) > 0) {
        @file_get_contents('http://localhost:3000/buzz', false, stream_context_create([
            'http' => ['timeout' => 2],
        ]));
    }
    ?>
    <main class="site-main">
        <section class="receipt-page">
            <div class="container">
                <h1 class="page-title">Receipt</h1>
                <?php if (count($rows) > 0): ?>
                    <p><strong>Dispensed by:</strong> <?= htmlspecialchars($userEmail) ?></p>
                    <p><strong>Date:</strong> <?= htmlspecialchars(date('M j, Y \@ H:i', strtotime($rows[0]['dispensed_at']))) ?></p>
                    <table class="receipt-table">
                        <thead>
                            <tr><th>Medication</th><th>SKU</th><th>Qty</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r): ?>
                                <tr>
                                    <td><?= htmlspecialchars($r['medication_name']) ?></td>
                                    <td><?= htmlspecialchars($r['medication_sku']) ?></td>
                                    <td><?= (int)$r['qty'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="receipt-actions">
                        <button class="btn" onclick="window.print()">Print receipt</button>
                        <a class="btn" href="shop.php">Back to dashboard</a>
                    </p>
                <?php else: ?>
                    <p>No recent dispense records found for your account.</p>
                    <p><a href="shop.php">Back to dashboard</a></p>
                <?php endif; ?>
            </div>
        </section>
    </main>
    <?php
    exit;
}

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
?>
