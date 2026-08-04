<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_department_access('dare');
dare_update_class_statuses();

$user = current_user();
$totalClasses = (int) db()->query('SELECT COUNT(*) FROM dare_classes')->fetchColumn();
$statusCounts = array_fill_keys(array_keys(dare_class_status_options()), 0);
$statusStatement = db()->query('SELECT status, COUNT(*) AS total FROM dare_classes GROUP BY status');
foreach ($statusStatement->fetchAll() as $statusRow) {
    $statusCounts[$statusRow['status']] = (int) $statusRow['total'];
}
$totalStudents = (int) db()->query('SELECT COUNT(*) FROM dare_students WHERE is_active = 1')->fetchColumn();
$essayCompleted = (int) db()->query(
    'SELECT COUNT(*)
     FROM dare_class_students
     WHERE essay_completed = 1'
)->fetchColumn();
$nextLessons = dare_next_lessons_for_user($user);

$actions = [
    ['label' => 'New class', 'href' => url('departments/dare/class-create.php'), 'primary' => true],
    ['label' => 'Classes', 'href' => url('departments/dare/classes.php')],
    ['label' => 'Student search', 'href' => url('departments/dare/students.php')],
    ['label' => 'Reports', 'href' => url('departments/dare/report.php')],
    ['label' => 'Teachers', 'href' => url('departments/dare/teachers.php')],
];

if (can_manage_department('dare')) {
    $actions[] = ['label' => 'Schools & officers', 'href' => url('departments/dare/lookups.php')];
    $actions[] = ['label' => 'Lessons', 'href' => url('departments/dare/lessons.php')];
}

if (is_system_admin()) {
    $actions[] = ['label' => 'Import preview', 'href' => url('departments/dare/import-preview.php')];
    $actions[] = ['label' => 'Import data', 'href' => url('departments/dare/import.php')];
    $actions[] = ['label' => 'Cleanup', 'href' => url('departments/dare/cleanup.php')];
}

page_header('DARE Home');
?>
<main class="shell">
    <section class="panel">
        <h1>DARE Home</h1>
        <p>Track DARE classes, students, essay completion, and certificate readiness.</p>
        <?php dare_navigation('home'); ?>

        <?php if ($message = flash('success')): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>
    </section>

    <section class="dashboard-stat-row" style="margin-top: 18px;">
        <div class="dashboard-stat-group status-summary-group">
            <h2>Classes</h2>
            <div class="grid dashboard-stat-grid">
                <?php foreach (['active', 'completed', 'graduated', 'closed'] as $statusValue): ?>
                    <a class="card dashboard-stat-card status-card" href="<?= e(url('departments/dare/classes.php?status=' . $statusValue)) ?>">
                        <h3><?= e((string) $statusCounts[$statusValue]) ?></h3>
                        <p><?= e(dare_class_status_label($statusValue)) ?></p>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="dashboard-stat-group summary-stat-group">
            <h2>Students</h2>
            <div class="grid dashboard-stat-grid">
                <article class="card dashboard-stat-card">
                    <h3><?= e((string) $totalStudents) ?></h3>
                    <p>Students</p>
                </article>
                <article class="card dashboard-stat-card">
                    <h3><?= e((string) $essayCompleted) ?></h3>
                    <p>Essays</p>
                </article>
            </div>
        </div>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Next Lessons</h1>
        <?php if (!$nextLessons): ?>
            <p>No upcoming lessons are ready to mark taught.</p>
        <?php else: ?>
            <table class="table mobile-card-table">
                <thead>
                    <tr>
                        <th>School</th>
                        <th>School Year</th>
                        <th>Semester</th>
                        <th>Period</th>
                        <th>Teacher</th>
                        <th>Next Lesson</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($nextLessons as $lesson): ?>
                        <tr>
                            <td data-label="School"><?= e($lesson['school_name']) ?></td>
                            <td data-label="School Year"><?= e($lesson['school_year'] ?: 'Not set') ?></td>
                            <td data-label="Semester"><?= e($lesson['semester'] ?: 'Not set') ?></td>
                            <td data-label="Period"><?= e($lesson['period'] ?: 'Not set') ?></td>
                            <td data-label="Teacher"><?= e(trim(($lesson['teacher_first_name'] ?? '') . ' ' . ($lesson['teacher_last_name'] ?? '')) ?: 'Not assigned') ?></td>
                            <td data-label="Next Lesson"><?= e($lesson['lesson_title']) ?></td>
                            <td data-label="Actions">
                                <div class="table-actions">
                                    <form method="post" action="<?= e(url('departments/dare/lesson-complete.php')) ?>">
                                        <input type="hidden" name="class_lesson_id" value="<?= e((string) $lesson['class_lesson_id']) ?>">
                                        <input type="hidden" name="return_to" value="dashboard">
                                        <button type="submit" class="secondary compact-button">Mark taught</button>
                                    </form>
                                    <a class="icon-link" href="<?= e(url('departments/dare/class-detail.php?id=' . $lesson['class_id'])) ?>" title="View class" aria-label="View DARE class">&#9636;</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</main>
<?php page_footer(); ?>
