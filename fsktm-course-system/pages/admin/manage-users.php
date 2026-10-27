<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) {
    redirect('/pages/auth/login.php');
}

/* Self-contained helpers for Manage Users.
   This page does not depend on old admin.js or user-api.php, so mixed patch versions will not break it. */
if (!function_exists('ucp_e')) {
    function ucp_e($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('ucpUpper')) {
    function ucpUpper($value) {
        $value = trim((string)$value);
        if ($value === '') return '';
        return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
    }
}

if (!function_exists('ucpDisplayUpper')) {
    function ucpDisplayUpper($value) {
        $value = trim((string)$value);
        return $value === '' ? '-' : ucpUpper($value);
    }
}

function ucp_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function ucp_require_csrf($token) {
    if (empty($token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$token)) {
        throw new Exception('Invalid security token. Please refresh the page and try again.');
    }
}

function ucp_query_csrf(array $params = []) {
    $params['csrf_token'] = ucp_csrf_token();
    return http_build_query($params);
}

function ucp_redirect_manage_users() {
    header('Location: manage-users.php');
    exit();
}

function ucp_set_notice($type, $title, $message, $temporaryPassword = '') {
    $_SESSION['ucp_admin_notice'] = [
        'type' => $type,
        'title' => $title,
        'message' => $message,
        'temporary_password' => $temporaryPassword
    ];
}

function ucp_clean_user_id($value) {
    return strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', trim((string)$value)));
}

function ucp_clean_email($value) {
    return strtolower(trim((string)$value));
}

function ucp_clean_name($value) {
    $value = preg_replace('/\s+/', ' ', trim((string)$value));
    return ucpUpper($value);
}

function ucp_clean_text_upper($value, $max = 255) {
    $value = preg_replace('/\s+/', ' ', trim((string)$value));
    if ($max > 0) {
        $value = substr($value, 0, $max);
    }
    return $value === '' ? '' : ucpUpper($value);
}

function ucp_clean_phone($value) {
    return trim(preg_replace('/[^0-9+\-\s]/', '', (string)$value));
}

function ucp_clean_identity($value) {
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim((string)$value)));
}

function ucp_validate_user_input($data, $isUpdate = false) {
    $errors = [];

    if (!preg_match('/^[a-z0-9_]{5,20}$/', $data['username'])) {
        $errors[] = 'User ID must be 5–20 characters and contain letters, numbers, or underscore only.';
    }

    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email format.';
    }

    if (!preg_match('/^[A-Z][A-Z\s\'\-.]{1,58}$/', $data['first_name'])) {
        $errors[] = 'First name must contain letters only.';
    }

    if (!preg_match('/^[A-Z][A-Z\s\'\-.]{1,58}$/', $data['last_name'])) {
        $errors[] = 'Last name must contain letters only.';
    }

    $allowedRoles = ['student', 'lecturer', 'admin'];
    if (!in_array($data['user_type'], $allowedRoles, true)) {
        $errors[] = 'Invalid user role.';
    }

    if ($data['phone'] !== '' && !preg_match('/^[0-9+\-\s]{10,20}$/', $data['phone'])) {
        $errors[] = 'Invalid phone number format.';
    }

    if ($data['date_of_birth'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['date_of_birth'])) {
        $errors[] = 'Invalid date of birth format.';
    }

    if ($data['user_type'] === 'student') {
        if (!preg_match('/^[A-Z]{2}\d{6}$/', $data['identity_no'])) {
            $errors[] = 'Student matric number must use two letters followed by six digits. Example: AI230159.';
        } else {
            $year = (int)substr($data['identity_no'], 2, 2);
            if ($year <= 20) {
                $errors[] = 'Registration denied. Users enrolled in 2020 or earlier are not allowed to register.';
            }
        }
    } else {
        if ($data['identity_no'] === '') {
            $errors[] = 'Staff ID is required for lecturer/admin accounts.';
        }
        if ($data['identity_no'] !== '' && !preg_match('/^[A-Z0-9_\-]{3,20}$/', $data['identity_no'])) {
            $errors[] = 'Staff ID must contain 3–20 letters, numbers, dash, or underscore only.';
        }
    }

    return $errors;
}

