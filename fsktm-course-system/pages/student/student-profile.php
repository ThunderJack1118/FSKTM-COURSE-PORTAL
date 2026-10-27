<?php
require_once '../../config/config.php';
require_once '../../includes/functions.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isLoggedIn()) {
    redirect('/pages/auth/login.php');
}

// Only allow lecturers and admins to view student profiles
if (!isLecturer() && !isAdmin()) {
    $_SESSION['error'] = "Access denied. You don't have permission to view student profiles.";
    redirect('/pages/dashboard.php');
}

$db = new Database();
$conn = $db->getConnection();

$student_id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);

if (!$student_id) {
    $_SESSION['error'] = "Invalid student ID.";
    redirect('/pages/my-courses.php');
}

// Get student information
$query = "SELECT 
            u.user_id, u.username, u.email, u.first_name, u.last_name,
            u.profile_picture, u.phone, u.date_of_birth, u.student_id,
            u.address, u.department, u.created_at, u.is_active
          FROM users u
          WHERE u.user_id = ? AND u.user_type = 'student'";

$stmt = $conn->prepare($query);
if (!$stmt) {
    $_SESSION['error'] = "Database error: " . $conn->error;
    redirect('/pages/my-courses.php');
}

$stmt->bind_param("i", $student_id);
if (!$stmt->execute()) {
    $_SESSION['error'] = "Execution error: " . $stmt->error;
    redirect('/pages/my-courses.php');
}

$result = $stmt->get_result();
$student = $result->fetch_assoc();
$stmt->close();

if (!$student) {
    $_SESSION['error'] = "Student not found or not a student account.";
    redirect('/pages/my-courses.php');
}

// Get student's course registrations
$query = "SELECT 
            r.*, c.course_code, c.course_name, c.credits, c.semester, c.academic_year,
            cc.category_name, cc.color_code,
            CONCAT(u.first_name, ' ', u.last_name) AS lecturer_name
          FROM registrations r
          JOIN courses c ON r.course_id = c.course_id
          LEFT JOIN course_categories cc ON c.category_id = cc.category_id
          LEFT JOIN users u ON c.instructor_id = u.user_id
          WHERE r.user_id = ?
          ORDER BY c.academic_year DESC, c.semester DESC, c.course_name";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$result = $stmt->get_result();
$courses = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Calculate statistics
$total_courses = count($courses);
$completed_courses = count(array_filter($courses, function($course) {
    return $course['status'] === 'completed';
}));
$approved_courses = count(array_filter($courses, function($course) {
    return $course['status'] === 'approved';
}));
$total_credits = array_sum(array_column($courses, 'credits'));

// Calculate GPA
$graded_courses = array_filter($courses, function($course) {
    return !empty($course['grade']);
});

$gpa = 0;
if (!empty($graded_courses)) {
    $grade_points = [
        'A+' => 4.0, 'A' => 4.0, 'A-' => 3.7,
        'B+' => 3.3, 'B' => 3.0, 'B-' => 2.7,
        'C+' => 2.3, 'C' => 2.0, 'C-' => 1.7,
        'D+' => 1.3, 'D' => 1.0, 'F' => 0.0
    ];
    
    $total_points = 0;
    $total_graded_credits = 0;
    
    foreach ($graded_courses as $course) {
        if (isset($grade_points[$course['grade']])) {
            $total_points += $grade_points[$course['grade']] * $course['credits'];
            $total_graded_credits += $course['credits'];
        }
    }
    
    if ($total_graded_credits > 0) {
        $gpa = $total_points / $total_graded_credits;
    }
}

// Set up profile picture paths
$default_pic = 'default-profile.jpg'; // Default image filename
$upload_dir = '/fsktm-course-system/assets/uploads/profile-pics/'; // Web-accessible path
$image_dir = $_SERVER['DOCUMENT_ROOT'] . $upload_dir; // Server filesystem path

// Determine which image to display
if (!empty($student['profile_picture'])) {
    $profile_pic = $student['profile_picture'];
    
    // Check if file exists and is readable
    if (file_exists($image_dir . $profile_pic) && is_readable($image_dir . $profile_pic)) {
        $display_pic = $upload_dir . $profile_pic;
    } else {
        // Fallback to default if custom image is missing
        $display_pic = '/fsktm-course-system/assets/images/' . $default_pic;
        error_log("Profile image missing: " . $image_dir . $profile_pic);
    }
} else {
    // Use default if no profile picture set
    $display_pic = '/fsktm-course-system/assets/images/' . $default_pic;
}

$db->closeConnection();

$pageTitle = "Student Profile - " . $student['first_name'] . " " . $student['last_name'];
include '../../includes/header.php';
?>

