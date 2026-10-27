<?php

if (!function_exists('sendNoStoreHeaders')) {
    function sendNoStoreHeaders() {
        if (!headers_sent()) {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Cache-Control: post-check=0, pre-check=0', false);
            header('Pragma: no-cache');
            header('Expires: 0');
        }
    }
}

function redirect($url) {
    header("Location: " . BASE_URL . $url);
    exit();
}

function isLoggedIn() {
    if (!isset($_SESSION['user_id'])) {
        return false;
    }

    // Enforce inactivity timeout on protected pages.
    $timeout = defined('SESSION_TIMEOUT_SECONDS') ? (int) SESSION_TIMEOUT_SECONDS : (30 * 60);
    if ($timeout > 0) {
        $lastActivity = isset($_SESSION['last_activity']) ? (int) $_SESSION['last_activity'] : time();

        if (isset($_SESSION['last_activity']) && (time() - $lastActivity) > $timeout) {
            $expiredMessage = 'Your session has expired due to inactivity. Please login again.';

            if (function_exists('logAuditEvent')) {
                logAuditEvent($_SESSION['user_id'] ?? null, 'session_timeout', 'Session expired due to inactivity.', [
                    'username' => $_SESSION['username'] ?? null,
                    'timeout_seconds' => $timeout
                ]);
            }

            if (function_exists('destroyCurrentSession')) {
                destroyCurrentSession();
            } else {
                $_SESSION = [];
                if (ini_get('session.use_cookies')) {
                    $params = session_get_cookie_params();
                    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
                }
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_destroy();
                }
            }

            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_start();
            }

            $_SESSION['session_expired_modal'] = $expiredMessage;
            $_SESSION['warning'] = $expiredMessage;
            redirect('/pages/auth/login.php?session_expired=1');
        }

        $_SESSION['last_activity'] = time();
    }

    return true;
}

function isAdmin() {
    return isLoggedIn() && $_SESSION['user_type'] === ROLE_ADMIN;
}

function isLecturer() {
    return isLoggedIn() && $_SESSION['user_type'] === ROLE_LECTURER;
}

function isStudent() {
    return isLoggedIn() && $_SESSION['user_type'] === ROLE_STUDENT;
}

function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

function displayError($error) {
    echo '<div class="alert alert-error">' . $error . '</div>';
}

function displaySuccess($message) {
    echo '<div class="alert alert-success">' . $message . '</div>';
}

function getCourseCategories() {
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("SELECT * FROM course_categories");
    $stmt->execute();
    $result = $stmt->get_result();
    
    $categories = [];
    while ($row = $result->fetch_assoc()) {
        $categories[] = $row;
    }
    
    $stmt->close();
    $db->closeConnection();
    
    return $categories;
}

function getCourses($limit = null, $category_id = null, $search = null, $exclude_registered = false) {
    $db = new Database();
    $conn = $db->getConnection();
    
    $query = "SELECT c.*, u.first_name, u.last_name, cc.category_name, cc.color_code 
              FROM courses c
              LEFT JOIN users u ON c.instructor_id = u.user_id
              LEFT JOIN course_categories cc ON c.category_id = cc.category_id";
    
    // Add WHERE clauses based on parameters
    $where = [];
    $params = [];
    $types = "";
    
    if ($category_id) {
        $where[] = "c.category_id = ?";
        $params[] = $category_id;
        $types .= "i";
    }
    
    if ($search) {
        $where[] = "(c.course_name LIKE ? OR c.course_code LIKE ? OR c.description LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $types .= "sss";
    }
    
    // Exclude registered courses for students
    if ($exclude_registered && isStudent()) {
        $where[] = "c.course_id NOT IN (
            SELECT course_id FROM registrations 
            WHERE user_id = ? AND status IN ('approved', 'pending', 'completed')
        )";
        $params[] = $_SESSION['user_id'];
        $types .= "i";
    }
    
    if (!empty($where)) {
        $query .= " WHERE " . implode(" AND ", $where);
    }
    
    $query .= " ORDER BY c.semester, c.course_name";
    
    if ($limit) {
        $query .= " LIMIT ?";
        $params[] = $limit;
        $types .= "i";
    }
    
    $stmt = $conn->prepare($query);
    
    if ($stmt) {
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $courses = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $db->closeConnection();
        return $courses;
    }
    
    $db->closeConnection();
    return [];
}
function getAvatarUrl($email, $size = 150) {
    if (!empty($email)) {
        $hash = md5(strtolower(trim($email)));
        return "https://www.gravatar.com/avatar/{$hash}?s={$size}&d=mp";
    }
    return "https://www.gravatar.com/avatar/?s={$size}&d=mp"; // Default avatar
}

