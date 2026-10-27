<?php
require_once '../config/config.php';
require_once '../includes/functions.php';

if (!isLoggedIn() || !isLecturer()) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$db = new Database();
$conn = $db->getConnection();

$response = ['success' => false, 'message' => ''];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'delete') {
            $resource_id = (int)($_POST['resource_id'] ?? 0);
            
            // First get the file path
            $stmt = $conn->prepare("SELECT file_path FROM course_resources WHERE resource_id = ?");
            $stmt->bind_param("i", $resource_id);
            $stmt->execute();
            $resource = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if ($resource) {
                // Delete from database
                $stmt = $conn->prepare("DELETE FROM course_resources WHERE resource_id = ?");
                $stmt->bind_param("i", $resource_id);
                $success = $stmt->execute();
                $stmt->close();
                
                if ($success) {
                    // Delete the file
                    $file_path = '../uploads/resources/' . $resource['file_path'];
                    if (file_exists($file_path)) {
                        unlink($file_path);
                    }
                    $response = ['success' => true, 'message' => 'Resource deleted successfully'];
                } else {
                    $response['message'] = 'Database error';
                }
            } else {
                $response['message'] = 'Resource not found';
            }
        }
    }
} catch (Exception $e) {
    $response['message'] = 'Error: ' . $e->getMessage();
}

header('Content-Type: application/json');
echo json_encode($response);
?>