<?php
/**
 * AMDPMS - dispensing engine.
 *
 * Shared by the paid checkout (checkout.php) and the legacy JSON endpoint
 * (log_dispense.php) so both paths validate stock, drive the physical
 * dispenser, and write exactly the same records.
 *
 * The dispenser is driven through the Node.js serial bridge. A unit only
 * counts as dispensed when the bridge confirms the hardware released it —
 * an unreachable bridge, a refusing Arduino or a dead PCA9685 all mean
 * NOTHING CAME OUT, and the caller must not report the transaction as done.
 */

require_once __DIR__ . '/stock.php';

// Address of the Node.js Arduino serial bridge (see arduino-bridge/README.md).
if (!defined('BRIDGE_URL')) {
    define('BRIDGE_URL', 'http://localhost:3000');
}

/**
 * Demo mode for benches with no hardware at all. When true, an unreachable
 * bridge completes the order as a clearly labelled SIMULATED dispense.
 *
 * It is off by default on purpose: with it on, checkout told the customer the
 * payment was complete even though no physical unit was ever released.
 */
if (!defined('ALLOW_OFFLINE_SIMULATION')) {
    define('ALLOW_OFFLINE_SIMULATION', false);
}

/** Read the bridge's connection status. Returns null when unreachable. */
function getBridgeStatus(): ?array
{
    $response = @file_get_contents(BRIDGE_URL . '/status', false, stream_context_create([
        'http' => ['timeout' => 3],
    ]));

    if ($response === false) {
        return null;
    }

    $data = json_decode($response, true);
    return is_array($data) ? $data : null;
}

/** Ping the dispenser to acknowledge a completed handover. */
function buzzBridge(): void
{
    @file_get_contents(BRIDGE_URL . '/buzz', false, stream_context_create([
        'http' => ['timeout' => 2],
    ]));
}

/**
 * Ask the bridge to drop one unit from a physical bin.
 * Only channels 1..5 map to real bins; channel 0 means "unassigned".
 *
 * @return array{ok:bool, reachable:bool, message:string, warning:?string}
 *   `reachable` is false ONLY when the bridge could not be contacted at all
 *   (not running / wrong port). A bridge that answered "no" is reachable, so
 *   "the bridge is offline" can never be confused with "the hardware refused"
 *   — the first means nothing ran, the second means the dispenser said no.
 */
function sendToArduino(int $channel): array
{
    $result = ['ok' => false, 'reachable' => false, 'message' => '', 'warning' => null];

    if ($channel < 1 || $channel > 5) {
        $result['message'] = "Channel {$channel} is not a valid dispenser channel.";
        return $result;
    }

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\n",
            'content' => json_encode(['channel' => $channel]),
            'timeout' => 35,
            // Without this, any 4xx/5xx (e.g. "409 Arduino is not connected")
            // makes file_get_contents() return false, which is indistinguishable
            // from a dead bridge — and used to turn a hardware refusal into a
            // fake successful dispense. A refusal is a reply, not a blackout.
            'ignore_errors' => true,
        ],
    ]);

    $response = @file_get_contents(BRIDGE_URL . '/dispense', false, $ctx);
    if ($response === false) {
        $result['message'] = 'the dispenser bridge is offline (start it with "node server.js" in arduino-bridge)';
        return $result;
    }

    $result['reachable'] = true;
    $data = json_decode($response, true);
    if (!is_array($data)) {
        $result['message'] = 'the dispenser bridge returned an unreadable response';
        return $result;
    }

    $result['ok']      = !empty($data['ok']);
    $result['message'] = trim((string)($data['message'] ?? ''));
    $warning           = $data['warning'] ?? null;
    $result['warning'] = is_string($warning) && $warning !== '' ? $warning : null;

    return $result;
}

/**
 * Pre-payment hardware gate. Asks the bridge whether the Arduino and the
 * PCA9685 servo driver are answering *right now*, so checkout can refuse the
 * transaction before any sale row exists instead of after.
 *
 * @return array{ready:bool, message:string}
 */
function check_dispenser_ready(): array
{
    $status = getBridgeStatus();

    if ($status === null) {
        return [
            'ready'   => false,
            'message' => 'the dispenser bridge is not running (start it with "node server.js" in arduino-bridge)',
        ];
    }

    if (empty($status['connected'])) {
        return [
            'ready'   => false,
            'message' => (string)($status['message'] ?? 'the Arduino is not connected to the dispenser bridge'),
        ];
    }

    // ready === false means the firmware's PCA9685 probe failed. A missing
    // "ready" key (older bridge) or null means "unknown", which is not a
    // reason to block: the dispense itself still has to confirm every unit.
    if (array_key_exists('ready', $status) && $status['ready'] === false) {
        return [
            'ready'   => false,
            'message' => (string)($status['message'] ?? 'the PCA9685 servo driver is not responding'),
        ];
    }

    return ['ready' => true, 'message' => ''];
}

