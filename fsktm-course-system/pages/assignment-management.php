<?php
require_once '../config/config.php';
require_once '../includes/header.php';
require_once '../includes/functions.php';

if (!isLoggedIn() || !isLecturer()) {
    redirect('/pages/auth/login.php');
}

$db = new Database();
$conn = $db->getConnection();

// Initialize variables
$assignments = [];
$courses = [];
$assigned_course_ids = [];
$stats = [
    'total_assignments' => 0,
    'active_assignments' => 0,
    'submissions_pending' => 0
];

// Get lecturer's courses
$stmt = $conn->prepare("SELECT * FROM courses WHERE instructor_id = ? ORDER BY course_name ASC");
$stmt->bind_param("i", $_SESSION['user_id']);
if ($stmt->execute()) {
    $courses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $assigned_course_ids = array_column($courses, 'course_id');
} else {
    echo "<div class='alert alert-danger'>Error loading courses: " . $conn->error . "</div>";
    require_once '../includes/footer.php';
    exit;
}
$stmt->close();

// Check if selected course belongs to lecturer
$selected_course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : null;
if ($selected_course_id && !in_array($selected_course_id, $assigned_course_ids)) {
    echo "<div class='alert alert-danger'>You are not assigned to this course.</div>";
    require_once '../includes/footer.php';
    exit;
}

