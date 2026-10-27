<?php
// Secure session configuration for UTHM Secure Course Portal.
// IMPORTANT: paste your real Google reCAPTCHA keys below after replacing this file.

// Base URL configuration
define('BASE_URL', 'https://fsktm-course-system.test');
define('ENVIRONMENT', 'development');

// Google reCAPTCHA v2 Checkbox keys.
define('RECAPTCHA_SITE_KEY', '6Lfa8RYtAAAAAJxZkSyft9T61f6IvsmVTpOcMuCX');
define('RECAPTCHA_SECRET_KEY', '6Lfa8RYtAAAAAExkekHlcrO_hSBqjjNdhZstTHxW');
define('RECAPTCHA_VERIFY_URL', 'https://www.google.com/recaptcha/api/siteverify');

// Authentication hardening settings.
define('LOGIN_MAX_FAILED_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_SECONDS', 5 * 60);
define('SESSION_TIMEOUT_SECONDS', 30 * 60);
define('LOGIN_CLIENT_COOKIE_NAME', 'ucp_login_client');
define('LOGIN_CLIENT_LOCK_PEPPER', 'change-this-random-pepper-for-production');

// User roles
define('ROLE_STUDENT', 'student');
define('ROLE_LECTURER', 'lecturer');
define('ROLE_ADMIN', 'admin');

// File upload paths
define('UPLOAD_PROFILE_PATH', 'assets/uploads/profile-pics/');
define('UPLOAD_COURSE_PATH', 'assets/uploads/courses/');
define('UPLOAD_ASSIGNMENT_PATH', 'assets/uploads/assignments/');
define('UPLOAD_SUBMISSION_PATH', 'assets/uploads/submissions/');

// Default images
define('DEFAULT_PROFILE_PIC', 'assets/images/default-profile.jpg');
define('DEFAULT_COURSE_IMAGE', 'default-course.jpg');

// Max file size (5MB)
define('MAX_FILE_SIZE', 5 * 1024 * 1024);

// Allowed file types
define('ALLOWED_TYPES', ['pdf', 'doc', 'docx', 'zip']);

// Secure session cookie settings must be configured before session_start().
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
ini_set('session.use_only_cookies', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/database.php';

// Autoload classes
spl_autoload_register(function ($class) {
    $file = __DIR__ . '/../includes/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// Error reporting for local development. Set display_errors to 0 for production hosting.
ini_set('display_errors', ENVIRONMENT === 'development' ? '1' : '0');
ini_set('display_startup_errors', ENVIRONMENT === 'development' ? '1' : '0');
error_reporting(E_ALL);
?>
