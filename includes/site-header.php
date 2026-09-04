<?php
require_once __DIR__ . '/config.php'; // loaded before $pageTitle in parent scripts
$currentPage = basename($_SERVER['PHP_SELF']);
$pageTitle = $pageTitle ?? SYSTEM_NAME_FULL;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
<link rel="stylesheet" href="css/pharmacy.css?v=<?= filemtime(__DIR__ . '/../css/pharmacy.css') ?>">
</head>
<body>
    <header class="site-header">
        <?php include __DIR__ . '/brand-mark.php'; ?>

        <div class="menu-toggle" id="menu-toggle" aria-label="Toggle menu">
            <span></span><span></span><span></span>
        </div>

<div class="header-actions">
            <form class="search-bar" action="shop.php" method="get" role="search">
                <input type="text" name="q" id="sea" placeholder="Search medications, SKU, or batch…" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>">
                <button type="submit" id="seab">Search</button>
            </form>
            <form method="post" class="user-bar">
                <span>Staff: <strong><?= htmlspecialchars($_SESSION['fname'] ?? 'User') ?></strong></span>
                <button type="submit" name="logout" class="btn-logout">Logout</button>
            </form>
        </div>
    </header>

    <nav class="site-nav" id="navbar">
        <a href="home.php" class="<?= $currentPage === 'home.php' ? 'active' : '' ?>">Dashboard</a>
        <?php if (isAdmin()): ?>
        <a href="admin.php" class="<?= $currentPage === 'admin.php' ? 'active' : '' ?>">Admin</a>
        <a href="support-tickets.php" class="<?= $currentPage === 'support-tickets.php' ? 'active' : '' ?>">Tickets</a>
        <?php endif; ?>
        <a href="reports.php" class="<?= $currentPage === 'reports.php' ? 'active' : '' ?>">Reports</a>
        <a href="shop.php" class="<?= $currentPage === 'shop.php' ? 'active' : '' ?>">Medications</a>
        <a href="contact.php" class="<?= $currentPage === 'contact.php' ? 'active' : '' ?>">Support</a>
        <a href="account.php" class="<?= $currentPage === 'account.php' ? 'active' : '' ?>">Account</a>
        <a href="about.php" class="<?= $currentPage === 'about.php' ? 'active' : '' ?>">About System</a>
    </nav>

    <main>
