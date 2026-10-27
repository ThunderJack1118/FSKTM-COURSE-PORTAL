<?php
require_once '../config/config.php';
require_once '../includes/functions.php';
require_once '../includes/header.php';

if (!isLoggedIn() || !isStudent()) {
    redirect('/pages/auth/login.php');
}

$db = new Database();
$conn = $db->getConnection();

// Get course filter if provided
$course_filter = isset($_GET['course_id']) ? (int)$_GET['course_id'] : null;

// Get all submissions by this student with optional course filter
$query = "
    SELECT s.*, 
           a.title as assignment_title, 
           a.due_date,
           c.course_name, 
           c.course_code,
           CASE 
               WHEN s.submission_date > a.due_date THEN 'late'
               WHEN s.marks IS NOT NULL THEN 'graded'
               ELSE 'submitted'
           END as status
    FROM submissions s
    JOIN assignments a ON s.assignment_id = a.assignment_id
    JOIN courses c ON a.course_id = c.course_id
    WHERE s.user_id = ?
";

// Add course filter if provided
if ($course_filter) {
    $query .= " AND c.course_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $_SESSION['user_id'], $course_filter);
} else {
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $_SESSION['user_id']);
}

$stmt->execute();
$submissions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get course name for filter if applicable
$filter_course_name = '';
if ($course_filter) {
    $stmt = $conn->prepare("SELECT course_name FROM courses WHERE course_id = ?");
    $stmt->bind_param("i", $course_filter);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $filter_course_name = $result['course_name'] ?? '';
    $stmt->close();
}

$db->closeConnection();
?>

