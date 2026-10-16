@echo off
cd /d "%~dp0"
echo ========================================
echo  AMDPMS Arduino Bridge - Auto Start
echo ========================================
echo.

echo [1/3] Installing dependencies...
call npm install
echo.

echo [2/3] Scanning for Arduino ports...
node find-port.js
echo.

echo [3/3] Starting server on COM3...
set PORT_FILE=COM3
node server.js