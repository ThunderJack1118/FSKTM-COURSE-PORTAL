<?php
require_once '../config/config.php';
require_once '../includes/functions.php';

if (!isLoggedIn()) {
    redirect('/pages/auth/login.php');
}

$db = new Database();
$conn = $db->getConnection();

// Initialize variables
$courses = [];
$students = [];
$selected_course = null;
$query = "";
$stmt = null;

// Handle grade update
if ((isLecturer() || isAdmin()) && isset($_POST['update_grade'])) {
    $registration_id = filter_input(INPUT_POST, 'registration_id', FILTER_SANITIZE_NUMBER_INT);
    $grade = filter_input(INPUT_POST, 'grade', FILTER_SANITIZE_STRING);
    $course_id = filter_input(INPUT_POST, 'course_id', FILTER_SANITIZE_NUMBER_INT);
    
    $query = "UPDATE registrations SET grade = ? WHERE registration_id = ?";
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("si", $grade, $registration_id);
        if ($stmt->execute()) {
            $_SESSION['success'] = "Grade updated successfully!";
        } else {
            $_SESSION['error'] = "Error updating grade: " . $stmt->error;
        }
        $stmt->close();
    }
    
    // Redirect to avoid form resubmission
    redirect("/pages/my-courses.php?course_id=" . $course_id);
}

// Handle status update - NEW FUNCTIONALITY
if ((isLecturer() || isAdmin()) && isset($_POST['update_status'])) {
    $registration_id = filter_input(INPUT_POST, 'registration_id', FILTER_SANITIZE_NUMBER_INT);
    $status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING);
    $course_id = filter_input(INPUT_POST, 'course_id', FILTER_SANITIZE_NUMBER_INT);
    
    // Validate status value
    $allowed_statuses = ['pending', 'approved', 'rejected', 'dropped', 'completed'];
    if (in_array($status, $allowed_statuses)) {
        $query = "UPDATE registrations SET status = ? WHERE registration_id = ?";
        $stmt = $conn->prepare($query);
        if ($stmt) {
            $stmt->bind_param("si", $status, $registration_id);
            if ($stmt->execute()) {
                $_SESSION['success'] = "Registration status updated successfully!";
            } else {
                $_SESSION['error'] = "Error updating status: " . $stmt->error;
            }
            $stmt->close();
        }
    } else {
        $_SESSION['error'] = "Invalid status value!";
    }
    
    // Redirect to avoid form resubmission
    redirect("/pages/my-courses.php?course_id=" . $course_id);
}

