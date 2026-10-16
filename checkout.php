<?php
/**
 * Checkout - the payment simulation.
 *
 * Flow:
 *   1. The dispense cart posts the selected SKUs here.
 *   2. Prices are re-read from the database (never trusted from the browser)
 *      and the order is parked in the session.
 *   3. The user picks how to pay: cash, GCash or Maya.
 *   4. Confirming records the sale, releases the medication through the
 *      dispenser, and redirects to the printed receipt.
 *
 * No real money moves. E-wallet payments generate a reference number locally.
 */
require_once __DIR__ . '/auth.php';;
require_once __DIR__ . '/database.php';;
requireLogin();
include("includes/logout.php");
require_once __DIR__ . '/includes/dispensing.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$conn      = db();
$userEmail = $_SESSION['email'] ?? null;
$userId    = (int)($_SESSION['user_id'] ?? 0);
$errors    = [];
$notice    = '';

/** How long a parked order stays valid. */
const ORDER_TTL_MINUTES = 15;

/** Fast lookup of everything the current user may dispense. */
function price_order(mysqli $conn, array $items): array
{
    $lines   = [];
    $issues  = [];
    $seen    = [];

    foreach ($items as $item) {
        $sku = trim((string)($item['sku'] ?? ''));
        $qty = (int)($item['qty'] ?? 0);
        if ($sku === '' || $qty < 1) {
            continue;
        }

        // Collapse duplicate lines for the same SKU.
        $sku = preg_replace('/[^A-Za-z0-9\-_]/', '', $sku);
        if ($sku === '') {
            continue;
        }
        $seen[$sku] = ($seen[$sku] ?? 0) + $qty;
    }

    foreach ($seen as $sku => $qty) {
        $stmt = $conn->prepare(
            "SELECT id, name, sku, price, stock, channel, expiry_date
             FROM medications WHERE sku = ?"
        );
        $stmt->bind_param("s", $sku);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res->num_rows === 0) {
            $issues[] = "SKU {$sku} is no longer in the catalogue.";
            continue;
        }

        $med = $res->fetch_assoc();

        if (!empty($med['expiry_date']) && $med['expiry_date'] < date('Y-m-d')) {
            $issues[] = "{$med['name']} ({$med['sku']}) is expired and cannot be dispensed.";
            continue;
        }
        if ((int)$med['channel'] < 1 || (int)$med['channel'] > 5) {
            $issues[] = "{$med['name']} ({$med['sku']}) is not assigned to a dispenser channel yet.";
            continue;
        }

        $stock = (int)$med['stock'];
        if ($stock < 1) {
            $issues[] = "{$med['name']} ({$med['sku']}) is out of stock.";
            continue;
        }
        if ($qty > $stock) {
            $issues[] = "Only {$stock} of {$med['name']} left; the quantity was reduced from {$qty}.";
            $qty = $stock;
        }

        $price = (float)$med['price'];
        $lines[] = [
            'sku'      => $med['sku'],
            'name'     => $med['name'],
            'price'    => $price,
            'qty'      => $qty,
            'subtotal' => round($price * $qty, 2),
        ];
    }

    return ['lines' => $lines, 'issues' => $issues];
}

function order_totals(array $lines): array
{
    $subtotal = 0.0;
    $qty      = 0;
    foreach ($lines as $l) {
        $subtotal += $l['subtotal'];
        $qty      += $l['qty'];
    }
    return ['subtotal' => round($subtotal, 2), 'total' => round($subtotal, 2), 'qty' => $qty];
}

function order_is_stale(array $order): bool
{
    return (time() - (int)($order['created_at'] ?? 0)) > (ORDER_TTL_MINUTES * 60);
}

$action = $_POST['action'] ?? 'view';

// Errors that survive a redirect (the cart posts here, then we redirect to
// keep the browser from being able to resubmit the form).
$_SESSION['checkout_errors'] = [];