// Get assignment statistics
$stmt = $conn->prepare("
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
");
$stmt->bind_param("ii", $_SESSION['user_id'], $_SESSION['user_id']);
if ($stmt->execute()) {
    $stats = $stmt->get_result()->fetch_assoc();
}
$stmt->close();

// Get assignments with submission counts
$query = "
    SELECT a.*, c.course_name, 
    (SELECT COUNT(*) FROM submissions s WHERE s.assignment_id = a.assignment_id) as submission_count,
    (SELECT COUNT(*) FROM submissions s WHERE s.assignment_id = a.assignment_id AND s.marks IS NULL) as ungraded_count
    FROM assignments a
    JOIN courses c ON a.course_id = c.course_id
    WHERE c.instructor_id = ?
";
if ($selected_course_id) {
    $query .= " AND a.course_id = ?";
}
$query .= " ORDER BY a.due_date ASC";

$stmt = $conn->prepare($query);
if ($selected_course_id) {
    $stmt->bind_param("ii", $_SESSION['user_id'], $selected_course_id);
} else {
    $stmt->bind_param("i", $_SESSION['user_id']);
}

if ($stmt->execute()) {
    $assignments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
    echo "<div class='alert alert-danger'>Error loading assignments: " . $stmt->error . "</div>";
}
$stmt->close();
?>

<div class="assignment-management-container">
    <!-- Page Header -->
    <div class="page-header">
        <h1><i class="fas fa-tasks"></i> Assignment Management</h1>
        <div class="header-actions">
            <button class="btn btn-primary" data-toggle="modal" data-target="#createAssignmentModal">
                <i class="fas fa-plus"></i> Create Assignment
            </button>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row summary-cards mb-4">
        <div class="col-md-4 mb-3">
            <div class="card summary-card total-assignments h-100">
                <div class="card-body">
                    <h5>Total Assignments</h5>
                    <h2><?= $stats['total_assignments'] ?></h2>
                    <i class="fas fa-tasks"></i>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="card summary-card active-assignments h-100">
                <div class="card-body">
                    <h5>Active Assignments</h5>
                    <h2><?= $stats['active_assignments'] ?></h2>
                    <i class="fas fa-clipboard-check"></i>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="card summary-card submissions-pending h-100">
                <div class="card-body">
                    <h5>Submissions Pending</h5>
                    <h2><?= $stats['submissions_pending'] ?></h2>
                    <i class="fas fa-inbox"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Course Filter -->
    <div class="course-filter card mb-4">
        <div class="card-body">
            <form id="courseFilterForm" class="form-inline">
                <label for="filterCourse" class="mr-2">Filter by Course:</label>
                <select class="form-control mr-2" id="filterCourse" name="course_id">
                    <option value="">All Courses</option>
                    <?php foreach ($courses as $course): ?>
                        <option value="<?= $course['course_id'] ?>" <?= $selected_course_id == $course['course_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($course['course_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-secondary">Filter</button>
            </form>
        </div>
    </div>

    <!-- Assignments Table -->
    <div class="card assignments-table">
        <div class="card-header">
            <h3>Assignments</h3>
            <div class="table-actions">
                <button class="btn btn-outline-secondary" id="refreshTable"><i class="fas fa-sync"></i> Refresh</button>
                <button class="btn btn-outline-primary" id="exportAssignments"><i class="fas fa-file-export"></i> Export</button>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table" id="assignmentsTable">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Course</th>
                            <th>Due Date</th>
                            <th>Submissions</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($assignments)): ?>
                        <tr>
                            <td colspan="6" class="text-center">No assignments found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($assignments as $assignment): 
                            $now = time();
                            $due = strtotime($assignment['due_date']);
                            if ($due < $now) {
                                $status = 'completed';
                                $badge = 'completed';
                                $status_text = 'Completed';
                            } elseif (($due - $now) < (7 * 24 * 60 * 60)) {
                                $status = 'due-soon';
                                $badge = 'due-soon';
                                $status_text = 'Due Soon';
                            } else {
                                $status = 'active';
                                $badge = 'active';
                                $status_text = 'Active';
                            }
                            
                            // Submission status
                            if ($assignment['submission_count'] > 0) {
                                if ($assignment['ungraded_count'] > 0) {
                                    $submission_badge = 'pending';
                                    $submission_text = 'Pending Grading';
                                } else {
                                    $submission_badge = 'graded';
                                    $submission_text = 'Graded';
                                }
                            } else {
                                $submission_badge = 'no-submissions';
                                $submission_text = 'No Submissions';
                            }
                        ?>
                        <tr data-id="<?= $assignment['assignment_id'] ?>">
                            <td>
                                <strong><?= htmlspecialchars($assignment['title']) ?></strong>
                                <div class="assignment-description"><?= nl2br(htmlspecialchars($assignment['description'])) ?></div>
                            </td>
                            <td>
                                <span class="course-info">
                                    <span class="course-color" style="background: #<?= substr(md5($assignment['course_id']), 0, 6) ?>"></span>
                                    <?= htmlspecialchars($assignment['course_name']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="due-date"><?= date('M j, Y H:i', strtotime($assignment['due_date'])) ?></span>
                                <div class="time-remaining">
                                    <?= ($due > $now) ? human_time_diff($now, $due) . ' left' : 'Overdue' ?>
                                </div>
                            </td>
                            <td>
                                <span class="submission-count"><?= $assignment['submission_count'] ?></span>
                                <small>Submissions</small>
                                <div class="submission-status-badge <?= $submission_badge ?>"><?= $submission_text ?></div>
                            </td>
                            <td>
                                <span class="status-badge <?= $badge ?>"><?= $status_text ?></span>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <button class="btn btn-sm btn-info edit-assignment" data-id="<?= $assignment['assignment_id'] ?>" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn btn-sm btn-danger delete-assignment" data-id="<?= $assignment['assignment_id'] ?>" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                    <a href="<?= BASE_URL ?>/pages/assignment-view.php?id=<?= $assignment['assignment_id'] ?>" class="btn btn-sm btn-primary" title="View Submissions">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Create Assignment Modal -->
<div class="modal fade" id="createAssignmentModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form id="createAssignmentForm" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title">Create Assignment</h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label for="course_id">Course *</label>
                        <select class="form-control" id="course_id" name="course_id" required>
                            <option value="">Select Course</option>
                            <?php foreach ($courses as $course): ?>
                                <option value="<?= $course['course_id'] ?>"><?= htmlspecialchars($course['course_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="title">Title *</label>
                        <input type="text" class="form-control" id="title" name="title" required>
                    </div>
                    <div class="form-group">
                        <label for="description">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="4"></textarea>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="due_date">Due Date *</label>
                            <input type="datetime-local" class="form-control" id="due_date" name="due_date" required>
                        </div>
                        <div class="form-group col-md-6">
                            <label for="max_marks">Max Marks *</label>
                            <input type="number" class="form-control" id="max_marks" name="max_marks" value="100" min="1" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="assignmentFiles">Supporting Files (Optional)</label>
                        <div class="custom-file">
                            <input type="file" class="custom-file-input" id="assignmentFiles" name="assignment_files[]" multiple>
                            <label class="custom-file-label" for="assignmentFiles">Choose files (max 10MB each)</label>
                        </div>
                        <small class="form-text text-muted">You can upload multiple files if needed</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">Create Assignment</button>
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Assignment Modal -->
<div class="modal fade" id="editAssignmentModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <!-- Content loaded dynamically via JS -->
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteConfirmationModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <!-- Content loaded dynamically via JS -->
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize tooltips
    $('[data-toggle="tooltip"]').tooltip();

    // Set default due date to tomorrow at current time
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    const formattedDate = tomorrow.toISOString().slice(0, 16);
    $('#due_date').val(formattedDate);

    // Course filter form submission
    $('#courseFilterForm').submit(function(e) {
        e.preventDefault();
        const courseId = $('#filterCourse').val();
        if (courseId) {
            window.location.href = 'assignment-management.php?course_id=' + courseId;
        } else {
            window.location.href = 'assignment-management.php';
        }
    });

    // Create Assignment Form Submission
    $('#createAssignmentForm').submit(function(e) {
        e.preventDefault();

        const form = $(this);
        const submitBtn = form.find('button[type="submit"]');
        submitBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Creating...');

        // Validate due date is in the future
        const dueDate = new Date($('#due_date').val());
        if (dueDate < new Date()) {
            showToast('error', 'Due date must be in the future');
            submitBtn.prop('disabled', false).html('Create Assignment');
            return;
        }

        // Use FormData to collect all fields
        const formData = new FormData(this);
        formData.append('action', 'create');

        $.ajax({
            url: '../api/assignment-api.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(response) {
                submitBtn.prop('disabled', false).html('Create Assignment');
                if (response.success) {
                    $('#createAssignmentModal').modal('hide');
                    showToast('success', response.message);
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast('error', response.message || 'Failed to create assignment');
                }
            },
            error: function(xhr) {
                submitBtn.prop('disabled', false).html('Create Assignment');
                showToast('error', 'Server error. Please try again.');
            }
        });
    });

    // Edit assignment button click
    $(document).on('click', '.edit-assignment', function() {
        const assignmentId = $(this).data('id');
        $('#editAssignmentModal .modal-content').html('<div class="modal-body text-center"><i class="fas fa-spinner fa-spin"></i> Loading...</div>');
        $('#editAssignmentModal').modal('show');
        
        $.get('../api/assignment-api.php', { action: 'get', assignment_id: assignmentId }, function(response) {
            if (response.success) {
                // Render edit form
                let html = `
                    <form id="editAssignmentForm">
                        <div class="modal-header">
                            <h5 class="modal-title">Edit Assignment</h5>
                            <button type="button" class="close" data-dismiss="modal">&times;</button>
                        </div>
                        <div class="modal-body">
                            <input type="hidden" name="assignment_id" value="${response.assignment_id}">
                            <div class="form-group">
                                <label for="edit_title">Title *</label>
                                <input type="text" class="form-control" id="edit_title" name="title" value="${response.title}" required>
                            </div>
                            <div class="form-group">
                                <label for="edit_description">Description</label>
                                <textarea class="form-control" id="edit_description" name="description" rows="4">${response.description || ''}</textarea>
                            </div>
                            <div class="form-row">
                                <div class="form-group col-md-6">
                                    <label for="edit_due_date">Due Date *</label>
                                    <input type="datetime-local" class="form-control" id="edit_due_date" name="due_date" value="${response.due_date_formatted}" required>
                                </div>
                                <div class="form-group col-md-6">
                                    <label for="edit_max_marks">Max Marks *</label>
                                    <input type="number" class="form-control" id="edit_max_marks" name="max_marks" value="${response.max_marks}" min="1" required>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Attached Files</label>
                                <div id="currentFiles" class="mb-3">
                                    ${response.files && response.files.length > 0 ? 
                                        response.files.map(file => `
                                            <div class="file-item d-flex justify-content-between align-items-center mb-2">
                                                <div>
                                                    <i class="fas fa-file"></i>
                                                    <a href="../uploads/${file.file_path}" target="_blank">${file.file_name}</a>
                                                    <small class="text-muted">(${(file.file_size / 1024).toFixed(1)} KB)</small>
                                                </div>
                                                <button type="button" class="btn btn-sm btn-outline-danger delete-file" data-file-id="${file.file_id}" data-assignment-id="${response.assignment_id}">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        `).join('') : 
                                        '<p>No files attached</p>'
                                    }
                                </div>
                                <label for="edit_assignmentFiles">Add More Files</label>
                                <div class="custom-file">
                                    <input type="file" class="custom-file-input" id="edit_assignmentFiles" name="assignment_files[]" multiple>
                                    <label class="custom-file-label" for="edit_assignmentFiles">Choose files (max 10MB each)</label>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="submit" class="btn btn-primary">Update Assignment</button>
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        </div>
                    </form>
                `;
                $('#editAssignmentModal .modal-content').html(html);
                $('.custom-file-input').trigger('change'); // Initialize file input labels
            } else {
                $('#editAssignmentModal .modal-content').html('<div class="modal-body text-danger">' + (response.message || 'Failed to load assignment') + '</div>');
            }
        }, 'json');
    });

    // Update Assignment Form Submission
    $(document).on('submit', '#editAssignmentForm', function(e) {
        e.preventDefault();
        const form = $(this);
        const submitBtn = form.find('button[type="submit"]');
        submitBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Updating...');
        
        // Validate due date is in the future
        const dueDate = new Date($('#edit_due_date').val());
        if (dueDate < new Date()) {
            showToast('error', 'Due date must be in the future');
            submitBtn.prop('disabled', false).html('Update Assignment');
            return;
        }

        const formData = new FormData(this);
        formData.append('action', 'update');
        
        $.ajax({
            url: '../api/assignment-api.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(response) {
                submitBtn.prop('disabled', false).html('Update Assignment');
                if (response.success) {
                    $('#editAssignmentModal').modal('hide');
                    showToast('success', response.message);
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast('error', response.message || 'Failed to update assignment');
                }
            },
            error: function() {
                submitBtn.prop('disabled', false).html('Update Assignment');
                showToast('error', 'Server error. Please try again.');
            }
        });
    });

    // Delete file from assignment
    $(document).on('click', '.delete-file', function() {
        const button = $(this);
        const fileId = button.data('file-id');
        const assignmentId = button.data('assignment-id');
        
        // Show loading state
        button.html('<i class="fas fa-spinner fa-spin"></i>');
        button.prop('disabled', true);
        
        $.ajax({
            url: '../api/assignment-api.php',  // Fixed endpoint
            type: 'POST',
            data: {
                action: 'delete_file',
                file_id: fileId,
                assignment_id: assignmentId
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    // Remove the file element from UI
                    button.closest('.file-item').remove();
                    showToast('success', 'File deleted successfully');
                } else {
                    // Reset button state
                    button.html('<i class="fas fa-trash"></i>');
                    button.prop('disabled', false);
                    showToast('error', response.message || 'Failed to delete file');
                }
            },
            error: function(xhr) {
                // Reset button state
                button.html('<i class="fas fa-trash"></i>');
                button.prop('disabled', false);
                showToast('error', 'An error occurred while deleting the file');
            }
        });
    });

    // Delete Assignment Handler
    $(document).on('click', '.delete-assignment', function() {
        const assignmentId = $(this).data('id');
        $('#deleteConfirmationModal .modal-content').html(`
            <div class="modal-header">
                <h5 class="modal-title">Delete Assignment</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete this assignment? This will also delete all submissions and files associated with it.</p>
                <div class="alert alert-warning">
                    <strong>Warning:</strong> This action cannot be undone.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-danger" id="confirmDeleteAssignment" data-id="${assignmentId}">Delete</button>
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
            </div>
        `);
        $('#deleteConfirmationModal').modal('show');
    });

    $(document).on('click', '#confirmDeleteAssignment', function() {
        const assignmentId = $(this).data('id');
        const btn = $(this);
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Deleting...');
        
        $.post('../api/assignment-api.php', { 
            action: 'delete', 
            assignment_id: assignmentId 
        }, function(response) {
            btn.prop('disabled', false).html('Delete');
            if (response.success) {
                $('#deleteConfirmationModal').modal('hide');
                showToast('success', response.message);
                setTimeout(() => location.reload(), 1000);
            } else {
                showToast('error', response.message || 'Failed to delete assignment');
            }
        }, 'json');
    });

    // File input label update
    $(document).on('change', '.custom-file-input', function() {
        const files = this.files;
        let label;
        if (files.length > 1) {
            label = files.length + ' files selected';
        } else if (files.length === 1) {
            label = files[0].name;
        } else {
            label = 'Choose files (max 10MB each)';
        }
        $(this).next('.custom-file-label').html(label);
    });

    // Refresh table button
    $('#refreshTable').click(function() {
        location.reload();
    });

    // Export assignments button
    $('#exportAssignments').click(function() {
        const courseId = $('#filterCourse').val();
        let url = '../api/export-api.php?type=assignments';
        if (courseId) {
            url += '&course_id=' + courseId;
        }
        window.location.href = url;
    });

    // Initialize DataTable if there are assignments
    <?php if (!empty($assignments)): ?>
    $('#assignmentsTable').DataTable({
        responsive: true,
        order: [[2, 'asc']], // Sort by due date by default
        columnDefs: [
            { responsivePriority: 1, targets: 0 }, // Title
            { responsivePriority: 2, targets: 5 }, // Actions
            { responsivePriority: 3, targets: 2 }, // Due Date
            { responsivePriority: 4, targets: 1 }, // Course
            { responsivePriority: 5, targets: 3 }, // Submissions
            { responsivePriority: 6, targets: 4 }  // Status
        ],
        language: {
            emptyTable: "No assignments found",
            info: "Showing _START_ to _END_ of _TOTAL_ assignments",
            infoEmpty: "Showing 0 to 0 of 0 assignments",
            infoFiltered: "(filtered from _MAX_ total assignments)",
            lengthMenu: "Show _MENU_ assignments",
            search: "Search:",
            zeroRecords: "No matching assignments found"
        }
    });
    <?php endif; ?>
});

