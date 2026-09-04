<?php

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();

include("database.php");
include("auth.php");
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

if (isset($_POST['reset_password'])) {
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

            $success = true;
            $msg = 'Your password has been reset. You can now log in with your new password.';
        }
    }
}

mysqli_close($conn);

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
        toggle.textContent = isHidden ? 'Hide' : 'Show';
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
            <div class="input-group" style="position: relative;">
                <input type="password" id="newPassword" name="password" placeholder="New password" required>
                <span class="toggle-password" onclick="togglePassword('newPassword')">Show</span>
            </div>
            <div class="input-group" style="position: relative;">
                <input type="password" id="confirmNewPassword" name="confirm_password" placeholder="Confirm new password" required>
                <span class="toggle-password" onclick="togglePassword('confirmNewPassword')">Show</span>
            </div>
            <p class="password-hint">Password must be at least 8 characters and include uppercase, lowercase, a number, and a special character.</p>
            <input type="submit" class="btn-primary" value="Set new password" name="reset_password">
        </form>

        <p style="margin-top:16px;font-size:0.9rem;">
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
