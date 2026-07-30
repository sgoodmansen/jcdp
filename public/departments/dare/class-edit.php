<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_department_access('dare');

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

$statement = db()->prepare('SELECT * FROM dare_classes WHERE id = :id');
$statement->execute(['id' => $id]);
$class = $statement->fetch();

if (!$class) {
    http_response_code(404);
    page_header('DARE class not found');
    echo '<main class="shell"><section class="panel"><h1>Class not found</h1><p>The selected DARE class could not be found.</p></section></main>';
    page_footer();
    exit;
}

$showInactiveLookups = ($_GET['show_inactive'] ?? '') === '1';

$schoolSql = 'SELECT * FROM dare_schools';
$schoolParams = [];
if (!$showInactiveLookups) {
    $schoolSql .= ' WHERE is_active = 1 OR id = :current_school_id';
    $schoolParams['current_school_id'] = (int) $class['school_id'];
}
$schoolSql .= ' ORDER BY name';
$schoolStatement = db()->prepare($schoolSql);
$schoolStatement->execute($schoolParams);
$schools = $schoolStatement->fetchAll();

$teacherSql = 'SELECT dare_teachers.*, dare_schools.name AS school_name
     FROM dare_teachers
     LEFT JOIN dare_schools ON dare_schools.id = dare_teachers.school_id';
$teacherParams = [];
if (!$showInactiveLookups) {
    $teacherSql .= ' WHERE dare_teachers.is_active = 1 OR dare_teachers.id = :current_teacher_id';
    $teacherParams['current_teacher_id'] = (int) ($class['teacher_id'] ?? 0);
}
$teacherSql .= ' ORDER BY dare_teachers.last_name, dare_teachers.first_name';
$teacherStatement = db()->prepare($teacherSql);
$teacherStatement->execute($teacherParams);
$teachers = $teacherStatement->fetchAll();

$officerSql = 'SELECT * FROM dare_officers';
$officerParams = [];
if (!$showInactiveLookups) {
    $officerSql .= ' WHERE is_active = 1 OR id = :current_officer_id';
    $officerParams['current_officer_id'] = (int) ($class['officer_id'] ?? 0);
}
$officerSql .= ' ORDER BY last_name, first_name';
$officerStatement = db()->prepare($officerSql);
$officerStatement->execute($officerParams);
$officers = $officerStatement->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $schoolYear = trim($_POST['school_year'] ?? '');
    $semester = trim($_POST['semester'] ?? '');
    $period = trim($_POST['period'] ?? '');
    $className = dare_class_label([
        'school_year' => $schoolYear,
        'semester' => $semester,
        'period' => $period,
    ]);

    $statement = db()->prepare(
        'UPDATE dare_classes
         SET school_id = :school_id,
             teacher_id = :teacher_id,
             officer_id = :officer_id,
             school_year = :school_year,
             class_name = :class_name,
             semester = :semester,
             period = :period,
             start_date = :start_date,
             end_date = :end_date,
             graduation_date = :graduation_date,
             status = :status,
             notes = :notes
         WHERE id = :id'
    );
    $statement->execute([
        'id' => $id,
        'school_id' => (int) ($_POST['school_id'] ?? 0),
        'teacher_id' => (int) ($_POST['teacher_id'] ?? 0) ?: null,
        'officer_id' => (int) ($_POST['officer_id'] ?? 0) ?: null,
        'school_year' => $schoolYear,
        'class_name' => $className,
        'semester' => $semester,
        'period' => $period,
        'start_date' => $_POST['start_date'] ?? date('Y-m-d'),
        'end_date' => $_POST['end_date'] ?? date('Y-m-d'),
        'graduation_date' => $_POST['graduation_date'] ?: null,
        'status' => $_POST['status'] ?? 'active',
        'notes' => trim($_POST['notes'] ?? ''),
    ]);

    audit_event('updated', 'dare_class', (string) $id, [
        'school_year' => $schoolYear,
        'semester' => $semester,
        'period' => $period,
        'graduation_date' => $_POST['graduation_date'] ?: null,
        'status' => $_POST['status'] ?? 'active',
    ]);

    flash('success', 'Class details updated.');
    redirect_to('departments/dare/class-detail.php?id=' . $id);
}

