<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

/*
 * Phase 8J Manage Users API
 * Function-only API used by pages/admin/manage-users.php.
 * No direct POST/GET handling is performed here, so AJAX responses stay JSON.
 */

if (!function_exists('getUserById')) {
    function getUserById(mysqli $conn, int $userId): ?array {
        $stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ? LIMIT 1");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $user ?: null;
    }
}

if (!function_exists('ucpValidateAdminMatric')) {
    function ucpValidateAdminMatric(string $studentId): array {
        $errors = [];

        if ($studentId === '' || preg_match('/^[A-Z]{2}[0-9]{6}$/', $studentId) !== 1) {
            $errors[] = 'Matric number must use two letters followed by six digits, for example AI230159.';
            return $errors;
        }

        if (function_exists('isEnrollmentYearAllowed') && !isEnrollmentYearAllowed($studentId)) {
            $errors[] = 'Registration denied. Users enrolled in 2020 or earlier are not allowed to register.';
        }

        return $errors;
    }
}

if (!function_exists('validateUserPayload')) {
    function validateUserPayload(mysqli $conn, array $post, ?int $existingUserId = null): array {
        $errors = [];

        $username = sanitizeUserId($post['username'] ?? '');
        $email = sanitizeEmailAddress($post['email'] ?? '');
        $firstName = sanitizePersonName($post['first_name'] ?? '');
        $lastName = sanitizePersonName($post['last_name'] ?? '');
        $userType = sanitizeUserRole($post['user_type'] ?? '');
        $phone = sanitizePhoneNumber($post['phone'] ?? '');
        $department = normalizeUppercaseText($post['department'] ?? '', 100);
        $address = normalizeUppercaseText($post['address'] ?? '', 255);
        $isActive = isset($post['is_active']) ? 1 : 0;

        if (!isValidPersonName($firstName)) {
            $errors[] = 'First name must contain only letters, spaces, apostrophes, or hyphens, with at least 2 characters.';
        }

        if (!isValidPersonName($lastName)) {
            $errors[] = 'Last name must contain only letters, spaces, apostrophes, or hyphens, with at least 2 characters.';
        }

        if (!isValidUserId($username)) {
            $errors[] = 'User ID must be 5–20 characters and contain lowercase letters, numbers, or underscores only.';
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email format.';
        }

        if ($userType === '') {
            $errors[] = 'Invalid user role.';
        }

        if (!isValidPhoneNumber($phone)) {
            $errors[] = 'Phone number must contain 10–20 digits/symbols only.';
        }

        $studentId = null;
        $staffId = null;

        if ($userType === ROLE_STUDENT) {
            $studentId = sanitizeMatricNumber($post['student_id'] ?? '');
            $errors = array_merge($errors, ucpValidateAdminMatric($studentId));
        }

        if (!empty($errors)) {
            throw new Exception(implode(' ', $errors));
        }

        if ($existingUserId === null) {
            $stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ? OR email = ? OR (student_id IS NOT NULL AND student_id = ?) LIMIT 1");
            $checkStudentId = $studentId ?? '';
            $stmt->bind_param("sss", $username, $email, $checkStudentId);
        } else {
            $stmt = $conn->prepare("SELECT user_id FROM users WHERE (username = ? OR email = ? OR (student_id IS NOT NULL AND student_id = ?)) AND user_id != ? LIMIT 1");
            $checkStudentId = $studentId ?? '';
            $stmt->bind_param("sssi", $username, $email, $checkStudentId, $existingUserId);
        }

        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $stmt->close();
            throw new Exception('User ID, email, or matric number already exists.');
        }
        $stmt->close();

        return [
            'username' => $username,
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'user_type' => $userType,
            'phone' => $phone !== '' ? $phone : null,
            'department' => $department !== '' ? $department : null,
            'address' => $address !== '' ? $address : null,
            'is_active' => $isActive,
            'student_id' => $studentId,
            'staff_id' => $staffId
        ];
    }
}

