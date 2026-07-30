<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_department_access('dare');
dare_update_class_statuses();

$status = $_GET['status'] ?? 'active';
$search = trim($_GET['search'] ?? '');
$params = [];
$whereParts = [];

$statusOptions = dare_class_status_options();

if (array_key_exists($status, $statusOptions)) {
    $whereParts[] = 'dare_classes.status = :status';
    $params['status'] = $status;
} elseif ($status !== 'all') {
    $status = 'active';
    $whereParts[] = 'dare_classes.status = :status';
    $params['status'] = $status;
}

if ($search !== '') {
    $whereParts[] = '(dare_classes.school_year LIKE :search OR dare_classes.semester LIKE :search OR dare_classes.period LIKE :search OR dare_schools.name LIKE :search OR dare_teachers.last_name LIKE :search OR dare_officers.last_name LIKE :search)';
    $params['search'] = '%' . $search . '%';
}

$where = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';
$statement = db()->prepare(
    "SELECT
        dare_classes.*,
        dare_schools.name AS school_name,
        dare_teachers.first_name AS teacher_first_name,
        dare_teachers.last_name AS teacher_last_name,
        dare_officers.first_name AS officer_first_name,
        dare_officers.last_name AS officer_last_name,
        COUNT(dare_class_students.student_id) AS student_count,
        SUM(dare_class_students.essay_completed = 1) AS graduate_count
     FROM dare_classes
     INNER JOIN dare_schools ON dare_schools.id = dare_classes.school_id
     LEFT JOIN dare_teachers ON dare_teachers.id = dare_classes.teacher_id
     LEFT JOIN dare_officers ON dare_officers.id = dare_classes.officer_id
     LEFT JOIN dare_class_students ON dare_class_students.class_id = dare_classes.id
     $where
     GROUP BY dare_classes.id
     ORDER BY dare_classes.start_date IS NULL, dare_classes.start_date DESC, dare_classes.school_year DESC, dare_classes.semester, dare_classes.period"
);
$statement->execute($params);
$classes = $statement->fetchAll();

$actions = [
    ['label' => 'New class', 'href' => url('departments/dare/class-create.php'), 'primary' => true],
    ['label' => 'DARE Home', 'href' => url('departments/dare/index.php')],
    ['label' => 'Student search', 'href' => url('departments/dare/students.php')],
    ['label' => 'Reports', 'href' => url('departments/dare/report.php')],
    ['label' => 'Teachers', 'href' => url('departments/dare/teachers.php')],
];

page_header('DARE Classes');
?>
<main class="shell">
    <section class="panel">
        <h1>DARE Classes</h1>
        <?php page_actions($actions); ?>
        <p class="filter-description">View classes by status.</p>

        <div class="filter-button-group" aria-label="Class status filters">
            <?php foreach (['active' => 'Active'] + $statusOptions + ['all' => 'All'] as $value => $label): ?>
                <?php
                if ($value === 'active' && isset($statusOptions['active'])) {
                    $label = $statusOptions['active'];
                }
                $query = ['status' => $value];
                if ($search !== '') {
                    $query['search'] = $search;
                }
                ?>
                <a class="button compact-button <?= $status === $value ? '' : 'secondary' ?>" href="<?= e(url('departments/dare/classes.php?' . http_build_query($query))) ?>">
                    <?= e($label) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <table class="table mobile-card-table">
            <thead>
                <tr>
                    <th>School Year</th>
                    <th>School</th>
                    <th>Teacher</th>
                    <th>Officer</th>
                    <th>Dates</th>
                    <th>Graduation</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($classes as $class): ?>
                    <tr>
                        <td data-label="School Year"><?= e(dare_class_label($class)) ?></td>
                        <td data-label="School"><?= e($class['school_name']) ?></td>
                        <td data-label="Teacher"><?= e(trim(($class['teacher_first_name'] ?? '') . ' ' . ($class['teacher_last_name'] ?? '')) ?: 'Not assigned') ?></td>
                        <td data-label="Officer"><?= e(trim(($class['officer_first_name'] ?? '') . ' ' . ($class['officer_last_name'] ?? '')) ?: 'Not assigned') ?></td>
                        <td data-label="Dates">
                            <?php if ($class['start_date'] || $class['end_date']): ?>
                                <?= e($class['start_date'] ?: 'Not set') ?> to <?= e($class['end_date'] ?: 'Not set') ?>
                            <?php else: ?>
                                Not set
                            <?php endif; ?>
                        </td>
                        <td data-label="Graduation"><?= e($class['graduation_date'] ?: 'Not set') ?></td>
                        <td data-label="Status"><?= e(dare_class_status_label($class['status'])) ?></td>
                        <td data-label="Actions">
                            <div class="table-actions">
                                <a class="icon-link" href="<?= e(url('departments/dare/class-detail.php?id=' . $class['id'])) ?>" title="View class" aria-label="View DARE class">&#9636;</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$classes): ?>
                    <tr>
                        <td colspan="8">No classes found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
</main>
<?php page_footer(); ?>
