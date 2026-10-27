<?php
require_once '../config/config.php';
require_once '../includes/functions.php';
require_once '../includes/header.php';

require_once '../includes/header.php';

if (!isLoggedIn() || !isLecturer()) {
    redirect('/pages/auth/login.php');
}

$assignment_id = (int)$_GET['id'];
$db = new Database();
$conn = $db->getConnection();

// Get assignment details and verify ownership
$stmt = $conn->prepare("
    SELECT a.*, c.course_name, c.instructor_id
    FROM assignments a
    JOIN courses c ON a.course_id = c.course_id
    WHERE a.assignment_id = ?
");
$stmt->bind_param("i", $assignment_id);
$stmt->execute();
$assignment = $stmt->get_result()->fetch_assoc();

if (!$assignment || $assignment['instructor_id'] != $_SESSION['user_id']) {
    echo "<div class='alert alert-danger'>Assignment not found or unauthorized access</div>";
    require_once '../includes/footer.php';
    exit;
}

// Get all submissions for this assignment
$stmt = $conn->prepare("
    SELECT s.*, u.username, CONCAT(u.first_name, ' ', u.last_name) AS full_name
    FROM submissions s
    JOIN users u ON s.user_id = u.user_id
    WHERE s.assignment_id = ?
    ORDER BY s.submission_date ASC
");
$stmt->bind_param("i", $assignment_id);
$stmt->execute();
$submissions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Handle grading submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['grade'])) {
    $submission_id = (int)$_POST['submission_id'];
    $marks = (float)$_POST['marks'];
    $feedback = sanitizeInput($_POST['feedback']);
    
    // Verify submission belongs to this assignment
    $stmt = $conn->prepare("SELECT assignment_id FROM submissions WHERE submission_id = ?");
    $stmt->bind_param("i", $submission_id);
    $stmt->execute();
    $sub = $stmt->get_result()->fetch_assoc();
    
    if ($sub && $sub['assignment_id'] == $assignment_id) {
        $stmt = $conn->prepare("
            UPDATE submissions 
            SET marks = ?, feedback = ?, status = 'graded'
            WHERE submission_id = ?
        ");
        $stmt->bind_param("dsi", $marks, $feedback, $submission_id);
        
        if ($stmt->execute()) {
            $_SESSION['success'] = "Grades updated successfully";
            redirect("/pages/assignment-view.php?id=<?= $assignment");
        } else {
            $error = "Failed to update grades: " . $conn->error;
        }
    } else {
        $error = "Invalid submission";
    }
}
?>

<div class="container">
    <h2>Submissions for: <?= $assignment['title'] ?></h2>
    <p>Course: <?= $assignment['course_name'] ?></p>
    <p>Due Date: <?= date('M j, Y H:i', strtotime($assignment['due_date'])) ?></p>
    <p>Total Submissions: <?= count($submissions) ?></p>
    
    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?= $error ?></div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success"><?= $_SESSION['success'] ?></div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>
    
    <div class="table-responsive">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Submitted</th>
                    <th>Status</th>
                    <th>Marks</th>
                    <th>Feedback</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($submissions as $submission): ?>
                    <tr>
                        <td><?= $submission['full_name'] ?> (<?= $submission['username'] ?>)</td>
                        <td><?= date('M j, Y H:i', strtotime($submission['submission_date'])) ?></td>
                        <td>
                            <?php 
                            $status = $submission['status'];
                            if (strtotime($submission['submission_date']) > strtotime($assignment['due_date'])) {
                                $status = 'late';
                            }
                            $badge_class = $status == 'graded' ? 'badge-success' : 
                                         ($status == 'late' ? 'badge-warning' : 'badge-primary');
                            ?>
                            <span class="badge <?= $badge_class ?>"><?= ucfirst($status) ?></span>
                        </td>
                        <td>
                            <?php if ($submission['marks'] !== null): ?>
                                <?= $submission['marks'] ?>/<?= $assignment['max_marks'] ?>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td><?= $submission['feedback'] ?: 'No feedback' ?></td>
                        <td>
                            <a href="../<?= UPLOAD_SUBMISSION_PATH . $submission['file_path'] ?>" 
                               class="btn btn-sm btn-info" download>Download</a>
                            <button class="btn btn-sm btn-primary grade-btn" 
                                    data-id="<?= $submission['submission_id'] ?>"
                                    data-marks="<?= $submission['marks'] ?>"
                                    data-feedback="<?= htmlspecialchars($submission['feedback'] ?? '') ?>">
                                Grade
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Grade Modal -->
<div class="modal fade" id="gradeModal" tabindex="-1" role="dialog" aria-labelledby="gradeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="gradeModalLabel">Grade Submission</h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="submission_id" id="modalSubmissionId">
                    <div class="form-group">
                        <label for="marks">Marks (Max: <?= $assignment['max_marks'] ?>)</label>
                        <input type="number" step="0.01" min="0" max="<?= $assignment['max_marks'] ?>" 
                               class="form-control" id="marks" name="marks" required>
                    </div>
                    <div class="form-group">
                        <label for="feedback">Feedback</label>
                        <textarea class="form-control" id="feedback" name="feedback" rows="4"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" name="grade" class="btn btn-success">Save Grade</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Handle grade button clicks
$('.grade-btn').click(function() {
    var submissionId = $(this).data('id');
    var marks = $(this).data('marks') || '';
    var feedback = $(this).data('feedback') || '';
    
    $('#modalSubmissionId').val(submissionId);
    $('#marks').val(marks);
    $('#feedback').val(feedback);
    
    $('#gradeModal').modal('show');
});
</script>
<style>
    h2 {
        color: #2c3e50;
        margin-top: 20px;
        margin-bottom: 20px;
        padding-bottom: 10px;
        border-bottom: 2px solid #eee;
    }
    
    .table-responsive {
        margin-top: 30px;
        box-shadow: 0 0 10px rgba(0, 0, 0, 0.05);
        border-radius: 5px;
        overflow: hidden;
    }
    
    .table {
        margin-bottom: 0;
    }
    
    .table th {
        background-color: #3498db;
        color: white;
        font-weight: 500;
    }
    
    .table td, .table th {
        vertical-align: middle;
        padding: 15px;
    }
    
    .badge {
        padding: 5px 10px;
        font-size: 12px;
        font-weight: 500;
        border-radius: 4px;
    }
    
    .badge-primary {
        background-color: #3498db;
    }
    
    .badge-success {
        background-color: #2ecc71;
    }
    
    .badge-warning {
        background-color: #f39c12;
    }
    
    .btn-sm {
        padding: 5px 10px;
        font-size: 13px;
        margin-right: 5px;
    }
    
    .grade-btn {
        background-color: #2ecc71;
        border-color: #2ecc71;
    }
    
    .grade-btn:hover {
        background-color: #27ae60;
        border-color: #27ae60;
    }
    
    .modal-header {
        background-color: #3498db;
        color: white;
    }
    
    .modal-title {
        font-weight: 500;
    }
    
    .close {
        color: white;
        opacity: 1;
    }
    
    .alert {
        margin-top: 20px;
    }

        /* Modal specific styles */
    #gradeModal .modal-content {
        border: none;
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 5px 20px rgba(0, 0, 0, 0.15);
    }
    
    #gradeModal .modal-header {
        padding: 15px 20px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }
    
    #gradeModal .modal-body {
        padding: 20px;
    }
    
    #gradeModal .modal-footer {
        padding: 15px 20px;
        border-top: 1px solid #eee;
    }
    
    #gradeModal .form-control {
        border-radius: 4px;
        padding: 10px 15px;
        border: 1px solid #ddd;
        transition: border-color 0.3s;
    }
    
    #gradeModal .form-control:focus {
        border-color: #3498db;
        box-shadow: 0 0 0 0.2rem rgba(52, 152, 219, 0.25);
    }
    
    #gradeModal textarea.form-control {
        min-height: 120px;
        resize: vertical;
    }
    
    #gradeModal .btn-success {
        background-color: #2ecc71;
        border-color: #2ecc71;
        padding: 8px 20px;
        font-weight: 500;
    }
    
    #gradeModal .btn-success:hover {
        background-color: #27ae60;
        border-color: #27ae60;
    }
    
    #gradeModal .btn-outline-secondary {
        padding: 8px 20px;
        font-weight: 500;
    }
    
    /* Accessibility improvements */
    #gradeModal .close:focus {
        outline: 2px solid rgba(255, 255, 255, 0.5);
    }
    
    /* Responsive adjustments */
    @media (max-width: 576px) {
        #gradeModal .modal-dialog {
            margin: 10px;
        }
    }
    
    @media (max-width: 768px) {
        .table td, .table th {
            padding: 10px;
            font-size: 14px;
        }
        
        .btn-sm {
            margin-bottom: 5px;
        }
    }
</style>

<?php require_once '../includes/footer.php'; ?>