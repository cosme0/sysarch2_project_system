<?php
/**
 * Handles the "Logout" button. Included near the top of every authenticated
 * page, before any HTML is emitted.
 */
if (isset($_POST['logout'])) {
    // Ignore forged logout requests rather than destroying the session.
    if (verifyCsrfToken($_POST['_csrf'] ?? null)) {
        $email    = $_SESSION['email'] ?? null;
        $userId   = (int)($_SESSION['user_id'] ?? 0);
        $name     = trim(($_SESSION['fname'] ?? '') . ' ' . ($_SESSION['lname'] ?? ''));
        $duration = isset($_SESSION['login_at'])
            ? max(1, (int)round((time() - $_SESSION['login_at']) / 60))
            : null;

        $details = 'Signed out.';
        if ($duration !== null) {
            $details .= " Session lasted about {$duration} minute(s).";
        }

        // Recorded before the session is torn down so the user is still known.
        log_activity(
            'logout',
            'auth',
            $details,
            'info',
            'user',
            $email,
            $userId,
            $email
        );

        if (!empty($_COOKIE[session_name()])) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }

        session_unset();
        session_destroy();
        header('Location: index.php?logout=1');
        exit;
    }
}