if (!function_exists('handleAddUser')) {
    function handleAddUser(mysqli $conn): array {
        requireAdminAccess();
        requireValidCsrfToken($_POST['csrf_token'] ?? '');

        $data = validateUserPayload($conn, $_POST, null);

        if ($data['user_type'] === ROLE_STUDENT) {
            $data['staff_id'] = null;
        } else {
            $data['student_id'] = null;
            $data['staff_id'] = generateStaffId($conn, $data['user_type']);
        }

        $password = generateSecureDefaultPassword($data['user_type']);
        $passwordHash = password_hash($password, PASSWORD_BCRYPT);
        $profilePicture = 'default-avatar.png';

        $stmt = $conn->prepare("
            INSERT INTO users
                (username, email, password, first_name, last_name, user_type, profile_picture, phone, student_id, staff_id, address, department, is_active)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->bind_param(
            "ssssssssssssi",
            $data['username'],
            $data['email'],
            $passwordHash,
            $data['first_name'],
            $data['last_name'],
            $data['user_type'],
            $profilePicture,
            $data['phone'],
            $data['student_id'],
            $data['staff_id'],
            $data['address'],
            $data['department'],
            $data['is_active']
        );

        $stmt->execute();
        $newUserId = (int)$conn->insert_id;
        $stmt->close();

        if (function_exists('logAuditEvent')) {
            logAuditEvent((int)($_SESSION['user_id'] ?? 0), 'admin_user_add', 'Administrator added a new user account.', [
                'target_user_id' => $newUserId,
                'target_username' => $data['username'],
                'target_role' => $data['user_type']
            ]);
        }

        return [
            'success' => true,
            'message' => 'User added successfully.',
            'user_id' => $newUserId
        ];
    }
}

if (!function_exists('handleUpdateUser')) {
    function handleUpdateUser(mysqli $conn): array {
        requireAdminAccess();
        requireValidCsrfToken($_POST['csrf_token'] ?? '');

        if (empty($_POST['user_id'])) {
            throw new Exception('User record is required.');
        }

        $userId = (int)$_POST['user_id'];
        $existingUser = getUserById($conn, $userId);
        if (!$existingUser) {
            throw new Exception('User not found.');
        }

        $data = validateUserPayload($conn, $_POST, $userId);

        if ($data['user_type'] === ROLE_STUDENT) {
            $data['staff_id'] = null;
        } else {
            $data['student_id'] = null;
            $data['staff_id'] = $existingUser['staff_id'] ?: generateStaffId($conn, $data['user_type']);
        }

        $stmt = $conn->prepare("
            UPDATE users
            SET username = ?, email = ?, first_name = ?, last_name = ?, user_type = ?,
                phone = ?, student_id = ?, staff_id = ?, address = ?, department = ?, is_active = ?
            WHERE user_id = ?
        ");

        $stmt->bind_param(
            "ssssssssssii",
            $data['username'],
            $data['email'],
            $data['first_name'],
            $data['last_name'],
            $data['user_type'],
            $data['phone'],
            $data['student_id'],
            $data['staff_id'],
            $data['address'],
            $data['department'],
            $data['is_active'],
            $userId
        );

        $stmt->execute();
        $stmt->close();

        if (function_exists('logAuditEvent')) {
            logAuditEvent((int)($_SESSION['user_id'] ?? 0), 'admin_user_update', 'Administrator updated a user account.', [
                'target_user_id' => $userId,
                'target_username' => $data['username']
            ]);
        }

        return [
            'success' => true,
            'message' => 'User updated successfully.',
            'user_id' => $userId
        ];
    }
}

if (!function_exists('toggleUserStatus')) {
    function toggleUserStatus(mysqli $conn, int $userId): void {
        requireAdminAccess();

        if (!validateCsrfToken($_GET['csrf_token'] ?? '')) {
            throw new Exception('Invalid security token. Please refresh the page and try again.');
        }

        if ($userId === (int)($_SESSION['user_id'] ?? 0)) {
            throw new Exception('You cannot deactivate your own administrator account.');
        }

        $user = getUserById($conn, $userId);
        if (!$user) {
            throw new Exception('User not found.');
        }

        $newStatus = (int)$user['is_active'] === 1 ? 0 : 1;
        $stmt = $conn->prepare("UPDATE users SET is_active = ? WHERE user_id = ?");
        $stmt->bind_param("ii", $newStatus, $userId);
        $stmt->execute();
        $stmt->close();

        if (function_exists('logAuditEvent')) {
            logAuditEvent((int)($_SESSION['user_id'] ?? 0), 'admin_user_status', 'Administrator changed a user account status.', [
                'target_user_id' => $userId,
                'new_status' => $newStatus
            ]);
        }
    }
}

if (!function_exists('deleteUser')) {
    function deleteUser(mysqli $conn, int $userId): void {
        requireAdminAccess();

        if (!validateCsrfToken($_GET['csrf_token'] ?? '')) {
            throw new Exception('Invalid security token. Please refresh the page and try again.');
        }

        if ($userId === (int)($_SESSION['user_id'] ?? 0)) {
            throw new Exception('You cannot delete your own administrator account.');
        }

        $user = getUserById($conn, $userId);
        if (!$user) {
            throw new Exception('User not found.');
        }

        try {
            $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $stmt->close();
        } catch (mysqli_sql_exception $e) {
            $inactive = 0;
            $stmt = $conn->prepare("UPDATE users SET is_active = ? WHERE user_id = ?");
            $stmt->bind_param("ii", $inactive, $userId);
            $stmt->execute();
            $stmt->close();
        }

        if (function_exists('logAuditEvent')) {
            logAuditEvent((int)($_SESSION['user_id'] ?? 0), 'admin_user_delete', 'Administrator deleted or deactivated a user account.', [
                'target_user_id' => $userId
            ]);
        }
    }
}

if (!function_exists('handleResetUserPassword')) {
    function handleResetUserPassword(mysqli $conn): array {
        requireAdminAccess();
        requireValidCsrfToken($_POST['csrf_token'] ?? '');

        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId <= 0) {
            throw new Exception('User record is required.');
        }

        $user = getUserById($conn, $userId);
        if (!$user) {
            throw new Exception('User not found.');
        }

        $temporaryPassword = generateSecureDefaultPassword($user['user_type'] ?? 'user');
        $passwordHash = password_hash($temporaryPassword, PASSWORD_BCRYPT);

        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
        $stmt->bind_param("si", $passwordHash, $userId);
        $stmt->execute();
        $stmt->close();

        $displayName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
        if ($displayName === '') {
            $displayName = $user['username'] ?? 'User';
        }

        if (function_exists('logAuditEvent')) {
            logAuditEvent((int)($_SESSION['user_id'] ?? 0), 'admin_password_reset', 'Administrator reset a user password.', [
                'target_user_id' => $userId,
                'target_username' => $user['username'] ?? null
            ]);
        }

        return [
            'success' => true,
            'message' => 'Temporary password generated successfully. Copy it now because it will only be shown once.',
            'temporary_password' => $temporaryPassword,
            'display_name' => ucpUpper($displayName),
            'user_id' => $userId
        ];
    }
}

if (!function_exists('generateStaffId')) {
    function generateStaffId(mysqli $conn, string $role): string {
        $prefix = $role === ROLE_ADMIN ? 'ADM' : 'LEC';

        do {
            $staffId = $prefix . random_int(100000, 999999);
            $stmt = $conn->prepare("SELECT user_id FROM users WHERE staff_id = ? LIMIT 1");
            $stmt->bind_param("s", $staffId);
            $stmt->execute();
            $exists = $stmt->get_result()->num_rows > 0;
            $stmt->close();
        } while ($exists);

        return $staffId;
    }
}

if (!function_exists('showAlertMessages')) {
    function showAlertMessages(): void {
        foreach (['success' => 'alert-success', 'error' => 'alert-error', 'warning' => 'alert-warning', 'info' => 'alert-info'] as $key => $class) {
            if (!empty($_SESSION[$key])) {
                echo '<div class="alert ' . e($class) . '">' . e($_SESSION[$key]) . '</div>';
                unset($_SESSION[$key]);
            }
        }
    }
}
