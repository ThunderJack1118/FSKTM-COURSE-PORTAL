<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';

// Prevent browser/back-cache from showing protected pages after logout.
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    header('Expires: 0');
}

// Fully destroy the current login session.
if (function_exists('destroyCurrentSession')) {
    destroyCurrentSession();
} else {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'] ?? '/',
            $params['domain'] ?? '',
            $params['secure'] ?? false,
            $params['httponly'] ?? true
        );
    }

    session_unset();
    session_destroy();
}

// Start a clean session only if your login page needs flash messages later.
// No logout success flash is set here, so it will not appear on Admin Dashboard.
header('Location: ' . BASE_URL . '/pages/auth/login.php');
exit();
?>
