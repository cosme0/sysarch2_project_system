<?php
/**
 * AMDPMS - shared authentication, session, CSRF and activity-logging layer.
 *
 * Every page includes this file first. It owns:
 *   - the session bootstrap and idle timeout
 *   - role checks (admin / staff)
 *   - CSRF tokens
 *   - the shared database connection (db())
 *   - the comprehensive activity log (log_activity / log_page_view)
 */
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

require_once __DIR__ . '/includes/config.php';

// ---------------------------------------------------------------------------
// Application constants
// ---------------------------------------------------------------------------
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_MINUTES', 15);
define('SESSION_TIMEOUT_MINUTES', 20);
define('LOW_STOCK_THRESHOLD', 50);
define('STOCK_REFERENCE', 100);

/** Payment methods offered by the checkout simulation. */
define('PAYMENT_METHODS', ['cash', 'gcash', 'maya']);
define('PAYMENT_LABELS', [
    'cash'  => 'Cash',
    'gcash' => 'GCash',
    'maya'  => 'Maya',
]);

/** Severity levels used by the activity log. */
define('AUDIT_SEVERITIES', ['info', 'notice', 'warning', 'critical']);

/**
 * Repeating visits to the same page inside this window are recorded once, so
 * ordinary navigation does not flood the log with page-view noise.
 */
define('AUDIT_VIEW_THROTTLE_SECONDS', 60);

// ---------------------------------------------------------------------------
// Shared database connection
//
// The project previously opened a separate mysqli handle in every file, and the
// activity log opened yet another one on every single call. All of them now
// come from this one lazily-created, reused connection.
// ---------------------------------------------------------------------------
if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_USER')) define('DB_USER', 'root');
if (!defined('DB_PASS')) define('DB_PASS', '');
if (!defined('DB_NAME')) define('DB_NAME', 'db_users');

function db(): mysqli
{
    static $conn = null;

    if ($conn instanceof mysqli) {
        try {
            if (@mysqli_ping($conn)) {
                return $conn;
            }
        } catch (Throwable $e) {
            // Handle was closed by an earlier explicit close(); fall through.
        }
        $conn = null;
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if (!$conn) {
        throw new RuntimeException('Could not connect to the database.');
    }
    mysqli_set_charset($conn, 'utf8mb4');

    return $conn;
}

// ---------------------------------------------------------------------------
// Password strength, lockout and session helpers
// ---------------------------------------------------------------------------
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
        return 'Password must include one special character.';
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
        log_activity('access_denied', 'admin', 'Non-admin user opened the admin console.', 'warning');
        header('Location: home.php');
        exit;
    }
}

function requireStaff(): void
{
    requireLogin();
    if (isAdmin()) {
        log_activity('access_denied', 'dashboard', 'Admin user opened a staff-only page.', 'warning');
        header('Location: admin.php');
        exit;
    }
}

function checkSessionTimeout(): void
{
    $timeout = SESSION_TIMEOUT_MINUTES * 60;
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
        $timedOutEmail = $_SESSION['email'] ?? null;
        session_unset();
        session_destroy();
        log_activity('session_expired', 'auth', 'Idle for more than ' . SESSION_TIMEOUT_MINUTES . ' minutes; session destroyed.', 'notice', 'user', $timedOutEmail, null, $timedOutEmail);
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
    $_SESSION['login_at'] = time();
    unset($_SESSION['_audit_views']);

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
        'login_required'  => 'Please log in to access that page.',
    ];
    return $messages[$code] ?? '';
}

// ---------------------------------------------------------------------------
// CSRF protection
// ---------------------------------------------------------------------------
function getCsrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): void
{
    echo '<input type="hidden" name="_csrf" value="' . htmlspecialchars(getCsrfToken()) . '">';
}

