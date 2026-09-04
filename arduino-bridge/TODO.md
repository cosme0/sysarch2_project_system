# Arduino Bridge — Task List

## Task: Connect the PHP web app to the Arduino dispenser

- [x] Create `arduino-bridge/package.json` (serialport dependency)
- [x] Create `arduino-bridge/server.js` (Node.js serial bridge, port 3000)
- [x] Create `arduino-bridge/find-port.js` (COM port detection helper)
- [x] Create `arduino-bridge/README.md` (setup + usage guide)
- [x] Install `serialport` package (21 packages, 0 vulnerabilities)
- [x] Update `log_dispense.php`:
  - [x] Add `getArduinoStatus()` helper (GET /status)
  - [x] Add `sendToArduino()` helper that POSTs {channel} to the bridge
  - [x] Call the bridge for each dispensed unit
  - [x] Report arduino status in the JSON response
- [x] Add Arduino connection status indicator to:
  - [x] `admin.php` dashboard
  - [x] `home.php` dashboard
- [x] Verify PHP syntax (all files pass) and Node.js syntax

## Status feature (new)

- The bridge now **always starts** the HTTP server, even when no Arduino is connected.
- `GET /status` returns `{ connected, port, baud, availablePorts, channels, error, message }`.
- The Admin and Home dashboards show a green banner ✅ "CONNECTED" or red ⚠️ "NOT connected".
- Verified live: Arduino detected on **COM3** → `connected: true`.

## How to test (when an Arduino is connected)

1. Plug in the Arduino Uno with the firmware uploaded.
2. Start the bridge:
   ```bash
   cd arduino-bridge
   node server.js                # auto-detects port
   # or: PORT_FILE=COM3 node server.js
   ```
3. Open the web app, log in — the dashboard shows if the Arduino is connected.
4. Add a medication to the cart and click **Dispense** — the servo rotates for the matching channel.

## Manual bridge test

```bash
curl http://localhost:3000/status                          # check connection
curl -X POST http://localhost:3000/dispense -H "Content-Type: application/json" -d "{\"channel\":1}"
