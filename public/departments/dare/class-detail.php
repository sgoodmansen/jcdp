<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_department_access('dare');
dare_update_class_statuses();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$studentSort = $_GET['student_sort'] ?? 'last';
$studentSort = in_array($studentSort, ['first', 'last'], true) ? $studentSort : 'last';

function dare_fetch_class(int $id): ?array
{
    $statement = db()->prepare(
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
         WHERE dare_classes.id = :id'
    );
    $statement->execute(['id' => $id]);
    $class = $statement->fetch();

    return $class ?: null;
}

function dare_find_or_create_student(string $name): ?int
{
    $studentName = dare_split_student_name($name);
    if ($studentName['first_name'] === '') {
        return null;
    }

    $statement = db()->prepare(
        'SELECT id
         FROM dare_students
         WHERE LOWER(first_name) = LOWER(:first_name)
           AND LOWER(last_name) = LOWER(:last_name)
         LIMIT 1'
    );
    $statement->execute($studentName);
    $studentId = $statement->fetchColumn();

    if ($studentId) {
        return (int) $studentId;
    }

    $statement = db()->prepare(
        'INSERT INTO dare_students (first_name, last_name)
         VALUES (:first_name, :last_name)'
    );
    $statement->execute($studentName);

    return (int) db()->lastInsertId();
}

$class = dare_fetch_class($id);

if (!$class) {
    http_response_code(404);
    page_header('DARE class not found');
    echo '<main class="shell"><section class="panel"><h1>Class not found</h1><p>The selected DARE class could not be found.</p></section></main>';
    page_footer();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_students') {
        $lines = preg_split('/\r\n|\r|\n/', trim($_POST['student_names'] ?? ''));
        $insert = db()->prepare(
            'INSERT IGNORE INTO dare_class_students (class_id, student_id)
             VALUES (:class_id, :student_id)'
        );
        $added = 0;

        foreach ($lines as $line) {
            $studentId = dare_find_or_create_student($line);
            if (!$studentId) {
                continue;
            }

            $insert->execute([
                'class_id' => $id,
                'student_id' => $studentId,
            ]);

            $added += $insert->rowCount();
        }

        audit_event('students_added', 'dare_class', (string) $id, ['students_added' => $added]);
        flash('success', $added . ' student' . ($added === 1 ? '' : 's') . ' added.');
        redirect_to('departments/dare/class-detail.php?id=' . $id);
    }

    if ($action === 'mark_all_essays') {
        $statement = db()->prepare(
            'UPDATE dare_class_students
             SET essay_completed = 1
             WHERE class_id = :class_id'
        );
        $statement->execute(['class_id' => $id]);

        audit_event('essays_completed_all', 'dare_class', (string) $id, [
            'students_updated' => $statement->rowCount(),
        ]);
        flash('success', 'All students have been marked essay completed.');
        redirect_to('departments/dare/class-detail.php?id=' . $id . '&student_sort=' . $studentSort);
    }

    if ($action === 'update_essays') {
        $completedStudentIds = array_map('intval', (array) ($_POST['essay_completed'] ?? []));
        $studentsStatement = db()->prepare('SELECT student_id FROM dare_class_students WHERE class_id = :class_id');
        $studentsStatement->execute(['class_id' => $id]);
        $studentIds = array_map('intval', array_column($studentsStatement->fetchAll(), 'student_id'));
        $update = db()->prepare(
            'UPDATE dare_class_students
             SET essay_completed = :essay_completed
             WHERE class_id = :class_id
               AND student_id = :student_id'
        );

        foreach ($studentIds as $studentId) {
            $update->execute([
                'class_id' => $id,
                'student_id' => $studentId,
                'essay_completed' => in_array($studentId, $completedStudentIds, true) ? 1 : 0,
            ]);
        }

        audit_event('essay_completion_updated', 'dare_class', (string) $id, [
            'completed_students' => count($completedStudentIds),
        ]);
        flash('success', 'Essay completion updated.');
        redirect_to('departments/dare/class-detail.php?id=' . $id);
    }

}

$studentOrder = $studentSort === 'first'
    ? 'dare_students.first_name, dare_students.last_name'
    : 'dare_students.last_name, dare_students.first_name';
