<?php
require_once '../config/config.php';
require_once '../includes/functions.php';

// Compatibility fallback: prevents profile page crash if the active functions.php
// does not contain the Phase 6 uppercase helper yet.
if (!function_exists('ucpUpper')) {
    function ucpUpper($value) {
        $value = trim((string)$value);
        if (function_exists('mb_strtoupper')) {
            return mb_strtoupper($value, 'UTF-8');
        }
        return strtoupper($value);
    }
}

if (!function_exists('ucpDisplayUpper')) {
    function ucpDisplayUpper($value) {
        return htmlspecialchars(ucpUpper($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}


if (!isLoggedIn()) {
    redirect('/pages/auth/login.php');
}

$db = new Database();
$conn = $db->getConnection();

$requested_user_id = isset($_GET['id']) ? (int)$_GET['id'] : $_SESSION['user_id'];
$isViewOnly = isAdmin() && $requested_user_id !== (int)$_SESSION['user_id'];

// Permission check
if ($requested_user_id !== $_SESSION['user_id'] && !isAdmin()) {
    $_SESSION['error'] = "You don't have permission to view this profile";
    redirect('/pages/dashboard.php');
}
// Get user details
$user = [];
$stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->bind_param("i", $requested_user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
    $user = $result->fetch_assoc();
} else {
    $_SESSION['error'] = "User not found";
    redirect(isAdmin() ? '/pages/admin/manage-users.php' : '/pages/dashboard.php');
}
$stmt->close();

// Initialize variables
$first_name = $user['first_name'] ?? '';
$last_name = $user['last_name'] ?? '';
$email = $user['email'] ?? '';
$phone = $user['phone'] ?? '';
$date_of_birth = $user['date_of_birth'] ?? '';
$address = $user['address'] ?? '';
$department = $user['department'] ?? '';

// Handle form submissions
$errors = [];
$success = '';

// Handle profile picture upload
if (!$isViewOnly && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_picture'])) {
    
    // Check if file was uploaded
    if (!isset($_FILES['profile_picture']) || $_FILES['profile_picture']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = "Please select a valid image file";
    } else {
        $upload_dir = '../assets/uploads/profile-pics/';
        
        // Create upload directory if it doesn't exist
        if (!file_exists($upload_dir)) {
            if (!mkdir($upload_dir, 0755, true)) {
                $errors[] = "Failed to create upload directory";
            }
        }
        
        if (empty($errors)) {
            $file = $_FILES['profile_picture'];
            $file_name = $file['name'];
            $file_tmp = $file['tmp_name'];
            $file_size = $file['size'];
            $file_type = $file['type'];
            
            // Get file extension
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            
            // Allowed file types
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
            $allowed_mime_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
            
            // Validate file
            if (!in_array($file_ext, $allowed_extensions)) {
                $errors[] = "Only JPG, JPEG, PNG and GIF files are allowed";
            } elseif (!in_array($file_type, $allowed_mime_types)) {
                $errors[] = "Invalid file type";
            } elseif ($file_size > 5000000) { // 5MB limit
                $errors[] = "File size must be less than 5MB";
            } else {
                // Generate new filename
                $new_filename = 'profile_' . $_SESSION['user_id'] . '_' . time() . '.' . $file_ext;
                $upload_path = $upload_dir . $new_filename;
                
                // Move uploaded file
                if (move_uploaded_file($file_tmp, $upload_path)) {
                    
                    // Delete old profile picture if exists and not default
                    if (!empty($user['profile_picture']) && $user['profile_picture'] !== 'default.png') {
                        $old_pic_path = $upload_dir . $user['profile_picture'];
                        if (file_exists($old_pic_path)) {
                            unlink($old_pic_path);
                        }
                    }
                    
                    // Update database
                    $update_pic_stmt = $conn->prepare("UPDATE users SET profile_picture = ? WHERE user_id = ?");
                    $update_pic_stmt->bind_param("si", $new_filename, $_SESSION['user_id']);
                    
                    if ($update_pic_stmt->execute()) {
                        // Update session and user data
                        $_SESSION['profile_picture'] = $new_filename;
                        $user['profile_picture'] = $new_filename;
                        
                        // Redirect to prevent form resubmission
                        header("Location: " . $_SERVER['PHP_SELF'] . "?success=pic_updated");
                        exit();
                    } else {
                        $errors[] = "Failed to update profile picture in database";
                        // Remove uploaded file if database update failed
                        unlink($upload_path);
                    }
                    $update_pic_stmt->close();
                } else {
                    $errors[] = "Failed to upload file";
                }
            }
        }
    }
}

if (!$isViewOnly && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle profile update
    if (isset($_POST['first_name'])) {
        $first_name = sanitizePersonName($_POST['first_name']);
        $last_name = sanitizePersonName($_POST['last_name']);
        $email = sanitizeEmailAddress($_POST['email']);
        $phone = sanitizePhoneNumber($_POST['phone']);
        $date_of_birth = sanitizeInput($_POST['date_of_birth']);
        $address = normalizeUppercaseText($_POST['address']);
        $department = normalizeUppercaseText($_POST['department'], 100);

        // Validate inputs
        if (empty($first_name)) $errors[] = "First name is required";
        if (empty($last_name)) $errors[] = "Last name is required";
        if (empty($email)) $errors[] = "Email is required";
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Invalid email format";

        if (empty($errors)) {
            // Check email uniqueness
            $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
            $stmt->bind_param("si", $email, $_SESSION['user_id']);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $errors[] = "Email is already taken";
            } else {
                // Update profile
                $update_stmt = $conn->prepare("UPDATE users SET 
                    first_name = ?, last_name = ?, email = ?, phone = ?, 
                    date_of_birth = ?, address = ?, department = ? 
                    WHERE user_id = ?");
                
                $update_stmt->bind_param("sssssssi", 
                    $first_name, $last_name, $email, $phone,
                    $date_of_birth, $address, $department, $_SESSION['user_id']
                );
                
                if ($update_stmt->execute()) {
                    $_SESSION['first_name'] = $first_name;
                    $_SESSION['last_name'] = $last_name;
                    $_SESSION['email'] = $email;
                    $success = "Profile updated successfully";
                    
                    // Refresh user data
                    $refresh_stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
                    $refresh_stmt->bind_param("i", $_SESSION['user_id']);
                    $refresh_stmt->execute();
                    $user = $refresh_stmt->get_result()->fetch_assoc();
                    $refresh_stmt->close();
                    
                    // Redirect to prevent resubmission
                    header("Location: " . $_SERVER['PHP_SELF'] . "?success=profile_update");
                    exit();
                } else {
                    $errors[] = "Failed to update profile";
                }
                $update_stmt->close();
            }
            $stmt->close();
        }
    }

    // Handle password change
    if (isset($_POST['change_password'])) {
        $current_password = sanitizeInput($_POST['current_password']);
        $new_password = sanitizeInput($_POST['new_password']);
        $confirm_password = sanitizeInput($_POST['confirm_password']);
        
        if (empty($current_password)) $errors[] = "Current password is required";
        if (empty($new_password)) $errors[] = "New password is required";
        if (strlen($new_password) < 8) $errors[] = "Password must be at least 8 characters";
        if ($new_password !== $confirm_password) $errors[] = "Passwords don't match";
        
        if (empty($errors)) {
            $password_stmt = $conn->prepare("SELECT password FROM users WHERE user_id = ?");
            $password_stmt->bind_param("i", $_SESSION['user_id']);
            $password_stmt->execute();
            $result = $password_stmt->get_result();
            
            if ($result->num_rows === 1) {
                $user_data = $result->fetch_assoc();
                
                if (password_verify($current_password, $user_data['password'])) {
                    $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
                    $update_password_stmt = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
                    $update_password_stmt->bind_param("si", $hashed_password, $_SESSION['user_id']);
                    
                    if ($update_password_stmt->execute()) {
                        $success = "Password changed successfully";
                        
                        // Redirect to prevent resubmission
                        header("Location: " . $_SERVER['PHP_SELF'] . "?success=password_change");
                        exit();
                    } else {
                        $errors[] = "Failed to change password";
                    }
                    $update_password_stmt->close();
                } else {
                    $errors[] = "Current password is incorrect";
                }
            }
            $password_stmt->close();
        }
    }
}

// Handle success messages from redirects
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'pic_updated':
            $success = "Profile picture updated successfully";
            break;
        case 'profile_update':
            $success = "Profile updated successfully";
            break;
        case 'password_change':
            $success = "Password changed successfully";
            break;
    }
}

$db->closeConnection();

$pageTitle = $isViewOnly ? "View User Profile" : "My Profile";
include '../includes/header.php';
?>

<div class="profile-container">
    <h1><?= $isViewOnly ? 'User Profile (View Only)' : 'My Profile' ?></h1>
    
    <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    
    <div class="profile-section">
        <div class="profile-picture">
            <?php
            // Set default profile picture path
            $default_pic = 'default.png';
            $profile_pic_name = !empty($user['profile_picture']) ? $user['profile_picture'] : $default_pic;
            
            // Construct the full path for the profile picture
            $profile_pic_path = '../assets/uploads/profile-pics/' . $profile_pic_name;
            
            // Check if custom profile picture exists, otherwise use default
            if (!empty($user['profile_picture']) && file_exists($profile_pic_path)) {
                $display_pic = '../assets/uploads/profile-pics/' . $user['profile_picture'];
            } else {
                $display_pic = '../assets/images/default-profile.jpg'; // Fallback to default
            }
            ?>
            
            <img src="<?php echo htmlspecialchars($display_pic); ?>" 
                 alt="Profile Picture" 
                 class="profile-img"
                 id="profileImage"
                 style="width: 150px; height: 150px; border-radius: 50%; object-fit: cover; border: 3px solid #ddd;">
            
            <?php if (!$isViewOnly): ?>
                <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" 
                      method="post" 
                      enctype="multipart/form-data" 
                      class="picture-form" 
                      style="margin-top: 15px;">
                      
                    <div class="file-input-wrapper">
                        <input type="file" 
                               name="profile_picture" 
                               id="profile_picture" 
                               accept="image/jpeg,image/jpg,image/png,image/gif" 
                               style="display: none;">
                        <label for="profile_picture" class="btn btn-small" style="margin-right: 10px;">
                            Choose Picture
                        </label>
                        <button type="submit" name="upload_picture" class="btn btn-small btn-primary">
                            Upload
                        </button>
                    </div>
                    <small style="display: block; margin-top: 5px; color: #666;">
                        Allowed: JPG, JPEG, PNG, GIF (Max 5MB)
                    </small>
                </form>
            <?php else: ?>
                <small style="display: block; margin-top: 12px; color: #666;">View-only profile opened from administrator account.</small>
            <?php endif; ?>
        </div>
        
        <div class="profile-info">
            <h2><?php echo htmlspecialchars(ucpUpper(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))); ?></h2>
            <p><strong>Username:</strong> <?php echo htmlspecialchars($user['username'] ?? ''); ?></p>
            <p><strong>Email:</strong> <?php echo htmlspecialchars($user['email'] ?? ''); ?></p>
            <p><strong>User Type:</strong> <?php echo htmlspecialchars(ucpUpper($user['user_type'] ?? '')); ?></p>
            <?php if (!empty($user['student_id'])): ?>
                <p><strong>ID Number / Matric Number:</strong> <?php echo htmlspecialchars($user['student_id']); ?></p>
            <?php endif; ?>
            <?php if (!empty($user['staff_id'])): ?>
                <p><strong>Staff ID:</strong> <?php echo htmlspecialchars($user['staff_id']); ?></p>
            <?php endif; ?>
            <?php if (!empty($user['department'])): ?>
                <p><strong>Department:</strong> <?php echo htmlspecialchars(ucpUpper($user['department'])); ?></p>
            <?php endif; ?>
        </div>
    </div>
    
    <?php if ($isViewOnly): ?>
        <div class="ucp-view-only-note">
            <i class="fas fa-lock"></i>
            <div>
                <strong>View-only mode</strong>
                <span>This profile was opened from Manage Users. Administrators can review account details here, but cannot change user information from this page.</span>
            </div>
        </div>
    <?php else: ?>
    <div class="profile-tabs">
        <ul class="tab-nav">
            <li class="active"><a href="#personal">Personal Information</a></li>
            <li><a href="#password">Change Password</a></li>
        </ul>
        
        <div class="tab-content">
            <div id="personal" class="tab-pane active">
                <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="post">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="first_name">First Name *</label>
                            <input type="text" id="first_name" name="first_name" 
                                   value="<?php echo htmlspecialchars($first_name); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="last_name">Last Name *</label>
                            <input type="text" id="last_name" name="last_name" 
                                   value="<?php echo htmlspecialchars($last_name); ?>" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="email">Email *</label>
                        <input type="email" id="email" name="email" 
                               value="<?php echo htmlspecialchars($email); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="phone">Phone Number</label>
                        <input type="text" id="phone" name="phone" 
                               value="<?php echo htmlspecialchars($phone); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="date_of_birth">Date of Birth</label>
                        <input type="date" id="date_of_birth" name="date_of_birth" 
                               value="<?php echo htmlspecialchars($date_of_birth); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="department">Department</label>
                        <input type="text" id="department" name="department" 
                               value="<?php echo htmlspecialchars($department); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="address">Address</label>
                        <textarea id="address" name="address"><?php echo htmlspecialchars($address); ?></textarea>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Update Profile</button>
                </form>
            </div>
            
            <div id="password" class="tab-pane">
                <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="post">
                    <div class="form-group">
                        <label for="current_password">Current Password *</label>
                        <input type="password" id="current_password" name="current_password" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="new_password">New Password *</label>
                        <input type="password" id="new_password" name="new_password" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password">Confirm New Password *</label>
                        <input type="password" id="confirm_password" name="confirm_password" required>
                    </div>
                    
                    <button type="submit" name="change_password" class="btn btn-primary">Change Password</button>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Tab switching
    const tabNav = document.querySelectorAll('.tab-nav li');
    const tabPanes = document.querySelectorAll('.tab-pane');
    
    tabNav.forEach(tab => {
        tab.addEventListener('click', function(e) {
            e.preventDefault();
            tabNav.forEach(t => t.classList.remove('active'));
            tabPanes.forEach(p => p.classList.remove('active'));
            
            this.classList.add('active');
            const paneId = this.querySelector('a').getAttribute('href').substring(1);
            document.getElementById(paneId).classList.add('active');
        });
    });

    ['first_name', 'last_name', 'department', 'address'].forEach(function(fieldId) {
        const field = document.getElementById(fieldId);
        if (field) {
            field.addEventListener('input', function() { this.value = this.value.toUpperCase(); });
        }
    });

    // Preview image before upload
    const profilePicInput = document.getElementById('profile_picture');
    const profileImage = document.getElementById('profileImage');
    
    if (profilePicInput && profileImage) {
        profilePicInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                // Validate file type
                const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
                if (!allowedTypes.includes(file.type)) {
                    alert('Please select a valid image file (JPG, JPEG, PNG, GIF)');
                    this.value = '';
                    return;
                }
                
                // Validate file size (5MB)
                if (file.size > 5000000) {
                    alert('File size must be less than 5MB');
                    this.value = '';
                    return;
                }
                
                // Preview the image
                const reader = new FileReader();
                reader.onload = function(event) {
                    profileImage.src = event.target.result;
                };
                reader.readAsDataURL(file);
            }
        });
    }
});
</script>

<?php include '../includes/footer.php'; ?>