function handleFileUpload($file, $target_dir, $allowed_types, $max_size) {
    $errors = [];
    
    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = "File upload error: " . $file['error'];
        return [false, $errors];
    }
    
    // Check file size
    if ($file['size'] > $max_size) {
        $errors[] = "File is too large. Maximum size is " . ($max_size / 1024 / 1024) . "MB.";
    }
    
    // Check file type
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($file_ext, $allowed_types)) {
        $errors[] = "File type not allowed. Allowed types: " . implode(', ', $allowed_types);
    }
    
    if (!empty($errors)) {
        return [false, $errors];
    }
    
    // Generate unique filename
    $new_filename = uniqid() . '_' . bin2hex(random_bytes(8)) . '.' . $file_ext;
    $target_path = $target_dir . $new_filename;
    
    // Move uploaded file
    if (move_uploaded_file($file['tmp_name'], $target_path)) {
        return [true, $new_filename];
    } else {
        $errors[] = "Failed to move uploaded file";
        return [false, $errors];
    }
}

function deleteFile($file_path) {
    if (file_exists($file_path)) {
        return unlink($file_path);
    }
    return false;
}

function getAssignmentStatus($due_date, $submission_date, $is_graded) {
    $now = time();
    $due = strtotime($due_date);
    $submitted = $submission_date ? strtotime($submission_date) : null;
    
    if ($is_graded) {
        return 'graded';
    } elseif ($submitted) {
        return $submitted > $due ? 'late' : 'submitted';
    } else {
        return $now > $due ? 'missed' : 'pending';
    }
}
/**
 * Sanitizes a filename by removing special characters
 * @param string $filename The original filename
 * @return string The sanitized filename
 */
function sanitizeFilename($filename) {
    // Remove any path information
    $filename = basename($filename);
    // Replace spaces with underscores
    $filename = str_replace(' ', '_', $filename);
    // Remove any non-alphanumeric, dash, underscore, or dot characters
    $filename = preg_replace('/[^a-zA-Z0-9\-_\.]/', '', $filename);
    // Remove any runs of periods
    $filename = preg_replace('/\.+/', '.', $filename);
    return $filename;
}

/**
 * Returns a human-readable time difference (e.g., "2 days ago")
 * @param int $from Timestamp
 * @param int $to Timestamp (defaults to now)
 * @return string Human-readable time difference
 */
