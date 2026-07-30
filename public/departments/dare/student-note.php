<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_department_access('dare');

$studentId = (int) ($_GET['student_id'] ?? $_POST['student_id'] ?? 0);
$classId = (int) ($_GET['class_id'] ?? $_POST['class_id'] ?? 0);

$statement = db()->prepare(
    'SELECT
        dare_students.*,
        dare_classes.id AS class_id,
        dare_classes.school_year,
        dare_classes.semester,
        dare_classes.period,
        dare_schools.name AS school_name,
        dare_teachers.first_name AS teacher_first_name,
        dare_teachers.last_name AS teacher_last_name
     FROM dare_students
     INNER JOIN dare_class_students ON dare_class_students.student_id = dare_students.id
     INNER JOIN dare_classes ON dare_classes.id = dare_class_students.class_id
     INNER JOIN dare_schools ON dare_schools.id = dare_classes.school_id
     LEFT JOIN dare_teachers ON dare_teachers.id = dare_classes.teacher_id
     WHERE dare_students.id = :student_id
       AND dare_classes.id = :class_id'
);
$statement->execute([
    'student_id' => $studentId,
    'class_id' => $classId,
]);
$student = $statement->fetch();

if (!$student) {
    http_response_code(404);
    page_header('DARE student not found');
    echo '<main class="shell"><section class="panel"><h1>Student not found</h1><p>The selected DARE student could not be found.</p></section></main>';
    page_footer();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $notes = trim($_POST['notes'] ?? '');
    $statement = db()->prepare('UPDATE dare_students SET notes = :notes WHERE id = :student_id');
    $statement->execute([
        'student_id' => $studentId,
        'notes' => $notes === '' ? null : $notes,
    ]);

    audit_event('student_note_updated', 'dare_student', (string) $studentId, [
        'class_id' => $classId,
        'has_note' => $notes !== '',
    ]);

    flash('success', 'Student note saved.');
    redirect_to('departments/dare/class-detail.php?id=' . $classId);
}

$teacherName = trim(($student['teacher_first_name'] ?? '') . ' ' . ($student['teacher_last_name'] ?? '')) ?: 'Not assigned';
$classMeta = array_filter([
    $student['school_year'] ?? '',
    $student['semester'] ?? '',
    $student['period'] ? 'Period ' . $student['period'] : '',
]);

$actions = [
    ['label' => 'Class details', 'href' => url('departments/dare/class-detail.php?id=' . $classId), 'primary' => true],
    ['label' => 'Student search', 'href' => url('departments/dare/students.php')],
    ['label' => 'DARE Home', 'href' => url('departments/dare/index.php')],
];

page_header('DARE Student Note');
?>
<main class="shell">
    <section class="panel">
        <h1><?= e(dare_person_name($student)) ?></h1>
        <p>
            <?= e($student['school_name']) ?>
            <?php if ($classMeta): ?>
                <br><span class="meta"><?= e(implode(' - ', $classMeta)) ?><?= $teacherName ? ' - ' . e($teacherName) : '' ?></span>
            <?php endif; ?>
        </p>
        <?php page_actions($actions); ?>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Student Note</h1>
        <p>Notes are for officer reference inside the website and are not printed on the class roster.</p>
        <form class="form" method="post">
            <input type="hidden" name="student_id" value="<?= e((string) $studentId) ?>">
            <input type="hidden" name="class_id" value="<?= e((string) $classId) ?>">
            <label>
                Note
                <textarea name="notes" autofocus><?= e($student['notes']) ?></textarea>
            </label>
            <div class="actions">
                <button type="submit">Save note</button>
                <a class="button secondary" href="<?= e(url('departments/dare/class-detail.php?id=' . $classId)) ?>">Cancel</a>
            </div>
        </form>
    </section>
</main>
<?php page_footer(); ?>
