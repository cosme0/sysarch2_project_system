<?php
/**
 * Receipt - the printed record of a paid transaction.
 *
 * Reached two ways:
 *   ?invoice=INV-...  the normal path after checkout (priced invoice)
 *   ?ref=B...         a dispense batch, used by the legacy cart endpoint
 */
require_once __DIR__ . '/auth.php';;
require_once __DIR__ . '/database.php';;
requireLogin();
include("includes/logout.php");
require_once __DIR__ . '/includes/dispensing.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$conn      = db();
$userEmail = $_SESSION['email'] ?? null;
$isAdmin   = isAdmin();

$invoiceNo = trim($_GET['invoice'] ?? '');
$batchRef  = trim($_GET['ref'] ?? '');
$recent    = isset($_GET['recent']) ? max(0, (int)$_GET['recent']) : 0;

$sale      = null;
$saleItems = [];
$rows      = [];
$simulated = false;
$notFound  = '';

// ---------------------------------------------------------------------------
// Load a priced invoice
//
// The status is deliberately NOT filtered here: a sale that failed at the
// dispenser must still open, so the page can say "not completed" instead of
// pretending the invoice does not exist.
// ---------------------------------------------------------------------------
if ($invoiceNo !== '') {
    $stmt = $conn->prepare(
        "SELECT * FROM sales WHERE invoice_no = ? AND (user_email = ? OR ? = 1)"
    );
    // bind_param() needs variables, so the admin flag is materialised first.
    $isAdminFlag = $isAdmin ? 1 : 0;
    $stmt->bind_param('ssi', $invoiceNo, $userEmail, $isAdminFlag);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows > 0) {
        $sale = $res->fetch_assoc();

        $itemStmt = $conn->prepare(
            "SELECT medication_name, medication_sku, price, qty, subtotal
             FROM sale_items WHERE sale_id = ? ORDER BY id ASC"
        );
        $itemStmt->bind_param('i', $sale['id']);
        $itemStmt->execute();
        $itemRes = $itemStmt->get_result();
        while ($r = $itemRes->fetch_assoc()) {
            $saleItems[] = $r;
        }

        // Did any of these units come from a simulated (offline) release?
        $simStmt = $conn->prepare(
            "SELECT COUNT(*) AS c FROM stock_movements
             WHERE reference = ? AND reason LIKE 'Simulated%'"
        );
        $simStmt->bind_param('s', $sale['batch_ref']);
        $simStmt->execute();
        $simulated = ((int)$simStmt->get_result()->fetch_assoc()['c']) > 0;

        log_activity(
            'receipt_viewed',
            'checkout',
            'Opened receipt ' . $invoiceNo . ' (₱' . number_format((float)$sale['total_amount'], 2) . ', status ' . $sale['status'] . ').',
            'info',
            'invoice',
            $invoiceNo
        );
    } else {
        $notFound = 'That invoice was not found, or it belongs to another account.';
    }
}

// Only a completed sale may be presented as a paid handover.
$saleCompleted = $sale !== null && $sale['status'] === 'completed';

// ---------------------------------------------------------------------------
// Legacy path: a dispense batch with no attached sale
// ---------------------------------------------------------------------------
if ($sale === null && $notFound === '' && ($batchRef !== '' || $recent > 0) && $userEmail) {
    if ($batchRef !== '') {
        $stmt = $conn->prepare(
            "SELECT medication_name, medication_sku, SUM(qty) AS qty, MAX(dispensed_at) AS dispensed_at
             FROM dispense_history
             WHERE user_email = ? AND batch_ref = ?
             GROUP BY medication_name, medication_sku
             ORDER BY MAX(dispensed_at) ASC"
        );
        $stmt->bind_param('ss', $userEmail, $batchRef);
    } else {
        $stmt = $conn->prepare(
            "SELECT medication_name, medication_sku, SUM(qty) AS qty, MAX(dispensed_at) AS dispensed_at
             FROM (
                 SELECT medication_name, medication_sku, qty, dispensed_at
                 FROM dispense_history WHERE user_email = ? ORDER BY id DESC LIMIT ?
             ) t
             GROUP BY medication_name, medication_sku
             ORDER BY dispensed_at ASC"
        );
        $stmt->bind_param('si', $userEmail, $recent);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
    }

    if (!empty($rows)) {
        // The dispenser already sounded its 3-beep "order complete" tone in
        // dispense_order() right after the units were released, so we do not
        // buzz again here to avoid double-beeping on the receipt page.
    }
}

// ---------------------------------------------------------------------------
// Legacy totals
// ---------------------------------------------------------------------------
$totalUnits = 0;
$refCode    = '';
if (!empty($rows)) {
    foreach ($rows as $r) {
        $totalUnits += (int)$r['qty'];
    }
    $refCode = 'REF-' . date('Ymd', strtotime($rows[0]['dispensed_at'])) . '-'
        . strtoupper(substr(md5($userEmail . $rows[0]['dispensed_at']), 0, 6));
}