function ucp_table_columns($conn, $table) {
    $cols = [];
    $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $result = $conn->query("SHOW COLUMNS FROM `$safeTable`");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $cols[$row['Field']] = true;
        }
    }
    return $cols;
}

function ucp_bind_params($stmt, $types, array $values) {
    if ($types === '') return true;
    $refs = [];
    foreach ($values as $key => $value) {
        $refs[$key] = $values[$key];
    }
    $bind = [$types];
    foreach ($refs as $key => $value) {
        $bind[] = &$refs[$key];
    }
    return call_user_func_array([$stmt, 'bind_param'], $bind);
}

function ucp_generate_password($role = 'user') {
    $prefix = strtoupper(substr(preg_replace('/[^a-z]/i', '', $role), 0, 3));
    if ($prefix === '') $prefix = 'UCP';
    return $prefix . '-' . bin2hex(random_bytes(3)) . 'Aa!';
}

function ucp_collect_user_form() {
    $role = strtolower(trim($_POST['user_type'] ?? 'student'));
    return [
        'username' => ucp_clean_user_id($_POST['username'] ?? ''),
        'email' => ucp_clean_email($_POST['email'] ?? ''),
        'first_name' => ucp_clean_name($_POST['first_name'] ?? ''),
        'last_name' => ucp_clean_name($_POST['last_name'] ?? ''),
        'user_type' => in_array($role, ['student', 'lecturer', 'admin'], true) ? $role : 'student',
        'identity_no' => ucp_clean_identity($_POST['identity_no'] ?? ''),
        'phone' => ucp_clean_phone($_POST['phone'] ?? ''),
        'date_of_birth' => trim($_POST['date_of_birth'] ?? ''),
        'department' => ucp_clean_text_upper($_POST['department'] ?? '', 100),
        'address' => ucp_clean_text_upper($_POST['address'] ?? '', 500),
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
    ];
}

function ucp_check_duplicate_user($conn, $username, $email, $excludeUserId = 0) {
    $sql = 'SELECT user_id FROM users WHERE (username = ? OR email = ?)';
    $types = 'ss';
    $params = [$username, $email];
    if ($excludeUserId > 0) {
        $sql .= ' AND user_id <> ?';
        $types .= 'i';
        $params[] = $excludeUserId;
    }
    $sql .= ' LIMIT 1';

    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception('Database error while checking duplicate user.');
    ucp_bind_params($stmt, $types, $params);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    if ($exists) {
        throw new Exception('User ID or email already exists.');
    }
}

function ucp_user_identity_value(array $user) {
    if (!empty($user['id_number'])) return $user['id_number'];
    if (($user['user_type'] ?? '') === 'student') return $user['student_id'] ?? '';
    return $user['staff_id'] ?? '';
}

function ucp_insert_user($conn, array $data, array $columns) {
    ucp_check_duplicate_user($conn, $data['username'], $data['email']);

    $password = ucp_generate_password($data['user_type']);
    $hash = password_hash($password, PASSWORD_DEFAULT);

    $insert = [
        'username' => $data['username'],
        'email' => $data['email'],
        'password' => $hash,
        'first_name' => $data['first_name'],
        'last_name' => $data['last_name'],
        'user_type' => $data['user_type'],
        'phone' => $data['phone'] ?: null,
        'date_of_birth' => $data['date_of_birth'] ?: null,
        'address' => $data['address'] ?: null,
        'department' => $data['department'] ?: null,
        'is_active' => (int)$data['is_active'],
    ];

    if (isset($columns['profile_picture'])) {
        $insert['profile_picture'] = 'default-avatar.png';
    }

    if (isset($columns['id_number'])) {
        $insert['id_number'] = $data['identity_no'];
    }
    if (isset($columns['student_id'])) {
        $insert['student_id'] = $data['user_type'] === 'student' ? $data['identity_no'] : null;
    }
    if (isset($columns['staff_id'])) {
        $insert['staff_id'] = $data['user_type'] !== 'student' ? $data['identity_no'] : null;
    }

    $insert = array_filter($insert, function($v, $k) use ($columns) {
        return isset($columns[$k]);
    }, ARRAY_FILTER_USE_BOTH);

    $fields = array_keys($insert);
    $placeholders = implode(', ', array_fill(0, count($fields), '?'));
    $sql = 'INSERT INTO users (`' . implode('`, `', $fields) . '`) VALUES (' . $placeholders . ')';
    $values = array_values($insert);
    $types = str_repeat('s', count($values));

    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception('Failed to prepare add user query: ' . $conn->error);
    ucp_bind_params($stmt, $types, $values);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new Exception('Failed to add user: ' . $error);
    }
    $stmt->close();

    return $password;
}

