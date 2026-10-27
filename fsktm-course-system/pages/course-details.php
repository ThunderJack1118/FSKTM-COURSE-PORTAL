<?php
require_once '../config/config.php';
require_once '../includes/functions.php';

if (!isLoggedIn()) {
    redirect('/pages/auth/login.php');
}

if (!isset($_GET['id'])) {
    redirect('/pages/courses.php');
}

$course_id = (int)$_GET['id'];
$db = new Database();
$conn = $db->getConnection();

// Get course details with instructor info
$stmt = $conn->prepare("SELECT c.*, CONCAT(u.first_name, ' ', u.last_name) AS full_name, u.email AS instructor_email, 
                        cc.category_name, cc.color_code 
                        FROM courses c 
                        LEFT JOIN users u ON c.instructor_id = u.user_id
                        LEFT JOIN course_categories cc ON c.category_id = cc.category_id
                        WHERE c.course_id = ?");
$stmt->bind_param("i", $course_id);
$stmt->execute();
$course = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$course) {
    $db->closeConnection();
    redirect('/pages/courses.php');
}

// Check enrollment/registration status
$is_enrolled = false;
$is_lecturer = ($course['instructor_id'] == $_SESSION['user_id']);

if (isStudent()) {
    $stmt = $conn->prepare("SELECT status, grade FROM registrations 
                           WHERE user_id = ? AND course_id = ?");
    $stmt->bind_param("ii", $_SESSION['user_id'], $course_id);
    $stmt->execute();
    $registration = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $is_enrolled = (bool)$registration;
}

// Get announcements
$stmt = $conn->prepare("SELECT * FROM announcements 
                       WHERE course_id = ? 
                       ORDER BY created_at DESC LIMIT 5");
$stmt->bind_param("i", $course_id);
$stmt->execute();
$announcements = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Handle announcement creation if lecturer
if ($is_lecturer && isset($_POST['create_announcement'])) {
    $title = trim($_POST['title']);
    $content = trim($_POST['content']);
    $priority = $_POST['priority'];
    
    if (!empty($title) && !empty($content)) {
        $stmt = $conn->prepare("INSERT INTO announcements (course_id, title, content, priority, created_by) 
                               VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("isssi", $course_id, $title, $content, $priority, $_SESSION['user_id']);
        if ($stmt->execute()) {
            $_SESSION['success'] = "Announcement created successfully!";
            // Redirect to prevent form resubmission
            header("Location: course-details.php?id=$course_id");
            exit();
        } else {
            $_SESSION['error'] = "Failed to create announcement: " . $conn->error;
        }
    } else {
        $_SESSION['error'] = "Title and content cannot be empty";
    }
}

// Handle resource upload if lecturer
if ($is_lecturer && isset($_FILES['resource_file'])) {
    $title = trim($_POST['resource_title']);
    $description = trim($_POST['resource_description']);
    
    if (!empty($_FILES['resource_file']['name']) && !empty($title)) {
        $upload_dir = '../uploads/resources/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $file_name = time() . '_' . basename($_FILES['resource_file']['name']);
        $target_path = $upload_dir . $file_name;
        
        if (move_uploaded_file($_FILES['resource_file']['tmp_name'], $target_path)) {
            $stmt = $conn->prepare("INSERT INTO course_resources (course_id, title, description, file_path, uploaded_by) 
                                   VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("isssi", $course_id, $title, $description, $file_name, $_SESSION['user_id']);
            if ($stmt->execute()) {
                $_SESSION['success'] = "Resource uploaded successfully!";
            } else {
                $_SESSION['error'] = "Failed to save resource info: " . $conn->error;
                unlink($target_path); // Remove uploaded file if DB insert failed
            }
        } else {
            $_SESSION['error'] = "File upload failed";
        }
    } else {
        $_SESSION['error'] = "Title and file are required";
    }
}

// Get course resources
$resources = [];
$stmt = $conn->prepare("SELECT * FROM course_resources WHERE course_id = ? ORDER BY uploaded_at DESC");
$stmt->bind_param("i", $course_id);
$stmt->execute();
$resources = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get assignments with additional status info
$assignments = [];
if ($is_lecturer || $is_enrolled) {
    // Modified query to get all needed data at once
    $query = "SELECT a.*,
             (SELECT COUNT(*) FROM submissions s WHERE s.assignment_id = a.assignment_id) as submission_count
             FROM assignments a 
             WHERE a.course_id = ? 
             ORDER BY a.due_date";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $course_id);
    $stmt->execute();
    $assignments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // For students, get their submission status for each assignment
    if ($is_enrolled) {
        foreach ($assignments as &$assignment) {
            $stmt = $conn->prepare("SELECT * FROM submissions 
                                  WHERE assignment_id = ? AND user_id = ?");
            $stmt->bind_param("ii", $assignment['assignment_id'], $_SESSION['user_id']);
            $stmt->execute();
            $submission = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            $assignment['submission'] = $submission;
            $assignment['status'] = getAssignmentStatus(
                $assignment['due_date'],
                $submission['submission_date'] ?? null,
                !empty($submission['marks'])
            );
        }
        unset($assignment); // Unset the reference
    }
}

$db->closeConnection();

$pageTitle = $course['course_name'];
include '../includes/header.php';
?>


<div class="course-details-container">
    <div class="course-header">
        <div class="course-header-content">
            <!-- Course title and basic info -->
            <div class="course-title-section">
                <div class="course-badge" style="background-color: <?= $course['color_code'] ?>">
                    <?= htmlspecialchars($course['category_name']) ?>
                </div>
                <h1><?= htmlspecialchars($course['course_name']) ?></h1>
                <p class="course-code"><?= htmlspecialchars($course['course_code']) ?></p>
            </div>
            
            <!-- Course metadata in a compact grid -->
            <div class="course-meta-grid">
                <div class="meta-item">
                    <i class="fas fa-chalkboard-teacher"></i>
                    <div>
                        <span class="meta-label">Instructor</span>
                        <span class="meta-value"><?= htmlspecialchars($course['full_name']) ?></span>
                    </div>
                </div>
                <div class="meta-item">
                    <i class="fas fa-envelope"></i>
                    <div>
                        <span class="meta-label">Email</span>
                        <span class="meta-value"><?= htmlspecialchars($course['instructor_email']) ?></span>
                    </div>
                </div>
                <div class="meta-item">
                    <i class="fas fa-calendar-alt"></i>
                    <div>
                        <span class="meta-label">Schedule</span>
                        <span class="meta-value"><?= htmlspecialchars($course['schedule_day']) ?> at <?= htmlspecialchars($course['schedule_time']) ?></span>
                    </div>
                </div>
                <div class="meta-item">
                    <i class="fas fa-map-marker-alt"></i>
                    <div>
                        <span class="meta-label">Location</span>
                        <span class="meta-value"><?= htmlspecialchars($course['classroom']) ?></span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Status card with action buttons -->
        <div class="course-status-card">
            <?php if (isStudent()): ?>
                <?php if ($is_enrolled): ?>
                    <div class="status-message success">
                        <i class="fas fa-check-circle"></i>
                        <div>
                            <h4>You're enrolled</h4>
                            <?php if (!empty($registration['grade'])): ?>
                                <p>Current grade: <strong><?= $registration['grade'] ?></strong></p>
                            <?php else: ?>
                                <p>Active in this course</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <a href="<?= BASE_URL ?>/pages/my-submissions.php?course_id=<?= $course_id ?>" 
                    class="btn btn-primary btn-block">
                        <i class="fas fa-file-alt"></i> View Submissions
                    </a>
                <?php else: ?>
                    <div class="status-message info">
                        <i class="fas fa-info-circle"></i>
                        <div>
                            <h4>Not enrolled</h4>
                            <p>Register to access all materials</p>
                        </div>
                    </div>
                    <a href="<?= BASE_URL ?>/pages/registration.php?course_id=<?= $course_id ?>" 
                    class="btn btn-primary btn-block">
                        <i class="fas fa-user-plus"></i> Register Now
                    </a>
                <?php endif; ?>
            <?php elseif ($is_lecturer): ?>
                <div class="status-message warning">
                    <i class="fas fa-chalkboard-teacher"></i>
                    <div>
                        <h4>You're teaching</h4>
                        <p>Course instructor</p>
                    </div>
                </div>
                <a href="<?= BASE_URL ?>/pages/assignment-management.php?course_id=<?= $course_id ?>" 
                class="btn btn-secondary btn-block">
                    <i class="fas fa-tasks"></i> Manage Course
                </a>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="course-description">
        <h3>Course Description</h3>
        <div class="bg-light p-3 rounded">
            <?= nl2br(htmlspecialchars($course['description'])) ?>
        </div>
    </div>
    
    <?php if (!empty($course['prerequisites'])): ?>
        <div class="course-prerequisites mt-4">
            <h3>Prerequisites</h3>
            <div class="bg-light p-3 rounded">
                <?= nl2br(htmlspecialchars($course['prerequisites'])) ?>
            </div>
        </div>
    <?php endif; ?>
    
    <div class="course-tabs mt-4">
        <ul class="tab-nav">
            <li class="active"><a href="#announcements"><i class="fas fa-bullhorn mr-1"></i> Announcements</a></li>
            <li><a href="#assignments"><i class="fas fa-tasks mr-1"></i> Assignments</a></li>
            <li><a href="#resources"><i class="fas fa-book mr-1"></i> Resources</a></li>
        </ul>
        
        <div class="tab-content">
            <!-- Announcements Tab -->
            <div id="announcements" class="tab-pane active">
                <?php if (empty($announcements)): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle mr-2"></i>No announcements have been posted yet.
                    </div>
                <?php else: ?>
                    <div class="announcement-list">
                        <?php foreach ($announcements as $announcement): ?>
                            <div class="announcement-item">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h4>
                                        <?= htmlspecialchars($announcement['title']) ?>
                                        <?php if ($announcement['priority'] === 'high'): ?>
                                            <span class="priority high">High Priority</span>
                                        <?php elseif ($announcement['priority'] === 'medium'): ?>
                                            <span class="priority medium">Medium Priority</span>
                                        <?php endif; ?>
                                    </h4>
                                    <div>
                                        <small class="text-muted"><?= date('M j, Y g:i a', strtotime($announcement['created_at'])) ?></small>
                                        <?php if ($is_lecturer): ?>
                                            <button class="btn btn-sm btn-outline-danger ml-2 delete-announcement" 
                                                    data-id="<?= $announcement['announcement_id'] ?>">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <p><?= nl2br(htmlspecialchars($announcement['content'])) ?></p>
                                <?php if (!empty($announcement['created_by'])): ?>
                                    <small class="text-muted">
                                        Posted by: <?= get_user_name($announcement['created_by']) ?>
                                    </small>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($is_lecturer): ?>
                    <div class="create-announcement mt-4">
                        <h4>Create New Announcement</h4>
                        <form method="POST">
                            <div class="form-group">
                                <label for="title">Title</label>
                                <input type="text" class="form-control" id="title" name="title" required>
                            </div>
                            <div class="form-group">
                                <label for="content">Content</label>
                                <textarea class="form-control" id="content" name="content" rows="3" required></textarea>
                            </div>
                            <div class="form-group">
                                <label for="priority">Priority</label>
                                <select class="form-control" id="priority" name="priority">
                                    <option value="normal">Normal</option>
                                    <option value="medium">Medium</option>
                                    <option value="high">High</option>
                                </select>
                            </div>
                            <button type="submit" name="create_announcement" class="btn btn-primary">Post Announcement</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Assignments Tab -->
            <div id="assignments" class="tab-pane">
                <?php if (empty($assignments)): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle mr-2"></i>No assignments have been posted yet.
                    </div>
                <?php else: ?>
                    <div class="assignment-list">
                        <table>
                            <thead>
                                <tr>
                                    <th>Assignment</th>
                                    <th>Due Date</th>
                                    <th>Max Marks</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($assignments as $assignment): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($assignment['title']) ?></strong>
                                            <?php if (!empty($assignment['description'])): ?>
                                                <p class="text-muted small mb-0 mt-1"><?= htmlspecialchars($assignment['description']) ?></p>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="d-flex flex-column">
                                                <span><?= date('M j, Y', strtotime($assignment['due_date'])) ?></span>
                                                <small class="text-muted"><?= date('g:i a', strtotime($assignment['due_date'])) ?></small>
                                            </div>
                                        </td>
                                        <td><?= $assignment['max_marks'] ?></td>
                                        <td>
                                            <?php if ($is_lecturer): ?>
                                                <span class="status-badge info">
                                                    <?= $assignment['submission_count'] ?> submission<?= $assignment['submission_count'] != 1 ? 's' : '' ?>
                                                </span>
                                            <?php elseif ($is_enrolled): ?>
                                                <?php 
                                                $status_class = str_replace(' ', '-', $assignment['status']);
                                                ?>
                                                <span class="status-badge <?= $status_class ?>">
                                                    <?= ucfirst($assignment['status']) ?>
                                                </span>
                                                <?php if (!empty($assignment['submission']['marks'])): ?>
                                                    <div class="mt-1">
                                                        <small class="text-success">
                                                            Grade: <?= $assignment['submission']['marks'] ?>/<?= $assignment['max_marks'] ?>
                                                        </small>
                                                    </div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="status-badge secondary">Not enrolled</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($is_lecturer): ?>
                                                <a href="<?= BASE_URL ?>/pages/assignment-view.php?id=<?= $assignment['assignment_id'] ?>" 
                                                   class="btn btn-info btn-small">
                                                    <i class="fas fa-eye mr-1"></i>View
                                                </a>
                                            <?php elseif ($is_enrolled): ?>
                                                <?php if (strtotime($assignment['due_date']) >= time()): ?>
                                                    <a href="<?= BASE_URL ?>/pages/assignment-submit.php?id=<?= $assignment['assignment_id'] ?>" 
                                                       class="btn btn-primary btn-small">
                                                        <i class="fas fa-upload mr-1"></i><?= $assignment['submission'] ? 'Resubmit' : 'Submit' ?>
                                                    </a>
                                                <?php endif; ?>
                                                <?php if ($assignment['submission']): ?>
                                                    <a href="<?= BASE_URL ?>/pages/my-submissions.php" 
                                                       class="btn btn-outline btn-small">
                                                        <i class="fas fa-file-alt mr-1"></i>Details
                                                    </a>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Resources Tab -->
            <div id="resources" class="tab-pane">
                <?php if (!empty($resources)): ?>
                    <div class="resource-list">
                        <?php foreach ($resources as $resource): ?>
                            <div class="resource-item card mb-3">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <h5><?= htmlspecialchars($resource['title']) ?></h5>
                                            <?php if (!empty($resource['description'])): ?>
                                                <p><?= nl2br(htmlspecialchars($resource['description'])) ?></p>
                                            <?php endif; ?>
                                            <small class="text-muted">
                                                Uploaded on <?= date('M j, Y', strtotime($resource['uploaded_at'])) ?>
                                            </small>
                                        </div>
                                        <div class="resource-actions">
                                            <a href="<?= BASE_URL ?>/uploads/resources/<?= htmlspecialchars($resource['file_path']) ?>" 
                                            class="btn btn-primary btn-sm" download>
                                                <i class="fas fa-download"></i> Download
                                            </a>
                                            <?php if ($is_lecturer): ?>
                                                <button class="btn btn-danger btn-sm delete-resource" 
                                                        data-id="<?= $resource['resource_id'] ?>">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle mr-2"></i>
                        No resources have been uploaded yet.
                    </div>
                <?php endif; ?>
                
                <?php if ($is_lecturer): ?>
                    <div class="upload-resource mt-4">
                        <h4>Upload New Resource</h4>
                        <form method="POST" enctype="multipart/form-data">
                            <div class="form-group">
                                <label for="resource_title">Title</label>
                                <input type="text" class="form-control" id="resource_title" name="resource_title" required>
                            </div>
                            <div class="form-group">
                                <label for="resource_description">Description (Optional)</label>
                                <textarea class="form-control" id="resource_description" name="resource_description" rows="2"></textarea>
                            </div>
                            <div class="form-group">
                                <label for="resource_file">File</label>
                                <div class="custom-file">
                                    <input type="file" class="custom-file-input" id="resource_file" name="resource_file" required>
                                    <label class="custom-file-label" for="resource_file">Choose file (max 10MB)</label>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary">Upload Resource</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
// Simple tab functionality
document.addEventListener('DOMContentLoaded', function() {
    const tabLinks = document.querySelectorAll('.tab-nav a');
    const tabPanes = document.querySelectorAll('.tab-pane');
    
    tabLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            
            // Remove active class from all tabs
            document.querySelector('.tab-nav li.active').classList.remove('active');
            document.querySelector('.tab-pane.active').classList.remove('active');
            
            // Add active class to clicked tab
            this.parentElement.classList.add('active');
            document.querySelector(this.getAttribute('href')).classList.add('active');
        });
    });
});

$(document).ready(function() {
    // Handle file input labels
    $('.custom-file-input').on('change', function() {
        let fileName = $(this).val().split('\\').pop();
        $(this).next('.custom-file-label').html(fileName);
    });
});

$(document).on('click', '.delete-announcement', async function(e) {
    e.preventDefault();
    const button = $(this);
    const announcementId = button.data('id');
    
    if (!confirm('Are you sure you want to delete this announcement?')) return;

    button.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');

    try {
        const response = await fetch('../api/announcement-api.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                action: 'delete',
                announcement_id: announcementId
            }),
            credentials: 'include'
        });

        const data = await response.json();
        
        if (!response.ok) {
            throw new Error(data.message || 'Request failed');
        }

        if (data.success) {
            button.closest('.announcement-item').fadeOut(300, function() {
                $(this).remove();
                if ($('.announcement-item').length === 0) {
                    $('.announcement-list').html(
                        '<div class="alert alert-info">No announcements found</div>'
                    );
                }
            });
        } else {
            throw new Error(data.message || 'Deletion failed');
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Error: ' + error.message);
    } finally {
        button.prop('disabled', false).html('<i class="fas fa-trash"></i>');
    }
});
</script>