/** Push current database stock to the dispenser so its LEDs stay in sync. */
function syncStockToArduino(mysqli $conn): bool
{
    $rows = $conn->query("SELECT channel, stock FROM medications WHERE channel BETWEEN 1 AND 5");
    if (!$rows) {
        return false;
    }

    $stock = [0, 0, 0, 0, 0];
    while ($row = $rows->fetch_assoc()) {
        $stock[(int)$row['channel'] - 1] = max(0, (int)$row['stock']);
    }

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\n",
            'content' => json_encode(['stock' => $stock]),
            'timeout' => 6,
        ],
    ]);

    $response = @file_get_contents(BRIDGE_URL . '/sync-stock', false, $ctx);
    if ($response === false) {
        return false;
    }
    $data = json_decode($response, true);
    return is_array($data) && !empty($data['ok']);
}

/** Generate a human-friendly batch reference for one handover. */
function newBatchRef(): string
{
    return 'B' . date('YmdHis') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
}

/**
 * Dispense a list of {sku, qty} items.
 *
 * Each unit is validated against stock and expiry, released by the dispenser,
 * then written to dispense_history and stock_movements. Prices are captured
 * here so the sale can record what was actually charged.
 *
 * Only units the hardware confirmed are counted. When nothing came out, the
 * returned `logged` is 0 and `warnings` says why, so the caller can block the
 * transaction instead of telling the customer it is done.
 *
 * @return array{batch_ref:string, logged:int, lines:array, warnings:array,
 *               items:array, arduino:bool, online:bool, simulated:bool}
 */
