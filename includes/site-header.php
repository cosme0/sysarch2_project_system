<?php
require_once __DIR__ . '/config.php'; // loaded before $pageTitle in parent scripts
$currentPage = basename($_SERVER['PHP_SELF']);
$pageTitle = $pageTitle ?? SYSTEM_NAME_FULL;

// Record that this module of the system was used. Throttled, and skipped for
// the background endpoints the front-end polls.
if (isset($_SESSION['email'])) {
    log_page_view($currentPage);
}

// Basic security headers (safe to send before any output).
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

/**
 * Shared navigation definition.
 *
 * The core links are identical for every role so nobody has to relearn the
 * layout; only genuinely admin-only tools live in the "Admin" menu. Each entry
 * is [label, file, icon key].
 *
 * The Dashboard is staff-only, so it appears in the nav only when the signed-in
 * user is not an admin. About System is not linked here at all - a condensed
 * version of it is shown on the login page instead.
 */
$coreNav = isAdmin() ? [
    ['Medications',  'shop.php',    'pill'],
    ['Reports',      'reports.php', 'chart'],
    ['Activity Log', 'audit-log.php', 'list'],
    ['Account',      'account.php', 'user'],
] : [
    ['Dashboard',    'home.php',    'grid'],
    ['Medications',  'shop.php',    'pill'],
    ['Reports',      'reports.php', 'chart'],
    ['Activity Log', 'audit-log.php', 'list'],
    ['Account',      'account.php', 'user'],
];

$adminNav = [
    ['Tickets', 'support-tickets.php', 'ticket'],
    ['Database Backup', 'db_backup.php', 'save'],
];

$navIcon = function (string $key): string {
    $paths = [
        'grid'   => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'pill'   => '<path d="M10.5 20.5a6.36 6.36 0 0 1-9-9l8.5-8.5a6.36 6.36 0 0 1 9 9z"/><path d="M8.5 8.5l7 7"/>',
        'chart'  => '<path d="M3 3v18h18"/><path d="M7 15l4-5 3 3 5-7"/>',
        'list'   => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
        'info'   => '<circle cx="12" cy="12" r="9"/><path d="M12 16v-4M12 8h.01"/>',
        'user'   => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'ticket' => '<path d="M3 9V6a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v3a2 2 0 0 0 0 4v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-3a2 2 0 0 0 0-4z"/>',
        'save'   => '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8M7 3v5h8"/>',
        'gear'   => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
    ];
    $d = $paths[$key] ?? $paths['grid'];
    return '<svg class="nav-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(getCsrfToken()) ?>">
    <title><?= htmlspecialchars($pageTitle) ?></title>
<link rel="stylesheet" href="css/pharmacy.css?v=<?= filemtime(__DIR__ . '/../css/pharmacy.css') ?>">
<script>
(function(){try{var t=localStorage.getItem('amdpms-theme');if(t==='dark'){document.documentElement.setAttribute('data-theme','dark');}}catch(e){}})();
</script>
<?php if (!empty($includeCharts)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<?php endif; ?>
</head>
<body class="<?= isAdmin() ? 'role-admin' : 'role-staff' ?>">
    <a class="skip-link" href="#main-content">Skip to main content</a>
    <div class="preloader" id="preloader" aria-hidden="true">
        <div class="preloader-pill" aria-hidden="true"></div>
        <p class="preloader-text">Loading medications&#8230;</p>
    </div>

    <div class="site-sticky">
    <header class="site-header">
        <?php include __DIR__ . '/brand-mark.php'; ?>

        <button type="button" class="menu-toggle" id="menu-toggle" aria-label="Toggle menu" aria-controls="navbar" aria-expanded="false">
            <span></span><span></span><span></span>
        </button>

<div class="header-actions">
            <form class="search-bar" action="shop.php" method="get" role="search">
                <svg class="search-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
                <input type="text" name="q" id="sea" placeholder="Search medications or SKU" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>" aria-label="Search medications">
                <button type="submit" id="seab">Search</button>
            </form>
            <form method="post" class="user-bar">
                <span class="role-badge"><?= isAdmin() ? 'Admin' : 'Staff' ?>: <strong><?= htmlspecialchars($_SESSION['fname'] ?? 'User') ?></strong></span>
                <?php csrf_field(); ?>
                <button type="button" class="theme-toggle" aria-label="Toggle color theme" aria-pressed="false"><span class="theme-icon" aria-hidden="true">🌙</span></button>
                <button type="submit" name="logout" class="btn-logout">Logout</button>
            </form>
        </div>
    </header>

    <nav class="site-nav" id="navbar" aria-label="Main navigation">
        <?php foreach ($coreNav as [$label, $file, $icon]): ?>
            <a href="<?= $file ?>" class="nav-link<?= $currentPage === $file ? ' active' : '' ?>"<?= $currentPage === $file ? ' aria-current="page"' : '' ?>>
                <?= $navIcon($icon) ?><span><?= htmlspecialchars($label) ?></span>
            </a>
        <?php endforeach; ?>

        <?php if (!isAdmin()): ?>
            <a href="contact.php" class="nav-link<?= $currentPage === 'contact.php' ? ' active' : '' ?>"<?= $currentPage === 'contact.php' ? ' aria-current="page"' : '' ?>>
                <?= $navIcon('ticket') ?><span>Support</span>
            </a>
        <?php else: ?>
            <div class="nav-drop">
                <a href="admin.php" class="nav-drop-trigger<?= in_array($currentPage, ['admin.php', 'support-tickets.php', 'db_backup.php'], true) ? ' active' : '' ?>" aria-haspopup="true" aria-expanded="false">
                    <?= $navIcon('gear') ?><span>Admin</span>
                    <span class="nav-drop-burger" aria-hidden="true">☰</span>
                </a>
                <div class="nav-drop-menu" role="menu" aria-label="Admin tools">
                    <?php foreach ($adminNav as [$label, $file, $icon]): ?>
                        <a href="<?= $file ?>" role="menuitem" class="<?= $currentPage === $file ? 'active' : '' ?>">
                            <?= $navIcon($icon) ?><span><?= htmlspecialchars($label) ?></span>
                        </a>
                    <?php endforeach; ?>
                    <a href="admin.php" role="menuitem" class="<?= $currentPage === 'admin.php' ? 'active' : '' ?>">
                        <?= $navIcon('grid') ?><span>Console</span>
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </nav>
    </div>

    <main id="main-content" class="page-shell">
