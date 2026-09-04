<?php
// Server-side proxy to the Arduino bridge /status endpoint with retries.
// Keeps the admin UI single-origin and makes status checks resilient to short timeouts.
header('Content-Type: application/json');

function fetch_bridge_status($attempts = 3, $timeout = 2) {
    $opts = ['http' => ['timeout' => $timeout]];
    $ctx = stream_context_create($opts);
    for ($i = 0; $i < $attempts; $i++) {
        $res = @file_get_contents('http://localhost:3000/status', false, $ctx);
        if ($res !== false) return $res;
        // small delay between attempts (non-blocking-ish)
        usleep(250000); // 250ms
    }
    return false;
}

$bridgeRaw = fetch_bridge_status(3, 2);
$serverTime = gmdate('Y-m-d\TH:i:s\Z');
if ($bridgeRaw === false) {
    echo json_encode([
        'ok' => false,
        'connected' => false,
        'error' => 'Bridge unreachable',
        'serverTime' => $serverTime,
    ]);
    exit;
}

$decoded = json_decode($bridgeRaw, true);
if (!is_array($decoded)) {
    echo json_encode([
        'ok' => false,
        'connected' => false,
        'error' => 'Invalid bridge response',
        'serverTime' => $serverTime,
    ]);
    exit;
}

// Normalize response: ensure 'connected' and 'error' are present
$decoded['ok'] = $decoded['ok'] ?? true;
$decoded['connected'] = $decoded['connected'] ?? ($decoded['ok'] ? true : false);
$decoded['error'] = $decoded['error'] ?? null;
$decoded['serverTime'] = $serverTime;

echo json_encode($decoded);
