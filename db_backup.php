<?php
require_once __DIR__ . '/auth.php';;
requireAdmin();
include("includes/logout.php");
require_once __DIR__ . '/includes/config.php';

$dbName = 'db_users';
$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = '';

$filename = 'amdpms_backup_' . date('Ymd_His') . '.sql';

// ---------------------------------------------------------------------------
// Option 1: use mysqldump when it exists (most reliable for a full DB dump).
// ---------------------------------------------------------------------------
$mysqldump = null;
foreach (['C:/xampp/mysql/bin/mysqldump.exe', 'C:/wamp64/bin/mysql/*/bin/*.exe'] as $pattern) {
    if (is_file($pattern)) { $mysqldump = $pattern; break; }
    foreach (glob($pattern) as $candidate) { $mysqldump = $candidate; break 2; }
}
if ($mysqldump && is_executable($mysqldump)) {
    $cmd = sprintf(
        '"%s" --host=%s --user=%s --password=%s --routines --single-transaction %s',
        $mysqldump,
        escapeshellarg($dbHost),
        escapeshellarg($dbUser),
        escapeshellarg($dbPass),
        escapeshellarg($dbName)
    );

    header('Content-Type: application/sql; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Accel-Buffering: no');

    log_activity('backup_download', 'admin', 'Full database dump downloaded (mysqldump).', 'critical', 'database', 'db_users');

    $output = [];
    $rc = 0;
    exec($cmd . ' 2>&1', $output, $rc);
    if ($rc === 0) {
        echo implode("\n", $output);
    } else {
        http_response_code(500);
        echo '-- mysqldump failed: ' . implode("\n", $output);
    }
    exit;
}

// ---------------------------------------------------------------------------
// Option 2: pure-PHP fallback dump (streams SQL without mysqldump).
// ---------------------------------------------------------------------------
$conn = @mysqli_connect($dbHost, $dbUser, $dbPass, $dbName);
if (!$conn) {
    http_response_code(500);
    echo 'Database backup failed: could not connect.';
    exit;
}

log_activity('backup_download', 'admin', 'Full database dump downloaded (PHP fallback).', 'critical', 'database', 'db_users');

header('Content-Type: application/sql; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('X-Accel-Buffering: no');

echo "-- AMDPMS database backup\n";
echo "-- Generated: " . date('Y-m-d H:i:s') . "\n";
echo "-- Database: $dbName\n\n";

$tables = $conn->query("SHOW TABLES");
if (!$tables) { exit("\n-- No tables found.\n"); }

$tablesByName = [];
while ($row = $tables->fetch_row()) {
    $tablesByName[] = $row[0];
}

foreach ($tablesByName as $table) {
    $table = $conn->real_escape_string($table);
    echo "DROP TABLE IF EXISTS `$table`;\n";

    $createRes = $conn->query("SHOW CREATE TABLE `$table`");
    if ($createRes && $createRow = $createRes->fetch_row()) {
        echo $createRow[1] . ";\n\n";
    }

    $dataRes = $conn->query("SELECT * FROM `$table`");
    if (!$dataRes) { echo "\n\n"; continue; }
    $cols = $dataRes->fetch_fields();
    $colNames = [];
    foreach ($cols as $c) {
        $colNames[] = '`' . $c->name . '`';
    }
    echo "INSERT INTO `$table` (" . implode(', ', $colNames) . ") VALUES\n";
    $first = true;
    while ($row = $dataRes->fetch_assoc()) {
        $vals = [];
        foreach ($row as $value) {
            if ($value === null) {
                $vals[] = 'NULL';
            } else {
                $vals[] = "'" . $conn->real_escape_string((string)$value) . "'";
            }
        }
        echo ($first ? '' : ",\n") . '(' . implode(', ', $vals) . ')';
        $first = false;
    }
    if ($first) {
        echo "() -- (empty table)\n";
    } else {
        echo ";\n";
    }
    $dataRes->free();
    echo "\n";
}

mysqli_close($conn);
exit;