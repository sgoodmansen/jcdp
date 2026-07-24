<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_department_access('dare');

$search = trim($_GET['search'] ?? '');
$students = [];

if ($search !== '') {
    $statement = db()->prepare(
        'SELECT
            dare_students.id AS student_id,
            dare_students.first_name,
            dare_students.last_name,
            dare_class_students.essay_completed,
            dare_classes.id AS class_id,
            dare_classes.school_year,
            dare_classes.semester,
            dare_classes.period,
            dare_schools.name AS school_name,
            dare_teachers.first_name AS teacher_first_name,
            dare_teachers.last_name AS teacher_last_name,
            dare_officers.first_name AS officer_first_name,
            dare_officers.last_name AS officer_last_name
         FROM dare_students
         INNER JOIN dare_class_students ON dare_class_students.student_id = dare_students.id
         INNER JOIN dare_classes ON dare_classes.id = dare_class_students.class_id
         INNER JOIN dare_schools ON dare_schools.id = dare_classes.school_id
         LEFT JOIN dare_teachers ON dare_teachers.id = dare_classes.teacher_id
         LEFT JOIN dare_officers ON dare_officers.id = dare_classes.officer_id
         WHERE dare_students.first_name LIKE :search
            OR dare_students.last_name LIKE :search
            OR CONCAT(dare_students.first_name, " ", dare_students.last_name) LIKE :search
         ORDER BY dare_students.last_name, dare_students.first_name, dare_classes.school_year DESC, dare_classes.semester, dare_classes.period
         LIMIT 100'
    );
    $statement->execute(['search' => '%' . $search . '%']);
    $students = $statement->fetchAll();
}

$actions = [
    ['label' => 'DARE Home', 'href' => url('departments/dare/index.php'), 'primary' => true],
    ['label' => 'Classes', 'href' => url('departments/dare/classes.php')],
    ['label' => 'New class', 'href' => url('departments/dare/class-create.php')],
];

page_header('DARE Student Search');
?>
<main class="shell">
    <section class="panel">
        <h1>Student Search</h1>
        <p>Search for student names across DARE classes.</p>
        <?php page_actions($actions); ?>

        <form class="form" method="get">
            <label>
                Student name
                <input name="search" value="<?= e($search) ?>" autofocus>
            </label>
            <div class="actions">
                <button type="submit">Search</button>
            </div>
        </form>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Results</h1>
        <?php if ($search === ''): ?>
            <p>Enter part of a first or last name to search.</p>
        <?php elseif (!$students): ?>
            <p>No students matched that search.</p>
        <?php else: ?>
            <table class="table mobile-card-table">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>School Year</th>
                        <th>School</th>
                        <th>Teacher</th>
                        <th>DARE Officer</th>
                        <th>Class</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $student): ?>
                        <tr>
                            <?php
                            $classMeta = array_filter([
                                $student['semester'] ?? '',
                                $student['period'] ? 'Period ' . $student['period'] : '',
                            ]);
                            ?>
                            <td data-label="Student"><?= e(dare_person_name($student)) ?></td>
                            <td data-label="School Year">
                                <?= e($student['school_year'] ?: 'Not set') ?>
                                <?php if ($classMeta): ?>
                                    <br><span class="meta"><?= e(implode(' - ', $classMeta)) ?></span>
                                <?php endif; ?>
                            </td>
                            <td data-label="School"><?= e($student['school_name']) ?></td>
                            <td data-label="Teacher"><?= e(trim(($student['teacher_first_name'] ?? '') . ' ' . ($student['teacher_last_name'] ?? '')) ?: 'Not assigned') ?></td>
                            <td data-label="DARE Officer"><?= e(trim(($student['officer_first_name'] ?? '') . ' ' . ($student['officer_last_name'] ?? '')) ?: 'Not assigned') ?></td>
                            <td data-label="Class">
                                <a class="button secondary compact-button" href="<?= e(url('departments/dare/class-detail.php?id=' . $student['class_id'])) ?>">View class</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</main>
<?php page_footer(); ?>