function ucp_update_user($conn, $userId, array $data, array $columns) {
    if ($userId <= 0) throw new Exception('Invalid user selected.');
    ucp_check_duplicate_user($conn, $data['username'], $data['email'], $userId);

    $update = [
        'username' => $data['username'],
        'email' => $data['email'],
        'first_name' => $data['first_name'],
        'last_name' => $data['last_name'],
        'user_type' => $data['user_type'],
        'phone' => $data['phone'] ?: null,
        'date_of_birth' => $data['date_of_birth'] ?: null,
        'address' => $data['address'] ?: null,
        'department' => $data['department'] ?: null,
        'is_active' => (int)$data['is_active'],
    ];

    if (isset($columns['id_number'])) {
        $update['id_number'] = $data['identity_no'];
    }
    if (isset($columns['student_id'])) {
        $update['student_id'] = $data['user_type'] === 'student' ? $data['identity_no'] : null;
    }
    if (isset($columns['staff_id'])) {
        $update['staff_id'] = $data['user_type'] !== 'student' ? $data['identity_no'] : null;
    }

    $update = array_filter($update, function($v, $k) use ($columns) {
        return isset($columns[$k]);
    }, ARRAY_FILTER_USE_BOTH);

    $sets = [];
    foreach (array_keys($update) as $field) {
        $sets[] = "`$field` = ?";
    }
    $values = array_values($update);
    $types = str_repeat('s', count($values)) . 'i';
    $values[] = $userId;

    $sql = 'UPDATE users SET ' . implode(', ', $sets) . ' WHERE user_id = ?';
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception('Failed to prepare update user query: ' . $conn->error);
    ucp_bind_params($stmt, $types, $values);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new Exception('Failed to update user: ' . $error);
    }
    $stmt->close();
}

function ucp_reset_user_password($conn, $userId) {
    if ($userId <= 0) throw new Exception('Invalid user selected.');

    $stmt = $conn->prepare('SELECT user_type, username FROM users WHERE user_id = ? LIMIT 1');
    if (!$stmt) throw new Exception('Failed to prepare reset query.');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user) throw new Exception('User not found.');

    $password = ucp_generate_password($user['user_type']);
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare('UPDATE users SET password = ? WHERE user_id = ?');
    if (!$stmt) throw new Exception('Failed to prepare password update query.');
    $stmt->bind_param('si', $hash, $userId);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new Exception('Failed to reset password: ' . $error);
    }
    $stmt->close();

    return [$password, $user['username']];
}

function ucp_toggle_user_status($conn, $userId) {
    if ($userId <= 0) throw new Exception('Invalid user selected.');
    if ((int)$userId === (int)$_SESSION['user_id']) {
        throw new Exception('You cannot deactivate your own account.');
    }

    $stmt = $conn->prepare('UPDATE users SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END WHERE user_id = ?');
    if (!$stmt) throw new Exception('Failed to prepare status update query.');
    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new Exception('Failed to update status: ' . $error);
    }
    $stmt->close();
}

function ucp_delete_user($conn, $userId) {
    if ($userId <= 0) throw new Exception('Invalid user selected.');
    if ((int)$userId === (int)$_SESSION['user_id']) {
        throw new Exception('You cannot delete your own administrator account.');
    }

    $stmt = $conn->prepare('DELETE FROM users WHERE user_id = ?');
    if (!$stmt) throw new Exception('Failed to prepare delete query.');
    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) {
        $stmt->close();
        $fallback = $conn->prepare('UPDATE users SET is_active = 0 WHERE user_id = ?');
        if (!$fallback) throw new Exception('User has related records and could not be deactivated.');
        $fallback->bind_param('i', $userId);
        if (!$fallback->execute()) {
            $error = $fallback->error;
            $fallback->close();
            throw new Exception('User has related records and could not be deactivated: ' . $error);
        }
        $fallback->close();
        return 'User has related records, so the account was deactivated instead of deleted.';
    }
    $stmt->close();
    return 'User deleted successfully.';
}