function human_time_diff($from, $to = null) {
    if ($to === null) {
        $to = time();
    }
    
    $diff = abs($to - $from);
    
    if ($diff < 60) {
        return $diff . ' second' . ($diff !== 1 ? 's' : '');
    }
    
    $mins = round($diff / 60);
    if ($mins < 60) {
        return $mins . ' minute' . ($mins !== 1 ? 's' : '');
    }
    
    $hours = round($diff / 3600);
    if ($hours < 24) {
        return $hours . ' hour' . ($hours !== 1 ? 's' : '');
    }
    
    $days = round($diff / 86400);
    if ($days < 7) {
        return $days . ' day' . ($days !== 1 ? 's' : '');
    }
    
    $weeks = round($diff / 604800);
    if ($weeks < 4) {
        return $weeks . ' week' . ($weeks !== 1 ? 's' : '');
    }
    
    $months = round($diff / 2592000);
    if ($months < 12) {
        return $months . ' month' . ($months !== 1 ? 's' : '');
    }
    
    $years = round($diff / 31536000);
    return $years . ' year' . ($years !== 1 ? 's' : '');
}
function get_user_name($user_id) {
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("SELECT CONCAT(first_name, ' ', last_name) as full_name FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $db->closeConnection();
    
    return $result ? $result['full_name'] : 'Unknown';
}

function displaySessionMessages() {
    if (isset($_SESSION['success'])) {
        echo '<div class="alert alert-success">' . htmlspecialchars($_SESSION['success']) . '</div>';
        unset($_SESSION['success']);
    }
    
    if (isset($_SESSION['error'])) {
        echo '<div class="alert alert-error">' . htmlspecialchars($_SESSION['error']) . '</div>';
        unset($_SESSION['error']);
    }
    
    if (isset($_SESSION['warning'])) {
        echo '<div class="alert alert-warning">' . htmlspecialchars($_SESSION['warning']) . '</div>';
        unset($_SESSION['warning']);
    }
    
    if (isset($_SESSION['info'])) {
        echo '<div class="alert alert-info">' . htmlspecialchars($_SESSION['info']) . '</div>';
        unset($_SESSION['info']);
    }
}


/* Session timeout modal + compatibility helpers */
if (!function_exists('destroyCurrentSession')) {
    function destroyCurrentSession() {
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
}

if (!function_exists('e')) {
    function e($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('ucpUpper')) {
    function ucpUpper($value) {
        return strtoupper(trim((string)$value));
    }
}

if (!function_exists('ucpDisplayUpper')) {
    function ucpDisplayUpper($value) {
        return e(ucpUpper($value));
    }
}

if (!function_exists('sanitizeUserId')) {
    function sanitizeUserId($value) {
        $value = trim((string)$value);
        $value = preg_replace('/[^A-Za-z0-9_]/', '', $value);
        return strtolower($value);
    }
}

if (!function_exists('sanitizeEmailAddress')) {
    function sanitizeEmailAddress($email) {
        return strtolower(trim((string)$email));
    }
}

if (!function_exists('sanitizeMatricNumber')) {
    function sanitizeMatricNumber($idNumber) {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$idNumber));
    }
}

if (!function_exists('validateUserId')) {
    function validateUserId($value) {
        return preg_match('/^[a-z0-9_]{5,20}$/', strtolower(trim((string)$value))) === 1;
    }
}

if (!function_exists('validateNameField')) {
    function validateNameField($value) {
        $value = trim((string)$value);
        return $value !== '' && preg_match("/^[A-Za-z][A-Za-z\\s'\\-.]{1,58}$/", $value) === 1;
    }
}

if (!function_exists('validateMatricNumber')) {
    function validateMatricNumber($idNumber) {
        return preg_match('/^[A-Z]{2}\d{6}$/', strtoupper(trim((string)$idNumber))) === 1;
    }
}

if (!function_exists('isEnrollmentYearAllowed')) {
    function isEnrollmentYearAllowed($idNumber) {
        $idNumber = strtoupper(trim((string)$idNumber));
        if (!validateMatricNumber($idNumber)) {
            return false;
        }
        $year = (int)('20' . substr($idNumber, 2, 2));
        return $year > 2020;
    }
}

if (!function_exists('getPasswordValidationErrors')) {
    function getPasswordValidationErrors($password) {
        $password = (string)$password;
        $errors = [];
        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }
        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must contain at least one uppercase letter.';
        }
        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must contain at least one lowercase letter.';
        }
        if (!preg_match('/\d/', $password)) {
            $errors[] = 'Password must contain at least one number.';
        }
        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = 'Password must contain at least one special character.';
        }
        return $errors;
    }
}