$hasContent = ($sale !== null) || !empty($rows);

$pageTitle = 'Receipt | ' . SYSTEM_NAME_FULL;
include("includes/site-header.php");
?>

<section class="receipt-page">
    <div class="receipt-card">
        <?php if ($notFound !== ''): ?>
            <div class="receipt-head">
                <div class="receipt-check error" aria-hidden="true">!</div>
                <h1 class="receipt-title">Receipt unavailable</h1>
                <p class="receipt-sub"><?= htmlspecialchars($notFound) ?></p>
            </div>
            <div class="receipt-actions">
                <a class="btn-primary" href="shop.php">Back to medications</a>
            </div>

        <?php elseif ($saleCompleted): ?>
            <!-- ================= PAID INVOICE ================= -->
            <div class="receipt-head">
                <div class="receipt-check" aria-hidden="true">&#10003;</div>
                <h1 class="receipt-title">Payment complete</h1>
                <p class="receipt-sub"><?= htmlspecialchars(PAYMENT_LABELS[$sale['payment_method']] ?? $sale['payment_method']) ?> payment recorded and medication released.</p>
            </div>

            <?php if ($simulated): ?>
                <div class="receipt-sim-banner">
                    SIMULATED DISPENSE &mdash; the dispenser bridge was offline, so no physical unit was released.
                </div>
            <?php endif; ?>

            <div class="receipt-meta">
                <div class="receipt-meta-row">
                    <span>Invoice no.</span>
                    <strong class="mono"><?= htmlspecialchars($sale['invoice_no']) ?></strong>
                </div>
                <div class="receipt-meta-row">
                    <span>Date &amp; time</span>
                    <strong><?= htmlspecialchars(date('M j, Y — h:i A', strtotime($sale['sold_at']))) ?></strong>
                </div>
                <div class="receipt-meta-row">
                    <span>Cashier</span>
                    <strong><?= htmlspecialchars($sale['user_email'] ?? '—') ?></strong>
                </div>
                <div class="receipt-meta-row">
                    <span>Batch ref</span>
                    <strong class="mono"><?= htmlspecialchars($sale['batch_ref'] ?? '—') ?></strong>
                </div>
            </div>

            <div class="receipt-items">
                <div class="receipt-items-head receipt-items-grid">
                    <span>Item</span><span>Price</span><span>Qty</span><span>Amount</span>
                </div>
                <?php foreach ($saleItems as $r): ?>
                <div class="receipt-item receipt-items-grid">
                    <span class="receipt-item-name">
                        <?= htmlspecialchars($r['medication_name']) ?>
                        <small><?= htmlspecialchars($r['medication_sku']) ?></small>
                    </span>
                    <span>₱<?= number_format((float)$r['price'], 2) ?></span>
                    <span>&times; <?= (int)$r['qty'] ?></span>
                    <span>₱<?= number_format((float)$r['subtotal'], 2) ?></span>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="receipt-totals">
                <div class="receipt-total-row">
                    <span>Items</span><strong><?= (int)$sale['total_qty'] ?></strong>
                </div>
                <div class="receipt-total-row">
                    <span>Total</span><strong>₱<?= number_format((float)$sale['total_amount'], 2) ?></strong>
                </div>
                <div class="receipt-total-row">
                    <span>Payment method</span><strong><?= htmlspecialchars(PAYMENT_LABELS[$sale['payment_method']] ?? $sale['payment_method']) ?></strong>
                </div>
                <?php if (!empty($sale['reference_no'])): ?>
                <div class="receipt-total-row">
                    <span>Reference no.</span><strong class="mono"><?= htmlspecialchars($sale['reference_no']) ?></strong>
                </div>
                <?php endif; ?>
                <?php if ($sale['payment_method'] === 'cash'): ?>
                <div class="receipt-total-row">
                    <span>Cash received</span><strong>₱<?= number_format((float)$sale['cash_received'], 2) ?></strong>
                </div>
                <div class="receipt-total-row receipt-total-change">
                    <span>Change</span><strong>₱<?= number_format((float)$sale['change_due'], 2) ?></strong>
                </div>
                <?php endif; ?>
            </div>

            <p class="receipt-note">
                Automatically issued by <?= htmlspecialchars(SYSTEM_ACRONYM) ?>. Keep this receipt for your records.
                No real payment was processed.
            </p>

            <div class="receipt-actions">
                <button type="button" class="btn-primary" data-print-pdf="AMDPMS-Receipt-<?= htmlspecialchars($sale['invoice_no']) ?>">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Save as PDF
                </button>
                <button type="button" class="btn-secondary" onclick="window.print()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                    Print receipt
                </button>
                <a class="btn-secondary" href="shop.php">Back to medications</a>
            </div>

        <?php elseif ($sale !== null): ?>
            <!-- ============ SALE EXISTS BUT DID NOT COMPLETE ============ -->
            <div class="receipt-head">
                <div class="receipt-check error" aria-hidden="true">!</div>
                <h1 class="receipt-title">Transaction not completed</h1>
                <p class="receipt-sub">
                    <?php if ($sale['status'] === 'failed'): ?>
                        The dispenser did not release the medication for this invoice, so the sale failed.
                        Nothing was dispensed.
                    <?php elseif ($sale['status'] === 'pending'): ?>
                        The dispenser never confirmed the release for this invoice, so the sale is still
                        pending and has not been completed.
                    <?php else: ?>
                        This sale is not marked as completed, so no medication was handed over.
                    <?php endif; ?>
                </p>
            </div>

            <div class="receipt-meta">
                <div class="receipt-meta-row">
                    <span>Invoice no.</span>
                    <strong class="mono"><?= htmlspecialchars($sale['invoice_no']) ?></strong>
                </div>
                <div class="receipt-meta-row">
                    <span>Date &amp; time</span>
                    <strong><?= htmlspecialchars(date('M j, Y — h:i A', strtotime($sale['sold_at']))) ?></strong>
                </div>
                <div class="receipt-meta-row">
                    <span>Status</span>
                    <strong><?= htmlspecialchars(strtoupper($sale['status'])) ?></strong>
                </div>
            </div>

            <p class="receipt-note">
                No medication was released for this sale. Ask a pharmacist to check the dispenser
                and run the transaction again.
            </p>

            <div class="receipt-actions">
                <a class="btn-primary" href="shop.php">Back to medications</a>
                <a class="btn-secondary" href="checkout.php">Retry checkout</a>
            </div>

        <?php elseif (!empty($rows)): ?>
            <!-- ================= LEGACY BATCH RECEIPT ================= -->
            <div class="receipt-head">
                <div class="receipt-check" aria-hidden="true">&#10003;</div>
                <h1 class="receipt-title">Dispensing complete</h1>
                <p class="receipt-sub">Items successfully dispensed from the automated system.</p>
            </div>

            <div class="receipt-meta">
                <div class="receipt-meta-row">
                    <span>Dispensed by</span>
                    <strong><?= htmlspecialchars((string)$userEmail) ?></strong>
                </div>
                <div class="receipt-meta-row">
                    <span>Date &amp; time</span>
                    <strong><?= htmlspecialchars(date('M j, Y — h:i A', strtotime($rows[0]['dispensed_at']))) ?></strong>
                </div>
                <div class="receipt-meta-row">
                    <span>Transaction</span>
                    <strong class="mono"><?= htmlspecialchars($refCode) ?></strong>
                </div>
            </div>

            <div class="receipt-items">
                <div class="receipt-items-head">
                    <span>Item</span>
                    <span>Qty</span>
                </div>
                <?php foreach ($rows as $i => $r): ?>
                <div class="receipt-item">
                    <span class="receipt-item-name"><?= ($i + 1) ?>. <?= htmlspecialchars($r['medication_name']) ?>
                        <small><?= htmlspecialchars($r['medication_sku']) ?></small>
                    </span>
                    <span class="receipt-item-qty">&times; <?= (int)$r['qty'] ?></span>
                </div>
                <?php endforeach; ?>
                <div class="receipt-summary">
                    <span><?= count($rows) ?> item<?= count($rows) === 1 ? '' : 's' ?></span>
                    <strong><?= $totalUnits ?> unit<?= $totalUnits === 1 ? '' : 's' ?> dispensed</strong>
                </div>
            </div>

            <p class="receipt-note">Automatically issued by <?= htmlspecialchars(SYSTEM_ACRONYM) ?>. Keep this receipt for your records.</p>

            <div class="receipt-actions">
                <button type="button" class="btn-primary" data-print-pdf="AMDPMS-Dispense-<?= htmlspecialchars($refCode) ?>">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Save as PDF
                </button>
                <button type="button" class="btn-secondary" onclick="window.print()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                    Print receipt
                </button>
                <a class="btn-secondary" href="shop.php">Back to medications</a>
            </div>

        <?php else: ?>
            <div class="receipt-empty">
                <p class="receipt-empty-title">No recent records</p>
                <p>No completed transactions were found for your account.</p>
                <a class="btn-primary" href="shop.php">Back to medications</a>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($hasContent): ?>
        <p class="receipt-foot-links">
            <a class="link-primary" href="reports.php?type=sales">Sales report</a>
            <span aria-hidden="true">&middot;</span>
            <a class="link-primary" href="audit-log.php?module=checkout">Checkout activity</a>
        </p>
    <?php endif; ?>
</section>

<?php if ($saleCompleted): ?>
<script>
    // A completed order is finished with, so drop the stale cart from
    // sessionStorage. A failed/pending sale keeps the cart so it can be retried.
    try { sessionStorage.removeItem('amdpms-cart'); } catch (e) { /* ignore */ }
</script>
<?php endif; ?>

<?php include("includes/site-footer.php"); ?>