function verifyCsrfToken(?string $token): bool
{
    return is_string($token)
        && is_string($_SESSION['csrf_token'] ?? null)
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Binds an array of values to a prepared statement.
 *
 * mysqli_stmt::bind_param() takes its arguments by reference, so a plain
 * `$stmt->bind_param($types, ...$values)` throws "cannot be passed by
 * reference" in PHP 8. This helper rebuilds the argument list as references.
 *
 * $values is deliberately taken by value so callers can pass a literal such as
 * `[$from, $to]`; the local copy below is what ends up referenced.
 */
function bind_stmt(mysqli_stmt $stmt, string $types, array $values): void
{
    $args = [];
    $args[] = &$types;
    foreach (array_values($values) as $key => &$value) {
        $args[$key + 1] = &$value;
    }
    unset($value);
    $stmt->bind_param(...$args);
}

// ---------------------------------------------------------------------------
// Comprehensive activity log
//
// The log records *what* happened, not just that someone logged in. Every entry
// answers: who, which module of the system, which action, which record it
// touched, how serious it is, from where, and with what device.
//
//   log_activity('med_update', 'inventory', 'Paracetamol 500mg price 45.00 -> 48.00',
//                'notice', 'medication', 'MED-001');
//
// Logging is best-effort: a failure here must never break the page the user
// was trying to reach, so every write is wrapped and errors go to error_log.
// ---------------------------------------------------------------------------

/** Maps a page file to the module name used in the activity log. */
function moduleForPage(string $page): ?string
{
    // Polling / JSON endpoints and the login screen are not user "activity".
    static $map = [
        'index.php'             => 'auth',
        'forgot_password.php'   => 'auth',
        'reset_password.php'    => 'auth',
        'home.php'              => 'dashboard',
        'shop.php'              => 'shop',
        'checkout.php'          => 'checkout',
        'receipt.php'           => 'checkout',
        'reports.php'           => 'reports',
        'audit-log.php'         => 'audit',
        'admin.php'             => 'admin',
        'db_backup.php'         => 'admin',
        'support-tickets.php'   => 'support',
        'contact.php'           => 'support',
        'account.php'           => 'account',
        'change_password.php'   => 'account',
        'about.php'             => 'about',
    ];

    // Endpoints called by JavaScript on a timer.
    static $skip = ['stock-status.php', 'arduino-status.php', 'log_dispense.php'];

    if (in_array($page, $skip, true)) {
        return null;
    }
    return $map[$page] ?? null;
}

/**
 * Records an activity. Safe to call from anywhere; never throws.
 *
 * @param string      $action   what happened, e.g. 'login_success'
 * @param string      $module   which part of the system, e.g. 'inventory'
 * @param string      $details  human-readable context
 * @param string      $severity info | notice | warning | critical
 * @param string|null $entity   record type touched, e.g. 'medication'
 * @param string|null $entityId identifier of the record touched
 */
function log_activity(
    string $action,
    string $module = 'system',
    string $details = '',
    string $severity = 'info',
    ?string $entity = null,
    ?string $entityId = null,
    ?int $userId = null,
    ?string $userEmail = null
): void {
    try {
        $userId    = $userId    ?? (int)($_SESSION['user_id'] ?? 0);
        $userEmail = $userEmail ?? ($_SESSION['email'] ?? null);
        $userRole  = isset($_SESSION['role']) ? (getCurrentUserRole() === 1 ? 'admin' : 'staff') : null;

        $action   = mb_substr(trim($action), 0, 50);
        $module   = mb_substr(trim($module), 0, 50);
        $details  = mb_substr(trim($details), 0, 490);
        $severity = in_array($severity, AUDIT_SEVERITIES, true) ? $severity : 'info';
        $entity   = $entity !== null ? mb_substr(trim($entity), 0, 50) : null;
        $entityId = $entityId !== null ? mb_substr(trim($entityId), 0, 64) : null;

        $ip      = $_SERVER['REMOTE_ADDR'] ?? null;
        $agent   = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        $uri     = mb_substr((string)($_SERVER['REQUEST_URI'] ?? ''), 0, 255);

        $stmt = db()->prepare(
            "INSERT INTO audit_log
                (user_email, user_id, user_role, module, action, severity,
                 entity, entity_id, details, ip_address, user_agent, request_uri, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );
       // One type character per column, in the exact order of the INSERT list:
       // user_email s, user_id i, then ten strings (role, module, action,
       // severity, entity, entity_id, details, ip, user agent, request URI).
       $stmt->bind_param(
           "sissssssssss",
           $userEmail, $userId, $userRole, $module, $action, $severity,
           $entity, $entityId, $details, $ip, $agent, $uri
       );
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('log_activity failed: ' . $e->getMessage());
    }
}

/**
 * Backwards-compatible wrapper for the original audit_log() signature.
 * @deprecated Use log_activity() so the module and severity are recorded.
 */
function audit_log(string $action, string $details = '', ?int $userId = null, ?string $userEmail = null): void
{
    log_activity($action, 'admin', $details, 'notice', null, null, $userId, $userEmail);
}

/**
 * Records that a user opened a module, so the audit trail shows which parts of
 * the system were actually used. Throttled and skipped for background polling.
 */
function log_page_view(?string $page = null): void
{
    try {
        $page = $page ?? basename((string)($_SERVER['PHP_SELF'] ?? ''));

        // Background requests from the browser's fetch() are not user activity.
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
            return;
        }

        $module = moduleForPage($page);
        if ($module === null) {
            return;
        }

        // Record the login screen only for real sign-in attempts, which are
        // logged explicitly by index.php.
        if ($module === 'auth' && !isset($_SESSION['email'])) {
            return;
        }

        $now = time();
        if (isset($_SESSION['_audit_views'][$page]) && ($now - $_SESSION['_audit_views'][$page]) < AUDIT_VIEW_THROTTLE_SECONDS) {
            return;
        }
        $_SESSION['_audit_views'][$page] = $now;

        $label = audit_label_for_page($page);
        log_activity('page_view', $module, 'Opened ' . $label . '.', 'info', 'page', $page);
    } catch (Throwable $e) {
        error_log('log_page_view failed: ' . $e->getMessage());
    }
}

/** Readable name for a page, used in activity-log details. */
function audit_label_for_page(string $page): string
{
    $labels = [
        'home.php'            => 'Dashboard',
        'shop.php'            => 'Medications catalogue',
        'checkout.php'        => 'Checkout',
        'receipt.php'         => 'Receipt',
        'reports.php'         => 'Reports and analytics',
        'audit-log.php'       => 'System activity log',
        'admin.php'           => 'Admin console',
        'db_backup.php'       => 'Database backup',
        'support-tickets.php' => 'Support tickets',
        'contact.php'         => 'Support request form',
        'account.php'         => 'My account',
        'change_password.php' => 'Change password',
        'about.php'           => 'About the system',
    ];
    return $labels[$page] ?? $page;
}

/** Human-readable label for a stored action code. */
function audit_action_label(string $action): string
{
    return str_replace('_', ' ', $action);
}
