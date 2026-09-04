<?php
include("auth.php");
requireLogin();
include("includes/logout.php");
require_once __DIR__ . '/includes/config.php';

$pageTitle = 'About System | ' . SYSTEM_NAME_FULL;
include("includes/site-header.php");
?>

<h1 class="page-title">About the system</h1>
<p class="page-subtitle"><?= SYSTEM_NAME_FULL ?></p>

<div class="about-grid">
    <div class="card">
        <h3>System overview</h3>
        <p>This web-based platform supports pharmacy staff in managing medication inventory, processing dispense requests, and maintaining secure user accounts with automated session and lockout policies.</p>
    </div>
    <div class="card">
        <h3>Core modules</h3>
        <ul class="module-list">
            <li>User authentication &amp; registration</li>
            <li>Password recovery with verification codes</li>
            <li>Medication inventory &amp; stock monitoring</li>
            <li>Automated dispensing workflow (UI demonstration)</li>
            <li>Dashboard metrics &amp; operational summary</li>
        </ul>
    </div>
    <div class="card">
        <h3>Objectives</h3>
        <p>Reduce manual dispensing errors, improve traceability of medicines, streamline inventory updates, and provide a single portal for pharmacy management tasks.</p>
    </div>
</div>

<?php include("includes/site-footer.php"); ?>