$db = new Database();
$conn = $db->getConnection();
$columns = ucp_table_columns($conn, 'users');

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        ucp_require_csrf($_POST['csrf_token'] ?? '');
        $action = $_POST['action'] ?? '';

        if ($action === 'add' || $action === 'update') {
            $data = ucp_collect_user_form();
            $errors = ucp_validate_user_input($data, $action === 'update');
            if (!empty($errors)) {
                throw new Exception(implode(' ', $errors));
            }

            if ($action === 'add') {
                $tempPassword = ucp_insert_user($conn, $data, $columns);
                ucp_set_notice('success', 'User Added', 'The user account was created successfully. The temporary password is shown once only.', $tempPassword);
            } else {
                $userId = (int)($_POST['user_id'] ?? 0);
                ucp_update_user($conn, $userId, $data, $columns);
                ucp_set_notice('success', 'User Updated', 'The user account was updated successfully.');
            }
        } else {
            throw new Exception('Invalid action.');
        }

        ucp_redirect_manage_users();
    }

    if (isset($_GET['toggle_status'])) {
        ucp_require_csrf($_GET['csrf_token'] ?? '');
        ucp_toggle_user_status($conn, (int)$_GET['toggle_status']);
        ucp_set_notice('success', 'Status Updated', 'The user status was updated successfully.');
        ucp_redirect_manage_users();
    }

    if (isset($_GET['delete'])) {
        ucp_require_csrf($_GET['csrf_token'] ?? '');
        $message = ucp_delete_user($conn, (int)$_GET['delete']);
        ucp_set_notice('success', 'User Removed', $message, '');
        ucp_redirect_manage_users();
    }

    if (isset($_GET['reset_password'])) {
        ucp_require_csrf($_GET['csrf_token'] ?? '');
        [$tempPassword, $username] = ucp_reset_user_password($conn, (int)$_GET['reset_password']);
        ucp_set_notice('success', 'Password Reset', 'Temporary password generated for ' . $username . '. It is shown once only.', $tempPassword);
        ucp_redirect_manage_users();
    }
} catch (Exception $e) {
    ucp_set_notice('danger', 'Action Failed', $e->getMessage());
    ucp_redirect_manage_users();
}

$result = $conn->query('SELECT * FROM users ORDER BY user_type, first_name, last_name, username');
$users = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

$totalUsers = count($users);
$activeUsers = 0;
$studentUsers = 0;
$adminUsers = 0;
foreach ($users as $row) {
    if ((int)($row['is_active'] ?? 0) === 1) $activeUsers++;
    if (($row['user_type'] ?? '') === 'student') $studentUsers++;
    if (($row['user_type'] ?? '') === 'admin') $adminUsers++;
}

$notice = $_SESSION['ucp_admin_notice'] ?? null;
unset($_SESSION['ucp_admin_notice']);

$pageTitle = 'Manage Users';
include __DIR__ . '/../../includes/header.php';
?>

