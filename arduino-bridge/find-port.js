/**
 * Helper to list available serial ports and identify the Arduino.
 * Run with: node find-port.js
 */
const { SerialPort } = require('serialport');

(async () => {
  const ports = await SerialPort.list();
  console.log('Available serial ports:');
  if (ports.length === 0) {
    console.log('  (none)  — plug in the Arduino Uno.');
    return;
  }
  ports.forEach((p) => {
    console.log(
      `  ${p.path}  vendorId=${p.vendorId || '-'}  productId=${p.productId || '-'}  manufacturer=${p.manufacturer || '-'}`
    );
  });
})();
