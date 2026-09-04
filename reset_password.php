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
$prefillEmail = $_SESSION['reset_demo_email'] ?? '';

// This page only verifies the code. After verification the user is redirected to change_password.php
if (isset($_POST["verify_token"])) {
    $email = filter_input(INPUT_POST, "email", FILTER_SANITIZE_EMAIL);
    $token = trim($_POST["token"] ?? "");

    if (empty($email) || empty($token)) {
        $msg = "Please provide your email and the 6-digit verification code.";
    } else {
        $stmt = $conn->prepare("SELECT id FROM password_reset_tokens WHERE email = ? AND token = ? AND expires_at > NOW()");
        $stmt->bind_param("ss", $email, $token);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res->num_rows === 0) {
            $msg = "Invalid or expired verification code. Please request a new one.";
        } else {
            // Mark verified in session and redirect to change password page
            $_SESSION['reset_email_verified'] = $email;
            // Optional: set a short-lived verification timestamp
            $_SESSION['reset_verified_at'] = time();

            header('Location: change_password.php');
            exit;
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
<title><?= SYSTEM_NAME_FULL ?> | Reset Password</title>
<link rel="stylesheet" href="css/pharmacy.css">
</head>
<body class="auth-page">

<div class="auth-topbar">
    <?php $brandHref = 'index.php'; include __DIR__ . '/includes/brand-mark.php'; ?>
</div>

<div class="auth-wrap">
<div class="auth-card">
    <h2>Reset password</h2>
    <p class="subtitle"><?= SYSTEM_ACRONYM ?> account security reset.</p>

    <?php if ($success): ?>
        <div class="message success" style="max-width:none;"><?= htmlspecialchars($msg) ?></div>
        <p style="margin-top:16px;"><a class="link-primary" href="index.php">Go to login</a></p>
    <?php else: ?>

        <?php if (!empty($msg)): ?>
            <div class="message error" style="max-width:none;margin-bottom:16px;"><?= htmlspecialchars($msg) ?></div>
        <?php endif; ?>

        <form action="reset_password.php" method="post">
            <div class="input-group">
                <input type="email" name="email" placeholder="Registered email address" value="<?= htmlspecialchars($prefillEmail) ?>" required>
            </div>

            <div class="input-group">
                <input type="text" name="token" placeholder="6-digit verification code" maxlength="6" pattern="[0-9]{6}" required>
            </div>

            <input type="submit" class="btn-primary" value="Verify code" name="verify_token">
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