function showToast(type, message) {
    const toast = $(`
        <div class="toast toast-${type}">
            <span class="toast-icon"><i class="fas fa-${type === 'success' ? 'check' : 'times'}"></i></span>
            <span class="toast-message">${message}</span>
        </div>
    `);

    $('body').append(toast);
    toast.fadeIn();

    setTimeout(() => {
        toast.fadeOut(() => toast.remove());
    }, 5000);
}
</script>

<style>
/* Base Styles */
.assignment-management-container {
    padding: 20px;
    max-width: 1400px;
    margin: 0 auto;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    color: #333;
}

/* Header Styles */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    padding-bottom: 15px;
    border-bottom: 1px solid #e3e6f0;
}

.page-header h1 {
    font-size: 28px;
    font-weight: 600;
    margin: 0;
    display: flex;
    align-items: center;
}

.page-header h1 i {
    margin-right: 10px;
    color: #4e73df;
}

.header-actions .btn {
    padding: 8px 16px;
    font-weight: 500;
    border-radius: 4px;
}

/* Summary Cards */
.summary-cards {
    margin-bottom: 30px;
}

.summary-cards .card {
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 4px 6px rgba(0,0,0,0.05);
    transition: transform 0.3s ease;
    height: 100%;
    border: none;
}

.summary-cards .card:hover {
    transform: translateY(-5px);
}

