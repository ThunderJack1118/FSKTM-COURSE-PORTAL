<?php
// Navigation component for the UTHM Secure Course Portal

// Check if user is logged in
$isLoggedIn = isset($_SESSION['user_id']);
$userType = $_SESSION['user_type'] ?? '';
$firstName = $_SESSION['first_name'] ?? '';
$lastName = $_SESSION['last_name'] ?? '';
$profilePic = $_SESSION['profile_picture'] ?? 'default-avatar.png';
?>

<nav class="main-nav">
    <div class="nav-container">
        <!-- Logo/Brand -->
        <div class="nav-brand">
            <a href="<?php echo BASE_URL; ?>">
                <img src="<?php echo BASE_URL; ?>/assets/images/logo.png" alt="UTHM Secure Course Portal Logo">
                <span>UTHM Secure Course Portal</span>
            </a>
        </div>

        <!-- Mobile Menu Toggle -->
        <button class="mobile-menu-toggle" aria-label="Toggle navigation">
            <i class="fas fa-bars"></i>
        </button>

        <!-- Navigation Links -->
        <div class="nav-links">
            <ul class="nav-menu">
                <li><a href="<?php echo BASE_URL; ?>">Home</a></li>
                <li><a href="<?php echo BASE_URL; ?>/pages/courses.php">Courses</a></li>

                <?php if ($isLoggedIn): ?>
                    <?php if ($userType === 'student'): ?>
                        <li><a href="<?php echo BASE_URL; ?>/pages/my-courses.php">My Courses</a></li>
                    <?php elseif ($userType === 'lecturer' || $userType === 'admin'): ?>
                        <li><a href="<?php echo BASE_URL; ?>/pages/my-courses.php">Teaching Courses</a></li>
                    <?php endif; ?>

                    <li class="nav-dropdown">
                        <a href="#" class="dropdown-toggle">
                            <span class="user-avatar">
                                <img src="<?php echo BASE_URL . '/' . UPLOAD_PROFILE_PATH . $profilePic; ?>" alt="Profile Picture">
                            </span>
                            <span class="user-name"><?php echo htmlspecialchars($firstName); ?></span>
                            <i class="fas fa-caret-down"></i>
                        </a>
                        <ul class="dropdown-menu">
                            <li><a href="<?php echo BASE_URL; ?>/pages/profile.php">Profile</a></li>
                            <?php if ($userType === 'admin'): ?>
                                <li><a href="<?php echo BASE_URL; ?>/pages/admin/dashboard.php">Admin Dashboard</a></li>
                                <li class="divider"></li>
                            <?php endif; ?>
                            <li><a href="<?php echo BASE_URL; ?>/pages/auth/logout.php">Logout</a></li>
                        </ul>
                    </li>
                <?php else: ?>
                    <li><a href="<?php echo BASE_URL; ?>/pages/auth/login.php">Login</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/pages/auth/register.php" class="btn btn-outline">Register</a></li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>

<!-- Mobile Menu Overlay -->
<div class="mobile-menu-overlay"></div>

<script>
// Mobile menu toggle functionality
document.addEventListener('DOMContentLoaded', function() {
    const toggleBtn = document.querySelector('.mobile-menu-toggle');
    const mobileMenu = document.querySelector('.nav-links');
    const overlay = document.querySelector('.mobile-menu-overlay');
    
    if (toggleBtn && mobileMenu && overlay) {
        toggleBtn.addEventListener('click', function() {
            mobileMenu.classList.toggle('active');
            overlay.classList.toggle('active');
            document.body.classList.toggle('no-scroll');
        });
        
        overlay.addEventListener('click', function() {
            mobileMenu.classList.remove('active');
            overlay.classList.remove('active');
            document.body.classList.remove('no-scroll');
        });
    }
    
    // Dropdown menu functionality
    const dropdownToggles = document.querySelectorAll('.dropdown-toggle');
    dropdownToggles.forEach(toggle => {
        toggle.addEventListener('click', function(e) {
            e.preventDefault();
            const dropdown = this.nextElementSibling;
            dropdown.classList.toggle('show');
            
            // Close other open dropdowns
            document.querySelectorAll('.dropdown-menu').forEach(menu => {
                if (menu !== dropdown) {
                    menu.classList.remove('show');
                }
            });
        });
    });
    
    // Close dropdowns when clicking outside
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.nav-dropdown')) {
            document.querySelectorAll('.dropdown-menu').forEach(menu => {
                menu.classList.remove('show');
            });
        }
    });
});
</script>