// Get user's registered courses or teaching courses based on user type
if (isStudent()) {
    $query = "SELECT r.*, c.course_code, c.course_name, c.credits, c.semester, c.academic_year, 
                     u.first_name, u.last_name, cc.category_name, cc.color_code
              FROM registrations r
              JOIN courses c ON r.course_id = c.course_id
              LEFT JOIN users u ON c.instructor_id = u.user_id
              LEFT JOIN course_categories cc ON c.category_id = cc.category_id
              WHERE r.user_id = ?
              ORDER BY c.semester, c.course_name";
    
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $courses = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
} elseif (isLecturer() || isAdmin()) {
    // Get teaching courses
    $query = "SELECT c.*, cc.category_name, cc.color_code
              FROM courses c
              LEFT JOIN course_categories cc ON c.category_id = cc.category_id
              WHERE c.instructor_id = ?
              ORDER BY c.semester, c.course_name";
    
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $courses = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

    // If a specific course is selected, get enrolled students
    if (isset($_GET['course_id'])) {
        $course_id = filter_input(INPUT_GET, 'course_id', FILTER_SANITIZE_NUMBER_INT);
        $query = "SELECT r.registration_id, r.status, r.grade, r.registration_date,
                         u.user_id, u.first_name, u.last_name, u.email, u.student_id
                  FROM registrations r
                  JOIN users u ON r.user_id = u.user_id
                  WHERE r.course_id = ?
                  ORDER BY u.last_name, u.first_name";
        
        $stmt = $conn->prepare($query);
        if ($stmt) {
            $stmt->bind_param("i", $course_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $students = $result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }

        // Get the selected course details
        foreach ($courses as $course) {
            if ($course['course_id'] == $course_id) {
                $selected_course = $course;
                break;
            }
        }
    }
}

$db->closeConnection();

$pageTitle = isStudent() ? "My Courses" : "My Teaching Courses";
include '../includes/header.php';
?>

<style>
    .student-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 15px;
        margin: 20px 0;
    }
    
    .student-table th, .student-table td {
        padding: 12px 15px;
        text-align: left;
        border-bottom: 1px solid #ddd;
        background-color: #f9f9f9;
    }
    
    .student-table th {
        background-color: #f2f2f2;
        font-weight: bold;
    }
    
    .student-table tr:hover td {
        background-color: #f1f1f1;
    }
    
    .table-responsive {
        overflow-x: auto;
        margin-bottom: 30px;
    }
    
    .btn-sm {
        padding: 5px 10px;
        font-size: 14px;
        margin: 2px;
        white-space: nowrap;
    }
    
    .student-list-container {
        margin-top: 30px;
        padding: 20px;
        background-color: #fff;
        border-radius: 5px;
        box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    }
    
    .grade-form, .status-form {
        display: inline-block;
        margin-right: 10px;
    }
    
    .grade-input {
        width: 60px;
        display: inline-block;
        margin-right: 5px;
        padding: 5px;
        border: 1px solid #ddd;
        border-radius: 3px;
    }
    
    .status-select {
        padding: 5px;
        border: 1px solid #ddd;
        border-radius: 3px;
        margin-right: 5px;
        font-size: 12px;
    }
    
    .action-buttons {
        display: flex;
        gap: 5px;
        flex-wrap: wrap;
        justify-content: flex-start;
    }
    
    .btn-sm i {
        margin-right: 3px;
        font-size: 12px;
    }
    
    .status-badge {
        padding: 3px 8px;
        border-radius: 3px;
        font-size: 12px;
        font-weight: bold;
    }
    
    .status-badge.pending {
        background-color: #fff3cd;
        color: #856404;
    }
    
    .status-badge.approved {
        background-color: #d4edda;
        color: #155724;
    }
    
    .status-badge.dropped {
        background-color: #e2e3e5;
        color: #383d41;
    }
    
    .status-badge.completed {
        background-color: #cce5ff;
        color: #004085;
    }
    
    .course-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 20px;
        margin-top: 20px;
    }
    
    .course-card {
        border: 1px solid #ddd;
        border-radius: 5px;
        overflow: hidden;
        transition: transform 0.3s ease;
    }
    
    .course-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    }
    
    .course-header {
        padding: 15px;
        color: white;
    }
    
    .course-body {
        padding: 15px;
    }
    
    .course-actions {
        padding: 10px 15px;
        background-color: #f9f9f9;
        border-top: 1px solid #ddd;
        display: flex;
        justify-content: space-between;
    }
    
    .semester-tabs {
        margin: 20px 0;
    }
    
    .tab-btn {
        padding: 8px 15px;
        margin-right: 5px;
        background-color: #f2f2f2;
        border: 1px solid #ddd;
        border-radius: 3px;
        cursor: pointer;
    }
    
    .tab-btn.active {
        background-color: #007bff;
        color: white;
        border-color: #007bff;
    }
    
    .status-container {
        display: flex;
        flex-direction: column;
        gap: 8px;
        align-items: flex-start;
    }
    
    .current-status {
        margin-bottom: 5px;
    }
    
    .status-actions {
        display: flex;
        gap: 5px;
        align-items: center;
    }
</style>

