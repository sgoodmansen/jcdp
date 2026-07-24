<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_department_access('dare');

$user = current_user();
$schools = db()->query('SELECT * FROM dare_schools WHERE is_active = 1 ORDER BY name')->fetchAll();
$teachers = db()->query(
    'SELECT dare_teachers.*, dare_schools.name AS school_name
     FROM dare_teachers
     LEFT JOIN dare_schools ON dare_schools.id = dare_teachers.school_id
     WHERE dare_teachers.is_active = 1
     ORDER BY dare_teachers.last_name, dare_teachers.first_name'
)->fetchAll();
$officers = db()->query('SELECT * FROM dare_officers WHERE is_active = 1 ORDER BY last_name, first_name')->fetchAll();
$currentOfficerId = dare_current_officer_id($user);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $officerId = (int) ($_POST['officer_id'] ?? 0);
    if (!can_manage_department('dare') && $currentOfficerId) {
        $officerId = $currentOfficerId;
    }

    $schoolYear = trim($_POST['school_year'] ?? '');
    $semester = trim($_POST['semester'] ?? '');
    $period = trim($_POST['period'] ?? '');
    $className = dare_class_label([
        'school_year' => $schoolYear,
        'semester' => $semester,
        'period' => $period,
    ]);

    $statement = db()->prepare(
        'INSERT INTO dare_classes
            (school_id, teacher_id, officer_id, created_by, school_year, class_name, semester, period, start_date, end_date, graduation_date, status, notes)
         VALUES
            (:school_id, :teacher_id, :officer_id, :created_by, :school_year, :class_name, :semester, :period, :start_date, :end_date, :graduation_date, :status, :notes)'
    );
    $statement->execute([
        'school_id' => (int) ($_POST['school_id'] ?? 0),
        'teacher_id' => (int) ($_POST['teacher_id'] ?? 0) ?: null,
        'officer_id' => $officerId > 0 ? $officerId : null,
        'created_by' => $user['id'],
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

    $classId = (int) db()->lastInsertId();
    audit_event('created', 'dare_class', (string) $classId, [
        'school_year' => $schoolYear,
        'semester' => $semester,
        'period' => $period,
        'school_id' => (int) ($_POST['school_id'] ?? 0),
    ]);

    flash('success', 'DARE class created.');
    redirect_to('departments/dare/class-detail.php?id=' . $classId);
}

$actions = [
    ['label' => 'DARE Home', 'href' => url('departments/dare/index.php'), 'primary' => true],
    ['label' => 'Classes', 'href' => url('departments/dare/classes.php')],
    ['label' => 'Teachers', 'href' => url('departments/dare/teachers.php')],
];

page_header('New DARE Class');
?>
<main class="shell">
    <section class="panel">
        <h1>New DARE Class</h1>
        <p>Create a semester class before adding students.</p>
        <?php page_actions($actions); ?>

        <?php if (!$schools): ?>
            <div class="notice error">A department supervisor must add at least one school before classes can be created.</div>
        <?php endif; ?>

        <form class="form compact-form" method="post">
            <label>
                School year
                <input name="school_year" placeholder="2025 - 2026" required>
            </label>
            <label>
                Semester
                <input name="semester" placeholder="Fall">
            </label>
            <label>
                Period
                <input name="period" placeholder="Period 2">
            </label>
            <label>
                School
                <select name="school_id" required>
                    <option value="">Select school</option>
                    <?php foreach ($schools as $school): ?>
                        <option value="<?= e((string) $school['id']) ?>"><?= e($school['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Teacher
                <select name="teacher_id">
                    <option value="">Select teacher</option>
                    <?php foreach ($teachers as $teacher): ?>
                        <option value="<?= e((string) $teacher['id']) ?>"><?= e(dare_person_name($teacher)) ?><?= $teacher['school_name'] ? ' - ' . e($teacher['school_name']) : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                DARE officer
                <select name="officer_id" <?= !can_manage_department('dare') && $currentOfficerId ? 'disabled' : '' ?>>
                    <option value="">Select officer</option>
                    <?php foreach ($officers as $officer): ?>
                        <option value="<?= e((string) $officer['id']) ?>" <?= $currentOfficerId === (int) $officer['id'] ? 'selected' : '' ?>><?= e(dare_person_name($officer)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Status
                <select name="status">
                    <?php foreach (dare_class_status_options() as $value => $label): ?>
                        <option value="<?= e($value) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Start date
                <input type="date" name="start_date" value="<?= e(date('Y-m-d')) ?>" required>
            </label>
            <label>
                End date
                <input type="date" name="end_date" required>
            </label>
            <label>
                Graduation date
                <input type="date" name="graduation_date">
            </label>
            <label class="span-2">
                Notes
                <textarea name="notes"></textarea>
            </label>
            <div class="actions span-2">
                <button type="submit" <?= !$schools ? 'disabled' : '' ?>>Create class</button>
                <a class="button secondary" href="<?= e(url('departments/dare/index.php')) ?>">Cancel</a>
            </div>
        </form>
    </section>
</main>
<?php page_footer(); ?>
