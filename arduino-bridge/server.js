/**
 * ============================================================================
 *  AMDPMS Arduino Serial Bridge
 *  ---------------------------------------------------------------------------
 *  Connects the PHP web app (log_dispense.php) to the Arduino Uno
 *  5-medicine dispenser over USB serial.
 *
 *  HOW IT WORKS
 *    - Listens on http://localhost:3000
 *    - GET  /status      -> Arduino connection status + available ports
 *    - POST /dispense    { "channel": 1..5 } -> writes "D1".."D5" to Arduino
 *    - GET  / /favicon   -> simple info page
 *
 *  The HTTP server ALWAYS starts, even when no Arduino is connected, so the
 *  web app can always check whether the dispenser is online.
 *
 *  CONFIG (environment variables)
 *    PORT_FILE   serial port to use, e.g. COM3 (Windows) or /dev/ttyUSB0 (Linux)
 *                Default: auto-detect the first available port if not set.
 *    BAUD        9600 (matches the firmware)
 *    HTTP_PORT   3000
 *
 *  RUN
 *    cd arduino-bridge
 *    npm install        (already done)
 *    node server.js                 (auto-detect port)
 *    PORT_FILE=COM3 node server.js  (specify port)
 * ============================================================================
 */

const http = require('http');
const { SerialPort } = require('serialport');

const HTTP_PORT = process.env.HTTP_PORT || 3000;
const BAUD = parseInt(process.env.BAUD || '9600', 10);
const MAX_DISPENSE_MS = 30000; // time to wait for DONE before giving up

// Hardware connection state
let connected = false;
let portPath = null;
let port = null;
let lastError = '';
let availablePorts = [];

// Map of SKU -> channel (must match the firmware + shop.php)
const CHANNEL_BY_SKU = {
  'MED-001': 1,
  'MED-014': 2,
  'MED-032': 3,
  'MED-078': 4,
  'MED-091': 5,
};

// --------------------------------------------------------------------------
// Serial port helpers
// --------------------------------------------------------------------------
function listPorts() {
  return SerialPort.list();
}

function openPort(path) {
  return new Promise((resolve, reject) => {
    const p = new SerialPort({
      path,
      baudRate: BAUD,
      autoOpen: false,
    });
    p.open((err) => (err ? reject(err) : resolve(p)));
  });
}

async function refreshPorts() {
  try {
    availablePorts = (await listPorts()).map((p) => p.path);
  } catch (e) {
    availablePorts = [];
  }
  return availablePorts;
}

// Detach all listeners from a serial port and free the OS handle so a stale
// port can never block a later reopen with "Access denied".
async function disposePort(p) {
  if (!p) return;
  try { p.removeAllListeners(); } catch (e) { /* ignore */ }
  if (p.isOpen) {
    try { await new Promise((r) => { p.close(() => r()); }); } catch (e) { /* ignore */ }
  }
}

async function openArduinoPort(path) {
  const p = await openPort(path);
  p.on('data', (chunk) => {
    const text = chunk.toString();
    if (text.trim()) console.log('ARDUINO >', text.trim());
  });
  p.on('error', (err) => {
    lastError = err.message;
    connected = false;
    port = null;
  });
  p.on('close', () => {
    connected = false;
    lastError = 'Serial port closed.';
    port = null;
  });
  return p;
}

async function connectToArduino() {
  // A stale (not-open, or half-open) port object blocks a clean reopen. Always
  // fully free it first so a failed dispense / Arduino reset can't leave the
  // bridge stuck in "Access denied".
  if (port) {
    if (port.isOpen) {
      connected = true;
      lastError = '';
      return true;
    }
    await disposePort(port);
    port = null;
    connected = false;
  }

  // Try to find a port if none specified.
  let path = process.env.PORT_FILE;
  if (!path) {
    await refreshPorts();
    if (availablePorts.length > 0) path = availablePorts[0];
  }

  if (!path) {
    lastError = 'No serial port found. Plug in the Arduino.';
    connected = false;
    return false;
  }

  try {
    port = await openArduinoPort(path);
    portPath = path;
    connected = true;
    lastError = '';
    console.log(`Connected to Arduino on ${path} @ ${BAUD} baud.`);
    return true;
  } catch (e) {
    lastError = e.message;
    connected = false;
    port = null;
    console.error('Failed to open serial port:', e.message);
    return false;
  }
}

async function ensureArduinoConnected() {
  if (!connected || !port || !port.isOpen) {
    return connectToArduino();
  }
  return true;
}

// --------------------------------------------------------------------------
// Build the status object (shared by /status and the web app)
// --------------------------------------------------------------------------
function buildStatus() {
  return {
    connected,
    port: portPath,
    baud: BAUD,
    availablePorts,
    channels: CHANNEL_BY_SKU,
    error: lastError || null,
    message: connected
      ? 'Arduino is connected and ready.'
      : 'Arduino is NOT connected. ' + (lastError || 'Plug in the USB cable.'),
  };
}