<div class="my-courses-container">
    <h1><?php echo $pageTitle; ?></h1>
    
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-error"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
    <?php endif; ?>
    
    <?php if (empty($courses)): ?>
        <div class="no-courses">
            <p>You are not currently <?php echo isStudent() ? 'registered for' : 'teaching'; ?> any courses.</p>
            <a href="<?php echo BASE_URL; ?>/pages/courses.php" class="btn btn-primary">Browse Courses</a>
        </div>
    <?php else: ?>
        <?php if (isLecturer() || isAdmin()): ?>
            <div class="semester-tabs">
                <button class="tab-btn active" onclick="filterCourses('all')">All</button>
                <button class="tab-btn" onclick="filterCourses('1')">Semester 1</button>
                <button class="tab-btn" onclick="filterCourses('2')">Semester 2</button>
                <button class="tab-btn" onclick="filterCourses('3')">Semester 3</button>
            </div>
        <?php endif; ?>
        
        <div class="course-grid">
            <?php foreach ($courses as $course): ?>
                <div class="course-card" data-semester="<?php echo $course['semester']; ?>">
                    <div class="course-header" style="background-color: <?php echo $course['color_code'] ?? '#007bff'; ?>">
                        <h3><?php echo $course['course_code']; ?></h3>
                        <p><?php echo $course['course_name']; ?></p>
                    </div>
                    <div class="course-body">
                        <?php if (isStudent()): ?>
                            <p><strong>Instructor:</strong> <?php echo isset($course['first_name']) ? $course['first_name'] . ' ' . $course['last_name'] : 'Not assigned'; ?></p>
                        <?php endif; ?>
                        <p><strong>Semester:</strong> <?php echo $course['semester']; ?></p>
                        <p><strong>Academic Year:</strong> <?php echo $course['academic_year']; ?></p>
                        <p><strong>Credits:</strong> <?php echo $course['credits']; ?></p>
                        
                        <?php if (isStudent() && isset($course['status'])): ?>
                            <p><strong>Status:</strong> 
                                <span class="status-badge <?php echo strtolower($course['status']); ?>">
                                    <?php echo ucfirst($course['status']); ?>
                                </span>
                            </p>
                            <?php if (!empty($course['grade'])): ?>
                                <p><strong>Grade:</strong> <?php echo $course['grade']; ?></p>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <div class="course-actions">
                        <?php if (isStudent()): ?>
                            <a href="<?php echo BASE_URL; ?>/pages/course-details.php?id=<?php echo $course['course_id']; ?>" class="btn btn-secondary">View</a>
                            <?php if (isset($course['status']) && $course['status'] === 'pending'): ?>
                                <a href="<?php echo BASE_URL; ?>/api/registration-api.php?action=cancel&id=<?php echo $course['registration_id']; ?>" class="btn btn-danger" onclick="return confirm('Are you sure you want to cancel this registration?')">Cancel</a>
                            <?php endif; ?>
                        <?php else: ?>
                            <a href="<?php echo BASE_URL; ?>/pages/my-courses.php?course_id=<?php echo $course['course_id']; ?>" class="btn btn-secondary">View Students</a>
                            <!-- Add this button for course details -->
                            <a href="<?php echo BASE_URL; ?>/pages/course-details.php?id=<?php echo $course['course_id']; ?>" class="btn btn-info">Course Details</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ((isLecturer() || isAdmin()) && $selected_course): ?>
            <div class="student-list-container">
                <h2>Students Enrolled in <?php echo $selected_course['course_code'] . ' - ' . $selected_course['course_name']; ?></h2>
                
                <?php if (empty($students)): ?>
                    <p>No students are currently enrolled in this course.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="student-table">
                            <thead>
                                <tr>
                                    <th>Student ID</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Status</th>
                                    <th>Grade</th>
                                    <th>Registration Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($students as $student): ?>
                                    <tr>
                                        <td><?php echo $student['student_id']; ?></td>
                                        <td><?php echo $student['first_name'] . ' ' . $student['last_name']; ?></td>
                                        <td><?php echo $student['email']; ?></td>
                                        <td>
                                            <div class="status-container">
                                                <div class="current-status">
                                                    <span class="status-badge <?php echo strtolower($student['status']); ?>">
                                                        <?php echo ucfirst($student['status']); ?>
                                                    </span>
                                                </div>
                                                <div class="status-actions">
                                                    <form class="status-form" method="POST" action="" style="display: inline;">
                                                        <input type="hidden" name="registration_id" value="<?php echo $student['registration_id']; ?>">
                                                        <input type="hidden" name="course_id" value="<?php echo $selected_course['course_id']; ?>">
                                                        <select name="status" class="status-select" onchange="confirmStatusChange(this)">
                                                            <option value="" disabled selected>Change Status</option>
                                                            <option value="pending">Pending</option>
                                                            <option value="approved">Approved</option>
                                                            <option value="rejected">Rejected</option>
                                                            <option value="dropped">Dropped</option>
                                                            <option value="completed">Completed</option>
                                                        </select>
                                                        <button type="submit" name="update_status" class="btn btn-sm btn-primary" style="display: none;" id="status-btn-<?php echo $student['registration_id']; ?>">Update</button>
                                                    </form>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <form class="grade-form" method="POST" action="">
                                                <input type="hidden" name="registration_id" value="<?php echo $student['registration_id']; ?>">
                                                <input type="hidden" name="course_id" value="<?php echo $selected_course['course_id']; ?>">
                                                <input type="text" name="grade" class="grade-input" value="<?php echo !empty($student['grade']) ? $student['grade'] : ''; ?>" placeholder="Grade">
                                                <button type="submit" name="update_grade" class="btn btn-sm btn-warning">Update</button>
                                            </form>
                                        </td>
                                        <td><?php echo date('M d, Y', strtotime($student['registration_date'])); ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="<?php echo BASE_URL; ?>/pages/student/student-profile.php?id=<?php echo $student['user_id']; ?>" 
                                                   class="btn btn-sm btn-info" 
                                                   title="View <?php echo $student['first_name']; ?>'s Profile">
                                                    <i class="fa fa-user"></i> Profile
                                                </a>
                                                <a href="mailto:<?php echo $student['email']; ?>" 
                                                   class="btn btn-sm btn-secondary" 
                                                   title="Email <?php echo $student['first_name']; ?>">
                                                    <i class="fa fa-envelope"></i> Email
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
function filterCourses(semester) {
    const courseCards = document.querySelectorAll('.course-card');
    const tabBtns = document.querySelectorAll('.tab-btn');
    
    // Update active tab
    tabBtns.forEach(btn => {
        btn.classList.remove('active');
        if (btn.textContent.includes(semester) || (semester === 'all' && btn.textContent === 'All')) {
            btn.classList.add('active');
        }
    });
    
    // Filter courses
    courseCards.forEach(card => {
        if (semester === 'all' || card.dataset.semester === semester) {
            card.style.display = 'block';
        } else {
            card.style.display = 'none';
        }
    });
}

function confirmStatusChange(selectElement) {
    const newStatus = selectElement.value;
    const currentStatus = selectElement.closest('td').querySelector('.status-badge').textContent.toLowerCase();
    const studentName = selectElement.closest('tr').querySelector('td:nth-child(2)').textContent;
    
    if (newStatus && newStatus !== currentStatus) {
        const confirmMessage = `Are you sure you want to change ${studentName}'s status from "${currentStatus}" to "${newStatus}"?`;
        
        if (confirm(confirmMessage)) {
            // Show the update button and highlight it
            const registrationId = selectElement.closest('form').querySelector('input[name="registration_id"]').value;
            const updateBtn = document.getElementById('status-btn-' + registrationId);
            updateBtn.style.display = 'inline-block';
            updateBtn.classList.add('btn-warning');
            updateBtn.textContent = 'Confirm Change';
            
            // Alternatively, you can auto-submit here:
            // selectElement.form.submit();
        } else {
            // Reset the dropdown to default if cancelled
            selectElement.selectedIndex = 0;
        }
    }
}
</script>

<?php include '../includes/footer.php'; ?>