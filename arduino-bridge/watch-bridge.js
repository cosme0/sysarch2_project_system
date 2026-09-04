const { spawn } = require('child_process');
const path = require('path');

const bridgeDir = __dirname;
const serverPath = path.join(bridgeDir, 'server.js');
let child = null;
let shuttingDown = false;

function startBridge() {
  console.log('[watch] Starting Arduino bridge...');
  child = spawn(process.execPath, [serverPath], {
    cwd: bridgeDir,
    stdio: 'inherit',
    env: process.env,
  });

  child.on('exit', (code, signal) => {
    if (shuttingDown) return;
    const reason = signal ? `signal ${signal}` : `code ${code}`;
    console.log(`[watch] Arduino bridge exited (${reason}). Restarting in 3 seconds...`);
    setTimeout(startBridge, 3000);
  });

  child.on('error', (err) => {
    console.error('[watch] Failed to start Arduino bridge:', err.message);
    if (!shuttingDown) {
      setTimeout(startBridge, 3000);
    }
  });
}

process.on('SIGINT', () => {
  shuttingDown = true;
  console.log('[watch] Stopping bridge watcher...');
  if (child && child.exitCode === null) {
    child.kill('SIGINT');
  }
  process.exit(0);
});

process.on('SIGTERM', () => {
  shuttingDown = true;
  console.log('[watch] Stopping bridge watcher...');
  if (child && child.exitCode === null) {
    child.kill('SIGINT');
  }
  process.exit(0);
});

startBridge();