// ---------------------------------------------------------------------------
// Step 1 - the cart hands the order over
// ---------------------------------------------------------------------------
if ($action === 'start') {
    if (!verifyCsrfToken($_POST['_csrf'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $priced  = price_order($conn, $_POST['items'] ?? []);
        $errors  = array_merge($errors, $priced['issues']);

        if (empty($priced['lines'])) {
            $errors[] = 'There was nothing to check out.';
            unset($_SESSION['pending_order']);
        } else {
            $totals = order_totals($priced['lines']);
            $_SESSION['pending_order'] = [
                'lines'      => $priced['lines'],
                'subtotal'   => $totals['subtotal'],
                'total'      => $totals['total'],
                'qty'        => $totals['qty'],
                'created_at' => time(),
            ];

            $summary = implode(', ', array_map(
                fn($l) => $l['qty'] . '× ' . $l['name'],
                $priced['lines']
            ));
            log_activity(
                'checkout_started',
                'checkout',
                'Started checkout for ' . $totals['qty'] . ' unit(s) totalling ₱'
                    . number_format($totals['total'], 2) . ' — ' . $summary . '.',
                'info',
                'order',
                null
            );
        }
    }

    if ($errors) {
        $_SESSION['checkout_errors'] = $errors;
    }
    header('Location: checkout.php');
    exit;
}

// ---------------------------------------------------------------------------
// Step 2 - confirm payment and release the medication
// ---------------------------------------------------------------------------
if ($action === 'confirm') {
    $order = $_SESSION['pending_order'] ?? null;

    if (!$order || empty($order['lines'])) {
        header('Location: checkout.php?error=no_order');
        exit;
    }
    if (order_is_stale($order)) {
        unset($_SESSION['pending_order']);
        header('Location: checkout.php?error=expired');
        exit;
    }
    if (!verifyCsrfToken($_POST['_csrf'] ?? null)) {
        $errors[] = 'Your session expired. Please confirm the payment again.';
    }

    $method = (string)($_POST['payment_method'] ?? 'cash');
    if (!in_array($method, PAYMENT_METHODS, true)) {
        $method = 'cash';
    }

    $total  = (float)$order['total'];
    $refNo  = null;

    if ($method === 'cash') {
        $tender = round((float)($_POST['cash_received'] ?? 0), 2);
        $change = null;
        if ($tender < $total) {
            $errors[] = 'The cash received (₱' . number_format($tender, 2) . ') is less than the total due (₱' . number_format($total, 2) . ').';
        } else {
            $change = round($tender - $total, 2);
        }
    } else {
        // Simulated e-wallet confirmation: a locally generated reference.
        $refNo  = strtoupper(substr($method, 0, 2)) . '-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $tender = null;
        $change = null;
    }

    // Hardware gate: if the dispenser cannot possibly release anything, refuse
    // the payment up front so no sale row (and no "payment complete" receipt)
    // is ever created for a transaction that dispensed nothing.
    if (!$errors) {
        $hardware = check_dispenser_ready();
        if (!$hardware['ready']) {
            $errors[] = 'The transaction was NOT completed and no payment was taken: '
                . $hardware['message'] . '.';
            log_activity(
                'payment_blocked',
                'checkout',
                'Blocked a payment of ₱' . number_format($total, 2) . ' — ' . $hardware['message'] . '.',
                'warning',
                'order',
                null
            );
        }
    }

    if (!$errors) {
        $saleId    = 0;
        $invoiceNo = '';

        try {
            $invoiceNo = 'INV-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $batchRef  = newBatchRef();

            // Recorded as pending first: the sale exists before any physical
            // unit is released, so dispense_history can always be tied to it.
            $oQty   = (int)$order['qty'];
            $oSub   = (float)$order['subtotal'];
            $oTotal = $total;

            $insSale = $conn->prepare(
                "INSERT INTO sales
                    (invoice_no, user_email, user_id, total_qty, subtotal, total_amount,
                     payment_method, reference_no, cash_received, change_due, status, batch_ref, sold_at, paid_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW(), NOW())"
            );
            // One type character per placeholder, in column order:
            // invoice_no, user_email, user_id, qty (i), subtotal, total
            // (d), payment_method, reference_no (s), tender, change (d),
            // batch_ref (s).
            $insSale->bind_param(
                'ssiiddssdds',
                $invoiceNo, $userEmail, $userId, $oQty, $oSub, $oTotal,
                $method, $refNo, $tender, $change, $batchRef
            );
            $insSale->execute();
            $saleId = (int)$conn->insert_id;

            // Release the medication outside the sales insert: each unit waits on
            // the physical dispenser and can take a few seconds.
            $result = dispense_order(
                $conn,
                array_map(fn($l) => ['sku' => $l['sku'], 'qty' => $l['qty']], $order['lines']),
                $userEmail,
                $saleId
            );

            if ($result['logged'] === 0) {
                // Nothing came out, so there is nothing to charge for. The sale
                // is removed again so the transaction can never be reported as
                // done, paid or dispensed.
                $del = $conn->prepare("DELETE FROM sales WHERE id = ?");
                $del->bind_param('i', $saleId);
                $del->execute();

                $errors[] = 'The transaction was NOT completed: no medication was dispensed, '
                    . 'so no payment was taken. ' . implode(' ', $result['warnings']);
                log_activity(
                    'payment_voided',
                    'checkout',
                    'Payment of ₱' . number_format($total, 2) . ' was voided: no units were released.',
                    'warning',
                    'invoice',
                    $invoiceNo
                );
            } else {
                // Record line items at the price actually charged, so editing a
                // medication price later does not rewrite historical revenue.
                $insItem = $conn->prepare(
                    "INSERT INTO sale_items (sale_id, medication_sku, medication_name, price, qty, subtotal)
                     VALUES (?, ?, ?, ?, ?, ?)"
                );
                foreach ($result['lines'] as $line) {
                    $liSku  = $line['sku'];
                    $liName = $line['name'];
                    $liPrice = (float)$line['price'];
                    $liQty   = (int)$line['qty'];
                    $liSub   = (float)$line['subtotal'];
                    $insItem->bind_param('issdds', $saleId, $liSku, $liName, $liPrice, $liQty, $liSub);
                    $insItem->execute();
                }

                $finalQty      = array_sum(array_column($result['lines'], 'qty'));
                $finalSubtotal = round(array_sum(array_column($result['lines'], 'subtotal')), 2);
                $finalTotal    = round($finalSubtotal, 2);
                $finalRef      = $result['batch_ref'];
                $status        = 'completed';

                $updSale = $conn->prepare(
                    "UPDATE sales
                        SET total_qty = ?, subtotal = ?, total_amount = ?, status = ?, batch_ref = ?
                      WHERE id = ?"
                );
                $updSale->bind_param('iddssi', $finalQty, $finalSubtotal, $finalTotal, $status, $finalRef, $saleId);
                $updSale->execute();

                // Change is recalculated against what was really released, since
                // a partial dispense can lower the amount due.
                if ($method === 'cash') {
                    $finalChange = round((float)$tender - $finalTotal, 2);
                    $updChange = $conn->prepare("UPDATE sales SET change_due = ? WHERE id = ?");
                    $updChange->bind_param('di', $finalChange, $saleId);
                    $updChange->execute();
                }

                if (!empty($result['warnings'])) {
                    $notice = implode(' ', $result['warnings']);
                }

                $detail = sprintf(
                    '%s payment of ₱%s for %d unit(s) on invoice %s (batch %s)',
                    PAYMENT_LABELS[$method],
                    number_format($finalTotal, 2),
                    $finalQty,
                    $invoiceNo,
                    $finalRef
                );
                if ($method === 'cash') {
                    $detail .= sprintf(
                        '; received ₱%s, change ₱%s',
                        number_format((float)$tender, 2),
                        number_format((float)$finalChange, 2)
                    );
                } else {
                    $detail .= '; reference ' . $refNo;
                }
                if ($result['simulated']) {
                    $detail .= ' — SIMULATED, dispenser bridge offline';
                }

                log_activity(
                    'payment_completed',
                    'checkout',
                    $detail,
                    $result['simulated'] ? 'warning' : 'info',
                    'invoice',
                    $invoiceNo
                );

                unset($_SESSION['pending_order']);
                header('Location: receipt.php?invoice=' . urlencode($invoiceNo));
                exit;
            }
        } catch (Throwable $e) {
            $errors[] = 'The transaction could not be completed. Please try again.';
            error_log('checkout error: ' . $e->getMessage());

            // A sale row is written before the medication is released, so a failure
            // part-way through can leave it stuck on 'pending'. Close it out and
            // record how much actually came out, otherwise the next report shows a
            // phantom transaction and the stock looks unexplained.
            $releasedUnits = 0;
            if ($saleId > 0) {
                $hist = $conn->prepare(
                    "SELECT COALESCE(SUM(qty), 0) AS units FROM dispense_history WHERE sale_id = ?"
                );
                $hist->bind_param('i', $saleId);
                $hist->execute();
                $releasedUnits = (int)$hist->get_result()->fetch_assoc()['units'];

                $fail = $conn->prepare("UPDATE sales SET status = 'failed' WHERE id = ?");
                $fail->bind_param('i', $saleId);
                $fail->execute();
            }

            $detail = 'Checkout for invoice ' . ($invoiceNo !== '' ? $invoiceNo : '(invoice not yet created)')
                . ' failed: ' . $e->getMessage();
            if ($releasedUnits > 0) {
                $detail .= ' — ' . $releasedUnits . ' unit(s) had already been released and '
                    . 'were left deducted from stock; reconcile this order by hand.';
            }

            log_activity(
                'payment_failed',
                'checkout',
                $detail,
                'critical',
                'invoice',
                $invoiceNo !== '' ? $invoiceNo : null
            );

            if ($releasedUnits > 0) {
                $errors[] = 'Some units were already released. Stock was reduced for those units; '
                    . 'please tell an administrator so the order can be reconciled.';
            }
        }
    }
}

// ---------------------------------------------------------------------------
// Render the payment step
// ---------------------------------------------------------------------------
$order = $_SESSION['pending_order'] ?? null;

if ($order && order_is_stale($order)) {
    unset($_SESSION['pending_order']);
    $order  = null;
    $errors[] = 'Your checkout session expired. Please select the items again.';
}

// Errors handed over by the "start" redirect.
if (!empty($_SESSION['checkout_errors'])) {
    $errors = array_merge($errors, $_SESSION['checkout_errors']);
    $_SESSION['checkout_errors'] = [];
}

if (isset($_GET['error'])) {
    $map = [
        'no_order' => 'There is no pending order. Add medications to your cart first.',
        'expired' => 'Your checkout session expired. Please select the items again.',
    ];
    if (isset($map[$_GET['error']])) {
        $errors[] = $map[$_GET['error']];
    }
}

$lines        = $order['lines'] ?? [];
$totals       = $order ? order_totals($lines) : ['subtotal' => 0.0, 'total' => 0.0, 'qty' => 0];
$defaultTender = $totals['total'] > 0 ? (float)ceil($totals['total']) : 0.0;

// Tell the cashier up front when the hardware cannot dispense, instead of
// letting them take a payment that would then be refused.
$dispenserCheck = check_dispenser_ready();

$pageTitle = 'Checkout | ' . SYSTEM_NAME_FULL;
include("includes/site-header.php");
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Checkout</h1>
        <p class="page-subtitle">Review the order, choose a payment method, and release the medication.</p>
    </div>
    <span class="sim-chip" title="No real payment is processed">SIMULATION MODE</span>
</div>

<?php if ($errors): ?>
    <div class="message error" role="alert">
        <?php foreach ($errors as $e): ?>
            <p style="margin:0 0 6px;"><?= htmlspecialchars($e) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($notice): ?>
    <div class="message warn" role="status"><?= htmlspecialchars($notice) ?></div>
<?php endif; ?>

<?php if (!$dispenserCheck['ready']): ?>
    <div class="message warn" role="status">
        Dispenser unavailable: <?= htmlspecialchars($dispenserCheck['message']) ?>.
        Payment will be refused until the hardware is back online.
    </div>
<?php endif; ?>

<?php if (empty($lines)): ?>
    <div class="card empty-state">
        <h2>Your cart is empty</h2>
        <p>Select medications from the catalogue to start a transaction.</p>
        <a class="btn-primary" href="shop.php">Browse medications</a>
    </div>
<?php else: ?>
<form method="post" action="checkout.php" class="checkout-grid" id="checkoutForm">
    <input type="hidden" name="action" value="confirm">
    <?php csrf_field(); ?>

    <!-- Order summary -->
    <div class="card checkout-summary-card">
        <h2 class="admin-section-title" style="margin-top:0;">Order summary</h2>
        <div class="order-lines">
            <div class="order-lines-head">
                <span>Item</span><span>Price</span><span>Qty</span><span>Subtotal</span>
            </div>
            <?php foreach ($lines as $l): ?>
            <div class="order-line">
                <span class="order-line-name">
                    <?= htmlspecialchars($l['name']) ?>
                    <small><?= htmlspecialchars($l['sku']) ?></small>
                </span>
                <span>₱<?= number_format($l['price'], 2) ?></span>
                <span>&times; <?= (int)$l['qty'] ?></span>
                <span class="order-line-sub">₱<?= number_format($l['subtotal'], 2) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="order-totals">
            <div class="order-total-row">
                <span>Items</span><strong><?= (int)$totals['qty'] ?></strong>
            </div>
            <div class="order-total-row">
                <span>Subtotal</span><strong>₱<?= number_format($totals['subtotal'], 2) ?></strong>
            </div>
            <div class="order-total-row order-total-grand">
                <span>Total due</span><strong>₱<?= number_format($totals['total'], 2) ?></strong>
            </div>
        </div>
    </div>

    <!-- Payment -->
    <div class="card checkout-pay-card">
        <h2 class="admin-section-title" style="margin-top:0;">Payment method</h2>

        <div class="pay-methods" role="radiogroup" aria-label="Payment method">
            <?php foreach (PAYMENT_METHODS as $pm): ?>
                <label class="pay-method">
                    <input type="radio" name="payment_method" value="<?= $pm ?>"
                           <?= $pm === 'cash' ? 'checked' : '' ?>
                           data-method="<?= $pm ?>">
                    <span class="pay-method-body">
                        <span class="pay-method-icon" aria-hidden="true">
                            <?= $pm === 'cash' ? '&#8369;' : ($pm === 'gcash' ? '&#128179;' : '&#128176;') ?>
                        </span>
                        <span class="pay-method-name"><?= htmlspecialchars(PAYMENT_LABELS[$pm]) ?></span>
                        <span class="pay-method-hint">
                            <?= $pm === 'cash' ? 'Pay exact or get change' : 'Simulated e-wallet transfer' ?>
                        </span>
                    </span>
                </label>
            <?php endforeach; ?>
        </div>

        <!-- Cash panel -->
        <div class="pay-panel" id="panelCash" data-panel="cash">
            <label class="form-group" for="cashReceived">Cash received</label>
            <div class="cash-input-wrap">
                <span class="peso">₱</span>
                <input type="number" id="cashReceived" name="cash_received"
                       min="<?= number_format($totals['total'], 2, '.', '') ?>"
                       step="0.01" inputmode="decimal"
                       value="<?= number_format($defaultTender, 2, '.', '') ?>"
                       placeholder="0.00">
            </div>

            <div class="quick-cash" id="quickCash">
                <?php
                $suggestions = [];
                foreach ([$totals['total'], 50, 100, 200, 500, 1000] as $amount) {
                    $amount = ceil((float)$amount);
                    if ($amount >= $totals['total'] && $amount <= 2000) {
                        $suggestions[$amount] = $amount;
                    }
                }
                ksort($suggestions);
                foreach ($suggestions as $amount):
                ?>
                    <button type="button" class="quick-cash-btn" data-amount="<?= $amount ?>">₱<?= number_format($amount) ?></button>
                <?php endforeach; ?>
            </div>

            <div class="cash-status" id="cashStatus" aria-live="polite"></div>
        </div>

        <!-- E-wallet panel -->
        <div class="pay-panel" id="panelWallet" data-panel="wallet" hidden>
            <div class="wallet-card">
                <div class="wallet-head">
                    <span class="wallet-icon" id="walletIcon" aria-hidden="true">&#128179;</span>
                    <div>
                        <strong id="walletName">GCash</strong>
                        <small>Simulated e-wallet</small>
                    </div>
                </div>
                <p class="wallet-body">
                    A transfer reference is generated locally when you confirm. No money moves and no
                    e-wallet service is contacted.
                </p>
                <div class="wallet-amount">
                    <span>Amount to transfer</span>
                    <strong>₱<?= number_format($totals['total'], 2) ?></strong>
                </div>
            </div>
        </div>

        <div class="checkout-actions">
            <a class="btn-secondary" href="shop.php">Back to medications</a>
            <button type="submit" class="btn-primary btn-pay" id="payBtn">
                Confirm payment &amp; dispense
            </button>
        </div>
        <p class="checkout-note">
            Payment is recorded against invoice <strong><?= 'INV-' . date('Ymd') . '-······' ?></strong>.
            The medication is only released after the dispenser confirms each unit.
        </p>
    </div>
</form>

<script>
(function () {
    var TOTAL = <?= json_encode((float)$totals['total']) ?>;
    var form = document.getElementById('checkoutForm');
    if (!form) return;

    var cashPanel = document.getElementById('panelCash');
    var walletPanel = document.getElementById('panelWallet');
    var cashInput = document.getElementById('cashReceived');
    var cashStatus = document.getElementById('cashStatus');
    var payBtn = document.getElementById('payBtn');
    var walletName = document.getElementById('walletName');
    var walletIcon = document.getElementById('walletIcon');

    function peso(n) { return '₱' + n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }

    function selectedMethod() {
        var checked = form.querySelector('input[name="payment_method"]:checked');
        return checked ? checked.value : 'cash';
    }

    function updatePanels() {
        var method = selectedMethod();
        cashPanel.hidden = method !== 'cash';
        walletPanel.hidden = method === 'cash';
        walletName.textContent = method === 'gcash' ? 'GCash' : 'Maya';
        walletIcon.textContent = method === 'gcash' ? '\u{1F4B3}' : '\u{1F4B0}';
        payBtn.textContent = method === 'cash' ? 'Confirm payment & dispense' : 'Confirm ' + (method === 'gcash' ? 'GCash' : 'Maya') + ' payment & dispense';
        if (method === 'cash') { updateCash(); }
    }

    function updateCash() {
        var tender = parseFloat(cashInput.value);
        if (isNaN(tender)) { tender = 0; }

        if (tender < TOTAL) {
            var short = TOTAL - tender;
            cashStatus.className = 'cash-status short';
            cashStatus.textContent = short > 0 ? 'Short by ' + peso(short) : '';
            payBtn.disabled = true;
        } else {
            var change = tender - TOTAL;
            cashStatus.className = 'cash-status ok';
            cashStatus.textContent = 'Change due: ' + peso(change);
            payBtn.disabled = false;
        }
    }

    form.querySelectorAll('input[name="payment_method"]').forEach(function (radio) {
        radio.addEventListener('change', updatePanels);
    });

    document.getElementById('quickCash').addEventListener('click', function (e) {
        var btn = e.target.closest('.quick-cash-btn');
        if (!btn) return;
        cashInput.value = parseFloat(btn.getAttribute('data-amount')).toFixed(2);
        updateCash();
        cashInput.focus();
    });

    cashInput.addEventListener('input', updateCash);

    // Guard against paying the wrong amount to a real till.
    form.addEventListener('submit', function (e) {
        if (selectedMethod() === 'cash') {
            updateCash();
            if (payBtn.disabled) {
                e.preventDefault();
                cashInput.focus();
            }
        }
    });

    updatePanels();
})();
</script>
<?php endif; ?>

<?php include("includes/site-footer.php"); ?>