.summary-card {
    position: relative;
    color: white;
}

.summary-card .card-body {
    padding: 20px;
}

.summary-card h5 {
    font-size: 14px;
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-bottom: 10px;
    opacity: 0.9;
    font-weight: 600;
}

.summary-card h2 {
    font-size: 28px;
    font-weight: 700;
    margin-bottom: 15px;
}

.summary-card i {
    position: absolute;
    right: 20px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 50px;
    opacity: 0.3;
}

.total-assignments {
    background: linear-gradient(135deg, #4e73df 0%, #224abe 100%);
}

.active-assignments {
    background: linear-gradient(135deg, #1cc88a 0%, #13855c 100%);
}

.submissions-pending {
    background: linear-gradient(135deg, #f6c23e 0%, #dda20a 100%);
}

/* Course Filter */
.course-filter {
    margin-bottom: 30px;
    border: none;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

.course-filter .card-body {
    padding: 15px 20px;
}

.form-inline {
    display: flex;
    align-items: center;
}

.form-inline label {
    margin-right: 10px;
    font-weight: 500;
}

.form-control {
    border-radius: 4px;
    border: 1px solid #d1d3e2;
    padding: 8px 12px;
}

/* Assignments Table */
.assignments-table {
    border: none;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
    margin-bottom: 30px;
}

.assignments-table .card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 24px;
    background-color: #fff;
    border-bottom: 1px solid #f0f0f0;
}

.assignments-table .card-header h3 {
    margin: 0;
    font-size: 18px;
    font-weight: 600;
    color: #2d3748;
}

.table-actions {
    display: flex;
    gap: 12px;
}

.table-actions .btn {
    padding: 6px 12px;
    font-size: 13px;
    border-radius: 6px;
    display: flex;
    align-items: center;
    gap: 6px;
}

#assignmentsTable {
    width: 100%;
    margin: 0;
    border-collapse: separate;
    border-spacing: 0;
}

#assignmentsTable thead th {
    background-color: #f8fafc;
    color: #64748b;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 12px;
    letter-spacing: 0.5px;
    padding: 14px 16px;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
}

#assignmentsTable tbody td {
    padding: 16px;
    vertical-align: middle;
    border-bottom: 1px solid #f1f5f9;
    background-color: #fff;
    transition: background-color 0.2s ease;
}

