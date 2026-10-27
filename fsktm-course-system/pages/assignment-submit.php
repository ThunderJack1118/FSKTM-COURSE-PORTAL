<?php
require_once '../config/config.php';
require_once '../includes/functions.php';
require_once '../includes/header.php';

if (!isLoggedIn() || !isStudent()) {
    redirect('/pages/auth/login.php');
}

$assignment_id = (int)$_GET['id'];
$db = new Database();
$conn = $db->getConnection();

// Get assignment details
$stmt = $conn->prepare("
    SELECT a.*, c.course_name 
    FROM assignments a
    JOIN courses c ON a.course_id = c.course_id
    WHERE a.assignment_id = ?
");
$stmt->bind_param("i", $assignment_id);
$stmt->execute();
$assignment = $stmt->get_result()->fetch_assoc();

if (!$assignment) {
    echo "<div class='alert alert-danger'>Assignment not found</div>";
    require_once '../includes/footer.php';
    exit;
}

// Check if student is enrolled in the course
$stmt = $conn->prepare("
    SELECT 1 FROM registrations 
    WHERE course_id = ? AND user_id = ? AND status = 'approved'
");
$stmt->bind_param("ii", $assignment['course_id'], $_SESSION['user_id']);
$stmt->execute();
if (!$stmt->get_result()->fetch_assoc()) {
    echo "<div class='alert alert-danger'>You are not enrolled in this course</div>";
    require_once '../includes/footer.php';
    exit;
}

// Check for existing submission
$stmt = $conn->prepare("
    SELECT * FROM submissions 
    WHERE assignment_id = ? AND user_id = ?
");
$stmt->bind_param("ii", $assignment_id, $_SESSION['user_id']);
$stmt->execute();
$existing_submission = $stmt->get_result()->fetch_assoc();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['submission_file'])) {
    $file = $_FILES['submission_file'];
    
    // Validate file
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($file_ext, ALLOWED_TYPES)) {
        $error = "File type not allowed. Only " . implode(', ', ALLOWED_TYPES) . " are allowed.";
    } elseif ($file['size'] > MAX_FILE_SIZE) {
        $error = "File too large. Maximum size is " . (MAX_FILE_SIZE / 1024 / 1024) . "MB.";
    } else {
        $new_filename = 'submission_' . $_SESSION['user_id'] . '_' . $assignment_id . '_' . time() . '.' . $file_ext;
        $upload_path = '../' . UPLOAD_SUBMISSION_PATH . $new_filename;
        
        if (move_uploaded_file($file['tmp_name'], $upload_path)) {
            if ($existing_submission) {
                // Update existing submission
                $stmt = $conn->prepare("
                    UPDATE submissions 
                    SET file_path = ?, submission_date = CURRENT_TIMESTAMP, status = 'submitted'
                    WHERE submission_id = ?
                ");
                $stmt->bind_param("si", $new_filename, $existing_submission['submission_id']);
            } else {
                // Create new submission
                $stmt = $conn->prepare("
                    INSERT INTO submissions (assignment_id, user_id, file_path)
                    VALUES (?, ?, ?)
                ");
                $stmt->bind_param("iis", $assignment_id, $_SESSION['user_id'], $new_filename);
            }
            
            if ($stmt->execute()) {
                $_SESSION['success'] = "Assignment submitted successfully!";
                redirect('/pages/my-submissions.php?course_id=' . $assignment['course_id']);
            } else {
                $error = "Database error: " . $conn->error;
            }
        } else {
            $error = "File upload failed";
        }
    }
}
?>

<div class="submit-container">
    <h2>Submit Assignment: <?= $assignment['title'] ?></h2>
    <p>Course: <?= $assignment['course_name'] ?></p>
    <p>Due: <?= date('M j, Y H:i', strtotime($assignment['due_date'])) ?></p>
    
    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?= $error ?></div>
    <?php endif; ?>
    
    <div class="card">
        <div class="card-body">
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="submission_file">Upload your work</label>
                    <input type="file" class="form-control-file" id="submission_file" name="submission_file" required>
                    <small class="form-text text-muted">
                        Allowed formats: <?= implode(', ', ALLOWED_TYPES) ?>. Max size: <?= (MAX_FILE_SIZE / 1024 / 1024) ?>MB
                    </small>
                </div>
                
                <?php if ($existing_submission): ?>
                    <div class="alert alert-info">
                        You already submitted this assignment on <?= date('M j, Y H:i', strtotime($existing_submission['submission_date'])) ?>.
                        Uploading a new file will replace your previous submission.
                    </div>
                <?php endif; ?>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Submit Assignment</button>
                    <a href="course-details.php?id=<?= $assignment['course_id'] ?>" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
    /* assignment-submit.css */
.submit-container {
    max-width: 800px;
    margin: 2rem auto;
    padding: 0 15px;
}

h2 {
    color: #2c3e50;
    margin-bottom: 1rem;
    font-weight: 600;
}

.card {
    border: none;
    border-radius: 10px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
    margin-top: 1.5rem;
    overflow: hidden;
}

.card-body {
    padding: 2rem;
}

.form-group {
    margin-bottom: 1.5rem;
}

label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 500;
    color: #34495e;
}

.form-control-file {
    display: block;
    width: 100%;
    padding: 0.75rem;
    border: 2px dashed #bdc3c7;
    border-radius: 8px;
    background-color: #f8f9fa;
    transition: all 0.3s ease;
}

.form-control-file:hover {
    border-color: #3498db;
    background-color: #f1f8fe;
}

.form-text {
    font-size: 0.85rem;
    color: #7f8c8d;
    margin-top: 0.5rem;
}

.btn {
    padding: 0.6rem 1.5rem;
    border-radius: 6px;
    font-weight: 500;
    transition: all 0.2s ease;
}

.btn-primary {
    background-color: #3498db;
    border-color: #3498db;
}

.btn-primary:hover {
    background-color: #2980b9;
    border-color: #2980b9;
    transform: translateY(-1px);
}

.btn-secondary {
    background-color: #95a5a6;
    border-color: #95a5a6;
    margin-left: 0.75rem;
}

.btn-secondary:hover {
    background-color: #7f8c8d;
    border-color: #7f8c8d;
    transform: translateY(-1px);
}

.alert {
    border-radius: 8px;
    padding: 1rem 1.25rem;
    margin-bottom: 1.5rem;
}

.alert-danger {
    background-color: #fdecea;
    border-color: #fadbd8;
    color: #c0392b;
}

.alert-info {
    background-color: #e8f4fd;
    border-color: #d4e6f7;
    color: #2980b9;
}

.due-date {
    font-weight: 500;
    color: #e74c3c;
    margin-bottom: 1.5rem;
    display: inline-block;
    padding: 0.5rem 1rem;
    background-color: #fdecea;
    border-radius: 6px;
}

.course-info {
    color: #7f8c8d;
    margin-bottom: 1.5rem;
    font-size: 1.1rem;
}
/* Add this to your existing CSS */
.form-actions {
    display: flex;
    gap: 12px;
    margin-top: 20px;
}

.form-actions .btn {
    flex: 1;
    text-align: center;
    padding: 10px 0;
}

/* If you want equal width regardless of text length */
.form-actions .btn {
    min-width: 120px;
    flex: 0 0 auto;
}

/* Or if you want them to stretch equally */
.form-actions .btn {
    flex: 1;
}

/* Responsive adjustments */
@media (max-width: 576px) {
    .card-body {
        padding: 1.5rem;
    }
    
    .btn {
        width: 100%;
        margin-bottom: 0.5rem;
    }
    
    .btn-secondary {
        margin-left: 0;
    }
}
</style>
<?php require_once '../includes/footer.php'; ?>