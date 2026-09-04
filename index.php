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

if (isset($_GET['error'])) {
    $authMsg = getAuthErrorMessage($_GET['error']);
    if ($authMsg !== '') {
        $msg = $authMsg;
    }
}

// --- REGISTER ---
if (isset($_POST["signUp"])) {
    $fname = filter_input(INPUT_POST, "fname", FILTER_SANITIZE_SPECIAL_CHARS);
    $lname = filter_input(INPUT_POST, "lname", FILTER_SANITIZE_SPECIAL_CHARS);
    $email = filter_input(INPUT_POST, "email", FILTER_SANITIZE_EMAIL);
    $password = $_POST["password"] ?? "";

    if (empty($fname) || empty($lname) || empty($email) || empty($password)) {
        $msg = "Please fill in all fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $msg = "Please enter a valid email address.";
    } else {
        $passwordError = validatePasswordStrength($password);
        if ($passwordError !== null) {
            $msg = $passwordError;
        } else {
            $checkStmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $checkStmt->bind_param("s", $email);
            $checkStmt->execute();
            $checkRes = $checkStmt->get_result();

            if ($checkRes->num_rows > 0) {
                $msg = "That email is already registered. <a href='#' onclick='showSignIn()'>Login instead</a>.";
            } else {
                // First registered user becomes the administrator.
                $countStmt = $conn->query("SELECT COUNT(*) AS c FROM users");
                $totalUsers = (int)$countStmt->fetch_assoc()['c'];
                $role = $totalUsers === 0 ? 'admin' : 'staff';

                $hash = password_hash($password, PASSWORD_DEFAULT);
                $insStmt = $conn->prepare("INSERT INTO users (fname, lname, email, `pass`, role) VALUES (?, ?, ?, ?, ?)");
                $insStmt->bind_param("sssss", $fname, $lname, $email, $hash, $role);
                if ($insStmt->execute()) {
                    $roleInt = $role === 'admin' ? 1 : 0;
                    setUserSession($email, $fname, $lname, false, $roleInt, (int)$insStmt->insert_id);
                    header("Location: " . ($roleInt === 1 ? 'admin.php' : 'home.php'));
                    exit;
                } else {
                    $msg = "Registration failed. Please try again.";
                }
            }
        }
    }
}

// --- LOGIN ---
if (isset($_POST["signIn"])) {
    $loginId = trim($_POST["login_id"] ?? "");
    $password = $_POST["password"] ?? "";
    $rememberMe = isset($_POST["remember_me"]);

    if ($loginId === "" || $password === "") {
        $msg = "Please enter both username and password.";
    } else {
        $stmt = $conn->prepare("SELECT id, fname, lname, email, `pass`, role, failed_attempts, last_failed FROM users WHERE email = ?");
        $stmt->bind_param("s", $loginId);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res->num_rows > 0) {
            $row = $res->fetch_assoc();
            $failedAttempts = (int)$row['failed_attempts'];
            $lastFailed = $row['last_failed'];

            if (isAccountLocked($failedAttempts, $lastFailed)) {
                $remaining = getLockoutRemainingSeconds($lastFailed);
                $minutes = (int)max(1, ceil($remaining / 60));
                $msg = "Your account has been locked due to multiple failed login attempts. Try again in {$minutes} minute(s) or contact the system administrator.";
            } elseif (password_verify($password, $row['pass'])) {
                $resetStmt = $conn->prepare("UPDATE users SET failed_attempts = 0, last_failed = NULL WHERE id = ?");
                $resetStmt->bind_param("i", $row['id']);
                $resetStmt->execute();

                $roleInt = ($row['role'] ?? 'staff') === 'admin' ? 1 : 0;
                setUserSession($row['email'], $row['fname'], $row['lname'], $rememberMe, $roleInt, (int)$row['id']);
                header("Location: " . ($roleInt === 1 ? 'admin.php' : 'home.php'));
                exit;
            } else {
                $newAttempts = $failedAttempts + 1;
                $nowStr = date("Y-m-d H:i:s");
                $updateStmt = $conn->prepare("UPDATE users SET failed_attempts = ?, last_failed = ? WHERE id = ?");
                $updateStmt->bind_param("isi", $newAttempts, $nowStr, $row['id']);
                $updateStmt->execute();

                if ($newAttempts >= MAX_LOGIN_ATTEMPTS) {
                    $msg = "Your account has been locked due to multiple failed login attempts.";
                } else {
                    $msg = "Invalid username or password.";
                }
            }
        } else {
            $msg = "Invalid username or password.";
        }
    }
}