#assignmentsTable tbody tr:last-child td {
    border-bottom: none;
}

#assignmentsTable tbody tr:hover td {
    background-color: #f8fafc;
}
/* Cell Content Styles */
.assignment-title {
    font-weight: 500;
    color: #1e293b;
    margin-bottom: 4px;
}

.assignment-description {
    font-size: 13px;
    color: #64748b;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    text-overflow: ellipsis;
    line-height: 1.4;
}

.course-info {
    display: flex;
    align-items: center;
    gap: 10px;
}

.course-badge {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    flex-shrink: 0;
}

.course-name {
    font-weight: 500;
    color: #1e293b;
}

.due-date {
    font-weight: 500;
    color: #1e293b;
    display: block;
    margin-bottom: 4px;
}

.time-remaining {
    font-size: 12px;
    color: #64748b;
}

.submission-count {
    font-weight: 600;
    color: #1e293b;
    font-size: 16px;
    display: block;
}

.submission-label {
    font-size: 12px;
    color: #64748b;
    display: block;
    margin-top: 2px;
}


/* Table Content Styles */
.course-color {
    width: 16px;
    height: 16px;
    border-radius: 50%;
    margin-right: 10px;
    flex-shrink: 0;
}

.assignment-description {
    font-size: 13px;
    color: #6e707e;
    margin-top: 5px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}




