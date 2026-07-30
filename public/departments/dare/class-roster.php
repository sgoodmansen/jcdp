<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_department_access('dare');
dare_update_class_statuses();

$classId = (int) ($_GET['class_id'] ?? 0);

$classStatement = db()->prepare(
    'SELECT
        dare_classes.*,
        dare_schools.name AS school_name,
        dare_teachers.first_name AS teacher_first_name,
        dare_teachers.last_name AS teacher_last_name,
        dare_officers.first_name AS officer_first_name,
        dare_officers.last_name AS officer_last_name
     FROM dare_classes
     INNER JOIN dare_schools ON dare_schools.id = dare_classes.school_id
     LEFT JOIN dare_teachers ON dare_teachers.id = dare_classes.teacher_id
     LEFT JOIN dare_officers ON dare_officers.id = dare_classes.officer_id
     WHERE dare_classes.id = :class_id'
);
$classStatement->execute(['class_id' => $classId]);
$class = $classStatement->fetch();

if (!$class) {
    http_response_code(404);
    page_header('Class roster not found');
    echo '<main class="shell"><section class="panel"><h1>Class not found</h1><p>The selected DARE class could not be found.</p></section></main>';
    page_footer();
    exit;
}

$studentsStatement = db()->prepare(
    'SELECT
        dare_students.first_name,
        dare_students.last_name,
        dare_class_students.essay_completed,
        dare_class_students.essay_winner,
        dare_class_students.gender
     FROM dare_class_students
     INNER JOIN dare_students ON dare_students.id = dare_class_students.student_id
     WHERE dare_class_students.class_id = :class_id
     ORDER BY dare_students.last_name, dare_students.first_name'
);
$studentsStatement->execute(['class_id' => $classId]);
$students = $studentsStatement->fetchAll();

$teacherName = trim(($class['teacher_first_name'] ?? '') . ' ' . ($class['teacher_last_name'] ?? '')) ?: 'Not assigned';
$officerName = trim(($class['officer_first_name'] ?? '') . ' ' . ($class['officer_last_name'] ?? '')) ?: 'Not assigned';
$actions = [
    ['label' => 'Class details', 'href' => url('departments/dare/class-detail.php?id=' . $classId), 'primary' => true],
    ['label' => 'DARE Home', 'href' => url('departments/dare/index.php')],
];

page_header('DARE Class Roster');
?>
<style>
    @page {
        size: portrait;
        margin: 0.45in;
    }
</style>
<main class="shell roster-shell">
    <section class="panel roster-toolbar">
        <div class="letter-action-row">
            <button type="button" class="button desktop-print-button" onclick="window.print()">Print roster</button>
            <?php page_actions($actions); ?>
        </div>
    </section>

    <section class="panel printable-roster" style="margin-top: 18px;">
        <header class="roster-header">
            <div>
                <p class="meta"><?= e($class['school_name']) ?></p>
                <h1><?= e($teacherName) ?><?= $class['period'] ? ' - Period ' . e($class['period']) : '' ?></h1>
            </div>
            <dl class="roster-summary">
                <dt>School Year</dt>
                <dd><?= e($class['school_year'] ?: 'Not set') ?></dd>
                <dt>Semester</dt>
                <dd><?= e($class['semester'] ?: 'Not set') ?></dd>
                <dt>Officer</dt>
                <dd><?= e($officerName) ?></dd>
                <dt>Students</dt>
                <dd><?= e((string) count($students)) ?></dd>
            </dl>
        </header>

        <table class="table roster-table">
            <thead>
                <tr>
                    <th class="roster-number">#</th>
                    <th>Student Name</th>
                    <th>Gender</th>
                    <th>Essay</th>
                    <th>Winner</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($students as $index => $student): ?>
                    <tr>
                        <td class="roster-number"><?= e((string) ($index + 1)) ?></td>
                        <td><?= e(dare_person_name($student)) ?></td>
                        <td><?= e($student['gender'] ?: '') ?></td>
                        <td><?= (int) $student['essay_completed'] === 1 ? 'Yes' : '' ?></td>
                        <td><?= (int) $student['essay_winner'] === 1 ? 'Yes' : '' ?></td>
                        <td></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$students): ?>
                    <tr>
                        <td colspan="6">No students have been added to this class yet.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
</main>
<?php page_footer(); ?>
