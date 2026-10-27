<?php
require_once '../../config/config.php';
require_once '../../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) {
    redirect('/pages/auth/login.php');
}

$db = new Database();
$conn = $db->getConnection();

$enrollmentStats = [];
$statusCounts = [];
$userTypeCounts = [];
$reportStats = [
    'total_courses' => 0,
    'approved' => 0,
    'pending' => 0,
    'average_capacity' => 0,
];

try {
    $stmt = $conn->prepare("SELECT c.course_id, c.course_code, c.course_name,
                                   COUNT(r.registration_id) AS enrolled,
                                   c.max_students,
                                   CASE WHEN c.max_students > 0 THEN (COUNT(r.registration_id) / c.max_students * 100) ELSE 0 END AS percentage
                            FROM courses c
                            LEFT JOIN registrations r ON c.course_id = r.course_id AND r.status = 'approved'
                            GROUP BY c.course_id, c.course_code, c.course_name, c.max_students
                            ORDER BY percentage DESC, c.course_name ASC");
    $stmt->execute();
    $enrollmentStats = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $stmt = $conn->prepare("SELECT status, COUNT(*) AS count FROM registrations GROUP BY status");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $statusCounts[$row['status']] = (int) $row['count'];
    }
    $stmt->close();

    $stmt = $conn->prepare("SELECT user_type, COUNT(*) AS count FROM users GROUP BY user_type");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $userTypeCounts[$row['user_type']] = (int) $row['count'];
    }
    $stmt->close();

    $reportStats['total_courses'] = count($enrollmentStats);
    $reportStats['approved'] = $statusCounts['approved'] ?? 0;
    $reportStats['pending'] = $statusCounts['pending'] ?? 0;
    if ($reportStats['total_courses'] > 0) {
        $totalPercentage = array_sum(array_map(function($course) {
            return (float) ($course['percentage'] ?? 0);
        }, $enrollmentStats));
        $reportStats['average_capacity'] = round($totalPercentage / $reportStats['total_courses']);
    }
} catch (Exception $e) {
    $_SESSION['error'] = "Failed to load report data.";
}

$db->closeConnection();

$pageTitle = "Reports & Analytics";
include '../../includes/header.php';
?>