function dispense_order(mysqli $conn, array $items, ?string $userEmail, ?int $saleId = null): array
{
    $batchRef     = newBatchRef();
    $logged       = 0;
    $allReleased  = true;   // every requested unit was physically released
    $bridgeOnline = null;   // tri-state: unknown until the first attempt
    $simulated    = false;
    $itemResults  = [];
    $lines        = [];
    $warnings     = [];

    foreach ($items as $item) {
        $sku       = trim((string)($item['sku'] ?? ''));
        $requested = max(1, (int)($item['qty'] ?? 1));
        if ($sku === '') {
            continue;
        }

        $stmt = $conn->prepare(
            "SELECT id, name, sku, price, stock, reserve_stock, channel, expiry_date
             FROM medications WHERE sku = ?"
        );
        $stmt->bind_param("s", $sku);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res->num_rows === 0) {
            $warnings[] = "SKU {$sku} was not found in inventory and was skipped.";
            continue;
        }

        $med        = $res->fetch_assoc();
        $channel    = (int)$med['channel'];
        $stockAvail = (int)$med['stock'];
        $price      = (float)$med['price'];

        // Never dispense an expired item; it must be written off first.
        if (!empty($med['expiry_date']) && $med['expiry_date'] < date('Y-m-d')) {
            $warnings[] = "{$med['name']} ({$med['sku']}) is EXPIRED (since {$med['expiry_date']}) and was not dispensed. Write it off in Admin → Movements.";
            $itemResults[$med['sku']] = ['dispensed' => 0, 'requested' => $requested, 'ok' => false];
            continue;
        }

        if ($stockAvail <= 0) {
            $warnings[] = "{$med['name']} is out of stock and was not dispensed.";
            $itemResults[$med['sku']] = ['dispensed' => 0, 'requested' => $requested, 'ok' => false];
            continue;
        }

        // Channel 0 means the item was never assigned to a physical bin.
        if ($channel < 1 || $channel > 5) {
            $warnings[] = "{$med['name']} ({$med['sku']}) has no valid dispenser channel and was not dispensed.";
            $itemResults[$med['sku']] = ['dispensed' => 0, 'requested' => $requested, 'ok' => false];
            continue;
        }

        // Cap to what is actually on hand so we never over-dispense.
        $qty = min($requested, $stockAvail);
        if ($qty < $requested) {
            $warnings[] = "Only {$qty} of {$requested} unit(s) of {$med['name']} were in stock; dispensed the available {$qty}.";
        }

        // Release one unit at a time and only count what the hardware confirms.
        $successfulQty = 0;
        $failReason    = '';
        $hwWarning     = null;
        $offline       = false;

        for ($i = 0; $i < $qty; $i++) {
            // The bridge already proved unreachable for an earlier line: do
            // not burn a connection timeout on every remaining unit.
            if ($bridgeOnline === false) {
                $offline    = true;
                $failReason = 'the dispenser bridge is offline (start it with "node server.js" in arduino-bridge)';
                break;
            }

            $reply = sendToArduino($channel);

            if ($bridgeOnline === null) {
                $bridgeOnline = $reply['reachable'];
            }
            if ($reply['warning'] !== null) {
                $hwWarning = $reply['warning'];
            }

            if ($reply['ok']) {
                $successfulQty++;
                continue;
            }

            if (!$reply['reachable']) {
                $offline = true;
            }

            // Stop at the first failure: repeating it cannot release anything,
            // it only stalls the checkout.
            $failReason = rtrim(
                $reply['message'] !== '' ? $reply['message'] : 'the dispenser refused the command',
                ". \t"
            );
            break;
        }

        // Demo benches with no hardware at all: only applied when the bridge
        // itself is unreachable, and only when explicitly enabled above.
        if ($successfulQty === 0 && $offline && ALLOW_OFFLINE_SIMULATION) {
            $successfulQty = $qty;
            $simulated     = true;
            $warnings[]    = "Dispenser bridge offline — {$med['name']} was recorded as a SIMULATED dispense (no physical unit released).";
        }

        if ($successfulQty < $qty) {
            $allReleased = false;
        }

        if ($hwWarning !== null) {
            $warnings[] = "{$med['name']}: the dispenser reported \"{$hwWarning}\" — check that channel before the next order.";
        }

        // Inventory only changes once the units are accounted for.
        if ($successfulQty > 0) {
            $newStock     = max(0, $stockAvail - $successfulQty);
            $totalAfter   = $newStock + (int)$med['reserve_stock'];
            $medicationId = (int)$med['id'];

            $upd = $conn->prepare("UPDATE medications SET stock = ?, updated_at = NOW() WHERE id = ?");
            $upd->bind_param("ii", $newStock, $medicationId);
            $upd->execute();

            $ins = $conn->prepare(
                "INSERT INTO dispense_history (batch_ref, sale_id, user_email, medication_sku, medication_name, qty, channel)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $ins->bind_param("sisssii", $batchRef, $saleId, $userEmail, $med['sku'], $med['name'], $successfulQty, $channel);
            $ins->execute();
            $logged += $successfulQty;

            recordStockMovement(
                $conn,
                $med,
                'sale',
                -$successfulQty,
                $totalAfter,
                $simulated ? 'Simulated dispense (dispenser offline)' : 'Dispensed to customer',
                $batchRef,
                null
            );

            $lines[] = [
                'sku'        => $med['sku'],
                'name'       => $med['name'],
                'price'      => $price,
                'qty'        => $successfulQty,
                'subtotal'   => round($price * $successfulQty, 2),
            ];

            if ($successfulQty < $qty) {
                $warnings[] = "{$med['name']}: only {$successfulQty} of {$qty} unit(s) came out of the dispenser"
                    . ($failReason !== '' ? " ({$failReason})" : '')
                    . '; only the units actually released were charged.';
            }
        } else {
            $warnings[] = "{$med['name']} ({$med['sku']}) was NOT dispensed"
                . ($failReason !== '' ? ": {$failReason}" : ' by the dispenser')
                . '. Inventory was not changed.';
        }

        // Only clear the cart line when every requested unit was released.
        $itemResults[$med['sku']] = [
            'dispensed' => $successfulQty,
            'requested' => $requested,
            'ok'        => $successfulQty === $requested,
        ];
    }

    if ($logged > 0) {
        syncStockToArduino($conn);

        // Audible "order complete" signal: once the whole order has been
        // dispensed, tell the Arduino to play its 3-beep done tone
        // (DONE_BUZZ_COUNT = 3). This is one confirmation for the entire
        // order, in addition to the per-channel beep after each unit.
        buzzBridge();
    }

    return [
        'batch_ref' => $batchRef,
        'logged'    => $logged,
        'lines'     => $lines,
        'warnings'  => $warnings,
        'items'     => $itemResults,
        'arduino'   => $allReleased,
        'online'    => $bridgeOnline === true,
        'simulated' => $simulated,
    ];
}
