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

// track whether email was successfully handed off to SMTP lib
$mailSent = false;

// CSRF guard
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrfToken($_POST['_csrf'] ?? null)) {
    $msg = "Your form session expired. Please refresh the page and try again.";
} elseif (isset($_POST["request_reset"])) {

    $email = filter_input(INPUT_POST, "email", FILTER_SANITIZE_EMAIL);

    if (empty($email)) {
        $msg = "Please enter your registered email address.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $msg = "Please enter a valid email address.";
    } else {
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res->num_rows > 0) {
            // generate 6-digit numeric token
            $token = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            // remove any previous tokens for this email
            $delStmt = $conn->prepare("DELETE FROM password_reset_tokens WHERE email = ?");
            $delStmt->bind_param("s", $email);
            $delStmt->execute();

            $hours = RESET_TOKEN_EXPIRY_HOURS;
            $insStmt = $conn->prepare("INSERT INTO password_reset_tokens (email, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? HOUR))");
            $insStmt->bind_param("ssi", $email, $token, $hours);
            $insStmt->execute();

            // remember email in session so forms can be prefilled
            $_SESSION['reset_demo_email'] = $email;

            // attempt to send via PHPMailer if available (vendor/autoload.php)
            $mailSent = false;
            $smtpTemplateWarning = (
                SMTP_USERNAME === 'your-email@gmail.com' ||
                SMTP_PASSWORD === 'your-app-password' ||
                SMTP_FROM_EMAIL === 'no-reply@yourdomain.com'
            );

            if (file_exists(__DIR__ . '/vendor/autoload.php')) {
                require_once __DIR__ . '/vendor/autoload.php';

                if ($smtpTemplateWarning) {
                    $mailSent = false;
                } else {
                    try {
                        $mail = new PHPMailer\PHPMailer\PHPMailer(true);

                        // server settings
                        $mail->isSMTP();
                        $mail->Host = SMTP_HOST;
                        $mail->SMTPAuth = true;
                        $mail->Username = SMTP_USERNAME;
                        $mail->Password = SMTP_PASSWORD;
                        $mail->SMTPSecure = SMTP_SECURE;
                        $mail->Port = SMTP_PORT;

                        // recipients and content
                        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
                        $mail->addAddress($email);
                        $mail->isHTML(true);
                        $mail->Subject = 'Password reset verification code';
                        $mail->Body = "<p>Hi,</p><p>Your password reset verification code is: <strong>{$token}</strong></p><p>This code will expire in {$hours} hour(s).</p>";
                        $mail->AltBody = "Your password reset verification code is: {$token}. It will expire in {$hours} hour(s).";

                        $mail->send();
                        $mailSent = true;
                    } catch (Exception $e) {
                        error_log('Mail send error: ' . $e->getMessage());
                        $mailSent = false;
                    }
                }
            }

            // Only keep the token in the error log for developers when the email
            // could not be sent. It is NEVER rendered on the page.
            if (!$mailSent) {
                error_log("Password reset code for {$email}: {$token} (email not sent)");
                unset($_SESSION['reset_demo_token']);
            } else {
                unset($_SESSION['reset_demo_token']);
            }

            log_activity(
                'reset_code_issued',
                'auth',
                'Issued a password reset code for ' . $email
                    . ($mailSent ? ' and emailed it.' : '; the email could not be sent so the code was written to the error log only.'),
                $mailSent ? 'notice' : 'warning',
                'user',
                $email,
                null,
                $email
            );
        }

        $success = true;
        if ($mailSent) {
            $msg = "If your email is registered, a verification code has been emailed to you. Use it on the reset password page.";
        } else {
            $msg = "If your email is registered, a verification code has been generated.";
            if (!empty($smtpTemplateWarning) && $smtpTemplateWarning) {
                $msg .= " SMTP is still using placeholder Gmail values. Edit includes/smtp_config.php and replace the sample email/app password with your real Gmail credentials.";
            } else {
                $msg .= " Email sending is not configured or failed. Check your SMTP credentials and Gmail app password.";
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
<title><?= SYSTEM_NAME_FULL ?> | Forgot Password</title>
<link rel="stylesheet" href="css/pharmacy.css">
</head>

<body class="auth-page">

<div class="auth-topbar">
    <?php $brandHref = 'index.php'; include __DIR__ . '/includes/brand-mark.php'; ?>
</div>

<div class="auth-wrap">
<div class="auth-card">
    <h2>Forgot password</h2>
    <p class="subtitle">Recover access to your <?= SYSTEM_ACRONYM ?> staff account.</p>

    <form action="forgot_password.php" method="post">
        <?php csrf_field(); ?>
        <div class="input-group">
            <input type="email" name="email" placeholder="Registered email address" required>
        </div>
        <input type="submit" class="btn-primary" value="Send verification code" name="request_reset">
    </form>

    <p style="margin-top:16px;"><a class="link-primary" href="reset_password.php">Already have a code?</a></p>
    <p><a class="link-primary" href="index.php">Back to login</a></p>
</div>

<?php if (!empty($msg)): ?>
<div class="message <?= $success ? 'success' : 'error' ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

</div>

<footer class="site-footer">
    <p>&copy; <?= date('Y') ?> <?= SYSTEM_NAME_FULL ?></p>
</footer>

</body>

</html>