// --------------------------------------------------------------------------
// HTTP server
// --------------------------------------------------------------------------
const server = http.createServer(async (req, res) => {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');

  if (req.method === 'OPTIONS') {
    res.writeHead(204);
    res.end();
    return;
  }

  const url = new URL(req.url, `http://localhost:${HTTP_PORT}`);

  // --- STATUS ---
  if (req.method === 'GET' && url.pathname === '/status') {
    const ok = await ensureArduinoConnected();
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ ok: true, ...buildStatus() }));
    return;
  }

  // --- RECONNECT / soft-reset ---
  if ((req.method === 'POST' || req.method === 'GET') && url.pathname === '/reconnect') {
    // Close and reopen the serial port to simulate unplug/replug without physically removing the USB.
    try {
      console.log('Reconnect requested via /reconnect');
      if (port) {
        try { port.close(); } catch (e) { /* ignore close errors */ }
        port = null;
        connected = false;
        lastError = 'Reconnecting...';
      }
      const ok = await connectToArduino();
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ ok, ...buildStatus() }));
    } catch (ex) {
      res.writeHead(500, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ ok: false, error: (ex && ex.message) || 'Reconnect failed' }));
    }
    return;
  }

  // --- DISPENSE ---
  if (req.method === 'POST' && url.pathname === '/dispense') {
    let body = '';
    req.on('data', (c) => (body += c));
    req.on('end', async () => {
      let payload = {};
      try {
        payload = JSON.parse(body || '{}');
      } catch (e) {
        res.writeHead(400, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ ok: false, message: 'Invalid JSON.' }));
        return;
      }

      const channel = parseInt(payload.channel, 10);
      if (!Number.isInteger(channel) || channel < 1 || channel > 5) {
        res.writeHead(400, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ ok: false, message: 'channel must be an integer 1..5.' }));
        return;
      }

      const ok = await ensureArduinoConnected();
      if (!ok) {
        res.writeHead(409, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ ok: false, message: 'Arduino is not connected.', ...buildStatus() }));
        return;
      }

      doDispense(channel, res);
    });
    return;
  }

  // --- BUZZ (receipt notification, 3 long buzzes) ---
  if ((req.method === 'GET' || req.method === 'POST') && url.pathname === '/buzz') {
    (async () => {
      const ok = await ensureArduinoConnected();
      if (!ok) {
        res.writeHead(409, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ ok: false, message: 'Arduino is not connected.', ...buildStatus() }));
        return;
      }
      if (!port) {
        res.writeHead(500, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ ok: false, message: 'No serial port available.' }));
        return;
      }
      try {
        await new Promise((resolve, reject) => {
          port.write('BUZZ\n', (err) => (err ? reject(err) : resolve()));
        });
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ ok: true, message: 'Buzz triggered.' }));
      } catch (e) {
        res.writeHead(500, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ ok: false, message: 'Write failed: ' + (e && e.message) }));
      }
    })();
    return;
  }

  // --- SYNC STOCK (push DB stock so the Arduino's LEDs match reality) ---
  if (req.method === 'POST' && url.pathname === '/sync-stock') {
    let body = '';
    req.on('data', (c) => (body += c));
    req.on('end', async () => {
      let payload = {};
      try {
        payload = JSON.parse(body || '{}');
      } catch (e) {
        res.writeHead(400, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ ok: false, message: 'Invalid JSON.' }));
        return;
      }

      const stockArr = payload.stock;
      if (!Array.isArray(stockArr) || stockArr.length !== 5 ||
          !stockArr.every((v) => Number.isInteger(v) && v >= 0 && v <= 32767)) {
        res.writeHead(400, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ ok: false, message: 'stock must be an array of 5 integers (0..32767).' }));
        return;
      }

      const ok = await ensureArduinoConnected();
      if (!ok) {
        res.writeHead(409, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ ok: false, message: 'Arduino is not connected.', ...buildStatus() }));
        return;
      }
      if (!port) {
        res.writeHead(500, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ ok: false, message: 'No serial port available.' }));
        return;
      }

      const line = 'SETSTOCK ' + stockArr.join(',') + '\n';
      const doneLine = 'DONE STOCK';
      const failLine = 'ERROR STOCK';
      let reply = '';
      let done = false;
      let failed = false;

      const onData = (chunk) => {
        reply += chunk.toString();
        if (reply.includes(doneLine)) done = true;
        if (reply.includes(failLine)) failed = true;
      };
      port.on('data', onData);

      const started = Date.now();
      const timer = setInterval(() => {
        if (done || failed || Date.now() - started > 5000) {
          clearInterval(timer);
          port.off('data', onData);
          const timedOut = !done && !failed;
          res.writeHead(done ? 200 : (timedOut ? 504 : 400), { 'Content-Type': 'application/json' });
          res.end(JSON.stringify({
            ok: done,
            stock: stockArr,
            arduinoReply: reply.trim(),
            message: done
              ? 'Stock synced to Arduino.'
              : (timedOut ? 'Timed out waiting for Arduino stock reply.' : 'Arduino rejected the stock sync.'),
          }));
        }
      }, 100);

      port.write(line, (err) => {
        if (err) {
          clearInterval(timer);
          port.off('data', onData);
          res.writeHead(500, { 'Content-Type': 'application/json' });
          res.end(JSON.stringify({ ok: false, message: 'Failed to write to Arduino: ' + err.message }));
          return;
        }
        console.log('Sent stock sync to Arduino:', line.trim());
      });
    });
    return;
  }

  // --- ROOT / info ---
  if (req.method === 'GET' && (url.pathname === '/' || url.pathname === '/favicon.ico')) {
    res.writeHead(200, { 'Content-Type': 'text/plain; charset=utf-8' });
    res.end('AMDPMS Arduino Serial Bridge\nStatus: ' + (connected ? 'CONNECTED' : 'DISCONNECTED') + '\n');
    return;
  }

  res.writeHead(404, { 'Content-Type': 'application/json' });
  res.end(JSON.stringify({ ok: false, message: 'Not found.' }));
});