$studentsStatement = db()->prepare(
    'SELECT
        dare_students.*,
        dare_class_students.essay_completed
     FROM dare_class_students
     INNER JOIN dare_students ON dare_students.id = dare_class_students.student_id
     WHERE dare_class_students.class_id = :class_id
     ORDER BY ' . $studentOrder
);
$studentsStatement->execute(['class_id' => $id]);
$students = $studentsStatement->fetchAll();
$graduateCount = count(array_filter($students, fn ($student) => (int) $student['essay_completed'] === 1));

$actions = [
    ['label' => 'DARE Home', 'href' => url('departments/dare/index.php'), 'primary' => true],
    ['label' => 'Classes', 'href' => url('departments/dare/classes.php')],
    ['label' => 'Student search', 'href' => url('departments/dare/students.php')],
];

page_header('DARE Class Detail');
?>
<main class="shell">
    <section class="panel">
        <?php if ($message = flash('success')): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>

        <?php $teacherName = trim(($class['teacher_first_name'] ?? '') . ' ' . ($class['teacher_last_name'] ?? '')) ?: 'Unassigned Teacher'; ?>
        <div class="class-title-block">
            <p class="meta"><?= e($class['school_name']) ?></p>
            <h1><?= e($teacherName) ?><?= $class['period'] ? ' - Period ' . e($class['period']) : '' ?></h1>
            <div class="class-title-pills">
                <span><?= e($class['school_year'] ?: 'School year not set') ?></span>
                <span><?= e($class['semester'] ?: 'Semester not set') ?></span>
                <span class="badge"><?= e(dare_class_status_label($class['status'])) ?></span>
            </div>
        </div>
        <?php page_actions($actions); ?>
    </section>

    <section class="detail-grid" style="margin-top: 18px;">
        <article class="panel detail-panel">
            <div class="section-heading-row">
                <h2>Class Details</h2>
                <a class="button secondary compact-button" href="<?= e(url('departments/dare/class-edit.php?id=' . $id)) ?>">Edit Class</a>
                <button type="button" class="secondary compact-button" id="class-details-toggle" aria-expanded="true" aria-controls="class-details-content">Hide Details</button>
            </div>
            <dl class="detail-list" id="class-details-content">
                <dt>School</dt>
                <dd><?= e($class['school_name']) ?></dd>
                <dt>School Year</dt>
                <dd><?= e($class['school_year'] ?: 'Not set') ?></dd>
                <dt>Semester</dt>
                <dd><?= e($class['semester'] ?: 'Not set') ?></dd>
                <dt>Period</dt>
                <dd><?= e($class['period'] ?: 'Not set') ?></dd>
                <dt>Teacher</dt>
                <dd><?= e(trim(($class['teacher_first_name'] ?? '') . ' ' . ($class['teacher_last_name'] ?? '')) ?: 'Not assigned') ?></dd>
                <dt>Officer</dt>
                <dd><?= e(trim(($class['officer_first_name'] ?? '') . ' ' . ($class['officer_last_name'] ?? '')) ?: 'Not assigned') ?></dd>
                <dt>Dates</dt>
                <dd><?= e($class['start_date']) ?> to <?= e($class['end_date']) ?></dd>
                <dt>Graduation</dt>
                <dd><?= e($class['graduation_date'] ?: 'Not set') ?></dd>
                <dt>Students</dt>
                <dd><?= e((string) count($students)) ?> enrolled, <?= e((string) $graduateCount) ?> graduated</dd>
                <?php if ($class['notes']): ?>
                    <dt>Notes</dt>
                    <dd><?= nl2br(e($class['notes'])) ?></dd>
                <?php endif; ?>
            </dl>
        </article>

        <article class="panel detail-panel">
            <div class="section-heading-row">
                <h2>Add Students</h2>
                <button type="button" class="secondary compact-button" id="add-students-toggle" aria-expanded="false" aria-controls="add-students-content">Show Add Students</button>
            </div>
            <div id="add-students-content" hidden>
                <p>Paste or type one student name per line.</p>
                <form class="form" method="post">
                    <input type="hidden" name="id" value="<?= e((string) $id) ?>">
                    <input type="hidden" name="action" value="add_students">
                    <label>
                        Student names
                        <textarea name="student_names" placeholder="Jane Smith&#10;John Doe"></textarea>
                    </label>
                    <button type="submit">Add students</button>
                </form>
            </div>
        </article>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <div class="section-heading-row">
            <h1>Students</h1>
        </div>
        <?php if (!$students): ?>
            <p>No students have been added to this class yet.</p>
        <?php else: ?>
            <div class="student-mobile-tools">
                <span class="meta">Sort students</span>
                <div class="actions">
                    <a class="button <?= $studentSort === 'last' ? '' : 'secondary' ?> compact-button" href="<?= e(url('departments/dare/class-detail.php?id=' . $id . '&student_sort=last')) ?>">Last Name</a>
                    <a class="button <?= $studentSort === 'first' ? '' : 'secondary' ?> compact-button" href="<?= e(url('departments/dare/class-detail.php?id=' . $id . '&student_sort=first')) ?>">First Name</a>
                </div>
            </div>
            <form method="post">
                <input type="hidden" name="id" value="<?= e((string) $id) ?>">
                <input type="hidden" name="action" value="update_essays">
                <table class="table mobile-card-table student-status-table">
                    <thead>
                        <tr>
                            <th>
                                <div class="table-header-control">
                                    <span>Student</span>
                                    <span class="actions">
                                        <a class="button <?= $studentSort === 'last' ? '' : 'secondary' ?> compact-button" href="<?= e(url('departments/dare/class-detail.php?id=' . $id . '&student_sort=last')) ?>">Last Name</a>
                                        <a class="button <?= $studentSort === 'first' ? '' : 'secondary' ?> compact-button" href="<?= e(url('departments/dare/class-detail.php?id=' . $id . '&student_sort=first')) ?>">First Name</a>
                                    </span>
                                </div>
                            </th>
                            <th>
                                <div class="table-header-control">
                                    <span>Essay</span>
                                    <button type="submit" class="secondary compact-button" form="mark-all-essays-form">Mark all complete</button>
                                </div>
                            </th>
                            <th>Certificate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $student): ?>
                            <tr>
                                <td data-label="Student"><?= e(dare_person_name($student)) ?></td>
                                <td data-label="Essay">
                                    <label class="check-label">
                                        <input type="checkbox" name="essay_completed[]" value="<?= e((string) $student['id']) ?>" <?= (int) $student['essay_completed'] === 1 ? 'checked' : '' ?>>
                                        Completed
                                    </label>
                                </td>
                                <td data-label="Certificate">
                                    <?= (int) $student['essay_completed'] === 1 ? 'Graduation certificate' : 'Participation certificate' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="actions" style="margin-top: 14px;">
                    <button type="submit">Save essay status</button>
                </div>
            </form>
            <form method="post" id="mark-all-essays-form">
                <input type="hidden" name="id" value="<?= e((string) $id) ?>">
                <input type="hidden" name="action" value="mark_all_essays">
            </form>
        <?php endif; ?>
    </section>
</main>
<script>
    const addStudentsToggle = document.getElementById('add-students-toggle');
    const addStudentsContent = document.getElementById('add-students-content');
    const classDetailsToggle = document.getElementById('class-details-toggle');
    const classDetailsContent = document.getElementById('class-details-content');

    addStudentsToggle?.addEventListener('click', () => {
        const willExpand = addStudentsContent.hidden;
        addStudentsContent.hidden = !willExpand;
        addStudentsToggle.setAttribute('aria-expanded', willExpand ? 'true' : 'false');
        addStudentsToggle.textContent = willExpand ? 'Hide Add Students' : 'Show Add Students';
    });

    classDetailsToggle?.addEventListener('click', () => {
        const willExpand = classDetailsContent.hidden;
        classDetailsContent.hidden = !willExpand;
        classDetailsToggle.setAttribute('aria-expanded', willExpand ? 'true' : 'false');
        classDetailsToggle.textContent = willExpand ? 'Hide Details' : 'Show Details';
    });
</script>
<?php page_footer(); ?>
