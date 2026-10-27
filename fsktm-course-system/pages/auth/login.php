<?php
require_once '../../config/config.php';
require_once '../../includes/functions.php';

if (isLoggedIn()) {
    redirect('/pages/profile.php');
}

$error = '';
$notice = '';
$sessionExpiredMessage = '';

if (!empty($_SESSION['session_expired_modal'])) {
    $sessionExpiredMessage = (string)$_SESSION['session_expired_modal'];
    unset($_SESSION['session_expired_modal']);
}

if (isset($_GET['session_expired']) && $_GET['session_expired'] === '1' && $sessionExpiredMessage === '') {
    $sessionExpiredMessage = 'Your session has expired due to inactivity. Please login again.';
}

if ($sessionExpiredMessage !== '' && isset($_SESSION['warning']) && $_SESSION['warning'] === $sessionExpiredMessage) {
    unset($_SESSION['warning']);
}

if (!empty($_SESSION['warning'])) {
    $notice = (string)$_SESSION['warning'];
    unset($_SESSION['warning']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitizeUserId($_POST['username'] ?? '');
    $password = trim((string)($_POST['password'] ?? ''));
    $recaptchaResponse = $_POST['g-recaptcha-response'] ?? '';
    $genericError = 'Invalid User ID or Password.';

    $db = new Database();
    $conn = $db->getConnection();
    $clientKeyHash = getLoginClientKeyHash();

    if (isLoginClientLocked($conn, $clientKeyHash)) {
        $remaining = max(1, (int)ceil(getLoginClientLockRemaining($conn, $clientKeyHash) / 60));
        $error = 'Too many failed login attempts from this browser. Please try again in approximately ' . $remaining . ' minute(s).';

        logAuditEvent(null, 'login_blocked_client_lockout', 'Login blocked by browser/client database lockout.', [
            'username' => $username,
            'client_key_hash_prefix' => substr($clientKeyHash, 0, 12),
            'remaining_seconds' => getLoginClientLockRemaining($conn, $clientKeyHash)
        ]);
    } elseif (isLoginSessionLocked()) {
        $remaining = max(1, (int)ceil(getLoginSessionLockRemaining() / 60));
        $error = 'Too many failed login attempts. Please try again in approximately ' . $remaining . ' minute(s).';

        logAuditEvent(null, 'login_blocked_session_lockout', 'Login blocked by session-based rate limiting.', [
            'username' => $username,
            'remaining_seconds' => getLoginSessionLockRemaining()
        ]);
    } else {
        $captcha = verifyGoogleRecaptcha($recaptchaResponse, $_SERVER['REMOTE_ADDR'] ?? null);

        if (!$captcha['success']) {
            $error = 'Please complete the CAPTCHA verification before logging in.';

            logAuditEvent(null, 'login_captcha_failed', 'Login rejected because CAPTCHA verification failed.', [
                'username' => $username,
                'captcha_message' => $captcha['message'] ?? 'CAPTCHA failed'
            ]);
        } else {
            $stmt = $conn->prepare("SELECT user_id, username, email, password, first_name, last_name, user_type, profile_picture, is_active, failed_login_attempts, locked_until FROM users WHERE username = ? LIMIT 1");
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $accountLocked = false;
            if ($user && !empty($user['locked_until'])) {
                $lockedUntil = strtotime((string)$user['locked_until']);
                $accountLocked = $lockedUntil !== false && $lockedUntil > time();
            }

            if ($accountLocked) {
                $error = 'Too many failed login attempts. Please try again later.';

                logAuditEvent((int)$user['user_id'], 'login_blocked_account_lockout', 'Login blocked because account is temporarily locked.', [
                    'username' => $username,
                    'locked_until' => $user['locked_until']
                ]);
            } elseif ($user && (int)$user['is_active'] === 1 && password_verify($password, $user['password'])) {
                session_regenerate_id(true);
                clearLoginSessionFailures();
                clearLoginClientFailures($conn, $clientKeyHash);

                $_SESSION['user_id'] = (int)$user['user_id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['user_type'] = $user['user_type'];
                $_SESSION['first_name'] = $user['first_name'];
                $_SESSION['last_name'] = $user['last_name'];
                $_SESSION['profile_picture'] = $user['profile_picture'] ?? 'default-avatar.png';
                $_SESSION['last_activity'] = time();

                $update = $conn->prepare("UPDATE users SET last_login = NOW(), failed_login_attempts = 0, locked_until = NULL WHERE user_id = ?");
                $update->bind_param('i', $user['user_id']);
                $update->execute();
                $update->close();

                logAuditEvent((int)$user['user_id'], 'login_success', 'User logged in successfully.', [
                    'username' => $user['username'],
                    'user_type' => $user['user_type']
                ]);

                $db->closeConnection();
                redirect('/pages/profile.php');
            } else {
                registerSessionLoginFailure();
                $clientAttempt = registerLoginClientFailure($conn, $username);

                if ($user) {
                    $attempts = ((int)($user['failed_login_attempts'] ?? 0)) + 1;

                    if ($attempts >= getLoginMaxFailedAttempts()) {
                        $lockoutSeconds = getLoginLockoutSeconds();
                        $update = $conn->prepare("UPDATE users SET failed_login_attempts = ?, locked_until = DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE user_id = ?");
                        $update->bind_param('iii', $attempts, $lockoutSeconds, $user['user_id']);
                        $update->execute();
                        $update->close();
                        $error = 'Too many failed login attempts. Please try again later.';
                    } else {
                        $update = $conn->prepare("UPDATE users SET failed_login_attempts = ? WHERE user_id = ?");
                        $update->bind_param('ii', $attempts, $user['user_id']);
                        $update->execute();
                        $update->close();
                        $error = $genericError;
                    }
                } else {
                    $error = (isLoginSessionLocked() || !empty($clientAttempt['locked']))
                        ? 'Too many failed login attempts. Please try again later.'
                        : $genericError;
                }

                logAuditEvent($user['user_id'] ?? null, 'login_failed', 'Login attempt failed.', [
                    'username' => $username,
                    'reason' => 'invalid_credentials_or_inactive_account',
                    'session_failed_attempts' => (int)($_SESSION['login_failed_attempts'] ?? 0),
                    'client_failed_attempts' => (int)($clientAttempt['failed_attempts'] ?? 0),
                    'client_locked' => !empty($clientAttempt['locked'])
                ]);
            }
        }
    }

    $db->closeConnection();
}

$pageTitle = 'Login';
include '../../includes/header.php';
?>

<div class="auth-container">
    <h1>Login to UTHM Secure Course Portal</h1>

    <?php if ($notice): ?>
        <div class="alert alert-warning"><?php echo e($notice); ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo e($error); ?></div>
    <?php endif; ?>

    <form action="<?php echo e($_SERVER['PHP_SELF']); ?>" method="post">
        <div class="form-group">
            <label for="username">User ID</label>
            <input type="text" id="username" name="username" required autocomplete="username">
        </div>

        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required autocomplete="current-password">
        </div>

        <div class="form-group">
            <label>CAPTCHA Verification</label>
            <?php if (isRecaptchaConfigured()): ?>
                <div class="g-recaptcha" data-sitekey="<?php echo e(RECAPTCHA_SITE_KEY); ?>"></div>
                <small class="form-text">Complete the Google reCAPTCHA challenge before logging in.</small>
            <?php else: ?>
                <div class="alert alert-error">CAPTCHA service is not configured. Paste your Google reCAPTCHA site key and secret key in config/config.php.</div>
            <?php endif; ?>
        </div>

        <button type="submit" class="btn btn-primary">Login</button>
    </form>

    <div class="auth-links">
        <p>Don't have an account? <a href="<?php echo e(BASE_URL); ?>/pages/auth/register.php">Register here</a></p>
        <p><a href="#">Forgot password?</a></p>
    </div>
</div>

<?php if (isRecaptchaConfigured()): ?>
<script src="https://www.google.com/recaptcha/api.js" async defer></script>
<?php endif; ?>

<?php if ($sessionExpiredMessage !== ''): ?>
<div class="session-timeout-modal-backdrop" id="sessionTimeoutModal" role="dialog" aria-modal="true" aria-labelledby="sessionTimeoutTitle">
    <div class="session-timeout-modal-box">
        <div class="session-timeout-modal-icon">!</div>
        <h2 id="sessionTimeoutTitle">Session Expired</h2>
        <p><?php echo e($sessionExpiredMessage); ?></p>
        <button type="button" class="btn btn-primary" id="sessionTimeoutCloseBtn">OK</button>
    </div>
</div>

<style>
.session-timeout-modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.55);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
}
.session-timeout-modal-box {
    width: min(420px, calc(100% - 32px));
    background: #ffffff;
    border-radius: 16px;
    padding: 28px 30px;
    text-align: center;
    box-shadow: 0 22px 55px rgba(15, 23, 42, 0.32);
    border: 1px solid #e5e7eb;
}
.session-timeout-modal-icon {
    width: 54px;
    height: 54px;
    margin: 0 auto 14px;
    border-radius: 50%;
    background: #fff3cd;
    border: 1px solid #ffda6a;
    color: #9a6700;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 30px;
    font-weight: 800;
}
.session-timeout-modal-box h2 {
    margin: 0 0 10px;
    font-size: 24px;
    color: #1f2937;
}
.session-timeout-modal-box p {
    margin: 0 0 22px;
    color: #4b5563;
    line-height: 1.5;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('sessionTimeoutModal');
    const closeBtn = document.getElementById('sessionTimeoutCloseBtn');
    if (modal && closeBtn) {
        closeBtn.focus();
        closeBtn.addEventListener('click', function () {
            modal.style.display = 'none';
        });
    }
});
</script>
<?php endif; ?>

<?php include '../../includes/footer.php'; ?>
