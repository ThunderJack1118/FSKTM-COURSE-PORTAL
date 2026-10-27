<?php
require_once '../config/config.php';
require_once '../includes/functions.php';

if (!isLoggedIn()) {
    redirect('/pages/auth/login.php');
}

$category_id = isset($_GET['category']) ? (int)$_GET['category'] : null;
$search = isset($_GET['search']) ? sanitizeInput($_GET['search']) : null;

$categories = getCourseCategories();
// Add true as the last parameter to exclude registered courses for students
$courses = getCourses(null, $category_id, $search, true);

$pageTitle = "Available Courses";
include '../includes/header.php';
?>

<div class="courses-header">
    <h1><?php echo $pageTitle; ?></h1>
    
    <form class="course-search" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="get">
        <div class="search-bar">
            <input type="text" name="search" placeholder="Search courses..." value="<?php echo $search ?? ''; ?>">
            <button type="submit"><i class="fas fa-search"></i></button>
        </div>
        
        <div class="category-filter">
            <label for="category">Filter by Category:</label>
            <select name="category" id="category" onchange="this.form.submit()">
                <option value="">All Categories</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?php echo $category['category_id']; ?>" <?php echo ($category_id == $category['category_id']) ? 'selected' : ''; ?>>
                        <?php echo $category['category_name']; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
</div>

<div class="course-list">
    <?php if (empty($courses)): ?>
        <div class="no-results">
            <p>No courses found matching your criteria.</p>
            <?php if (isStudent() && (isset($search) || isset($category_id))): ?>
                <p>You may have already registered for all courses matching your search.</p>
            <?php endif; ?>
            <a href="<?php echo BASE_URL; ?>/pages/courses.php" class="btn btn-primary">Reset Filters</a>
        </div>
    <?php else: ?>
        <?php foreach ($courses as $course): ?>
            <div class="course-item">
                <div class="course-image">
                    <?php if (!empty($course['course_image'])): ?>
                        <img src="<?php echo BASE_URL; ?>/assets/images/courses/<?php echo $course['course_image']; ?>" alt="<?php echo $course['course_name']; ?>">
                    <?php else: ?>
                        <div class="default-image" style="background-color: <?php echo $course['color_code'] ?? '#007bff'; ?>">
                            <i class="fas fa-book-open"></i>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="course-info">
                    <h3><?php echo $course['course_name']; ?></h3>
                    <p class="course-code"><?php echo $course['course_code']; ?></p>
                    <p class="instructor">Instructor: <?php echo $course['first_name'] . ' ' . $course['last_name']; ?></p>
                    <p class="description"><?php echo substr($course['description'], 0, 150); ?>...</p>
                    
                    <div class="course-meta">
                        <span><i class="fas fa-credit-card"></i> <?php echo $course['credits']; ?> Credits</span>
                        <span><i class="fas fa-calendar-alt"></i> Semester <?php echo $course['semester']; ?></span>
                        <span><i class="fas fa-users"></i> <?php echo $course['current_enrolled']; ?>/<?php echo $course['max_students']; ?> Students</span>
                    </div>
                    
                    <div class="course-actions">
                        <a href="<?php echo BASE_URL; ?>/pages/course-details.php?id=<?php echo $course['course_id']; ?>" class="btn btn-secondary">View Details</a>
                        <?php if (isStudent()): ?>
                            <?php if ($course['current_enrolled'] < $course['max_students']): ?>
                                <a href="<?php echo BASE_URL; ?>/pages/registration.php?course_id=<?php echo $course['course_id']; ?>" class="btn btn-primary">Register</a>
                            <?php else: ?>
                                <span class="badge badge-danger">Full</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php include '../includes/footer.php'; ?>