if (!function_exists('logAuditEvent')) {
    function logAuditEvent($userId, $eventType, $description = '', $metadata = []) {
        try {
            $db = new Database();
            $conn = $db->getConnection();
            $username = $metadata['username'] ?? ($_SESSION['username'] ?? null);
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $details = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $uid = $userId !== null ? (int)$userId : null;

            $stmt = $conn->prepare("INSERT INTO audit_logs (user_id, username, event_type, description, ip_address, user_agent, details, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
            if ($stmt) {
                $stmt->bind_param('issssss', $uid, $username, $eventType, $description, $ipAddress, $userAgent, $details);
                $stmt->execute();
                $stmt->close();
            }
            $db->closeConnection();
        } catch (Throwable $e) {
            error_log('Audit log failed: ' . $e->getMessage());
        }
    }
}

/* CAPTCHA + login lockout compatibility helpers */
if (!defined('RECAPTCHA_VERIFY_URL')) {
    define('RECAPTCHA_VERIFY_URL', 'https://www.google.com/recaptcha/api/siteverify');
}

if (!function_exists('isRecaptchaConfigured')) {
    function isRecaptchaConfigured() {
        if (!defined('RECAPTCHA_SITE_KEY') || !defined('RECAPTCHA_SECRET_KEY')) {
            return false;
        }
        $siteKey = trim((string)RECAPTCHA_SITE_KEY);
        $secretKey = trim((string)RECAPTCHA_SECRET_KEY);
        return $siteKey !== ''
            && $secretKey !== ''
            && $siteKey !== 'PASTE_YOUR_RECAPTCHA_SITE_KEY_HERE'
            && $secretKey !== 'PASTE_YOUR_RECAPTCHA_SECRET_KEY_HERE'
            && $siteKey !== 'your_site_key'
            && $secretKey !== 'your_secret_key';
    }
}

if (!function_exists('verifyGoogleRecaptcha')) {
    function verifyGoogleRecaptcha($recaptchaResponse, $remoteIp = null) {
        if (!isRecaptchaConfigured()) {
            return ['success' => false, 'message' => 'CAPTCHA service is not configured.'];
        }

        $recaptchaResponse = trim((string)$recaptchaResponse);
        if ($recaptchaResponse === '') {
            return ['success' => false, 'message' => 'Please complete the CAPTCHA challenge.'];
        }

        $postData = [
            'secret' => RECAPTCHA_SECRET_KEY,
            'response' => $recaptchaResponse
        ];

        if (!empty($remoteIp)) {
            $postData['remoteip'] = $remoteIp;
        }

        $encodedPostData = http_build_query($postData, '', '&');
        $rawResponse = false;

        if (function_exists('curl_init')) {
            $ch = curl_init(RECAPTCHA_VERIFY_URL);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $encodedPostData,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']
            ]);
            $rawResponse = curl_exec($ch);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($rawResponse === false) {
                return ['success' => false, 'message' => 'CAPTCHA verification request failed: ' . $curlError];
            }
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                    'content' => $encodedPostData,
                    'timeout' => 10
                ]
            ]);
            $rawResponse = @file_get_contents(RECAPTCHA_VERIFY_URL, false, $context);
            if ($rawResponse === false) {
                return ['success' => false, 'message' => 'CAPTCHA verification request failed.'];
            }
        }

        $decoded = json_decode($rawResponse, true);
        if (!is_array($decoded)) {
            return ['success' => false, 'message' => 'Invalid CAPTCHA verification response.'];
        }

        if (!empty($decoded['success'])) {
            return ['success' => true, 'message' => 'CAPTCHA verified.'];
        }

        return [
            'success' => false,
            'message' => 'CAPTCHA verification failed.',
            'error_codes' => $decoded['error-codes'] ?? []
        ];
    }
}

if (!function_exists('getLoginMaxFailedAttempts')) {
    function getLoginMaxFailedAttempts() {
        return defined('LOGIN_MAX_FAILED_ATTEMPTS') ? max(1, (int)LOGIN_MAX_FAILED_ATTEMPTS) : 5;
    }
}

if (!function_exists('getLoginLockoutSeconds')) {
    function getLoginLockoutSeconds() {
        return defined('LOGIN_LOCKOUT_SECONDS') ? max(60, (int)LOGIN_LOCKOUT_SECONDS) : 300;
    }
}

if (!function_exists('getClientIpAddress')) {
    function getClientIpAddress() {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                $value = explode(',', (string)$_SERVER[$key])[0];
                return trim($value);
            }
        }
        return '0.0.0.0';
    }
}

