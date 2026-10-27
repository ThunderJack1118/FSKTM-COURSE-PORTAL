//announcement-api.php
<?php
// Ensure no output before headers
if (ob_get_level()) ob_end_clean();

// Start session and set headers FIRST
session_start();
header('Content-Type: application/json');

// Then require files
require_once '../config/config.php';
require_once '../includes/functions.php';

// Initialize response
$response = ['success' => false, 'message' => ''];

try {
    // Verify POST request
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method', 405);
    }

    // Check authentication
    if (!isLoggedIn() || !isLecturer()) {
        throw new Exception('Unauthorized access', 403);
    }

    // Get input data
    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        parse_str(file_get_contents('php://input'), $input);
    }

    $action = $input['action'] ?? '';
    $announcement_id = (int)($input['announcement_id'] ?? 0);

    // Validate input
    if ($action !== 'delete' || $announcement_id <= 0) {
        throw new Exception('Invalid parameters', 400);
    }

    // Database operations
    $db = new Database();
    $conn = $db->getConnection();

    // Verify ownership
    $stmt = $conn->prepare("SELECT 1 FROM announcements a 
                          JOIN courses c ON a.course_id = c.course_id 
                          WHERE a.announcement_id = ? AND c.instructor_id = ?");
    $stmt->bind_param("ii", $announcement_id, $_SESSION['user_id']);
    $stmt->execute();
    
    if (!$stmt->get_result()->fetch_assoc()) {
        throw new Exception('Announcement not found or access denied', 404);
    }

    // Delete announcement
    $stmt = $conn->prepare("DELETE FROM announcements WHERE announcement_id = ?");
    $stmt->bind_param("i", $announcement_id);
    
    if (!$stmt->execute()) {
        throw new Exception('Database error: ' . $conn->error, 500);
    }

    $response = ['success' => true, 'message' => 'Announcement deleted'];

} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    $response['message'] = $e->getMessage();
} finally {
    if (isset($conn)) $db->closeConnection();
}

// Ensure only JSON is output
die(json_encode($response));