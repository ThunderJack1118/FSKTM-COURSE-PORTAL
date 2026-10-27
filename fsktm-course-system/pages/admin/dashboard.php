<?php
require_once '../../config/config.php';
require_once '../../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) {
    redirect('/pages/auth/login.php');
}

$db = new Database();
$conn = $db->getConnection();

$stats = [
    'total_courses' => 0,
    'total_users' => 0,
    'active_courses' => 0,
    'pending_registrations' => 0,
    'students' => 0,
    'lecturers' => 0,
];
$recentCourses = [];
$pendingRows = [];

function ucpDashboardScalar($conn, $sql) {
    try {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return 0;
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int) ($row['count'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function ucpDashboardRows($conn, $sql) {
    try {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    } catch (Throwable $e) {
        return [];
    }
}

$stats['total_courses'] = ucpDashboardScalar($conn, "SELECT COUNT(*) AS count FROM courses");
$stats['total_users'] = ucpDashboardScalar($conn, "SELECT COUNT(*) AS count FROM users");
$stats['active_courses'] = ucpDashboardScalar($conn, "SELECT COUNT(*) AS count FROM courses WHERE is_active = 1");
$stats['pending_registrations'] = ucpDashboardScalar($conn, "SELECT COUNT(*) AS count FROM registrations WHERE status = 'pending'");
$stats['students'] = ucpDashboardScalar($conn, "SELECT COUNT(*) AS count FROM users WHERE user_type = 'student'");
$stats['lecturers'] = ucpDashboardScalar($conn, "SELECT COUNT(*) AS count FROM users WHERE user_type = 'lecturer'");

$recentCourses = ucpDashboardRows($conn, "SELECT c.course_code, c.course_name, c.is_active, u.first_name, u.last_name
                                      FROM courses c
                                      LEFT JOIN users u ON c.instructor_id = u.user_id
                                      ORDER BY c.course_id DESC
                                      LIMIT 5");

$pendingRows = ucpDashboardRows($conn, "SELECT r.registration_id, r.registration_date AS created_at, u.first_name, u.last_name, c.course_code, c.course_name
                                    FROM registrations r
                                    LEFT JOIN users u ON r.user_id = u.user_id
                                    LEFT JOIN courses c ON r.course_id = c.course_id
                                    WHERE r.status = 'pending'
                                    ORDER BY r.registration_date DESC
                                    LIMIT 5");

$db->closeConnection();

$pageTitle = "Admin Dashboard";
include '../../includes/header.php';
?>

<div class="ucp-admin-page ucp-dashboard-page">
    <section class="ucp-page-hero">
        <div>
            <p class="ucp-eyebrow">Administration</p>
            <h1>Admin Dashboard</h1>
            <p class="ucp-muted">Overview of course operations, user accounts, registration workload, and key administrative actions.</p>
            <p class="ucp-last-login"><i class="fas fa-clock"></i> Last login: <?= htmlspecialchars(date('Y-m-d H:i', strtotime($_SESSION['last_login'] ?? 'now'))) ?></p>
        </div>
        <div class="ucp-hero-actions">
            <a href="<?= BASE_URL ?>/pages/admin/manage-courses.php" class="ucp-btn ucp-btn-light"><i class="fas fa-book"></i> Manage Courses</a>
            <a href="<?= BASE_URL ?>/pages/admin/manage-users.php" class="ucp-btn ucp-btn-primary"><i class="fas fa-user-plus"></i> Manage Users</a>
        </div>
    </section>

    <?php displaySessionMessages(); ?>

    <section class="ucp-stats-grid ucp-dashboard-stats">
        <div class="ucp-stat-card">
            <div class="ucp-stat-icon"><i class="fas fa-book-open"></i></div>
            <div><span>Total Courses</span><strong><?= (int) $stats['total_courses'] ?></strong></div>
        </div>
        <div class="ucp-stat-card">
            <div class="ucp-stat-icon is-green"><i class="fas fa-users"></i></div>
            <div><span>Total Users</span><strong><?= (int) $stats['total_users'] ?></strong></div>
        </div>
        <div class="ucp-stat-card">
            <div class="ucp-stat-icon is-blue"><i class="fas fa-chalkboard-teacher"></i></div>
            <div><span>Active Courses</span><strong><?= (int) $stats['active_courses'] ?></strong></div>
        </div>
        <div class="ucp-stat-card">
            <div class="ucp-stat-icon is-dark"><i class="fas fa-hourglass-half"></i></div>
            <div><span>Pending Registrations</span><strong><?= (int) $stats['pending_registrations'] ?></strong></div>
        </div>
    </section>

    <section class="ucp-dashboard-grid">
        <div class="ucp-panel ucp-action-panel">
            <div class="ucp-panel-toolbar compact">
                <div>
                    <h2>Quick Actions</h2>
                    <p class="ucp-muted">Common administration shortcuts.</p>
                </div>
            </div>
            <div class="ucp-quick-action-grid">
                <a href="<?= BASE_URL ?>/pages/admin/manage-courses.php" class="ucp-quick-action-card">
                    <span><i class="fas fa-plus"></i></span>
                    <strong>Add New Course</strong>
                    <small>Create or update course offerings.</small>
                </a>
                <a href="<?= BASE_URL ?>/pages/admin/manage-users.php" class="ucp-quick-action-card">
                    <span><i class="fas fa-user-plus"></i></span>
                    <strong>Add New User</strong>
                    <small>Manage student, lecturer, and admin access.</small>
                </a>
                <a href="<?= BASE_URL ?>/pages/admin/reports.php" class="ucp-quick-action-card">
                    <span><i class="fas fa-chart-pie"></i></span>
                    <strong>Generate Reports</strong>
                    <small>Open the reports and analytics page.</small>
                </a>
            </div>
        </div>

        <div class="ucp-panel">
            <div class="ucp-panel-toolbar compact">
                <div>
                    <h2>Account Composition</h2>
                    <p class="ucp-muted">Current user role overview.</p>
                </div>
            </div>
            <div class="ucp-mini-metrics">
                <div><span>Students</span><strong><?= (int) $stats['students'] ?></strong></div>
                <div><span>Lecturers</span><strong><?= (int) $stats['lecturers'] ?></strong></div>
                <div><span>Admins</span><strong><?= max(0, (int) $stats['total_users'] - (int) $stats['students'] - (int) $stats['lecturers']) ?></strong></div>
            </div>
        </div>
    </section>

    <section class="ucp-dashboard-grid lower">
        <div class="ucp-panel">
            <div class="ucp-panel-toolbar compact">
                <div>
                    <h2>Recent Courses</h2>
                    <p class="ucp-muted">Latest course records added to the system.</p>
                </div>
            </div>
            <div class="ucp-list-stack">
                <?php if (empty($recentCourses)): ?>
                    <div class="ucp-empty-state"><i class="fas fa-folder-open"></i><strong>No recent courses</strong><span>No course records are available yet.</span></div>
                <?php else: ?>
                    <?php foreach ($recentCourses as $course): ?>
                        <?php $instructor = trim(($course['first_name'] ?? '') . ' ' . ($course['last_name'] ?? '')) ?: 'Unassigned'; ?>
                        <div class="ucp-list-row">
                            <div class="ucp-avatar"><?= htmlspecialchars(strtoupper(substr($course['course_code'] ?? 'C', 0, 2))) ?></div>
                            <div>
                                <strong><?= htmlspecialchars($course['course_name']) ?></strong>
                                <span><?= htmlspecialchars($course['course_code']) ?> · <?= htmlspecialchars($instructor) ?></span>
                            </div>
                            <span class="ucp-status-dot <?= !empty($course['is_active']) ? 'active' : 'inactive' ?>"><span></span><?= !empty($course['is_active']) ? 'Active' : 'Inactive' ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="ucp-panel">
            <div class="ucp-panel-toolbar compact">
                <div>
                    <h2>Pending Registrations</h2>
                    <p class="ucp-muted">Recent registration requests waiting for action.</p>
                </div>
            </div>
            <div class="ucp-list-stack">
                <?php if (empty($pendingRows)): ?>
                    <div class="ucp-empty-state"><i class="fas fa-check-circle"></i><strong>No pending registrations</strong><span>The registration queue is currently clear.</span></div>
                <?php else: ?>
                    <?php foreach ($pendingRows as $row): ?>
                        <?php $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: 'Unknown user'; ?>
                        <div class="ucp-list-row">
                            <div class="ucp-avatar"><?= htmlspecialchars(strtoupper(substr($name, 0, 2))) ?></div>
                            <div>
                                <strong><?= htmlspecialchars($name) ?></strong>
                                <span><?= htmlspecialchars($row['course_code'] ?? '-') ?> · <?= htmlspecialchars($row['course_name'] ?? '-') ?></span>
                            </div>
                            <span class="ucp-role-pill report-warning">Pending</span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </section>
</div>

<?php include '../../includes/footer.php'; ?>