// --------------------------------------------------------------------------
// Run a single dispense command and wait for the Arduino "DONE CHx" reply.
// --------------------------------------------------------------------------
function doDispense(channel, res) {
  const command = `D${channel}\n`;
  const doneLine = `DONE CH${channel}`;
  const failLine = `DISPENSE FAIL CH${channel}`;
  let done = false;
  let failed = false;
  let reply = '';

  const target = port;
  if (!target) {
    res.writeHead(503, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ ok: false, message: 'Arduino is not connected.', ...buildStatus() }));
    return;
  }

  const onData = (chunk) => {
    reply += chunk.toString();
    if (reply.includes(doneLine)) done = true;
    if (reply.includes(failLine)) failed = true;
  };

  target.on('data', onData);

  function detach() {
    if (target) target.off('data', onData);
  }

  const started = Date.now();
  const timer = setInterval(() => {
    if (done || failed || Date.now() - started > MAX_DISPENSE_MS) {
      clearInterval(timer);
      detach();

      // A timeout usually means the Arduino hung or brown-out reset (e.g. a
      // stalled servo). Fully release the serial port so the bridge can cleanly
      // reconnect for the next request instead of degrading into "Access denied".
      if (!done && target && target.isOpen) {
        console.warn(`Dispense channel ${channel} unresolved; resetting serial port for recovery.`);
        disposePort(target)
          .then(() => { if (port === target) { port = null; connected = false; } })
          .catch(() => {});
      }

      const timedOut = !done && !failed;
      res.writeHead(done ? 200 : (timedOut ? 504 : 502), { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({
        ok: done,
        channel,
        command: command.trim(),
        arduinoReply: reply.trim(),
        message: done
          ? `Dispensed channel ${channel}.`
          : (failed
            ? `Arduino rejected the dispense command for channel ${channel}. Check the dispenser hardware or firmware.`
            : 'Timed out waiting for Arduino response. The serial port will be reset to recover.'),
      }));
    }
  }, 100);

  target.write(command, (err) => {
    if (err) {
      clearInterval(timer);
      detach();
      res.writeHead(500, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ ok: false, message: 'Failed to write to Arduino: ' + err.message }));
      return;
    }
    console.log(`Sent command to Arduino: ${command.trim()}`);
  });
}

// --------------------------------------------------------------------------
// Startup
// --------------------------------------------------------------------------
(async () => {
  // Always start the HTTP server first so /status always works.
  server.listen(HTTP_PORT, () => {
    console.log(`Bridge listening on http://localhost:${HTTP_PORT}`);
    console.log('Dispense SKU->channel map:', CHANNEL_BY_SKU);
  });

  // Try to connect to the Arduino.
  const ok = await connectToArduino();
  if (!ok) {
    console.log('Arduino not connected yet. The bridge keeps running so the web app can still check /status.');
    console.log('Plug in the Arduino and it will connect automatically when the bridge checks again.');
  }

  // Auto-reconnect when the port disappears and is plugged back in later.
  // The Arduino's USB re-enumerates for ~1-2s after a brown-out or manual
  // reset, so transient "Access denied" opens are retried before giving up.
  setInterval(async () => {
    if (connected && port && port.isOpen) return;
    for (let attempt = 0; attempt < 3; attempt++) {
      const ok = await connectToArduino();
      if (ok) break;
      await new Promise((r) => setTimeout(r, 1500));
    }
  }, 4000);

  // Clean shutdown
  process.on('SIGINT', () => {
    console.log('\nClosing serial port...');
    if (port) {
      try { port.close(); } catch (e) { /* ignore */ }
    }
    server.close(() => process.exit(0));
    setTimeout(() => process.exit(0), 500);
  });
})();
