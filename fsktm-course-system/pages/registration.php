<?php
require_once '../config/config.php';
require_once '../includes/functions.php';

if (!isLoggedIn()) {
    redirect('/pages/auth/login.php');
}

if (!isStudent()) {
    redirect('/pages/courses.php');
}

if (!isset($_GET['course_id'])) {
    redirect('/pages/courses.php');
}

$course_id = (int)$_GET['course_id'];
$db = new Database();
$conn = $db->getConnection();

// Check if already registered
$stmt = $conn->prepare("SELECT * FROM registrations WHERE user_id = ? AND course_id = ?");
$stmt->bind_param("ii", $_SESSION['user_id'], $course_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $stmt->close();
    $db->closeConnection();
    redirect('/pages/my-courses.php');
}

// Get course details
$stmt = $conn->prepare("SELECT c.*, u.first_name, u.last_name FROM courses c 
                        LEFT JOIN users u ON c.instructor_id = u.user_id
                        WHERE c.course_id = ? AND c.is_active = TRUE");
$stmt->bind_param("i", $course_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $stmt->close();
    $db->closeConnection();
    redirect('/pages/courses.php');
}

$course = $result->fetch_assoc();
$stmt->close();

// Check if course has available slots
if ($course['current_enrolled'] >= $course['max_students']) {
    $db->closeConnection();
    $_SESSION['error'] = "This course has reached its maximum capacity.";
    redirect('/pages/course-details.php?id=' . $course_id);
}

// Handle registration submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $conn->prepare("INSERT INTO registrations (user_id, course_id, status) VALUES (?, ?, 'pending')");
    $stmt->bind_param("ii", $_SESSION['user_id'], $course_id);
    
    if ($stmt->execute()) {
        // Update enrolled count
        $update_stmt = $conn->prepare("UPDATE courses SET current_enrolled = current_enrolled + 1 WHERE course_id = ?");
        $update_stmt->bind_param("i", $course_id);
        $update_stmt->execute();
        $update_stmt->close();
        
        $_SESSION['success'] = "You have successfully registered for this course. Waiting for approval.";
        redirect('/pages/my-courses.php');
    } else {
        $error = "Registration failed. Please try again.";
    }
    
    $stmt->close();
}

$db->closeConnection();

$pageTitle = "Register for " . $course['course_name'];
include '../includes/header.php';
?>

<div class="registration-container">
    <h1>Course Registration</h1>
    
    <?php if (isset($error)): ?>
        <div class="alert alert-error"><?php echo $error; ?></div>
    <?php endif; ?>
    
    <div class="course-info">
        <h2><?php echo $course['course_name']; ?></h2>
        <p><strong>Course Code:</strong> <?php echo $course['course_code']; ?></p>
        <p><strong>Instructor:</strong> <?php echo $course['first_name'] . ' ' . $course['last_name']; ?></p>
        <p><strong>Credits:</strong> <?php echo $course['credits']; ?></p>
        <p><strong>Semester:</strong> <?php echo $course['semester']; ?></p>
        <p><strong>Schedule:</strong> <?php echo $course['schedule_day'] . ' ' . $course['schedule_time']; ?></p>
        <p><strong>Classroom:</strong> <?php echo $course['classroom']; ?></p>
        <p><strong>Available Slots:</strong> <?php echo $course['max_students'] - $course['current_enrolled']; ?></p>
    </div>
    
   <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'] . '?course_id=' . $course_id); ?>" method="post">
        <div class="form-group">
            <label>
                <input type="checkbox" name="confirm" required>
                I confirm that I want to register for this course.
            </label>
        </div>
        
        <div class="form-actions">
            <a href="<?php echo BASE_URL; ?>/pages/course-details.php?id=<?php echo $course_id; ?>" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Submit Registration</button>
        </div>
    </form>
</div>

<?php include '../includes/footer.php'; ?>