<style>
    .profile-container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 20px;
    }
    
    .profile-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 30px;
        border-radius: 10px;
        margin-bottom: 30px;
        position: relative;
        overflow: hidden;
    }
    
    .profile-content {
        display: flex;
        align-items: center;
        gap: 30px;
        flex-wrap: wrap;
    }
    
    .profile-avatar {
        width: 120px;
        height: 120px;
        border-radius: 50%;
        border: 4px solid rgba(255,255,255,0.3);
        object-fit: cover;
    }
    
    .profile-info h1 {
        margin: 0 0 10px 0;
        font-size: 2.5em;
    }
    
    .profile-info p {
        margin: 5px 0;
    }
    
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }
    
    .stat-card {
        background: white;
        padding: 20px;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    
    .stat-number {
        font-size: 2em;
        font-weight: bold;
        color: #667eea;
    }
    
    .course-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 20px;
    }
    
    .course-table th, .course-table td {
        padding: 12px 15px;
        border-bottom: 1px solid #ddd;
    }
    
    .course-table th {
        background-color: #f2f2f2;
        text-align: left;
    }
    
    .status-badge {
        padding: 3px 8px;
        border-radius: 4px;
        font-size: 12px;
        font-weight: bold;
    }
    
    .pending { background-color: #fff3cd; color: #856404; }
    .approved { background-color: #d4edda; color: #155724; }
    .rejected { background-color: #f8d7da; color: #721c24; }
    .dropped { background-color: #e2e3e5; color: #383d41; }
    .completed { background-color: #cce5ff; color: #004085; }

    .profile-avatar {
        transition: all 0.3s ease;
    }
    .profile-avatar:hover {
        transform: scale(1.05);
        box-shadow: 0 5px 15px rgba(0,0,0,0.2);
    }
    .status-badge {
        padding: 3px 8px;
        border-radius: 4px;
        font-size: 12px;
        font-weight: bold;
    }
    .status-badge.active {
        background-color: #d4edda;
        color: #155724;
    }
    .status-badge.inactive {
        background-color: #f8d7da;
        color: #721c24;
    }
    .profile-picture-container {
        position: relative;
        display: inline-block;
    }
</style>

<div class="profile-container">
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
    <?php endif; ?>
    
    <div class="profile-header">
        <div class="profile-content">
            <!-- Profile Picture Display -->
            <div class="profile-picture-container">
                <img src="<?php echo htmlspecialchars($display_pic); ?>" 
                    class="profile-avatar"
                    alt="Profile picture of <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>"
                    onerror="this.onerror=null;this.src='<?php echo '/fsktm-course-system/assets/images/default-profile.jpg' . $default_pic; ?>'"
                    style="width: 150px; height: 150px; border-radius: 50%; object-fit: cover; border: 3px solid #fff; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
                
                <!-- Optional: Add upload form for admins -->
                <?php if (isAdmin()): ?>
                <div class="profile-picture-upload" style="margin-top: 15px;">
                    <form action="/admin/update-profile-pic.php" method="post" enctype="multipart/form-data">
                        <input type="hidden" name="student_id" value="<?php echo $student['user_id']; ?>">
                        <input type="file" name="profile_picture" accept="image/*" style="display: none;" id="picUpload">
                        <label for="picUpload" class="btn btn-small">Change Picture</label>
                        <button type="submit" class="btn btn-small btn-primary">Upload</button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Student Information -->
            <div class="profile-info">
                <h1><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></h1>
                <p><strong>Student ID:</strong> <?php echo htmlspecialchars($student['student_id']); ?></p>
                <p><strong>Email:</strong> <?php echo htmlspecialchars($student['email']); ?></p>
                <p><strong>Status:</strong> 
                    <span class="status-badge <?php echo $student['is_active'] ? 'active' : 'inactive'; ?>">
                        <?php echo $student['is_active'] ? 'Active' : 'Inactive'; ?>
                    </span>
                </p>
            </div>
        </div>
    </div>
    
    <div class="stats-grid">
        <div class="stat-card">
            <span class="stat-number"><?php echo $total_courses; ?></span>
            <p>Total Courses</p>
        </div>
        <div class="stat-card">
            <span class="stat-number"><?php echo $completed_courses; ?></span>
            <p>Completed Courses</p>
        </div>
        <div class="stat-card">
            <span class="stat-number"><?php echo $approved_courses; ?></span>
            <p>Approved Courses</p>
        </div>
        <div class="stat-card">
            <span class="stat-number"><?php echo number_format($gpa, 2); ?></span>
            <p>Current GPA</p>
        </div>
    </div>
    
    <h2>Contact Information</h2>
    <div class="contact-info">
        <p><strong>Email:</strong> <?php echo htmlspecialchars($student['email']); ?></p>
        <p><strong>Phone:</strong> <?php echo htmlspecialchars($student['phone'] ?? 'Not provided'); ?></p>
        <p><strong>Address:</strong> <?php echo htmlspecialchars($student['address'] ?? 'Not provided'); ?></p>
        <p><strong>Date of Birth:</strong> <?php echo !empty($student['date_of_birth']) ? date('F j, Y', strtotime($student['date_of_birth'])) : 'Not provided'; ?></p>
    </div>
    
    <h2>Course History</h2>
    <?php if (!empty($courses)): ?>
        <div class="table-responsive">
            <table class="course-table">
                <thead>
                    <tr>
                        <th>Course Code</th>
                        <th>Course Name</th>
                        <th>Semester</th>
                        <th>Year</th>
                        <th>Lecturer</th>
                        <th>Status</th>
                        <th>Grade</th>
                        <th>Credits</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($courses as $course): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($course['course_code']); ?></td>
                            <td><?php echo htmlspecialchars($course['course_name']); ?></td>
                            <td><?php echo htmlspecialchars($course['semester']); ?></td>
                            <td><?php echo htmlspecialchars($course['academic_year']); ?></td>
                            <td><?php echo htmlspecialchars($course['lecturer_name'] ?? 'Not assigned'); ?></td>
                            <td>
                                <span class="status-badge <?php echo strtolower($course['status']); ?>">
                                    <?php echo ucfirst($course['status']); ?>
                                </span>
                            </td>
                            <td><?php echo !empty($course['grade']) ? $course['grade'] : 'N/A'; ?></td>
                            <td><?php echo $course['credits']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p>No course registrations found for this student.</p>
    <?php endif; ?>
</div>

<?php include '../../includes/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Preview image before upload (for admin upload form)
    const picUpload = document.getElementById('picUpload');
    if (picUpload) {
        picUpload.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file && file.type.match('image.*')) {
                const reader = new FileReader();
                reader.onload = function(event) {
                    document.querySelector('.profile-avatar').src = event.target.result;
                };
                reader.readAsDataURL(file);
            }
        });
    }
});
</script>