<div class="ucp-admin-page ucp-report-page">
    <section class="ucp-page-hero">
        <div>
            <p class="ucp-eyebrow">Analytics</p>
            <h1>Reports & Analytics</h1>
            <p class="ucp-muted">Monitor course capacity, registration status, and account distribution in one modern dashboard.</p>
        </div>
        <button type="button" class="ucp-btn ucp-btn-primary" onclick="window.print()">
            <i class="fas fa-file-export"></i> Export View
        </button>
    </section>

    <?php displaySessionMessages(); ?>

    <section class="ucp-stats-grid">
        <div class="ucp-stat-card">
            <div class="ucp-stat-icon"><i class="fas fa-book"></i></div>
            <div><span>Total Courses</span><strong><?= (int) $reportStats['total_courses'] ?></strong></div>
        </div>
        <div class="ucp-stat-card">
            <div class="ucp-stat-icon is-green"><i class="fas fa-user-check"></i></div>
            <div><span>Approved Registrations</span><strong><?= (int) $reportStats['approved'] ?></strong></div>
        </div>
        <div class="ucp-stat-card">
            <div class="ucp-stat-icon is-blue"><i class="fas fa-clock"></i></div>
            <div><span>Pending Registrations</span><strong><?= (int) $reportStats['pending'] ?></strong></div>
        </div>
        <div class="ucp-stat-card">
            <div class="ucp-stat-icon is-dark"><i class="fas fa-chart-line"></i></div>
            <div><span>Average Capacity</span><strong><?= (int) $reportStats['average_capacity'] ?>%</strong></div>
        </div>
    </section>

    <section class="ucp-report-grid">
        <div class="ucp-panel ucp-chart-panel">
            <div class="ucp-panel-toolbar compact">
                <div>
                    <h2>Registration Status</h2>
                    <p class="ucp-muted">Distribution of registration approval states.</p>
                </div>
            </div>
            <div class="ucp-chart-canvas"><canvas id="statusChart"></canvas></div>
        </div>
        <div class="ucp-panel ucp-chart-panel">
            <div class="ucp-panel-toolbar compact">
                <div>
                    <h2>User Type Distribution</h2>
                    <p class="ucp-muted">Breakdown of student, lecturer, and admin accounts.</p>
                </div>
            </div>
            <div class="ucp-chart-canvas"><canvas id="userTypeChart"></canvas></div>
        </div>
    </section>

    <section class="ucp-panel ucp-report-table-panel">
        <div class="ucp-panel-toolbar">
            <div>
                <h2>Course Enrollment Statistics</h2>
                <p class="ucp-muted">Capacity tracking for all courses.</p>
            </div>
            <label class="ucp-searchbox" for="reportSearchInput">
                <i class="fas fa-search"></i>
                <input type="search" id="reportSearchInput" placeholder="Search course code or name..." data-ucp-report-search>
            </label>
        </div>
        <div class="ucp-table-wrap">
            <table class="ucp-modern-table ucp-report-table">
                <thead>
                    <tr>
                        <th>Course</th>
                        <th>Code</th>
                        <th>Enrolled</th>
                        <th>Capacity</th>
                        <th>Usage</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($enrollmentStats as $course): ?>
                        <?php
                            $percentage = (float) ($course['percentage'] ?? 0);
                            $capacityPct = min(100, round($percentage));
                            $badgeClass = $percentage >= 90 ? 'danger' : ($percentage >= 75 ? 'warning' : 'success');
                            $badgeText = $percentage >= 90 ? 'Full' : ($percentage >= 75 ? 'Almost Full' : 'Available');
                            $searchText = strtolower(($course['course_code'] ?? '') . ' ' . ($course['course_name'] ?? ''));
                        ?>
                        <tr data-ucp-report-row data-search="<?= htmlspecialchars($searchText, ENT_QUOTES, 'UTF-8') ?>">
                            <td>
                                <div class="ucp-user-cell">
                                    <div class="ucp-avatar"><?= htmlspecialchars(strtoupper(substr($course['course_name'] ?? 'C', 0, 2))) ?></div>
                                    <div>
                                        <span class="ucp-user-name"><?= htmlspecialchars($course['course_name']) ?></span>
                                        <span>Enrollment overview</span>
                                    </div>
                                </div>
                            </td>
                            <td><code><?= htmlspecialchars($course['course_code']) ?></code></td>
                            <td><?= (int) $course['enrolled'] ?></td>
                            <td><?= (int) $course['max_students'] ?></td>
                            <td>
                                <div class="ucp-capacity-cell">
                                    <span><?= number_format($percentage, 2) ?>%</span>
                                    <div class="ucp-progress"><span style="width: <?= $capacityPct ?>%"></span></div>
                                </div>
                            </td>
                            <td><span class="ucp-role-pill report-<?= $badgeClass ?>"><?= $badgeText ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr data-ucp-report-empty hidden>
                        <td colspan="6" class="ucp-empty-state">
                            <i class="fas fa-search"></i>
                            <strong>No report rows found</strong>
                            <span>Try another keyword.</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const statusLabels = <?= json_encode(array_keys($statusCounts)) ?>;
    const statusData = <?= json_encode(array_values($statusCounts)) ?>;
    const userTypeLabels = <?= json_encode(array_keys($userTypeCounts)) ?>;
    const userTypeData = <?= json_encode(array_values($userTypeCounts)) ?>;

    const commonOptions = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom',
                labels: { boxWidth: 12, usePointStyle: true }
            }
        }
    };

    const statusCtx = document.getElementById('statusChart');
    if (statusCtx && window.Chart) {
        new Chart(statusCtx.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: statusLabels,
                datasets: [{
                    data: statusData,
                    backgroundColor: ['#1f6feb', '#16a34a', '#f59e0b', '#e11d48', '#64748b'],
                    borderWidth: 0
                }]
            },
            options: commonOptions
        });
    }

    const userTypeCtx = document.getElementById('userTypeChart');
    if (userTypeCtx && window.Chart) {
        new Chart(userTypeCtx.getContext('2d'), {
            type: 'bar',
            data: {
                labels: userTypeLabels,
                datasets: [{
                    data: userTypeData,
                    backgroundColor: '#1f6feb',
                    borderRadius: 8
                }]
            },
            options: {
                ...commonOptions,
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
            }
        });
    }

    const search = document.querySelector('[data-ucp-report-search]');
    const rows = Array.from(document.querySelectorAll('[data-ucp-report-row]'));
    const empty = document.querySelector('[data-ucp-report-empty]');
    if (search) {
        search.addEventListener('input', function() {
            const keyword = search.value.trim().toLowerCase();
            let visible = 0;
            rows.forEach(function(row) {
                const show = !keyword || (row.dataset.search || '').includes(keyword);
                row.hidden = !show;
                if (show) visible++;
            });
            if (empty) empty.hidden = visible !== 0;
        });
    }
});
</script>

<?php include '../../includes/footer.php'; ?>
