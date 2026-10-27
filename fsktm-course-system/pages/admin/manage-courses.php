<?php
// pages/admin/manage-courses.php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) {
    redirect('/pages/auth/login.php');
}

if (!function_exists('ucpUpper')) {
    function ucpUpper($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        if (function_exists('mb_strtoupper')) {
            return mb_strtoupper($value, 'UTF-8');
        }
        return strtoupper($value);
    }
}

if (!function_exists('e')) {
    function e($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

$db = new Database();
$conn = $db->getConnection();

// Handle course deletion
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['delete'])) {
    $course_id = (int) $_GET['delete'];

    try {
        $stmt = $conn->prepare("SELECT COUNT(*) AS count FROM registrations WHERE course_id = ?");
        $stmt->bind_param("i", $course_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (($result['count'] ?? 0) > 0) {
            $_SESSION['error'] = "Cannot delete course with active registrations. Please deactivate it instead.";
        } else {
            $stmt = $conn->prepare("DELETE FROM courses WHERE course_id = ?");
            $stmt->bind_param("i", $course_id);

            if ($stmt->execute()) {
                $_SESSION['success'] = "Course deleted successfully.";
            } else {
                throw new Exception("Failed to delete course.");
            }
            $stmt->close();
        }
    } catch (Exception $e) {
        $_SESSION['error'] = "Unable to delete the selected course.";
    }

    redirect('/pages/admin/manage-courses.php');
}

$courses = [];
$instructors = [];
$categories = [];
$courseStats = [
    'total' => 0,
    'active' => 0,
    'categories' => 0,
    'approved_registrations' => 0,
];

try {
    $query = "SELECT c.*, u.first_name, u.last_name, cc.category_name,
                     COALESCE(reg.approved_count, 0) AS approved_count
              FROM courses c
              LEFT JOIN users u ON c.instructor_id = u.user_id
              LEFT JOIN course_categories cc ON c.category_id = cc.category_id
              LEFT JOIN (
                  SELECT course_id, COUNT(*) AS approved_count
                  FROM registrations
                  WHERE status = 'approved'
                  GROUP BY course_id
              ) reg ON reg.course_id = c.course_id
              ORDER BY c.course_name";
    $courses = $conn->query($query)->fetch_all(MYSQLI_ASSOC);

    $stmt = $conn->prepare("SELECT user_id, first_name, last_name FROM users WHERE user_type = ? ORDER BY first_name, last_name");
    $user_type = ROLE_LECTURER;
    $stmt->bind_param("s", $user_type);
    $stmt->execute();
    $instructors = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $categories = getCourseCategories();

    $courseStats['total'] = count($courses);
    foreach ($courses as $course) {
        if (!empty($course['is_active'])) {
            $courseStats['active']++;
        }
        $courseStats['approved_registrations'] += (int) ($course['approved_count'] ?? 0);
    }
    $courseStats['categories'] = count($categories);
} catch (Exception $e) {
    $_SESSION['error'] = "Failed to load course management data.";
}

$db->closeConnection();

$courseNotices = [];
if (!empty($_GET['success'])) {
    $courseNotices[] = ['type' => 'success', 'title' => 'Action Completed', 'message' => (string) $_GET['success']];
}
if (!empty($_GET['error'])) {
    $courseNotices[] = ['type' => 'error', 'title' => 'Action Failed', 'message' => (string) $_GET['error']];
}
foreach (['success' => 'Action Completed', 'error' => 'Action Failed', 'warning' => 'Notice', 'info' => 'Information'] as $key => $title) {
    if (!empty($_SESSION[$key])) {
        $courseNotices[] = ['type' => $key === 'error' ? 'error' : $key, 'title' => $title, 'message' => (string) $_SESSION[$key]];
        unset($_SESSION[$key]);
    }
}

function ucpCourseInitials($courseName) {
    $words = preg_split('/\s+/', trim((string) $courseName));
    $letters = '';
    foreach ($words as $word) {
        if ($word !== '') {
            $letters .= strtoupper(substr($word, 0, 1));
        }
        if (strlen($letters) >= 2) {
            break;
        }
    }
    return $letters !== '' ? $letters : 'CR';
}

function ucpCoursePayload($course) {
    return htmlspecialchars(json_encode([
        'course_id' => (int) $course['course_id'],
        'course_code' => (string) $course['course_code'],
        'course_name' => (string) $course['course_name'],
        'description' => (string) ($course['description'] ?? ''),
        'credits' => (int) $course['credits'],
        'semester' => (string) $course['semester'],
        'academic_year' => (string) $course['academic_year'],
        'max_students' => (int) $course['max_students'],
        'instructor_id' => $course['instructor_id'] !== null ? (int) $course['instructor_id'] : '',
        'schedule_day' => (string) ($course['schedule_day'] ?? ''),
        'schedule_time' => (string) ($course['schedule_time'] ?? ''),
        'classroom' => (string) ($course['classroom'] ?? ''),
        'prerequisites' => (string) ($course['prerequisites'] ?? ''),
        'category_id' => $course['category_id'] !== null ? (int) $course['category_id'] : '',
        'is_active' => (int) $course['is_active'],
    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8');
}

$pageTitle = "Manage Courses";
include '../../includes/header.php';
?>

<div class="ucp-admin-page ucp-course-page">
    <section class="ucp-page-hero">
        <div>
            <p class="ucp-eyebrow">Course Administration</p>
            <h1>Course Management</h1>
            <p class="ucp-muted">Create, update, and monitor secure course offerings for the UTHM Secure Course Portal.</p>
        </div>
        <button type="button" class="ucp-btn ucp-btn-primary" data-open-course-modal="add">
            <i class="fas fa-plus"></i> Add Course
        </button>
    </section>

    <section class="ucp-stats-grid">
        <div class="ucp-stat-card">
            <div class="ucp-stat-icon"><i class="fas fa-book-open"></i></div>
            <div><span>Total Courses</span><strong><?= (int) $courseStats['total'] ?></strong></div>
        </div>
        <div class="ucp-stat-card">
            <div class="ucp-stat-icon is-green"><i class="fas fa-check-circle"></i></div>
            <div><span>Active Courses</span><strong><?= (int) $courseStats['active'] ?></strong></div>
        </div>
        <div class="ucp-stat-card">
            <div class="ucp-stat-icon is-blue"><i class="fas fa-layer-group"></i></div>
            <div><span>Categories</span><strong><?= (int) $courseStats['categories'] ?></strong></div>
        </div>
        <div class="ucp-stat-card">
            <div class="ucp-stat-icon is-dark"><i class="fas fa-user-graduate"></i></div>
            <div><span>Approved Enrolments</span><strong><?= (int) $courseStats['approved_registrations'] ?></strong></div>
        </div>
    </section>

    <section class="ucp-panel">
        <div class="ucp-panel-toolbar">
            <div>
                <h2>Course Directory</h2>
                <p class="ucp-muted">Search and filter all registered courses.</p>
            </div>
            <div class="ucp-table-tools">
                <label class="ucp-searchbox" for="courseSearchInput">
                    <i class="fas fa-search"></i>
                    <input type="search" id="courseSearchInput" placeholder="Search code, name, instructor..." data-ucp-course-search>
                </label>
                <div class="ucp-filter-popout-wrap">
                    <button type="button" class="ucp-filter-button" data-ucp-filter-toggle>
                        <i class="fas fa-sliders-h"></i> Filters <span data-ucp-filter-count hidden>0</span>
                    </button>
                    <div class="ucp-filter-popout" data-ucp-filter-panel hidden>
                        <div class="ucp-filter-popout-header">
                            <strong>Course Filters</strong>
                            <button type="button" data-ucp-filter-reset>Reset</button>
                        </div>
                        <label for="courseStatusFilter">Status</label>
                        <select id="courseStatusFilter" class="ucp-filter-select" data-ucp-course-status>
                            <option value="all">All status</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                        <label for="courseCategoryFilter">Category</label>
                        <select id="courseCategoryFilter" class="ucp-filter-select" data-ucp-course-category>
                            <option value="all">All categories</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= htmlspecialchars(strtolower($category['category_name']), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(ucpUpper($category['category_name'])) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="ucp-btn ucp-btn-primary ucp-filter-apply" data-ucp-filter-apply>Apply Filters</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="ucp-table-wrap">
            <table class="ucp-modern-table ucp-course-table">
                <thead>
                    <tr>
                        <th>Course</th>
                        <th>Code</th>
                        <th>Instructor</th>
                        <th>Category</th>
                        <th>Semester</th>
                        <th>Capacity</th>
                        <th>Status</th>
                        <th class="ucp-actions-col">Actions</th>
                    </tr>
                </thead>
                <tbody data-ucp-course-rows>
                    <?php foreach ($courses as $course): ?>
                        <?php
                            $status = !empty($course['is_active']) ? 'active' : 'inactive';
                            $category = $course['category_name'] ?? 'Uncategorised';
                            $instructor = trim(($course['first_name'] ?? '') . ' ' . ($course['last_name'] ?? '')) ?: 'Unassigned';
                            $approved = (int) ($course['approved_count'] ?? 0);
                            $maxStudents = max(1, (int) ($course['max_students'] ?? 1));
                            $capacityPct = min(100, round(($approved / $maxStudents) * 100));
                            $searchText = strtolower(implode(' ', [$course['course_code'], $course['course_name'], $instructor, $category, $course['semester']]));
                        ?>
                        <tr data-ucp-course-row data-search="<?= htmlspecialchars($searchText, ENT_QUOTES, 'UTF-8') ?>" data-status="<?= $status ?>" data-category="<?= htmlspecialchars(strtolower($category), ENT_QUOTES, 'UTF-8') ?>">
                            <td>
                                <div class="ucp-user-cell">
                                    <div class="ucp-avatar"><?= htmlspecialchars(ucpCourseInitials($course['course_name'])) ?></div>
                                    <div>
                                        <span class="ucp-user-name"><?= htmlspecialchars(ucpUpper($course['course_name'])) ?></span>
                                        <span><?= htmlspecialchars(ucpUpper($course['academic_year'] ?? '-')) ?></span>
                                    </div>
                                </div>
                            </td>
                            <td><code><?= htmlspecialchars($course['course_code']) ?></code></td>
                            <td><?= htmlspecialchars(ucpUpper($instructor)) ?></td>
                            <td><?= htmlspecialchars(ucpUpper($category)) ?></td>
                            <td>Semester <?= htmlspecialchars($course['semester']) ?></td>
                            <td>
                                <div class="ucp-capacity-cell">
                                    <span><?= $approved ?> / <?= (int) $course['max_students'] ?></span>
                                    <div class="ucp-progress"><span style="width: <?= $capacityPct ?>%"></span></div>
                                </div>
                            </td>
                            <td>
                                <span class="ucp-status-dot <?= $status ?>"><span></span><?= ucpUpper($status) ?></span>
                            </td>
                            <td class="ucp-actions-col">
                                <div class="ucp-action-buttons">
                                    <a href="<?= BASE_URL ?>/pages/course-details.php?id=<?= (int) $course['course_id'] ?>" class="ucp-icon-btn info" title="View course"><i class="fas fa-eye"></i></a>
                                    <button type="button" class="ucp-icon-btn warning" title="Edit course" data-course='<?= ucpCoursePayload($course) ?>' data-open-course-modal="edit"><i class="fas fa-edit"></i></button>
                                    <a href="<?= BASE_URL ?>/pages/admin/manage-courses.php?delete=<?= (int) $course['course_id'] ?>" class="ucp-icon-btn danger" title="Delete course" onclick="return confirm('Are you sure you want to delete this course?')"><i class="fas fa-trash"></i></a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr data-ucp-course-empty hidden>
                        <td colspan="8" class="ucp-empty-state">
                            <i class="fas fa-search"></i>
                            <strong>No courses found</strong>
                            <span>Try another keyword or reset the filters.</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</div>

<div class="ucp-notice-overlay" id="courseNoticeOverlay" aria-hidden="true" data-notices='<?= e(json_encode($courseNotices, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>'>
    <div class="ucp-notice-card" role="dialog" aria-modal="true" aria-labelledby="courseNoticeTitle">
        <button type="button" class="ucp-notice-close" id="courseNoticeClose" aria-label="Close message"><i class="fas fa-times"></i></button>
        <div class="ucp-notice-icon" id="courseNoticeIcon"><i class="fas fa-circle-check"></i></div>
        <p class="ucp-eyebrow" id="courseNoticeEyebrow">SYSTEM MESSAGE</p>
        <h2 id="courseNoticeTitle">Action Completed</h2>
        <p id="courseNoticeMessage">The action has been completed.</p>
        <div class="ucp-notice-actions">
            <button type="button" class="ucp-btn ucp-btn-primary" id="courseNoticeOk">OK</button>
        </div>
    </div>
</div>

<!-- Course Modal -->
<div id="course-modal" class="ucp-modal" aria-hidden="true">
    <div class="ucp-modal-card ucp-course-modal-card" role="dialog" aria-modal="true" aria-labelledby="course-modal-title">
        <div class="ucp-modal-header">
            <div>
                <p class="ucp-eyebrow">Course Record</p>
                <h2 id="course-modal-title">Add Course</h2>
                <p>Create or update academic course details.</p>
            </div>
            <button type="button" class="ucp-modal-close" data-close-course-modal aria-label="Close modal"><i class="fas fa-times"></i></button>
        </div>

        <form id="course-form" class="ucp-course-form" onsubmit="submitCourseForm(event)">
            <input type="hidden" name="action" id="course_form_action" value="add">
            <input type="hidden" name="course_id" id="course_id">

            <div class="ucp-modal-body">
                <div id="course-modal-alert" class="ucp-inline-alert" hidden></div>
                <div class="ucp-form-grid ucp-course-form-grid ucp-course-form-card">
                    <div class="ucp-field-group span-2">
                        <h3><i class="fas fa-id-card"></i> Course Information</h3>
                    </div>
                    <div class="ucp-field-group">
                        <label for="course_code">Course Code <span>*</span></label>
                        <input type="text" id="course_code" name="course_code" placeholder="e.g. BIS20303" required>
                    </div>
                    <div class="ucp-field-group">
                        <label for="course_name">Course Name <span>*</span></label>
                        <input type="text" id="course_name" name="course_name" placeholder="e.g. Web Security" required>
                    </div>
                    <div class="ucp-field-group span-2">
                        <label for="description">Description</label>
                        <textarea id="description" name="description" placeholder="Short course description"></textarea>
                    </div>

                    <div class="ucp-field-group span-2">
                        <h3><i class="fas fa-calendar-alt"></i> Academic Setup</h3>
                    </div>
                    <div class="ucp-field-group">
                        <label for="credits">Credits <span>*</span></label>
                        <input type="number" id="credits" name="credits" min="1" max="10" value="3" required>
                    </div>
                    <div class="ucp-field-group">
                        <label for="semester">Semester <span>*</span></label>
                        <select id="semester" name="semester" required>
                            <option value="1">Semester 1</option>
                            <option value="2">Semester 2</option>
                            <option value="3">Semester 3</option>
                        </select>
                    </div>
                    <div class="ucp-field-group">
                        <label for="academic_year">Academic Year <span>*</span></label>
                        <input type="text" id="academic_year" name="academic_year" placeholder="2025/2026" required>
                    </div>
                    <div class="ucp-field-group">
                        <label for="max_students">Max Students <span>*</span></label>
                        <input type="number" id="max_students" name="max_students" min="1" value="40" required>
                    </div>
                    <div class="ucp-field-group">
                        <label for="instructor_id">Instructor</label>
                        <select id="instructor_id" name="instructor_id">
                            <option value="">Select Instructor</option>
                            <?php foreach ($instructors as $instructor): ?>
                                <option value="<?= (int) $instructor['user_id'] ?>"><?= htmlspecialchars(ucpUpper($instructor['first_name'] . ' ' . $instructor['last_name'])) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="ucp-field-group">
                        <label for="category_id">Category</label>
                        <select id="category_id" name="category_id">
                            <option value="">Select Category</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= (int) $category['category_id'] ?>"><?= htmlspecialchars(ucpUpper($category['category_name'])) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="ucp-field-group span-2">
                        <h3><i class="fas fa-map-marker-alt"></i> Schedule and Availability</h3>
                    </div>
                    <div class="ucp-field-group">
                        <label for="schedule_day">Schedule Day</label>
                        <input type="text" id="schedule_day" name="schedule_day" placeholder="e.g. Monday">
                    </div>
                    <div class="ucp-field-group">
                        <label for="schedule_time">Schedule Time</label>
                        <input type="text" id="schedule_time" name="schedule_time" placeholder="e.g. 9:00 AM - 11:00 AM">
                    </div>
                    <div class="ucp-field-group">
                        <label for="classroom">Classroom</label>
                        <input type="text" id="classroom" name="classroom" placeholder="e.g. FSKTM Lab 1">
                    </div>
                    <label class="ucp-switch-row">
                        <input type="checkbox" id="is_active" name="is_active" checked>
                        <span class="ucp-switch-ui"></span>
                        <span><strong>Active Course</strong><small>Students can register when enabled.</small></span>
                    </label>
                    <div class="ucp-field-group span-2">
                        <label for="prerequisites">Prerequisites</label>
                        <textarea id="prerequisites" name="prerequisites" placeholder="Optional prerequisite details"></textarea>
                    </div>
                </div>
            </div>

            <div class="ucp-modal-footer">
                <button type="button" class="ucp-btn ucp-btn-light" data-close-course-modal>Cancel</button>
                <button type="submit" class="ucp-btn ucp-btn-primary" id="course-submit-btn"><i class="fas fa-save"></i> Save Course</button>
            </div>
        </form>
    </div>
</div>

<script>
(function() {
    const modal = document.getElementById('course-modal');
    const form = document.getElementById('course-form');
    const title = document.getElementById('course-modal-title');
    const actionInput = document.getElementById('course_form_action');
    const submitBtn = document.getElementById('course-submit-btn');
    const alertBox = document.getElementById('course-modal-alert');

    const courseNoticeOverlay = document.getElementById('courseNoticeOverlay');
    const courseNoticeIcon = document.getElementById('courseNoticeIcon');
    const courseNoticeEyebrow = document.getElementById('courseNoticeEyebrow');
    const courseNoticeTitle = document.getElementById('courseNoticeTitle');
    const courseNoticeMessage = document.getElementById('courseNoticeMessage');
    const courseNoticeOk = document.getElementById('courseNoticeOk');
    const courseNoticeClose = document.getElementById('courseNoticeClose');

    function showCourseNotice(type, titleText, message) {
        if (!courseNoticeOverlay) return;
        const danger = type === 'error' || type === 'danger';
        courseNoticeOverlay.classList.toggle('is-danger', danger);
        courseNoticeOverlay.classList.toggle('is-success', !danger);
        courseNoticeIcon.innerHTML = '<i class="fas ' + (danger ? 'fa-triangle-exclamation' : 'fa-circle-check') + '"></i>';
        courseNoticeEyebrow.textContent = danger ? 'ACTION REQUIRED' : 'SYSTEM MESSAGE';
        courseNoticeTitle.textContent = titleText || (danger ? 'Action Failed' : 'Action Completed');
        courseNoticeMessage.textContent = message || '';
        courseNoticeOverlay.classList.add('is-open');
        courseNoticeOverlay.setAttribute('aria-hidden', 'false');
        document.body.classList.add('ucp-modal-open');
    }

    function closeCourseNotice() {
        if (!courseNoticeOverlay) return;
        const shouldReload = courseNoticeOverlay.dataset.reloadOnClose === '1';
        courseNoticeOverlay.classList.remove('is-open');
        courseNoticeOverlay.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('ucp-modal-open');
        courseNoticeOverlay.dataset.reloadOnClose = '0';
        if (shouldReload) {
            window.location.href = '<?= BASE_URL ?>/pages/admin/manage-courses.php';
        }
    }

    function bootCourseNotices() {
        if (!courseNoticeOverlay) return;
        let notices = [];
        try { notices = JSON.parse(courseNoticeOverlay.dataset.notices || '[]'); } catch (error) { notices = []; }
        if (Array.isArray(notices) && notices.length > 0) {
            const first = notices[0];
            showCourseNotice(first.type || 'success', first.title || 'System Message', first.message || '');
        }
    }

    if (courseNoticeOk) courseNoticeOk.addEventListener('click', closeCourseNotice);
    if (courseNoticeClose) courseNoticeClose.addEventListener('click', closeCourseNotice);
    if (courseNoticeOverlay) {
        courseNoticeOverlay.addEventListener('mousedown', function(event) {
            if (event.target === courseNoticeOverlay) closeCourseNotice();
        });
    }

    const fieldMap = [
        'course_id', 'course_code', 'course_name', 'description', 'credits', 'semester',
        'academic_year', 'max_students', 'instructor_id', 'schedule_day', 'schedule_time',
        'classroom', 'prerequisites', 'category_id'
    ];

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function showAlert(type, message) {
        alertBox.hidden = false;
        alertBox.className = 'ucp-inline-alert ' + (type === 'success' ? 'is-success' : 'is-danger');
        alertBox.innerHTML = '<i class="fas ' + (type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle') + '"></i><span>' + escapeHtml(message) + '</span>';
        alertBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function clearAlert() {
        alertBox.hidden = true;
        alertBox.className = 'ucp-inline-alert';
        alertBox.innerHTML = '';
    }

    function openCourseModal(mode, payload) {
        form.reset();
        clearAlert();
        actionInput.value = mode === 'edit' ? 'update' : 'add';
        title.textContent = mode === 'edit' ? 'Edit Course' : 'Add Course';
        submitBtn.innerHTML = '<i class="fas fa-save"></i> ' + (mode === 'edit' ? 'Update Course' : 'Save Course');

        if (mode === 'edit' && payload) {
            fieldMap.forEach(function(field) {
                const input = document.getElementById(field);
                if (input && payload[field] !== undefined && payload[field] !== null) {
                    input.value = payload[field];
                }
            });
            document.getElementById('is_active').checked = String(payload.is_active) === '1';
        } else {
            document.getElementById('is_active').checked = true;
        }

        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('ucp-modal-open');
        setTimeout(function() { document.getElementById('course_code').focus(); }, 50);
    }

    function closeCourseModal() {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('ucp-modal-open');
    }

    document.querySelectorAll('[data-open-course-modal]').forEach(function(button) {
        button.addEventListener('click', function() {
            const mode = button.getAttribute('data-open-course-modal');
            let payload = null;
            if (mode === 'edit') {
                try { payload = JSON.parse(button.getAttribute('data-course') || '{}'); }
                catch (error) { payload = null; }
            }
            openCourseModal(mode, payload);
        });
    });

    document.querySelectorAll('[data-close-course-modal]').forEach(function(button) {
        button.addEventListener('click', closeCourseModal);
    });

    modal.addEventListener('click', function(event) {
        if (event.target === modal) {
            closeCourseModal();
        }
    });

    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && modal.classList.contains('is-open')) {
            closeCourseModal();
        }
    });

    ['course_code', 'course_name', 'description', 'academic_year', 'schedule_day', 'schedule_time', 'classroom', 'prerequisites'].forEach(function(fieldId) {
        const field = document.getElementById(fieldId);
        if (field) {
            field.addEventListener('input', function() {
                this.value = this.value.toUpperCase();
            });
        }
    });

    window.submitCourseForm = function(event) {
        event.preventDefault();
        clearAlert();
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

        fetch('<?= BASE_URL ?>/api/course-api.php', {
            method: 'POST',
            body: new FormData(form)
        })
        .then(function(response) {
            if (!response.ok) throw new Error('Network response was not ok');
            return response.json();
        })
        .then(function(data) {
            if (data.success) {
                closeCourseModal();
                courseNoticeOverlay.dataset.reloadOnClose = '1';
                showCourseNotice('success', actionInput.value === 'update' ? 'Course Updated' : 'Course Added', data.message || 'Course saved successfully.');
            } else {
                showAlert('error', data.message || 'Unable to save course.');
            }
        })
        .catch(function() {
            showAlert('error', 'An error occurred while saving. Please try again.');
        })
        .finally(function() {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-save"></i> ' + (actionInput.value === 'update' ? 'Update Course' : 'Save Course');
        });
    };

    const search = document.querySelector('[data-ucp-course-search]');
    const statusFilter = document.querySelector('[data-ucp-course-status]');
    const categoryFilter = document.querySelector('[data-ucp-course-category]');
    const rows = Array.from(document.querySelectorAll('[data-ucp-course-row]'));
    const empty = document.querySelector('[data-ucp-course-empty]');
    const filterToggle = document.querySelector('[data-ucp-filter-toggle]');
    const filterPanel = document.querySelector('[data-ucp-filter-panel]');
    const filterCount = document.querySelector('[data-ucp-filter-count]');

    function applyCourseFilters() {
        const keyword = (search ? search.value : '').trim().toLowerCase();
        const status = statusFilter ? statusFilter.value : 'all';
        const category = categoryFilter ? categoryFilter.value : 'all';
        let visible = 0;

        rows.forEach(function(row) {
            const matchKeyword = !keyword || (row.dataset.search || '').includes(keyword);
            const matchStatus = status === 'all' || row.dataset.status === status;
            const matchCategory = category === 'all' || row.dataset.category === category;
            const show = matchKeyword && matchStatus && matchCategory;
            row.hidden = !show;
            if (show) visible++;
        });

        if (empty) empty.hidden = visible !== 0;

        let activeFilters = 0;
        if (status !== 'all') activeFilters++;
        if (category !== 'all') activeFilters++;
        if (filterCount) {
            filterCount.textContent = activeFilters;
            filterCount.hidden = activeFilters === 0;
        }
    }

    if (search) search.addEventListener('input', applyCourseFilters);
    if (statusFilter) statusFilter.addEventListener('change', applyCourseFilters);
    if (categoryFilter) categoryFilter.addEventListener('change', applyCourseFilters);
    document.querySelector('[data-ucp-filter-apply]')?.addEventListener('click', function() {
        applyCourseFilters();
        if (filterPanel) filterPanel.hidden = true;
    });
    document.querySelector('[data-ucp-filter-reset]')?.addEventListener('click', function() {
        if (statusFilter) statusFilter.value = 'all';
        if (categoryFilter) categoryFilter.value = 'all';
        applyCourseFilters();
    });
    if (filterToggle && filterPanel) {
        filterToggle.addEventListener('click', function(event) {
            event.stopPropagation();
            filterPanel.hidden = !filterPanel.hidden;
        });
        document.addEventListener('click', function(event) {
            if (!filterPanel.hidden && !filterPanel.contains(event.target) && !filterToggle.contains(event.target)) {
                filterPanel.hidden = true;
            }
        });
    }

    bootCourseNotices();
    applyCourseFilters();
})();
</script>

<?php include '../../includes/footer.php'; ?>
