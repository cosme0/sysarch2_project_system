<?php
/**
 * JSON endpoint: releases medications through the dispenser without taking
 * payment. The user-facing flow is checkout.php, which records a sale and then
 * calls the same dispensing engine used here.
 *
 * POST with JSON body: { "items": [ { "sku": "MED-001", "qty": 2 } ] }
 */
require_once __DIR__ . '/auth.php';;
requireLogin();
require_once __DIR__ . '/includes/dispensing.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}

// CSRF check: the token is sent both in the JSON body and the X-CSRF-Token
// header so the endpoint is protected even if a form/header is missing.
$raw        = file_get_contents('php://input');
$data       = json_decode($raw, true);
$csrfToken  = (is_array($data) ? ($data['_csrf'] ?? null) : null)
    ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);

if (!verifyCsrfToken(is_string($csrfToken) ? $csrfToken : null)) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'message' => 'Your session expired. Refresh the page and try again.']);
    exit;
}

if (!$data || empty($data['items']) || !is_array($data['items'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'No items provided']);
    exit;
}

$conn = db();

log_activity(
    'dispense_request',
    'dispense',
    'Received a dispense request for ' . count($data['items']) . ' item line(s).',
    'info',
    'dispense',
    null
);

$result = dispense_order($conn, $data['items'], $_SESSION['email'] ?? null, null);

$message = "Dispensed {$result['logged']} unit(s) successfully.";
if ($result['warnings']) {
    $full = implode(' ', $result['warnings']);
    $message = $result['logged'] > 0
        ? "Dispensed {$result['logged']} unit(s). {$full}"
        : "Nothing was dispensed. {$full}";
}

if ($result['logged'] > 0) {
    log_activity(
        'dispensed',
        'dispense',
        "Released {$result['logged']} unit(s) (batch {$result['batch_ref']})." . ($result['simulated'] ? ' Simulated: dispenser bridge offline.' : ''),
        $result['simulated'] ? 'warning' : 'info',
        'dispense',
        $result['batch_ref']
    );
} else {
    log_activity(
        'dispense_failed',
        'dispense',
        'Dispense request produced no units. ' . implode(' ', $result['warnings']),
        'warning',
        'dispense',
        $result['batch_ref']
    );
}

echo json_encode([
    'ok'            => $result['logged'] > 0,
    'message'       => $message,
    'logged'        => $result['logged'],
    'batch_ref'     => $result['logged'] > 0 ? $result['batch_ref'] : null,
    'items'         => $result['items'],
    'arduino'       => $result['arduino'],
    'simulated'     => $result['simulated'],
    'arduinoStatus' => getBridgeStatus(),
]);
