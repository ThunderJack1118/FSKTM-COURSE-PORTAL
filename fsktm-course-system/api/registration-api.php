<?php
require_once '../config/config.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

$db = new Database();
$conn = $db->getConnection();

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'cancel':
        if (!isStudent()) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
            exit;
        }
        
        $registration_id = (int)$_GET['id'];
        
        // Verify the registration belongs to the current user
        $stmt = $conn->prepare("SELECT r.registration_id, r.course_id, r.status 
                               FROM registrations r
                               JOIN users u ON r.user_id = u.user_id
                               WHERE r.registration_id = ? AND u.user_id = ?");
        $stmt->bind_param("ii", $registration_id, $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            $stmt->close();
            echo json_encode(['success' => false, 'message' => 'Registration not found']);
            exit;
        }
        
        $registration = $result->fetch_assoc();
        $stmt->close();
        
        // Only allow cancellation if status is pending
        if ($registration['status'] !== 'pending') {
            echo json_encode(['success' => false, 'message' => 'Only pending registrations can be cancelled']);
            exit;
        }
        
        // Delete the registration
        $stmt = $conn->prepare("DELETE FROM registrations WHERE registration_id = ?");
        $stmt->bind_param("i", $registration_id);
        
        if ($stmt->execute()) {
            // Update enrolled count in courses table
            $update_stmt = $conn->prepare("UPDATE courses SET current_enrolled = current_enrolled - 1 WHERE course_id = ?");
            $update_stmt->bind_param("i", $registration['course_id']);
            $update_stmt->execute();
            $update_stmt->close();
            
            echo json_encode(['success' => true, 'message' => 'Registration cancelled successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to cancel registration']);
        }
        
        $stmt->close();
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        break;
}

$db->closeConnection();
?>