if (!function_exists('getLoginClientKeyHash')) {
    function getLoginClientKeyHash() {
        $cookieName = defined('LOGIN_CLIENT_COOKIE_NAME') ? LOGIN_CLIENT_COOKIE_NAME : 'ucp_login_client';
        $cookieValue = $_COOKIE[$cookieName] ?? '';

        if (!preg_match('/^[a-f0-9]{32,128}$/', (string)$cookieValue)) {
            $cookieValue = bin2hex(random_bytes(32));
            $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
            setcookie($cookieName, $cookieValue, [
                'expires' => time() + 60 * 60 * 24 * 30,
                'path' => '/',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            $_COOKIE[$cookieName] = $cookieValue;
        }

        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        $ipAddress = getClientIpAddress();
        $pepper = defined('LOGIN_CLIENT_LOCK_PEPPER') ? LOGIN_CLIENT_LOCK_PEPPER : 'uthm-secure-course-portal-client-lock';
        return hash('sha256', $ipAddress . '|' . $userAgent . '|' . $cookieValue . '|' . $pepper);
    }
}

if (!function_exists('getLoginClientLockRemaining')) {
    function getLoginClientLockRemaining($conn, $clientKeyHash) {
        try {
            $stmt = $conn->prepare("SELECT locked_until FROM login_attempt_locks WHERE client_key_hash = ? LIMIT 1");
            if (!$stmt) {
                return 0;
            }
            $stmt->bind_param('s', $clientKeyHash);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$row || empty($row['locked_until'])) {
                return 0;
            }

            return max(0, (int)(strtotime((string)$row['locked_until']) - time()));
        } catch (Throwable $e) {
            error_log('Client lock remaining check failed: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('isLoginClientLocked')) {
    function isLoginClientLocked($conn, $clientKeyHash) {
        $remaining = getLoginClientLockRemaining($conn, $clientKeyHash);
        if ($remaining > 0) {
            return true;
        }

        try {
            $stmt = $conn->prepare("UPDATE login_attempt_locks SET failed_attempts = 0, locked_until = NULL WHERE client_key_hash = ? AND locked_until IS NOT NULL AND locked_until <= NOW()");
            if ($stmt) {
                $stmt->bind_param('s', $clientKeyHash);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Throwable $e) {
            error_log('Client lock reset failed: ' . $e->getMessage());
        }
        return false;
    }
}

if (!function_exists('registerLoginClientFailure')) {
    function registerLoginClientFailure($conn, $username = '') {
        $clientKeyHash = getLoginClientKeyHash();
        $ipAddress = getClientIpAddress();
        $userAgentHash = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? 'unknown');
        $maxAttempts = getLoginMaxFailedAttempts();
        $lockoutSeconds = getLoginLockoutSeconds();

        try {
            $stmt = $conn->prepare("INSERT INTO login_attempt_locks
                (client_key_hash, ip_address, user_agent_hash, failed_attempts, locked_until, last_attempt_at, created_at)
                VALUES (?, ?, ?, 1, NULL, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    ip_address = VALUES(ip_address),
                    user_agent_hash = VALUES(user_agent_hash),
                    failed_attempts = failed_attempts + 1,
                    locked_until = CASE
                        WHEN failed_attempts + 1 >= ? THEN DATE_ADD(NOW(), INTERVAL ? SECOND)
                        ELSE locked_until
                    END,
                    last_attempt_at = NOW()");
            if ($stmt) {
                $stmt->bind_param('sssii', $clientKeyHash, $ipAddress, $userAgentHash, $maxAttempts, $lockoutSeconds);
                $stmt->execute();
                $stmt->close();
            }

            $select = $conn->prepare("SELECT failed_attempts, locked_until FROM login_attempt_locks WHERE client_key_hash = ? LIMIT 1");
            if (!$select) {
                return ['failed_attempts' => 0, 'locked' => false];
            }
            $select->bind_param('s', $clientKeyHash);
            $select->execute();
            $row = $select->get_result()->fetch_assoc();
            $select->close();

            $locked = !empty($row['locked_until']) && strtotime((string)$row['locked_until']) > time();
            return [
                'failed_attempts' => (int)($row['failed_attempts'] ?? 0),
                'locked' => $locked,
                'locked_until' => $row['locked_until'] ?? null
            ];
        } catch (Throwable $e) {
            error_log('Register client failure failed: ' . $e->getMessage());
            return ['failed_attempts' => 0, 'locked' => false];
        }
    }
}

if (!function_exists('clearLoginClientFailures')) {
    function clearLoginClientFailures($conn, $clientKeyHash = null) {
        try {
            $clientKeyHash = $clientKeyHash ?: getLoginClientKeyHash();
            $stmt = $conn->prepare("DELETE FROM login_attempt_locks WHERE client_key_hash = ?");
            if ($stmt) {
                $stmt->bind_param('s', $clientKeyHash);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Throwable $e) {
            error_log('Clear client failures failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('registerSessionLoginFailure')) {
    function registerSessionLoginFailure() {
        $_SESSION['login_failed_attempts'] = (int)($_SESSION['login_failed_attempts'] ?? 0) + 1;
        if ($_SESSION['login_failed_attempts'] >= getLoginMaxFailedAttempts()) {
            $_SESSION['login_locked_until'] = time() + getLoginLockoutSeconds();
        }
    }
}

if (!function_exists('isLoginSessionLocked')) {
    function isLoginSessionLocked() {
        $lockedUntil = (int)($_SESSION['login_locked_until'] ?? 0);
        if ($lockedUntil > time()) {
            return true;
        }
        if ($lockedUntil > 0 && $lockedUntil <= time()) {
            unset($_SESSION['login_locked_until'], $_SESSION['login_failed_attempts']);
        }
        return false;
    }
}

if (!function_exists('getLoginSessionLockRemaining')) {
    function getLoginSessionLockRemaining() {
        return max(0, (int)(($_SESSION['login_locked_until'] ?? 0) - time()));
    }
}

if (!function_exists('clearLoginSessionFailures')) {
    function clearLoginSessionFailures() {
        unset($_SESSION['login_failed_attempts'], $_SESSION['login_locked_until']);
    }
}

/* Phase 8L admin compatibility helpers: used by Manage Users stable page. */
if (!function_exists('getCsrfToken')) {
    function getCsrfToken() {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrfInput')) {
    function csrfInput() {
        return '<input type="hidden" name="csrf_token" value="' . e(getCsrfToken()) . '">';
    }
}

if (!function_exists('validateCsrfToken')) {
    function validateCsrfToken($token) {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        return is_string($token)
            && isset($_SESSION['csrf_token'])
            && hash_equals((string)$_SESSION['csrf_token'], (string)$token);
    }
}

if (!function_exists('requireValidCsrfToken')) {
    function requireValidCsrfToken($token) {
        if (!validateCsrfToken($token)) {
            throw new Exception('Invalid security token. Please refresh the page and try again.');
        }
    }
}

if (!function_exists('buildQueryStringWithCsrf')) {
    function buildQueryStringWithCsrf(array $params) {
        $params['csrf_token'] = getCsrfToken();
        return http_build_query($params);
    }
}

if (!function_exists('requireAdminAccess')) {
    function requireAdminAccess() {
        if (!isLoggedIn() || !isAdmin()) {
            redirect('/pages/auth/login.php');
        }
    }
}

if (!function_exists('sanitizePersonName')) {
    function sanitizePersonName($value) {
        $value = trim((string)$value);
        $value = preg_replace('/\s+/', ' ', $value);
        return ucpUpper($value);
    }
}

if (!function_exists('isValidPersonName')) {
    function isValidPersonName($value) {
        $value = trim((string)$value);
        return $value !== '' && preg_match('/^[A-Za-z][A-Za-z\s\'\-.]{1,58}$/', $value) === 1;
    }
}

if (!function_exists('normalizeUppercaseText')) {
    function normalizeUppercaseText($value, $maxLength = 255) {
        $value = trim((string)$value);
        $value = preg_replace('/\s+/', ' ', $value);
        if ($maxLength > 0) {
            $value = substr($value, 0, (int)$maxLength);
        }
        return $value === '' ? '' : ucpUpper($value);
    }
}

if (!function_exists('sanitizePhoneNumber')) {
    function sanitizePhoneNumber($value) {
        return trim(preg_replace('/[^0-9+\-\s]/', '', (string)$value));
    }
}

if (!function_exists('isValidPhoneNumber')) {
    function isValidPhoneNumber($value) {
        $value = trim((string)$value);
        return $value === '' || preg_match('/^[0-9+\-\s]{10,20}$/', $value) === 1;
    }
}

if (!function_exists('sanitizeUserRole')) {
    function sanitizeUserRole($value) {
        $value = strtolower(trim((string)$value));
        $allowed = [ROLE_STUDENT, ROLE_LECTURER, ROLE_ADMIN];
        return in_array($value, $allowed, true) ? $value : '';
    }
}

if (!function_exists('isValidUserId')) {
    function isValidUserId($value) {
        if (function_exists('validateUserId')) {
            return validateUserId($value);
        }
        return is_string($value) && preg_match('/^[a-z0-9_]{5,20}$/', strtolower(trim($value))) === 1;
    }
}

if (!function_exists('generateSecureDefaultPassword')) {
    function generateSecureDefaultPassword($role = '') {
        $prefix = strtoupper(substr(preg_replace('/[^a-z]/i', '', (string)$role), 0, 3));
        if ($prefix === '') {
            $prefix = 'UCP';
        }
        return $prefix . '-' . bin2hex(random_bytes(3)) . 'Aa!';
    }
}

