<?php
/**
 * JSON endpoint: logs a dispense event and decrements stock.
 * Used by the floating dispense cart (includes/dispense-cart.php).
 * POST with JSON body: { "items": [ { "sku": "MED-001", "qty": 2 } ] }
 */
session_start();
include("auth.php");
requireLogin();

// Address of the Node.js Arduino serial bridge (see arduino-bridge/README.md).
define('BRIDGE_URL', 'http://localhost:3000');

// ---------------------------------------------------------------------------
// Helper: check the Arduino connection status from the Node.js bridge.
// Returns an array with connection info, or null if the bridge is unreachable.
// ---------------------------------------------------------------------------
function getArduinoStatus(): ?array
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

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}

include("database.php");

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data || empty($data['items'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'No items provided']);
    exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = mysqli_connect("localhost", "root", "", "db_users");

// ---------------------------------------------------------------------------
// Helper: send a dispense command to the Arduino via the Node.js serial bridge.
// Returns true if the Arduino acknowledged the dispense.
// ---------------------------------------------------------------------------
function sendToArduino(int $channel): bool
{
    // Only channels 1..5 map to physical bins. Channel 0 means "unassigned"
    // (the default of the admin add form) and must never be coerced to bin 1.
    if ($channel < 1 || $channel > 5) {
        return false;
    }
    $payload = json_encode(['channel' => $channel]);

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\n",
            'content' => $payload,
            'timeout' => 35,
        ],
    ]);

    $response = @file_get_contents(BRIDGE_URL . '/dispense', false, $ctx);
    if ($response === false) {
        return false;
    }

    $data = json_decode($response, true);
    return is_array($data) && !empty($data['ok']);
}

// ---------------------------------------------------------------------------
// Helper: push the database stock for all 5 channels to the Arduino so its
// status LEDs always match the website's inventory.
// ---------------------------------------------------------------------------
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

    $payload = json_encode(['stock' => $stock]);
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\n",
            'content' => $payload,
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

// Current user info from session
$userEmail = $_SESSION['email'] ?? null;

$logged = 0;
$allBridgeOk = true;
$itemResults = [];
$warnings = [];

foreach ($data['items'] as $item) {
    $sku = trim($item['sku'] ?? '');
    $requested = max(1, (int)($item['qty'] ?? 1));
    if ($sku === '') continue;

    $stmt = $conn->prepare("SELECT id, name, sku, stock, channel FROM medications WHERE sku = ?");
    $stmt->bind_param("s", $sku);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows === 0) {
        $warnings[] = "SKU {$sku} was not found in inventory and was skipped.";
        continue;
    }

    $med = $res->fetch_assoc();

    $channel    = (int)$med['channel'];
    $stockAvail = (int)$med['stock'];

    // Server-side stock validation: never dispense more than what's on hand.
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

    // Cap the quantity to the current stock so we never over-dispense.
    $qty = min($requested, $stockAvail);
    if ($qty < $requested) {
        $warnings[] = "Only {$qty} of {$requested} unit(s) of {$med['name']} are in stock; dispensed the available {$qty}.";
    }

    // Trigger the physical dispenser for each unit requested.
    $successfulQty = 0;
    for ($i = 0; $i < $qty; $i++) {
        $sent = sendToArduino($channel);
        if (!$sent) $allBridgeOk = false;
        if ($sent) {
            $successfulQty++;
        }
    }

    // Only change inventory after the Arduino confirms the physical dispense.
    if ($successfulQty > 0) {
        $newStock = max(0, $stockAvail - $successfulQty);
        $medicationId = (int)$med['id'];
        $upd = $conn->prepare("UPDATE medications SET stock = ? WHERE id = ?");
        $upd->bind_param("ii", $newStock, $medicationId);
        $upd->execute();

        $ins = $conn->prepare(
            "INSERT INTO dispense_history (user_email, medication_sku, medication_name, qty, channel)
             VALUES (?, ?, ?, ?, ?)"
        );
        $ins->bind_param("sssii", $userEmail, $med['sku'], $med['name'], $successfulQty, $channel);
        $ins->execute();
        $logged += $successfulQty;

        if ($successfulQty < $qty) {
            $warnings[] = "{$med['name']}: dispensed {$successfulQty} of {$qty} (the rest were rejected by the Arduino).";
        }
    } else {
        $warnings[] = "{$med['name']} ({$med['sku']}) was rejected by the Arduino; inventory was not changed.";
    }

    // Mark the SKU as fully dispensed only when every requested unit was accepted,
    // so the client clears it from the cart but keeps partially-failed items.
    $itemResults[$med['sku']] = [
        'dispensed' => $successfulQty,
        'requested' => $requested,
        'ok'        => $successfulQty === $requested,
    ];
}

// Keep the physical dispenser's status LEDs in sync with the database stock.
syncStockToArduino($conn);

$conn->close();

$message = "Dispensed {$logged} unit(s) successfully.";
if ($warnings) {
    $fullWarning = implode(' ', $warnings);
    if ($logged > 0) {
        $message = "Dispensed {$logged} unit(s). {$fullWarning}";
    } else {
        $message = "Nothing was dispensed. {$fullWarning}";
    }
}

$arduinoStatus = getArduinoStatus();

echo json_encode([
    'ok' => $logged > 0,
    'message' => $message,
    'logged' => $logged,
    'items' => $itemResults,
    'arduino' => $allBridgeOk,
    'arduinoStatus' => $arduinoStatus,
]);
