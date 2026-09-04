<?php
include("auth.php");
requireLogin();
include("includes/logout.php");
require_once __DIR__ . '/includes/config.php';

$pageTitle = 'Account | ' . SYSTEM_NAME_FULL;
include("includes/site-header.php");
?>

<h1 class="page-title">Staff account</h1>
<p class="page-subtitle">Your profile in the <?= SYSTEM_ACRONYM ?> portal.</p>

<div class="card card-narrow">
    <div class="account-info">
        <div class="account-row">
            <span>First name</span>
            <strong><?= htmlspecialchars($_SESSION['fname'] ?? '—') ?></strong>
        </div>
        <div class="account-row">
            <span>Last name</span>
            <strong><?= htmlspecialchars($_SESSION['lname'] ?? '—') ?></strong>
        </div>
        <div class="account-row">
            <span>Login email</span>
            <strong><?= htmlspecialchars($_SESSION['email'] ?? '—') ?></strong>
        </div>
        <div class="account-row">
            <span>Access level</span>
            <strong style="color:var(--primary);"><?= isAdmin() ? 'Administrator' : 'Pharmacy staff' ?></strong>
        </div>
        <div class="account-row">
            <span>Session policy</span>
            <strong>20 min idle timeout</strong>
        </div>
    </div>
    <p style="margin-top:24px;font-size:0.85rem;color:var(--text-muted);text-align:center;">
        Reset password via <a class="link-primary" href="forgot_password.php">Forgot password</a> (available from the login screen after logout).
    </p>
</div>

<?php include("includes/site-footer.php"); ?>