<style>
/* Course Header Styles */
.course-header {
    display: flex;
    flex-wrap: wrap;
    gap: 2rem;
    margin-bottom: 2rem;
    padding-bottom: 2rem;
    border-bottom: 1px solid #eee;
}

.course-header-content {
    flex: 1;
    min-width: 300px;
}

.course-title-section {
    margin-bottom: 1.5rem;
}

.course-badge {
    display: inline-block;
    padding: 0.35rem 0.75rem;
    border-radius: 4px;
    color: white;
    font-weight: 600;
    font-size: 0.875rem;
    margin-bottom: 0.75rem;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.course-header h1 {
    font-size: 2.25rem;
    margin-bottom: 0.25rem;
    color: var(--dark-color);
}

.course-code {
    color: var(--gray-color);
    font-size: 1.1rem;
    margin-bottom: 1.5rem;
}

.course-meta-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 1rem;
}

.meta-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem;
    background-color: var(--light-color);
    border-radius: 6px;
}

.meta-item i {
    font-size: 1.25rem;
    color: var(--primary-color);
    width: 30px;
    text-align: center;
}

.meta-label {
    display: block;
    font-size: 0.75rem;
    color: var(--gray-color);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.meta-value {
    display: block;
    font-weight: 500;
    color: var(--dark-color);
}

.course-status-card {
    min-width: 280px;
    background: white;
    border-radius: 8px;
    padding: 1.5rem;
    box-shadow: 0 2px 10px rgba(0,0,0,0.08);
    align-self: flex-start;
}

.status-message {
    display: flex;
    gap: 1rem;
    align-items: center;
    margin-bottom: 1.5rem;
    padding: 1rem;
    border-radius: 6px;
}

.status-message i {
    font-size: 1.75rem;
}

.status-message.success {
    background-color: rgba(46, 204, 113, 0.1);
    color: var(--secondary-color);
}

.status-message.info {
    background-color: rgba(52, 152, 219, 0.1);
    color: var(--primary-color);
}

.status-message.warning {
    background-color: rgba(243, 156, 18, 0.1);
    color: var(--warning-color);
}

.status-message h4 {
    margin-bottom: 0.25rem;
    font-size: 1.1rem;
}

.status-message p {
    margin-bottom: 0;
    font-size: 0.9rem;
    color: var(--dark-color);
}

.btn-block {
    display: block;
    width: 100%;
    text-align: center;
}
.create-announcement, .upload-resource {
    background-color: #f8f9fa;
    padding: 20px;
    border-radius: 8px;
    margin-top: 20px;
}

.resource-item {
    transition: transform 0.2s ease;
}

.resource-item:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

.resource-actions {
    display: flex;
    gap: 10px;
    align-items: flex-start;
}

.custom-file-label::after {
    content: "Browse";
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .resource-item .d-flex {
        flex-direction: column;
    }
    
    .resource-actions {
        margin-top: 10px;
        justify-content: flex-end;
    }
}

@media (max-width: 768px) {
    .course-header {
        flex-direction: column;
    }
    
    .course-status-card {
        width: 100%;
    }
    
    .course-header h1 {
        font-size: 1.75rem;
    }
}
.announcement-item {
    position: relative;
}

.delete-announcement {
    position: absolute;
    top: 10px;
    right: 10px;
    opacity: 0;
    transition: opacity 0.2s ease;
}

.announcement-item:hover .delete-announcement {
    opacity: 1;
}

.priority {
    font-size: 0.7rem;
    padding: 2px 6px;
    border-radius: 4px;
    margin-left: 8px;
    font-weight: 600;
}

.priority.high {
    background-color: #f8d7da;
    color: #721c24;
}

.priority.medium {
    background-color: #fff3cd;
    color: #856404;
}
.announcement-item {
    transition: all 0.3s ease;
}

.announcement-item:hover {
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
}

.delete-announcement {
    transition: all 0.2s ease;
}

.delete-announcement:hover {
    transform: scale(1.1);
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .delete-announcement {
        opacity: 1; /* Always show on mobile */
    }
}

</style>

<?php include '../includes/footer.php'; ?>