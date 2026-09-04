<?php
date_default_timezone_set('Asia/Manila');

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_MINUTES', 15);
define('SESSION_TIMEOUT_MINUTES', 20);
define('RESET_TOKEN_EXPIRY_HOURS', 1);

function validatePasswordStrength(string $password): ?string
{
    if (strlen($password) < 8) {
        return 'Password must be at least 8 characters long.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return 'Password must include an uppercase letter.';
    }
    if (!preg_match('/[a-z]/', $password)) {
        return 'Password must include a lowercase letter.';
    }
    if (!preg_match('/[0-9]/', $password)) {
        return 'Password must include at least one number.';
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        return 'Password must include at least one special character.';
    }
    return null;
}

function isAccountLocked(int $failedAttempts, ?string $lastFailed): bool
{
    if ($failedAttempts < MAX_LOGIN_ATTEMPTS || $lastFailed === null) {
        return false;
    }
    return time() < strtotime($lastFailed) + (LOCKOUT_MINUTES * 60);
}

function getLockoutRemainingSeconds(?string $lastFailed): int
{
    if ($lastFailed === null) {
        return 0;
    }
    return max(0, strtotime($lastFailed) + (LOCKOUT_MINUTES * 60) - time());
}

function requireLogin(): void
{
    if (!isset($_SESSION['email'])) {
        header('Location: index.php?error=login_required');
        exit;
    }
    checkSessionTimeout();
}

function getCurrentUserRole(): int
{
    return (int)($_SESSION['role'] ?? 0);
}

function isAdmin(): bool
{
    return getCurrentUserRole() === 1;
}

function requireAdmin(): void
{
    requireLogin();
    if (!isAdmin()) {
        header('Location: home.php');
        exit;
    }
}

function checkSessionTimeout(): void
{
    $timeout = SESSION_TIMEOUT_MINUTES * 60;
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
        session_unset();
        session_destroy();
        header('Location: index.php?error=session_expired');
        exit;
    }
    $_SESSION['last_activity'] = time();
}

function setUserSession(string $email, string $fname, string $lname, bool $rememberMe = false, int $role = 0, int $userId = 0): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (!isset($_SESSION['email']) || $_SESSION['email'] !== $email) {
        session_regenerate_id(true);
    }

    $_SESSION['email'] = $email;
    $_SESSION['fname'] = $fname;
    $_SESSION['lname'] = $lname;
    $_SESSION['role'] = $role;
    $_SESSION['user_id'] = $userId;
    $_SESSION['last_activity'] = time();

    if ($rememberMe) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            session_id(),
            time() + (30 * 24 * 60 * 60),
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
}

function getAuthErrorMessage(string $code): string
{
    $messages = [
        'session_expired' => 'Session expired. Please log in again.',
        'login_required' => 'Please log in to access that page.',
    ];
    return $messages[$code] ?? '';
}
