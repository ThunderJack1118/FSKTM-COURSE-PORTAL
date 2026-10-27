<?php
// course-api.php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn() || !isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

$db = new Database();
$conn = $db->getConnection();

try {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'add':
            handleAddCourse($conn);
            break;
            
        case 'update':
            handleUpdateCourse($conn);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
} finally {
    $db->closeConnection();
}

function handleAddCourse($conn) {
    $required = ['course_code', 'course_name', 'credits', 'semester', 'academic_year', 'max_students'];
    foreach ($required as $field) {
        if (empty($_POST[$field])) {
            echo json_encode(['success' => false, 'message' => "$field is required"]);
            exit;
        }
    }
    
    $course_code = normalizeUppercaseText($_POST['course_code'], 10);
    $course_name = normalizeUppercaseText($_POST['course_name'], 100);
    $description = normalizeUppercaseText($_POST['description'] ?? '');
    $credits = (int)$_POST['credits'];
    $semester = sanitizeInput($_POST['semester']);
    $academic_year = normalizeUppercaseText($_POST['academic_year'], 9);
    $max_students = (int)$_POST['max_students'];
    $instructor_id = !empty($_POST['instructor_id']) ? (int)$_POST['instructor_id'] : null;
    $schedule_day = normalizeUppercaseText($_POST['schedule_day'] ?? '', 20);
    $schedule_time = normalizeUppercaseText($_POST['schedule_time'] ?? '', 50);
    $classroom = normalizeUppercaseText($_POST['classroom'] ?? '', 20);
    $prerequisites = normalizeUppercaseText($_POST['prerequisites'] ?? '');
    $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    // Check if course code exists
    $stmt = $conn->prepare("SELECT course_id FROM courses WHERE course_code = ?");
    $stmt->bind_param("s", $course_code);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'Course code already exists']);
        exit;
    }
    $stmt->close();
    
    // Insert new course
    $stmt = $conn->prepare("INSERT INTO courses (
        course_code, course_name, description, credits, semester, academic_year,
        max_students, instructor_id, schedule_day, schedule_time, classroom,
        prerequisites, category_id, is_active, created_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    
    $stmt->bind_param("sssissiissssii", 
        $course_code, $course_name, $description, $credits, $semester, $academic_year,
        $max_students, $instructor_id, $schedule_day, $schedule_time, $classroom,
        $prerequisites, $category_id, $is_active
    );
    
    if ($stmt->execute()) {
        $newCourseId = (int)$conn->insert_id;
        logAuditEvent((int)$_SESSION['user_id'], 'admin_course_add', 'Administrator added a new course.', [
            'course_id' => $newCourseId,
            'course_code' => $course_code,
            'course_name' => $course_name
        ]);
        echo json_encode(['success' => true, 'message' => 'Course added successfully']);
    } else {
        throw new Exception('Failed to add course: ' . $stmt->error);
    }
    $stmt->close();
}

function handleUpdateCourse($conn) {
    if (empty($_POST['course_id'])) {
        echo json_encode(['success' => false, 'message' => 'Course ID is required']);
        exit;
    }
    
    $required = ['course_code', 'course_name', 'credits', 'semester', 'academic_year', 'max_students'];
    foreach ($required as $field) {
        if (empty($_POST[$field])) {
            echo json_encode(['success' => false, 'message' => "$field is required"]);
            exit;
        }
    }
    
    $course_id = (int)$_POST['course_id'];
    $course_code = normalizeUppercaseText($_POST['course_code'], 10);
    $course_name = normalizeUppercaseText($_POST['course_name'], 100);
    $description = normalizeUppercaseText($_POST['description'] ?? '');
    $credits = (int)$_POST['credits'];
    $semester = sanitizeInput($_POST['semester']);
    $academic_year = normalizeUppercaseText($_POST['academic_year'], 9);
    $max_students = (int)$_POST['max_students'];
    $instructor_id = !empty($_POST['instructor_id']) ? (int)$_POST['instructor_id'] : null;
    $schedule_day = normalizeUppercaseText($_POST['schedule_day'] ?? '', 20);
    $schedule_time = normalizeUppercaseText($_POST['schedule_time'] ?? '', 50);
    $classroom = normalizeUppercaseText($_POST['classroom'] ?? '', 20);
    $prerequisites = normalizeUppercaseText($_POST['prerequisites'] ?? '');
    $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    // Check if course code exists for another course
    $stmt = $conn->prepare("SELECT course_id FROM courses WHERE course_code = ? AND course_id != ?");
    $stmt->bind_param("si", $course_code, $course_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'Course code already exists for another course']);
        exit;
    }
    $stmt->close();
    
    // Update course
    $stmt = $conn->prepare("UPDATE courses SET 
        course_code = ?, course_name = ?, description = ?, credits = ?, semester = ?, 
        academic_year = ?, max_students = ?, instructor_id = ?, schedule_day = ?, 
        schedule_time = ?, classroom = ?, prerequisites = ?, category_id = ?, is_active = ?,
        updated_at = NOW()
        WHERE course_id = ?");
    
    $stmt->bind_param("sssissiissssiii", 
        $course_code, $course_name, $description, $credits, $semester, $academic_year,
        $max_students, $instructor_id, $schedule_day, $schedule_time, $classroom,
        $prerequisites, $category_id, $is_active, $course_id
    );
    
    if ($stmt->execute()) {
        logAuditEvent((int)$_SESSION['user_id'], 'admin_course_update', 'Administrator updated a course.', [
            'course_id' => $course_id,
            'course_code' => $course_code,
            'course_name' => $course_name
        ]);
        echo json_encode(['success' => true, 'message' => 'Course updated successfully']);
    } else {
        throw new Exception('Failed to update course: ' . $stmt->error);
    }
    $stmt->close();
}
?>