.submission-count small {
    font-size: 12px;
    font-weight: normal;
    color: #6e707e;
}

.submission-status {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
    margin-top: 6px;
}

.submission-status.pending {
    background-color: #fff3cd;
    color: #856404;
}

.submission-status.graded {
    background-color: #d4edda;
    color: #155724;
}

.submission-status.none {
    background-color: #f8f9fa;
    color: #6c757d;
}


.status-badge {
    display: inline-block;
    padding: 5px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.status-badge.active {
    background-color: #e1f0fa;
    color: #36a3f7;
}

.status-badge.due-soon {
    background-color: #fef3e6;
    color: #f6a656;
}

.status-badge.completed {
    background-color: #e6f9f0;
    color: #1cc88a;
}

.action-buttons {
    display: flex;
    gap: 8px;
}

.action-buttons .btn {
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0;
    border-radius: 6px;
    transition: all 0.2s ease;
}
.action-buttons .btn:hover {
    transform: translateY(-1px);
}

.action-buttons .btn i {
    font-size: 14px;
}
/* Modal Styles */
.modal-content {
    border: none;
    border-radius: 8px;
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}

.modal-header {
    border-bottom: 1px solid #e3e6f0;
    padding: 15px 20px;
}

.modal-title {
    font-weight: 600;
}

.modal-body {
    padding: 20px;
}

.modal-footer {
    border-top: 1px solid #e3e6f0;
    padding: 15px 20px;
}

.form-group {
    margin-bottom: 20px;
}

.custom-file-label::after {
    content: "Browse";
    background-color: #f8f9fa;
    border-left: 1px solid #d1d3e2;
}

/* File Items */
.file-item {
    padding: 8px;
    background-color: #f8f9fa;
    border-radius: 4px;
    margin-bottom: 8px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.file-item i {
    margin-right: 8px;
    color: #6c757d;
}

/* Toast Notification */
.toast {
    position: fixed;
    bottom: 20px;
    right: 20px;
    padding: 15px 20px;
    border-radius: 4px;
    color: white;
    display: flex;
    align-items: center;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    z-index: 9999;
    display: none;
}

.toast-success {
    background-color: #1cc88a;
}

.toast-error {
    background-color: #e74a3b;
}

.toast-icon {
    margin-right: 10px;
    font-size: 20px;
}

.toast-message {
    font-size: 14px;
}

@media (max-width: 768px) {
    #assignmentsTable thead {
        display: none;
    }
    
    #assignmentsTable tbody tr {
        display: block;
        padding: 16px;
        border-bottom: 1px solid #f1f5f9;
    }
    
    #assignmentsTable tbody td {
        display: flex;
        justify-content: space-between;
        padding: 8px 0;
        border-bottom: none;
    }
    
    #assignmentsTable tbody td::before {
        content: attr(data-label);
        font-weight: 600;
        color: #64748b;
        margin-right: 12px;
    }
    
    .action-buttons {
        justify-content: flex-end;
        margin-top: 12px;
    }
    
    .assignment-description {
        -webkit-line-clamp: 3;
    }
}

