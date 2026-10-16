<?php
/**
 * Legacy bootstrap kept so existing pages that pull in database.php
 * keep working. The connection itself now comes from db() in auth.php, which
 * means the whole application shares a single handle instead of opening a new
 * one in every file.
 */
require_once __DIR__ . '/auth.php';

try {
    $conn = db();
} catch (Throwable $e) {
    echo "Could not connect to the database! Error: " . htmlspecialchars($e->getMessage());
    exit;
}