<section class="ucp-admin-page">
    <div class="ucp-page-hero">
        <div>
            <p class="ucp-eyebrow">Administration</p>
            <h1>User &amp; Permission Management</h1>
            <p class="ucp-muted">Manage student, lecturer, and administrator accounts with User ID validation, matric number validation, CAPTCHA-supported authentication, and 2020-or-earlier enrolment blocking.</p>
        </div>
        <button type="button" class="ucp-btn ucp-btn-primary" id="openAddUserBtn">
            <i class="fas fa-user-plus"></i> Add User
        </button>
    </div>

    <div class="ucp-stats-grid">
        <div class="ucp-stat-card"><div class="ucp-stat-icon"><i class="fas fa-users"></i></div><div><span>Total Users</span><strong><?= (int)$totalUsers ?></strong></div></div>
        <div class="ucp-stat-card"><div class="ucp-stat-icon"><i class="fas fa-user-check"></i></div><div><span>Active Accounts</span><strong><?= (int)$activeUsers ?></strong></div></div>
        <div class="ucp-stat-card"><div class="ucp-stat-icon"><i class="fas fa-graduation-cap"></i></div><div><span>Students</span><strong><?= (int)$studentUsers ?></strong></div></div>
        <div class="ucp-stat-card"><div class="ucp-stat-icon"><i class="fas fa-user-shield"></i></div><div><span>Administrators</span><strong><?= (int)$adminUsers ?></strong></div></div>
    </div>

    <div class="ucp-panel ucp-user-directory-panel">
        <div class="ucp-panel-toolbar">
            <div>
                <h2>User Directory</h2>
                <p class="ucp-muted">Search and filter registered users.</p>
            </div>
            <div class="ucp-table-tools">
                <div class="ucp-searchbox">
                    <i class="fas fa-search"></i>
                    <input type="search" id="userSearchInput" placeholder="Search name, user ID, email, matric...">
                </div>
                <div class="ucp-filter-popout-wrap">
                    <button type="button" class="ucp-filter-button" id="filterToggle"><i class="fas fa-sliders"></i> Filters <span id="activeFilterCount">0</span></button>
                    <div class="ucp-filter-popout" id="userFilterPopout" hidden>
                        <div class="ucp-filter-popout-header"><strong>Filter users</strong><button type="button" id="clearFiltersBtn">Clear</button></div>
                        <label for="roleFilter">Role</label>
                        <select id="roleFilter" class="ucp-filter-select"><option value="">All roles</option><option value="student">Student</option><option value="lecturer">Lecturer</option><option value="admin">Admin</option></select>
                        <label for="statusFilter">Status</label>
                        <select id="statusFilter" class="ucp-filter-select"><option value="">All status</option><option value="active">Active</option><option value="inactive">Inactive</option></select>
                        <button type="button" class="ucp-btn ucp-btn-primary ucp-filter-apply" id="applyFiltersBtn">Apply Filters</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="ucp-table-wrap">
            <table class="ucp-modern-table" id="usersTable">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>User ID</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Matric / Staff ID</th>
                        <th>Department</th>
                        <th>Status</th>
                        <th class="ucp-actions-col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $user): ?>
                    <?php
                        $fullName = ucpUpper(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')));
                        $identityNo = ucp_user_identity_value($user) ?: '-';
                        $department = !empty($user['department']) ? ucpUpper($user['department']) : '-';
                        $role = $user['user_type'] ?? 'student';
                        $status = (int)($user['is_active'] ?? 0) === 1 ? 'active' : 'inactive';
                        $jsonUser = [
                            'user_id' => (int)$user['user_id'],
                            'username' => $user['username'] ?? '',
                            'email' => $user['email'] ?? '',
                            'first_name' => $user['first_name'] ?? '',
                            'last_name' => $user['last_name'] ?? '',
                            'user_type' => $role,
                            'identity_no' => $identityNo === '-' ? '' : $identityNo,
                            'phone' => $user['phone'] ?? '',
                            'date_of_birth' => $user['date_of_birth'] ?? '',
                            'department' => $user['department'] ?? '',
                            'address' => $user['address'] ?? '',
                            'is_active' => (int)($user['is_active'] ?? 0),
                        ];
                    ?>
                    <tr data-search="<?= ucp_e(strtolower($fullName . ' ' . ($user['username'] ?? '') . ' ' . ($user['email'] ?? '') . ' ' . $identityNo . ' ' . $department)) ?>" data-role="<?= ucp_e($role) ?>" data-status="<?= ucp_e($status) ?>">
                        <td><div class="ucp-user-cell"><div class="ucp-avatar"><?= ucp_e(strtoupper(substr($user['first_name'] ?? 'U', 0, 1) . substr($user['last_name'] ?? 'U', 0, 1))) ?></div><div><a href="../profile.php?id=<?= (int)$user['user_id'] ?>&view=1" class="ucp-user-name"><?= ucp_e($fullName) ?></a><span>Created <?= ucp_e(!empty($user['created_at']) ? date('d M Y', strtotime($user['created_at'])) : '-') ?></span></div></div></td>
                        <td><code><?= ucp_e($user['username'] ?? '-') ?></code></td>
                        <td><?= ucp_e($user['email'] ?? '-') ?></td>
                        <td><span class="ucp-role-pill role-<?= ucp_e($role) ?>"><?= ucp_e(ucpUpper($role)) ?></span></td>
                        <td><?= ucp_e($identityNo) ?></td>
                        <td><?= ucp_e($department) ?></td>
                        <td><span class="ucp-status-dot <?= ucp_e($status) ?>"><span></span><?= $status === 'active' ? 'ACTIVE' : 'INACTIVE' ?></span></td>
                        <td><div class="ucp-action-buttons">
                            <a href="?<?= ucp_e(ucp_query_csrf(['reset_password' => (int)$user['user_id']])) ?>" class="ucp-icon-btn info" title="Reset Password" onclick="return confirm('Reset password for this user?');"><i class="fas fa-key"></i></a>
                            <button type="button" class="ucp-icon-btn warning edit-user-btn" title="Edit User" data-user='<?= ucp_e(json_encode($jsonUser, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>'><i class="fas fa-pen"></i></button>
                            <?php if ((int)$user['user_id'] !== (int)$_SESSION['user_id']): ?>
                                <a href="?<?= ucp_e(ucp_query_csrf(['toggle_status' => (int)$user['user_id']])) ?>" class="ucp-icon-btn <?= $status === 'active' ? 'neutral' : 'success' ?>" title="<?= $status === 'active' ? 'Deactivate' : 'Activate' ?>" onclick="return confirm('Confirm status change for this user?');"><i class="fas <?= $status === 'active' ? 'fa-ban' : 'fa-check' ?>"></i></a>
                                <a href="?<?= ucp_e(ucp_query_csrf(['delete' => (int)$user['user_id']])) ?>" class="ucp-icon-btn danger" title="Delete User" onclick="return confirm('Delete this user? If related records exist, the account will be deactivated instead.');"><i class="fas fa-trash"></i></a>
                            <?php endif; ?>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div class="ucp-empty-state" id="emptyUserState" hidden><i class="fas fa-magnifying-glass"></i><strong>No users found</strong><span>Try a different search term or filter.</span></div>
        </div>
        <div class="ucp-table-summary" id="userTableSummary">Showing <strong id="visibleUserCount"><?= (int)$totalUsers ?></strong> of <strong><?= (int)$totalUsers ?></strong> users</div>
    </div>
</section>

<div class="ucp-notice-overlay" id="adminNoticeOverlay" aria-hidden="true" data-notice='<?= ucp_e(json_encode($notice, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>'>
    <div class="ucp-notice-card" role="dialog" aria-modal="true">
        <button type="button" class="ucp-notice-close" id="adminNoticeClose"><i class="fas fa-times"></i></button>
        <div class="ucp-notice-icon"><i class="fas fa-circle-check"></i></div>
        <p class="ucp-eyebrow">SYSTEM MESSAGE</p>
        <h2 id="adminNoticeTitle"></h2>
        <p id="adminNoticeMessage"></p>
        <div class="ucp-temp-password" id="adminTempPasswordBox" hidden><span>Temporary Password</span><code id="adminTempPassword"></code><button type="button" class="ucp-btn ucp-btn-light" id="copyTempPasswordBtn"><i class="fas fa-copy"></i> Copy</button></div>
        <div class="ucp-notice-actions"><button type="button" class="ucp-btn ucp-btn-primary" id="adminNoticeOk">OK</button></div>
    </div>
</div>

<div class="ucp-modal" id="userModal" aria-hidden="true">
    <div class="ucp-modal-card" role="dialog" aria-modal="true">
        <form id="userForm" method="post" action="manage-users.php">
            <div class="ucp-modal-header">
                <div><p class="ucp-eyebrow">User Account</p><h2><span id="modalTitle">Add</span> User &amp; Permissions</h2><p>Create or update user accounts using secured validation rules.</p></div>
                <button type="button" class="ucp-modal-close" id="closeUserModal"><i class="fas fa-times"></i></button>
            </div>
            <div class="ucp-modal-body">
                <input type="hidden" name="csrf_token" value="<?= ucp_e(ucp_csrf_token()) ?>">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="user_id" id="user_id" value="">
                <div class="ucp-form-grid">
                    <div class="ucp-field-group"><label for="first_name">First Name <span>*</span></label><input type="text" id="first_name" name="first_name" required></div>
                    <div class="ucp-field-group"><label for="last_name">Last Name <span>*</span></label><input type="text" id="last_name" name="last_name" required></div>
                    <div class="ucp-field-group"><label for="username">User ID <span>*</span></label><input type="text" id="username" name="username" required><small>5–20 characters. Letters, numbers and underscore only.</small></div>
                    <div class="ucp-field-group"><label for="email">Email <span>*</span></label><input type="email" id="email" name="email" required></div>
                    <div class="ucp-field-group"><label for="user_type">Role <span>*</span></label><select id="user_type" name="user_type" required><option value="student">Student</option><option value="lecturer">Lecturer</option><option value="admin">Admin</option></select></div>
                    <div class="ucp-field-group"><label for="identity_no">Matric / Staff ID <span>*</span></label><input type="text" id="identity_no" name="identity_no" required><small>Student format: AI230159. Year 2020 or earlier is blocked.</small></div>
                    <div class="ucp-field-group"><label for="phone">Phone Number</label><input type="text" id="phone" name="phone"></div>
                    <div class="ucp-field-group"><label for="date_of_birth">Date of Birth</label><input type="date" id="date_of_birth" name="date_of_birth"></div>
                    <div class="ucp-field-group span-2"><label for="department">Department</label><select id="department" name="department"><option value="">Select Department</option><option>Information Technology</option><option>Information Security</option><option>Software Engineering</option><option>Network Security</option><option>Administration</option></select></div>
                    <div class="ucp-field-group span-2"><label for="address">Address</label><textarea id="address" name="address"></textarea></div>
                    <label class="ucp-switch-row span-2"><input type="checkbox" id="is_active" name="is_active" checked><span class="ucp-switch-ui"></span><span><strong>Active Account</strong><small>User can login when account is active.</small></span></label>
                </div>
            </div>
            <div class="ucp-modal-footer"><button type="button" class="ucp-btn ucp-btn-light" id="cancelUserModal">Cancel</button><button type="submit" class="ucp-btn ucp-btn-primary" id="saveUserBtn">Save User</button></div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('userModal');
    const form = document.getElementById('userForm');
    const modalTitle = document.getElementById('modalTitle');
    const addBtn = document.getElementById('openAddUserBtn');
    const closeBtn = document.getElementById('closeUserModal');
    const cancelBtn = document.getElementById('cancelUserModal');

    function openModal(mode, user) {
        form.reset();
        document.getElementById('formAction').value = mode === 'edit' ? 'update' : 'add';
        modalTitle.textContent = mode === 'edit' ? 'Edit' : 'Add';
        document.getElementById('user_id').value = '';
        document.getElementById('is_active').checked = true;

        if (mode === 'edit' && user) {
            document.getElementById('user_id').value = user.user_id || '';
            document.getElementById('first_name').value = user.first_name || '';
            document.getElementById('last_name').value = user.last_name || '';
            document.getElementById('username').value = user.username || '';
            document.getElementById('email').value = user.email || '';
            document.getElementById('user_type').value = user.user_type || 'student';
            document.getElementById('identity_no').value = user.identity_no || '';
            document.getElementById('phone').value = user.phone || '';
            document.getElementById('date_of_birth').value = (user.date_of_birth && user.date_of_birth !== '0000-00-00') ? user.date_of_birth : '';
            document.getElementById('department').value = user.department || '';
            document.getElementById('address').value = user.address || '';
            document.getElementById('is_active').checked = String(user.is_active) === '1';
        }

        modal.classList.add('is-open');
        document.body.classList.add('ucp-modal-open');
    }

    function closeModal() {
        modal.classList.remove('is-open');
        document.body.classList.remove('ucp-modal-open');
    }

    if (addBtn) addBtn.addEventListener('click', () => openModal('add'));
    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (cancelBtn) cancelBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', (event) => { if (event.target === modal) closeModal(); });

    document.querySelectorAll('.edit-user-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            try { openModal('edit', JSON.parse(this.dataset.user || '{}')); }
            catch (e) { alert('Unable to load user data.'); }
        });
    });

    const rows = Array.from(document.querySelectorAll('#usersTable tbody tr'));
    const search = document.getElementById('userSearchInput');
    const roleFilter = document.getElementById('roleFilter');
    const statusFilter = document.getElementById('statusFilter');
    const visibleCount = document.getElementById('visibleUserCount');
    const emptyState = document.getElementById('emptyUserState');
    const activeFilterCount = document.getElementById('activeFilterCount');
    const popout = document.getElementById('userFilterPopout');
    const filterToggle = document.getElementById('filterToggle');

    function applyFilters() {
        const q = (search.value || '').toLowerCase().trim();
        const role = roleFilter.value;
        const status = statusFilter.value;
        let count = 0;
        rows.forEach(row => {
            const okSearch = !q || (row.dataset.search || '').includes(q);
            const okRole = !role || row.dataset.role === role;
            const okStatus = !status || row.dataset.status === status;
            const show = okSearch && okRole && okStatus;
            row.hidden = !show;
            if (show) count++;
        });
        visibleCount.textContent = count;
        emptyState.hidden = count > 0;
        const filterCount = (role ? 1 : 0) + (status ? 1 : 0);
        activeFilterCount.textContent = filterCount;
    }

    if (search) search.addEventListener('input', applyFilters);
    if (roleFilter) roleFilter.addEventListener('change', applyFilters);
    if (statusFilter) statusFilter.addEventListener('change', applyFilters);
    document.getElementById('applyFiltersBtn')?.addEventListener('click', function () { applyFilters(); popout.hidden = true; });
    document.getElementById('clearFiltersBtn')?.addEventListener('click', function () { roleFilter.value = ''; statusFilter.value = ''; applyFilters(); });
    filterToggle?.addEventListener('click', function () { popout.hidden = !popout.hidden; });
    document.addEventListener('click', function (event) { if (popout && filterToggle && !popout.contains(event.target) && !filterToggle.contains(event.target)) popout.hidden = true; });
    applyFilters();

    const noticeOverlay = document.getElementById('adminNoticeOverlay');
    let notice = null;
    try { notice = JSON.parse(noticeOverlay.dataset.notice || 'null'); } catch (e) {}
    function closeNotice() { noticeOverlay.classList.remove('is-open', 'is-success', 'is-danger'); }
    if (notice) {
        noticeOverlay.classList.add('is-open', notice.type === 'danger' ? 'is-danger' : 'is-success');
        document.getElementById('adminNoticeTitle').textContent = notice.title || 'Message';
        document.getElementById('adminNoticeMessage').textContent = notice.message || '';

        const tempBox = document.getElementById('adminTempPasswordBox');
        const tempCode = document.getElementById('adminTempPassword');
        const tempPassword = String(notice.temporary_password || notice.temporaryPassword || '').trim();
        const noticeTitle = String(notice.title || '').toLowerCase();

        // Only Reset Password / Add User may show a temporary password.
        // Delete, status update, edit update, and error messages must always hide this box.
        if (tempPassword && (noticeTitle.includes('password') || noticeTitle.includes('user added'))) {
            tempCode.textContent = tempPassword;
            tempBox.hidden = false;
        } else {
            tempCode.textContent = '';
            tempBox.hidden = true;
        }
    } else {
        const tempBox = document.getElementById('adminTempPasswordBox');
        const tempCode = document.getElementById('adminTempPassword');
        if (tempBox) tempBox.hidden = true;
        if (tempCode) tempCode.textContent = '';
    }
    document.getElementById('adminNoticeClose')?.addEventListener('click', closeNotice);
    document.getElementById('adminNoticeOk')?.addEventListener('click', closeNotice);
    document.getElementById('copyTempPasswordBtn')?.addEventListener('click', function () {
        const text = document.getElementById('adminTempPassword').textContent;
        navigator.clipboard?.writeText(text);
    });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