@media (max-width: 576px) {
    .assignments-table .card-header {
        padding: 12px;
    }
    
    .table-actions .btn {
        padding: 5px 8px;
        font-size: 12px;
    }
    
    .action-buttons .btn {
        width: 28px;
        height: 28px;
    }
}

/* Responsive Adjustments */
@media (max-width: 768px) {
    .page-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .header-actions {
        margin-top: 15px;
        width: 100%;
    }
    
    .header-actions .btn {
        width: 100%;
    }
    
    .form-inline {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .form-inline .form-control {
        width: 100%;
        margin-bottom: 10px;
        margin-right: 0;
    }
    
    .form-inline .btn {
        width: 100%;
    }
    
    .table-actions {
        margin-top: 10px;
        width: 100%;
        justify-content: flex-end;
    }
    
    #assignmentsTable td {
        padding: 10px 5px;
    }
    
    .action-buttons {
        flex-direction: column;
        gap: 3px;
    }
    
    .action-buttons .btn {
        width: 28px;
        height: 28px;
        font-size: 12px;
    }
    
    .summary-card h5 {
        font-size: 12px;
    }
    
    .summary-card h2 {
        font-size: 24px;
    }
    
    .summary-card i {
        font-size: 40px;
    }
}

@media (max-width: 576px) {
    .assignment-management-container {
        padding: 15px;
    }
    
    .page-header h1 {
        font-size: 24px;
    }
    
    .summary-card .card-body {
        padding: 15px;
    }
    
    .modal-dialog {
        margin: 10px;
    }
}
</style>

<?php require_once '../includes/footer.php'; ?>