$actions = [
    ['label' => 'Class details', 'href' => url('departments/dare/class-detail.php?id=' . $id), 'primary' => true],
    ['label' => 'Classes', 'href' => url('departments/dare/classes.php')],
    ['label' => 'DARE Home', 'href' => url('departments/dare/index.php')],
];

page_header('Edit DARE Class');
?>
<main class="shell">
    <section class="panel">
        <h1>Edit DARE Class</h1>
        <p>Update class dates, assignments, status, and notes.</p>
        <?php page_actions($actions); ?>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <div class="section-heading-row">
            <div>
                <h2>Class Assignments</h2>
                <p class="muted">New classes use active lookups. Use inactive lookups only when correcting older records.</p>
            </div>
            <?php if ($showInactiveLookups): ?>
                <a class="button secondary compact-button" href="<?= e(url('departments/dare/class-edit.php?id=' . $id)) ?>">Hide inactive</a>
            <?php else: ?>
                <a class="button secondary compact-button" href="<?= e(url('departments/dare/class-edit.php?id=' . $id . '&show_inactive=1')) ?>">Show inactive</a>
            <?php endif; ?>
        </div>
        <form class="form compact-form" method="post">
            <input type="hidden" name="id" value="<?= e((string) $id) ?>">
            <label>
                School year
                <input name="school_year" value="<?= e($class['school_year']) ?>" placeholder="2025 - 2026" required>
            </label>
            <label>
                Semester
                <input name="semester" value="<?= e($class['semester']) ?>" placeholder="Fall">
            </label>
            <label>
                Period
                <input name="period" value="<?= e($class['period']) ?>" placeholder="Period 2">
            </label>
            <label>
                School
                <select name="school_id" required>
                    <option value="">Select school</option>
                    <?php foreach ($schools as $school): ?>
                        <option value="<?= e((string) $school['id']) ?>" <?= (int) $class['school_id'] === (int) $school['id'] ? 'selected' : '' ?>>
                            <?= e($school['name']) ?><?= (int) $school['is_active'] === 0 ? ' (inactive)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Teacher
                <select name="teacher_id">
                    <option value="">Select teacher</option>
                    <?php foreach ($teachers as $teacher): ?>
                        <option value="<?= e((string) $teacher['id']) ?>" <?= (int) ($class['teacher_id'] ?? 0) === (int) $teacher['id'] ? 'selected' : '' ?>>
                            <?= e(dare_person_name($teacher)) ?><?= $teacher['school_name'] ? ' - ' . e($teacher['school_name']) : '' ?><?= (int) $teacher['is_active'] === 0 ? ' (inactive)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                DARE officer
                <select name="officer_id">
                    <option value="">Select officer</option>
                    <?php foreach ($officers as $officer): ?>
                        <option value="<?= e((string) $officer['id']) ?>" <?= (int) ($class['officer_id'] ?? 0) === (int) $officer['id'] ? 'selected' : '' ?>>
                            <?= e(dare_person_name($officer)) ?><?= (int) $officer['is_active'] === 0 ? ' (inactive)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Start date
                <input type="date" name="start_date" value="<?= e($class['start_date']) ?>" required>
            </label>
            <label>
                End date
                <input type="date" name="end_date" value="<?= e($class['end_date']) ?>" required>
            </label>
            <label>
                Graduation date
                <input type="date" name="graduation_date" value="<?= e($class['graduation_date']) ?>">
            </label>
            <label>
                Status
                <select name="status">
                    <?php foreach (dare_class_status_options() as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $class['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="span-2">
                Notes
                <textarea name="notes"><?= e($class['notes']) ?></textarea>
            </label>
            <div class="actions span-2">
                <button type="submit">Save changes</button>
                <a class="button secondary" href="<?= e(url('departments/dare/class-detail.php?id=' . $id)) ?>">Cancel</a>
            </div>
        </form>
    </section>
</main>
<?php page_footer(); ?>
