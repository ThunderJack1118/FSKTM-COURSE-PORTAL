<?php
require_once '../config/config.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn() || !isLecturer()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

$db = new Database();
$conn = $db->getConnection();

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'create':
            handleCreateAssignment($conn);
            break;
            
        case 'update':
            handleUpdateAssignment($conn);
            break;
            
        case 'delete':
            handleDeleteAssignment($conn);
            break;
            
        case 'get':
            handleGetAssignment($conn);
            break;
            
        case 'list':
            handleListAssignments($conn);
            break;
            
        case 'stats':
            handleAssignmentStats($conn);
            break;
        
        case 'delete_file':
            handleDeleteFile($conn);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    error_log("Assignment API Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => ENVIRONMENT === 'production' 
            ? 'An error occurred' 
            : $e->getMessage()
    ]);
}

function handleCreateAssignment($conn) {
    // Start transaction
    $conn->begin_transaction();

    try {
        $required = ['course_id', 'title', 'due_date', 'max_marks'];
        foreach ($required as $field) {
            if (empty($_POST[$field])) {
                throw new Exception("Missing required field: $field");
            }
        }

        $course_id = (int)$_POST['course_id'];
        $title = sanitizeInput($_POST['title']);
        $description = sanitizeInput($_POST['description'] ?? '');
        $due_date = sanitizeInput($_POST['due_date']);
        $max_marks = (int)$_POST['max_marks'];

        // Verify lecturer owns the course
        $stmt = $conn->prepare("SELECT instructor_id FROM courses WHERE course_id = ?");
        $stmt->bind_param("i", $course_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        if (!$result || $result['instructor_id'] != $_SESSION['user_id']) {
            throw new Exception('Unauthorized: You don\'t teach this course');
        }

        $stmt = $conn->prepare("INSERT INTO assignments (course_id, title, description, due_date, max_marks) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("isssi", $course_id, $title, $description, $due_date, $max_marks);
        
        if (!$stmt->execute()) {
            throw new Exception('Failed to create assignment: ' . $conn->error);
        }

        $assignment_id = $stmt->insert_id;
        
        // Handle file uploads if any (only if files were actually uploaded)
        if (!empty($_FILES['assignment_files']['name'][0])) {
            handleFileUploads($conn, $assignment_id);
        }

        // Commit transaction if everything succeeded
        $conn->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Assignment created successfully',
            'assignment_id' => $assignment_id
        ]);
    } catch (Exception $e) {
        // Roll back on any error
        $conn->rollback();
        throw $e;
    }
}

