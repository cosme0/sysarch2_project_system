<?php

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();

require_once __DIR__ . '/database.php';;
require_once __DIR__ . '/auth.php';;
require_once __DIR__ . '/includes/config.php';

$msg = "";
$success = false;

// Ensure user verified the code on the previous step
if (empty($_SESSION['reset_email_verified'])) {
    // Not verified; redirect to verification step
    header('Location: reset_password.php');
    exit;
}

$email = $_SESSION['reset_email_verified'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF guard
    if (!verifyCsrfToken($_POST['_csrf'] ?? null)) {
        $msg = 'Your form session expired. Please refresh the page and try again.';
    } elseif (isset($_POST['reset_password'])) {
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (empty($password) || empty($confirm)) {
            $msg = 'Please fill in both password fields.';
        } elseif ($password !== $confirm) {
            $msg = 'Passwords do not match.';
        } else {
            $passwordError = validatePasswordStrength($password);
            if ($passwordError !== null) {
                $msg = $passwordError;
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $updStmt = $conn->prepare("UPDATE users SET `pass` = ?, failed_attempts = 0, last_failed = NULL WHERE email = ?");
                $updStmt->bind_param("ss", $hash, $email);
                $updStmt->execute();

                // delete any outstanding reset tokens for this email
                $delStmt = $conn->prepare("DELETE FROM password_reset_tokens WHERE email = ?");
                $delStmt->bind_param("s", $email);
                $delStmt->execute();

                // clear session flags
                unset($_SESSION['reset_email_verified'], $_SESSION['reset_verified_at'], $_SESSION['reset_demo_email'], $_SESSION['reset_demo_token']);

                log_activity(
                    'password_reset',
                    'account',
                    'Password reset completed for ' . $email . '; all outstanding reset codes were discarded.',
                    'critical',
                    'user',
                    $email,
                    null,
                    $email
                );

                $success = true;
                $msg = 'Your password has been reset. You can now log in with your new password.';
            }
        }
    }
}



?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= SYSTEM_NAME_FULL ?> | Create New Password</title>
<link rel="stylesheet" href="css/pharmacy.css">
<script>
function togglePassword(inputId) {
    const input = document.getElementById(inputId);
    if (!input) return;

    const isHidden = input.type === 'password';
    input.type = isHidden ? 'text' : 'password';

    const toggle = input.closest('.input-group')?.querySelector('.toggle-password');
    if (toggle) {
        toggle.classList.toggle('is-visible', !isHidden);
        toggle.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
    }
}
</script>
</head>
<body class="auth-page">

<div class="auth-topbar">
    <?php $brandHref = 'index.php'; include __DIR__ . '/includes/brand-mark.php'; ?>
</div>

<div class="auth-wrap">
<div class="auth-card">
    <h2>Create new password</h2>
    <p class="subtitle">Setting a new password for <?= htmlspecialchars($email) ?></p>

    <?php if ($success): ?>
        <div class="message success" style="max-width:none;"><?= htmlspecialchars($msg) ?></div>
        <p style="margin-top:16px;"><a class="link-primary" href="index.php">Go to login</a></p>
    <?php else: ?>
        <?php if (!empty($msg)): ?>
            <div class="message error" style="max-width:none;margin-bottom:16px;"><?= htmlspecialchars($msg) ?></div>
        <?php endif; ?>

        <form action="change_password.php" method="post">
            <?php csrf_field(); ?>
            <div class="input-group" style="position: relative;">
                <input type="password" id="newPassword" name="password" placeholder="New password" required>
                <button type="button" class="toggle-password" onclick="togglePassword('newPassword')" aria-label="Show password">
                    <svg class="eye-icon eye-open" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    <svg class="eye-icon eye-off" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                </button>
            </div>
            <div class="input-group" style="position: relative;">
                <input type="password" id="confirmNewPassword" name="confirm_password" placeholder="Confirm new password" required>
                <button type="button" class="toggle-password" onclick="togglePassword('confirmNewPassword')" aria-label="Show password">
                    <svg class="eye-icon eye-open" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    <svg class="eye-icon eye-off" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                </button>
            </div>
            <p class="password-hint">Password must be at least 8 characters and include uppercase, lowercase, a number, and a special character.</p>
            <input type="submit" class="btn-primary" value="Set new password" name="reset_password">
        </form>

        <p style="margin-top:16px;font-size:0.875rem;">
            <a class="link-primary" href="forgot_password.php">Request a new code</a> &nbsp;|&nbsp;
            <a class="link-primary" href="index.php">Back to login</a>
        </p>
    <?php endif; ?>
</div>
</div>

<footer class="site-footer">
    <p>&copy; <?= date('Y') ?> <?= SYSTEM_NAME_FULL ?></p>
</footer>

</body>
</html>