mysqli_close($conn);

// Track whether the sign-up form should be displayed (e.g., after a failed registration)
$showSignUp = isset($_POST['signUp']) ? true : false;
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= SYSTEM_NAME_FULL ?> | Login</title>
 <link rel="stylesheet" href="css/pharmacy.css?v=<?= filemtime(__DIR__ . '/css/pharmacy.css') ?>">
<style>.auth-card .btn-primary { margin-top: 8px; }</style>
<script src="index.js" defer></script>
<script>
function showSignUp() {
    document.getElementById("signup").style.display = "block";
    document.getElementById("signIn").style.display = "none";
}
function showSignIn() {
    document.getElementById("signup").style.display = "none";
    document.getElementById("signIn").style.display = "block";
}
</script>
</head>
<body class="auth-page">

<div class="auth-topbar">
    <?php $brandHref = 'index.php'; include __DIR__ . '/includes/brand-mark.php'; ?>
    <p class="auth-system-tagline"><?= SYSTEM_TAGLINE ?></p>
</div>

<div class="auth-wrap">
<div class="auth-banner">
    <img src="https://domf5oio6qrcr.cloudfront.net/medialibrary/15900/gettyimages-1342010434.jpg" alt="Automated medication dispensing system">
</div>

<?php if (!empty($msg)) echo "<div class='message error fade'>{$msg}</div>"; ?>

<?php
// Prefill registration fields so the user doesn't lose data after an error
$prefillFname = htmlspecialchars($_POST['fname'] ?? '', ENT_QUOTES);
$prefillLname = htmlspecialchars($_POST['lname'] ?? '', ENT_QUOTES);
$prefillEmail = htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES);
?>

<!-- SIGN IN -->
<div class="auth-card fade" id="signIn" <?= $showSignUp ? 'style="display:none;"' : '' ?>>
    <h2>Sign in</h2>
    <p class="subtitle">Sign in to access the pharmacy management portal.</p>
    <form action="index.php" method="post">
        <div class="input-group">
            <input type="text" name="login_id" placeholder="Username or Email Address" required autocomplete="username">
        </div>
        <div class="input-group" style="position: relative;">
            <input type="password" id="loginPassword" name="password" placeholder="Password" required autocomplete="current-password">
            <span class="toggle-password" onclick="togglePassword('loginPassword')">Show</span>
        </div>
        <label class="remember-row">
            <input type="checkbox" name="remember_me" value="1">
            Remember Me
        </label>
        <input type="submit" class="btn-primary" value="Login" name="signIn">
    </form>
    <p style="margin-top:16px;"><a class="link-primary" href="forgot_password.php">Forgot password?</a></p>
    <p style="margin-top:12px;font-size:0.9rem;color:var(--text-muted);">New here?</p>
    <button id="signUpButton" type="button" class="btn-secondary">Create account</button>
</div>

<!-- SIGN UP -->
<div class="auth-card fade" id="signup" <?= $showSignUp ? '' : 'style="display:none;"' ?>>
    <h2>Create account</h2>
    <p class="subtitle">Register authorized staff access to <?= SYSTEM_ACRONYM ?>.</p>
    <form action="index.php" method="post">
        <div class="input-group">
            <input type="text" name="fname" placeholder="First Name" value="<?= $prefillFname ?>" required>
        </div>
        <div class="input-group">
            <input type="text" name="lname" placeholder="Last Name" value="<?= $prefillLname ?>" required>
        </div>
        <div class="input-group">
            <input type="email" name="email" placeholder="Email Address" value="<?= $prefillEmail ?>" required>
        </div>
        <div class="input-group" style="position: relative;">
            <input type="password" id="signupPassword" name="password" placeholder="Password" required>
            <span class="toggle-password" onclick="togglePassword('signupPassword')">Show</span>
        </div>
        <p class="password-hint">Password must be at least 8 characters and include uppercase, lowercase, a number, and a special character (e.g. Hrm@2026!).</p>
        <input type="submit" class="btn-primary" value="Sign Up" name="signUp">
    </form>
    <p style="margin-top:12px;font-size:0.9rem;color:var(--text-muted);">Already registered?</p>
    <button id="signInButton" type="button" class="btn-secondary">Back to login</button>
</div>
</div>

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

<footer class="site-footer">
    <p>&copy; <?= date('Y') ?> <?= SYSTEM_NAME_FULL ?></p>
    <p class="footer-note"><?= SYSTEM_ACRONYM ?> — Authorized personnel only.</p>
</footer>

</body>
</html>