function handleUpdateAssignment($conn) {
    // Start transaction
    $conn->begin_transaction();

    try {
        $required = ['assignment_id', 'title', 'due_date', 'max_marks'];
        foreach ($required as $field) {
            if (empty($_POST[$field])) {
                throw new Exception("Missing required field: $field");
            }
        }

        $assignment_id = (int)$_POST['assignment_id'];
        $title = sanitizeInput($_POST['title']);
        $description = sanitizeInput($_POST['description'] ?? '');
        $due_date = sanitizeInput($_POST['due_date']);
        $max_marks = (int)$_POST['max_marks'];

        // Verify assignment belongs to lecturer
        $stmt = $conn->prepare("
            SELECT c.instructor_id 
            FROM assignments a
            JOIN courses c ON a.course_id = c.course_id
            WHERE a.assignment_id = ?
        ");
        $stmt->bind_param("i", $assignment_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        if (!$result || $result['instructor_id'] != $_SESSION['user_id']) {
            throw new Exception('Unauthorized to update this assignment');
        }

        // Update assignment details
        $stmt = $conn->prepare("
            UPDATE assignments 
            SET title = ?, description = ?, due_date = ?, max_marks = ? 
            WHERE assignment_id = ?
        ");
        $stmt->bind_param("sssii", $title, $description, $due_date, $max_marks, $assignment_id);
        
        if (!$stmt->execute()) {
            throw new Exception('Failed to update assignment: ' . $conn->error);
        }

        // Handle file uploads if any files were uploaded
        if (!empty($_FILES['assignment_files']['name'][0])) {
            handleFileUploads($conn, $assignment_id);
        }

        // Handle file deletions if any files were marked for deletion
        if (!empty($_POST['delete_files'])) {
            $delete_files = json_decode($_POST['delete_files'], true);
            if (is_array($delete_files)) {
                foreach ($delete_files as $file_id) {
                    $file_id = (int)$file_id;
                    // Verify file belongs to this assignment
                    $stmt = $conn->prepare("
                        DELETE FROM assignment_files 
                        WHERE file_id = ? AND assignment_id = ?
                    ");
                    $stmt->bind_param("ii", $file_id, $assignment_id);
                    if (!$stmt->execute()) {
                        throw new Exception('Failed to delete file: ' . $conn->error);
                    }
                }
            }
        }

        // Commit transaction if everything succeeded
        $conn->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Assignment updated successfully',
            'assignment_id' => $assignment_id
        ]);
    } catch (Exception $e) {
        // Roll back on any error
        $conn->rollback();
        throw $e;
    }
}

function handleDeleteAssignment($conn) {
    if (empty($_POST['assignment_id'])) {
        throw new Exception("Missing assignment_id");
    }

    $assignment_id = (int)$_POST['assignment_id'];
    
    // Verify assignment belongs to lecturer's course
    $stmt = $conn->prepare("
        SELECT c.instructor_id, a.title
        FROM assignments a
        JOIN courses c ON a.course_id = c.course_id
        WHERE a.assignment_id = ?
    ");
    $stmt->bind_param("i", $assignment_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    if (!$result || $result['instructor_id'] != $_SESSION['user_id']) {
        throw new Exception('Unauthorized to delete this assignment');
    }

    // Begin transaction
    $conn->begin_transaction();
    
    try {
        // Delete related files first
        $stmt = $conn->prepare("DELETE FROM assignment_files WHERE assignment_id = ?");
        $stmt->bind_param("i", $assignment_id);
        $stmt->execute();
        
        // Delete submissions
        $stmt = $conn->prepare("DELETE FROM submissions WHERE assignment_id = ?");
        $stmt->bind_param("i", $assignment_id);
        $stmt->execute();
        
        // Finally delete the assignment
        $stmt = $conn->prepare("DELETE FROM assignments WHERE assignment_id = ?");
        $stmt->bind_param("i", $assignment_id);
        $stmt->execute();
        
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        throw new Exception('Failed to delete assignment: ' . $e->getMessage());
    }

    echo json_encode([
        'success' => true,
        'message' => "Assignment '{$result['title']}' deleted successfully"
    ]);
}

function handleGetAssignment($conn) {
    if (empty($_GET['assignment_id'])) {
        throw new Exception("Missing assignment_id");
    }

    $assignment_id = (int)$_GET['assignment_id'];
    
    $stmt = $conn->prepare("
        SELECT a.*, c.course_name, c.instructor_id
        FROM assignments a
        JOIN courses c ON a.course_id = c.course_id
        WHERE a.assignment_id = ?
    ");
    $stmt->bind_param("i", $assignment_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    if (!$result) {
        throw new Exception('Assignment not found');
    }

    if ($result['instructor_id'] != $_SESSION['user_id']) {
        throw new Exception('Unauthorized to view this assignment');
    }

    // Get attached files
    $stmt = $conn->prepare("SELECT * FROM assignment_files WHERE assignment_id = ?");
    $stmt->bind_param("i", $assignment_id);
    $stmt->execute();
    $files = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Format for response
    $result['due_date_formatted'] = date('Y-m-d\TH:i', strtotime($result['due_date']));
    $result['files'] = $files;
    $result['success'] = true;

    echo json_encode($result);
}

function handleListAssignments($conn) {
    $course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : null;
    
    $query = "
        SELECT a.*, c.course_name, 
        (SELECT COUNT(*) FROM submissions s WHERE s.assignment_id = a.assignment_id) as submission_count,
        (SELECT COUNT(*) FROM submissions s WHERE s.assignment_id = a.assignment_id AND s.marks IS NULL) as ungraded_count
        FROM assignments a
        JOIN courses c ON a.course_id = c.course_id
        WHERE c.instructor_id = ?
    ";
    
    if ($course_id) {
        $query .= " AND a.course_id = ?";
    }
    
    $query .= " ORDER BY a.due_date ASC";
    
    $stmt = $conn->prepare($query);
    
    if ($course_id) {
        $stmt->bind_param("ii", $_SESSION['user_id'], $course_id);
    } else {
        $stmt->bind_param("i", $_SESSION['user_id']);
    }
    
    $stmt->execute();
    $assignments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Add status information
    $now = time();
    foreach ($assignments as &$assignment) {
        $due_date = strtotime($assignment['due_date']);
        if ($due_date < $now) {
            $assignment['status'] = 'completed';
        } elseif (($due_date - $now) < (7 * 24 * 60 * 60)) {
            $assignment['status'] = 'due_soon';
        } else {
            $assignment['status'] = 'active';
        }
        
        // Add submission status
        if ($assignment['submission_count'] > 0) {
            if ($assignment['ungraded_count'] > 0) {
                $assignment['submission_status'] = 'pending_grading';
            } else {
                $assignment['submission_status'] = 'graded';
            }
        } else {
            $assignment['submission_status'] = 'no_submissions';
        }
    }
    
    echo json_encode([
        'success' => true,
        'data' => $assignments
    ]);
}

function handleAssignmentStats($conn) {
    $query = "
        SELECT 
            COUNT(*) as total_assignments,
            SUM(CASE WHEN due_date > NOW() THEN 1 ELSE 0 END) as active_assignments,
            (SELECT COUNT(*) FROM submissions s 
             JOIN assignments a ON s.assignment_id = a.assignment_id
             JOIN courses c ON a.course_id = c.course_id
             WHERE c.instructor_id = ? AND s.marks IS NULL) as submissions_pending
        FROM assignments a
        JOIN courses c ON a.course_id = c.course_id
        WHERE c.instructor_id = ?
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $_SESSION['user_id'], $_SESSION['user_id']);
    $stmt->execute();
    $stats = $stmt->get_result()->fetch_assoc();
    
    echo json_encode([
        'success' => true,
        'stats' => $stats
    ]);
}
function handleFileUploads($conn, $assignment_id) {
    $uploadDir = '../uploads/assignments/';
    
    // Create directory if it doesn't exist
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    // Process all uploaded files
    foreach ($_FILES['assignment_files']['tmp_name'] as $key => $tmp_name) {
        // Skip if no file was uploaded for this entry
        if (empty($_FILES['assignment_files']['name'][$key])) {
            continue;
        }

        $fileName = sanitizeFilename($_FILES['assignment_files']['name'][$key]);
        $fileSize = $_FILES['assignment_files']['size'][$key];
        $fileType = $_FILES['assignment_files']['type'][$key];
        $fileError = $_FILES['assignment_files']['error'][$key];
        
        // Validate file
        if ($fileError !== UPLOAD_ERR_OK) {
            throw new Exception("File upload error: $fileError");
        }
        
        if ($fileSize > 10 * 1024 * 1024) { // 10MB limit
            throw new Exception("File $fileName is too large (max 10MB)");
        }
        
        // Generate unique filename
        $fileExt = pathinfo($fileName, PATHINFO_EXTENSION);
        $uniqueName = uniqid() . '.' . $fileExt;
        $destination = $uploadDir . $uniqueName;
        
        if (!move_uploaded_file($tmp_name, $destination)) {
            throw new Exception("Failed to move uploaded file $fileName");
        }
        
        // Save to database
        $stmt = $conn->prepare("
            INSERT INTO assignment_files (assignment_id, file_name, file_path, file_size, file_type)
            VALUES (?, ?, ?, ?, ?)
        ");
        $cleanPath = str_replace('../uploads/', '', $destination);
        $stmt->bind_param("issis", $assignment_id, $fileName, $cleanPath, $fileSize, $fileType);
        
        if (!$stmt->execute()) {
            unlink($destination); // Clean up
            throw new Exception("Failed to save file info to database: " . $conn->error);
        }
    }
}


function handleDeleteFile($conn) {
    if (empty($_POST['file_id']) || empty($_POST['assignment_id'])) {
        throw new Exception("Missing file_id or assignment_id");
    }

    $file_id = (int)$_POST['file_id'];
    $assignment_id = (int)$_POST['assignment_id'];

    // Verify the file belongs to an assignment owned by the lecturer
    $stmt = $conn->prepare("
        SELECT af.file_id 
        FROM assignment_files af
        JOIN assignments a ON af.assignment_id = a.assignment_id
        JOIN courses c ON a.course_id = c.course_id
        WHERE af.file_id = ? AND af.assignment_id = ? AND c.instructor_id = ?
    ");
    $stmt->bind_param("iii", $file_id, $assignment_id, $_SESSION['user_id']);
    $stmt->execute();
    
    if (!$stmt->get_result()->fetch_assoc()) {
        throw new Exception('Unauthorized to delete this file');
    }

    // Get file path before deletion
    $stmt = $conn->prepare("SELECT file_path FROM assignment_files WHERE file_id = ?");
    $stmt->bind_param("i", $file_id);
    $stmt->execute();
    $file = $stmt->get_result()->fetch_assoc();
    
    // Delete from database
    $stmt = $conn->prepare("DELETE FROM assignment_files WHERE file_id = ?");
    $stmt->bind_param("i", $file_id);
    
    if (!$stmt->execute()) {
        throw new Exception('Failed to delete file record');
    }

    // Delete physical file
    $filePath = '../uploads/' . $file['file_path'];
    if (file_exists($filePath)) {
        unlink($filePath);
    }

    echo json_encode(['success' => true, 'message' => 'File deleted successfully']);
}
?>