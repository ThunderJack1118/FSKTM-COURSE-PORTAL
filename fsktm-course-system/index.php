<?php
require_once 'config/config.php';

$pageTitle = "FSKTM Course Management System";
include 'includes/header.php';
require_once 'includes/functions.php';

// Get featured courses (limit to 6)
$featuredCourses = getCourses(6);

?>

<section class="hero">
    <div class="hero-content">
        <h1>Welcome to FSKTM Course Management</h1>
        <p>Explore our wide range of computer science and IT courses designed to advance your career.</p>
        <a href="<?php echo BASE_URL; ?>/pages/courses.php" class="btn btn-primary">Browse Courses</a>
    </div>
</section>

<section class="featured-courses">
    <h2>Featured Courses</h2>
    <div class="course-grid">
        <?php foreach ($featuredCourses as $course): ?>
            <div class="course-card">
                <div class="course-image" style="background-color: <?php echo $course['color_code'] ?? '#007bff'; ?>">
                    <?php if (!empty($course['course_image'])): ?>
                        <img src="<?php echo BASE_URL; ?>/assets/images/courses/<?php echo $course['course_image']; ?>" alt="<?php echo $course['course_name']; ?>">
                    <?php endif; ?>
                </div>
                <div class="course-details">
                    <h3><?php echo $course['course_name']; ?></h3>
                    <p class="course-code"><?php echo $course['course_code']; ?></p>
                    <p class="instructor"><?php echo $course['first_name'] . ' ' . $course['last_name']; ?></p>
                    <div class="course-meta">
                        <span><?php echo $course['credits']; ?> Credits</span>
                        <span>Semester <?php echo $course['semester']; ?></span>
                    </div>
                    <a href="<?php echo BASE_URL; ?>/pages/course-details.php?id=<?php echo $course['course_id']; ?>" class="btn btn-secondary">View Details</a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="view-all">
        <a href="<?php echo BASE_URL; ?>/pages/courses.php" class="btn btn-outline">View All Courses</a>
    </div>
</section>

<section class="features">
    <div class="feature">
        <i class="fas fa-graduation-cap"></i>
        <h3>Quality Education</h3>
        <p>Learn from industry experts and experienced faculty members.</p>
    </div>
    <div class="feature">
        <i class="fas fa-laptop-code"></i>
        <h3>Hands-on Learning</h3>
        <p>Practical projects and assignments to enhance your skills.</p>
    </div>
    <div class="feature">
        <i class="fas fa-certificate"></i>
        <h3>Certification</h3>
        <p>Earn recognized certificates upon course completion.</p>
    </div>
</section>


<?php include 'includes/footer.php'; ?>