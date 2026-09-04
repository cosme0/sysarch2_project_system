# AMDPMS Arduino Serial Bridge

This Node.js service connects the **PHP web app** to the **Arduino Uno**
5-medicine dispenser over USB serial.

When you click **Dispense** in the web app, the flow is:

```
PHP web app (log_dispense.php)
        │  POST /dispense { "channel": 3 }
        ▼
Node.js bridge (this server, port 3000)
        │  writes "D3\n" to the COM port
        ▼
Arduino Uno (dispenser firmware)
        │  rotates the CH3 servo, drops 1 unit
        │  replies "DONE CH3"
        ▼
bridge returns success to the web app
```

---

## Prerequisites

- [Node.js](https://nodejs.org) v12+ (v24 confirmed working)
- Arduino Uno with the firmware from
  `PlatformIO/Projects/SIA_project/src/main.cpp` uploaded
- Arduino plugged in via USB

---

## Setup

```bash
cd arduino-bridge
npm install
```

## Find the COM port

```bash
node find-port.js
```

This lists available ports. Look for the Arduino (usually `COM3`, `COM5`, etc.
on Windows, or `/dev/ttyUSB0` on Linux).

## Run the bridge

Auto-detect the port:

```bash
node server.js
```

Or specify a port explicitly:

```bash
# Windows
PORT_FILE=COM3 node server.js

# Linux / macOS
PORT_FILE=/dev/ttyUSB0 node server.js
```

You should see:

```
Opening serial port: COM3 @ 9600 baud...
Serial port OPEN. Listening for dispense commands.
Bridge listening on http://localhost:3000
```

> **Important:** Close the Arduino IDE Serial Monitor if it's open, otherwise
> the COM port will be busy and the bridge can't open it.

---

## Test it manually

With the bridge running, test a dispense from another terminal:

```bash
# PowerShell
Invoke-RestMethod -Method Post -Uri http://localhost:3000/dispense -ContentType "application/json" -Body '{"channel":1}'

# cmd
curl -X POST http://localhost:3000/dispense -H "Content-Type: application/json" -d "{\"channel\":1}"
```

Check the bridge status:

```bash
curl http://localhost:3000/status
```

---

## Wiring up the web app

The PHP side (`log_dispense.php`) already calls the bridge after logging a
dispense. It maps each dispensed SKU to its channel:

| SKU | Channel |
|-----|---------|
| MED-001 | CH1 |
| MED-014 | CH2 |
| MED-032 | CH3 |
| MED-078 | CH4 |
| MED-091 | CH5 |

The bridge URL is configurable via a constant. If you need to change it, edit
the `BRIDGE_URL` in `log_dispense.php`.

---

## Troubleshooting

| Problem | Fix |
|---------|-----|
| `No serial port found` | Plug in the Arduino, or set `PORT_FILE` explicitly |
| `Failed to open serial port` | The port is busy — close the Arduino IDE Serial Monitor / other app |
| Dispense times out | Confirm firmware is uploaded and the Arduino is running; check the channel has stock |
| No response from Arduino | Check the Arduino is powered and the firmware starts with boot self-test |