<div class="submission-container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>
            <?php if ($course_filter): ?>
                My Submissions for <?= htmlspecialchars($filter_course_name) ?>
            <?php else: ?>
                My Assignment Submissions
            <?php endif; ?>
        </h2>
        
        <?php if ($course_filter): ?>
            <a href="my-submissions.php" class="btn btn-outline-secondary">
                <i class="fas fa-list"></i> View All Submissions
            </a>
        <?php endif; ?>
    </div>
    
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success"><?= $_SESSION['success'] ?></div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>
    
    <!-- Course Filter Dropdown -->
    <?php if (!$course_filter): ?>
        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="form-inline">
                    <label for="course_filter" class="mr-2">Filter by Course:</label>
                    <select class="form-control mr-2" id="course_filter" name="course_id">
                        <option value="">All Courses</option>
                        <?php
                        // Get enrolled courses for this student
                        $db = new Database();
                        $conn = $db->getConnection();
                        $stmt = $conn->prepare("
                            SELECT c.course_id, c.course_name, c.course_code 
                            FROM courses c
                            JOIN registrations r ON c.course_id = r.course_id
                            WHERE r.user_id = ? AND r.status = 'approved'
                            ORDER BY c.course_name
                        ");
                        $stmt->bind_param("i", $_SESSION['user_id']);
                        $stmt->execute();
                        $enrolled_courses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                        $stmt->close();
                        $db->closeConnection();
                        
                        foreach ($enrolled_courses as $course): ?>
                            <option value="<?= $course['course_id'] ?>" 
                                <?= ($course_filter == $course['course_id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($course['course_code'] . ' - ' . $course['course_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-primary">Filter</button>
                </form>
            </div>
        </div>
    <?php endif; ?>
    
    <div class="table-responsive">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>Course</th>
                    <th>Assignment</th>
                    <th>Submitted</th>
                    <th>Due Date</th>
                    <th>Status</th>
                    <th>Marks</th>
                    <th>Feedback</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($submissions)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-4">
                            <?php if ($course_filter): ?>
                                No submissions found for this course.
                            <?php else: ?>
                                You haven't submitted any assignments yet.
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($submissions as $submission): ?>
                        <tr>
                            <td>
                                <?= $submission['course_code'] ?>: <?= $submission['course_name'] ?>
                            </td>
                            <td><?= $submission['assignment_title'] ?></td>
                            <td><?= date('M j, Y H:i', strtotime($submission['submission_date'])) ?></td>
                            <td><?= date('M j, Y H:i', strtotime($submission['due_date'])) ?></td>
                            <td>
                                <?php 
                                $badge_class = '';
                                if ($submission['status'] == 'late') $badge_class = 'badge-warning';
                                elseif ($submission['status'] == 'graded') $badge_class = 'badge-success';
                                else $badge_class = 'badge-primary';
                                ?>
                                <span class="badge <?= $badge_class ?>">
                                    <?= ucfirst($submission['status']) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($submission['marks'] !== null): ?>
                                    <?= $submission['marks'] ?>/100
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td><?= $submission['feedback'] ?: 'No feedback yet' ?></td>
                            <td>
                                <a href="../<?= UPLOAD_SUBMISSION_PATH . $submission['file_path'] ?>" 
                                   class="btn btn-sm btn-info" download>
                                   <i class="fas fa-download"></i> Download
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<style>
    /* Main Container */
.submission-container {
    max-width: 1200px;
    margin: 50px auto;
    padding: 0 20px;
}

/* Page Header */
.submission-container h2 {
    color: #2d3748;
    font-size: 28px;
    font-weight: 600;
    margin-bottom: 25px;
    padding-bottom: 10px;
    border-bottom: 1px solid #e2e8f0;
}

/* Alert Messages */
.alert {
    padding: 12px 16px;
    border-radius: 6px;
    margin-bottom: 20px;
}

.alert-success {
    background-color: #f0fff4;
    color: #2f855a;
    border: 1px solid #c6f6d5;
}

/* Table Styles */
.table-responsive {
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
}

.table {
    width: 100%;
    border-collapse: collapse;
    background-color: white;
}

.table thead {
    background-color: #f8fafc;
}

.table th {
    padding: 16px 12px;
    text-align: left;
    color: #4a5568;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 13px;
    letter-spacing: 0.5px;
    border-bottom: 2px solid #e2e8f0;
}

.table td {
    padding: 14px 12px;
    border-bottom: 1px solid #edf2f7;
    vertical-align: middle;
    color: #4a5568;
}

.table tbody tr:last-child td {
    border-bottom: none;
}

.table tbody tr:hover {
    background-color: #f8fafc;
}

/* Status Badges */
.badge {
    display: inline-block;
    padding: 5px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
}

.badge-primary {
    background-color: #ebf8ff;
    color: #3182ce;
}

.badge-success {
    background-color: #f0fff4;
    color: #38a169;
}

.badge-warning {
    background-color: #fffaf0;
    color: #dd6b20;
}

/* Action Buttons */
.btn {
    display: inline-block;
    padding: 6px 12px;
    border-radius: 4px;
    font-size: 13px;
    font-weight: 500;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s ease;
}

.btn-sm {
    padding: 5px 10px;
    font-size: 12px;
}

.btn-info {
    background-color: #ebf8ff;
    color: #3182ce;
    border: 1px solid #bee3f8;
}

.btn-info:hover {
    background-color: #bee3f8;
    border-color: #90cdf4;
}

/* Course Code Styling */
td:first-child {
    font-weight: 500;
    color: #2d3748;
}

/* Responsive Adjustments */
@media (max-width: 768px) {
    .table-responsive {
        border: 1px solid #e2e8f0;
    }
    
    .table thead {
        display: none;
    }
    
    .table, .table tbody, .table tr, .table td {
        display: block;
        width: 100%;
    }
    
    .table tr {
        padding: 12px 0;
        border-bottom: 1px solid #e2e8f0;
    }
    
    .table td {
        padding: 8px 12px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .table td::before {
        content: attr(data-label);
        font-weight: 600;
        color: #4a5568;
        margin-right: 15px;
    }
    
    .btn {
        width: 100%;
    }
}

@media (max-width: 576px) {
    .submission-container {
        padding: 0 15px;
    }
    
    .submission-container h2 {
        font-size: 24px;
    }
    
    .table td {
        font-size: 14px;
    }
}
</style>

<?php require_once '../includes/footer.php'; ?>