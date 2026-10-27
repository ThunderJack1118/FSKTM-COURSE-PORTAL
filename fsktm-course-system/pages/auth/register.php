<?php
require_once '../../config/config.php';
require_once '../../includes/functions.php';

if (isLoggedIn()) {
    redirect('/pages/profile.php');
}

function validateDate($date, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Required rubric fields
    $username = sanitizeUserId($_POST['username'] ?? ''); // User ID
    $email = sanitizeEmailAddress($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');
    $first_name = sanitizeInput($_POST['first_name'] ?? '');
    $last_name = sanitizeInput($_POST['last_name'] ?? '');
    $student_id = sanitizeMatricNumber($_POST['student_id'] ?? ''); // ID Number / Matric Number

    // Optional fields
    $phone = isset($_POST['phone']) ? sanitizeInput($_POST['phone']) : null;
    $date_of_birth = isset($_POST['date_of_birth']) ? sanitizeInput($_POST['date_of_birth']) : null;
    $address = isset($_POST['address']) ? sanitizeInput($_POST['address']) : null;
    $department = isset($_POST['department']) ? sanitizeInput($_POST['department']) : null;

    $user_type = ROLE_STUDENT;

    // User ID validation
    if (empty($username)) {
        $errors[] = 'User ID is required.';
    } elseif (!validateUserId($username)) {
        $errors[] = 'User ID must be 5–20 characters and contain only letters, numbers, or underscore.';
    }

    // Name validation
    if (empty($first_name)) {
        $errors[] = 'First name is required.';
    } elseif (!validateNameField($first_name)) {
        $errors[] = 'First name may contain letters, spaces, hyphen, and apostrophe only.';
    }

    if (empty($last_name)) {
        $errors[] = 'Last name is required.';
    } elseif (!validateNameField($last_name)) {
        $errors[] = 'Last name may contain letters, spaces, hyphen, and apostrophe only.';
    }

    // Email validation
    if (empty($email)) {
        $errors[] = 'Email is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    // ID Number / Matric Number validation and 2020 block rule
    if (empty($student_id)) {
        $errors[] = 'ID Number / Matric Number is required.';
    } elseif (!validateMatricNumber($student_id)) {
        $errors[] = 'ID Number must follow UTHM matric format, for example AI230159.';
    } elseif (!isEnrollmentYearAllowed($student_id)) {
        $errors[] = 'Registration denied. Users enrolled in 2020 or earlier are not allowed to register.';
    }

    // Strong password validation
    if (empty($password)) {
        $errors[] = 'Password is required.';
    } else {
        $errors = array_merge($errors, getPasswordValidationErrors($password));
    }

    if ($password !== $confirm_password) {
        $errors[] = 'Passwords do not match.';
    }

    // Optional field validation
    if (!empty($phone) && !preg_match('/^\+?[0-9\s\-]{10,15}$/', $phone)) {
        $errors[] = 'Phone number format is invalid.';
    }

    if (!empty($date_of_birth) && !validateDate($date_of_birth)) {
        $errors[] = 'Date of birth format is invalid (YYYY-MM-DD).';
    }

    if (empty($errors)) {
        $db = new Database();
        $conn = $db->getConnection();

        $stmt = $conn->prepare('SELECT user_id FROM users WHERE username = ? OR email = ? OR student_id = ?');
        $stmt->bind_param('sss', $username, $email, $student_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $errors[] = 'User ID, email, or ID Number already exists.';
        } else {
            $hashed_password = password_hash($password, PASSWORD_BCRYPT);
            $profile_picture = 'default-avatar.png';

            $insert_stmt = $conn->prepare('INSERT INTO users
                (username, email, password, first_name, last_name, user_type,
                 profile_picture, phone, date_of_birth, student_id, address, department)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

            $insert_stmt->bind_param('ssssssssssss',
                $username, $email, $hashed_password, $first_name, $last_name, $user_type,
                $profile_picture, $phone, $date_of_birth, $student_id, $address, $department
            );

            if ($insert_stmt->execute()) {
                $success = 'Registration successful! You can now <a href="login.php">login</a>.';
                $_POST = [];
            } else {
                $errors[] = 'Registration failed. Please try again.';
            }
            $insert_stmt->close();
        }

        $stmt->close();
        $db->closeConnection();
    }
}

$pageTitle = 'Register';
include '../../includes/header.php';
?>

<div class="auth-container">
    <h1>Create a UTHM Secure Course Portal Account</h1>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo $success; ?></div>
    <?php else: ?>
        <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8'); ?>" method="post">
            <div class="form-row">
                <div class="form-group">
                    <label for="first_name">First Name*</label>
                    <input type="text" id="first_name" name="first_name" value="<?php echo isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name'], ENT_QUOTES, 'UTF-8') : ''; ?>" required>
                </div>

                <div class="form-group">
                    <label for="last_name">Last Name*</label>
                    <input type="text" id="last_name" name="last_name" value="<?php echo isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name'], ENT_QUOTES, 'UTF-8') : ''; ?>" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="username">User ID*</label>
                    <input type="text" id="username" name="username" value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username'], ENT_QUOTES, 'UTF-8') : ''; ?>" placeholder="e.g. ai230159" required>
                    <small class="form-text">5–20 characters. Letters, numbers and underscore only.</small>
                </div>

                <div class="form-group">
                    <label for="email">Email*</label>
                    <input type="email" id="email" name="email" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email'], ENT_QUOTES, 'UTF-8') : ''; ?>" placeholder="e.g. ai230159@siswa.uthm.edu.my" required>
                </div>
            </div>

            <div class="form-group">
                <label for="student_id">ID Number / Matric Number*</label>
                <input type="text" id="student_id" name="student_id" value="<?php echo isset($_POST['student_id']) ? htmlspecialchars($_POST['student_id'], ENT_QUOTES, 'UTF-8') : ''; ?>" placeholder="e.g. AI230159" required>
                <small class="form-text">Format: two letters followed by six digits. Example: AI230159. Matric year 2020 or earlier will be blocked.</small>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="password">Password*</label>
                    <input type="password" id="password" name="password" required>
                    <small class="form-text">Must contain uppercase, lowercase, number, special character, and at least 8 characters.</small>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm Password*</label>
                    <input type="password" id="confirm_password" name="confirm_password" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="phone">Phone Number</label>
                    <input type="tel" id="phone" name="phone" value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone'], ENT_QUOTES, 'UTF-8') : ''; ?>" class="form-control">
                </div>

                <div class="form-group">
                    <label for="date_of_birth">Date of Birth</label>
                    <input type="date" id="date_of_birth" name="date_of_birth" value="<?php echo isset($_POST['date_of_birth']) ? htmlspecialchars($_POST['date_of_birth'], ENT_QUOTES, 'UTF-8') : ''; ?>">
                </div>
            </div>

            <div class="form-group">
                <label for="department">Department</label>
                <select id="department" name="department" class="form-control">
                    <option value="">Select Department</option>
                    <option value="Information Technology" <?php echo (isset($_POST['department']) && $_POST['department'] === 'Information Technology') ? 'selected' : ''; ?>>Information Technology</option>
                    <option value="Software Engineering" <?php echo (isset($_POST['department']) && $_POST['department'] === 'Software Engineering') ? 'selected' : ''; ?>>Software Engineering</option>
                    <option value="Information Security" <?php echo (isset($_POST['department']) && $_POST['department'] === 'Information Security') ? 'selected' : ''; ?>>Information Security</option>
                    <option value="Multimedia Computing" <?php echo (isset($_POST['department']) && $_POST['department'] === 'Multimedia Computing') ? 'selected' : ''; ?>>Multimedia Computing</option>
                    <option value="Web Technology" <?php echo (isset($_POST['department']) && $_POST['department'] === 'Web Technology') ? 'selected' : ''; ?>>Web Technology</option>
                </select>
            </div>

            <div class="form-group">
                <label for="address">Address</label>
                <textarea id="address" name="address" rows="3" class="form-control"><?php echo isset($_POST['address']) ? htmlspecialchars($_POST['address'], ENT_QUOTES, 'UTF-8') : ''; ?></textarea>
            </div>

            <button type="submit" class="btn btn-primary">Register</button>
        </form>

        <div class="auth-links">
            <p>Already have an account? <a href="<?php echo BASE_URL; ?>/pages/auth/login.php">Login here</a></p>
        </div>
    <?php endif; ?>
</div>

<?php include '../../includes/footer.php'; ?>
