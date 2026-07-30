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

function dare_class_history_action(string $action): string
{
    return match ($action) {
        'created' => 'Class created',
        'updated' => 'Class edited',
        'students_added' => 'Students added',
        'essays_completed_all' => 'All essays marked complete',
        'essay_completion_updated' => 'Essay status updated',
        'essay_winner_updated' => 'Essay winner updated',
        'status_updated' => 'Status changed',
        'lesson_completed' => 'Lesson marked taught',
        'lesson_completion_reversed' => 'Lesson marked not taught',
        default => ucwords(str_replace('_', ' ', $action)),
    };
}

function dare_class_history_details(?string $json): string
{
    $details = json_decode($json ?? '', true);

    if (!is_array($details)) {
        return '';
    }

    $parts = [];

    if (isset($details['from'], $details['to'])) {
        $parts[] = 'Status changed from ' . dare_class_status_label((string) $details['from']) . ' to ' . dare_class_status_label((string) $details['to']);
    }

    if (isset($details['students_added'])) {
        $parts[] = $details['students_added'] . ' student' . ((int) $details['students_added'] === 1 ? '' : 's') . ' added';
    }

    if (isset($details['students_updated'])) {
        $parts[] = $details['students_updated'] . ' student' . ((int) $details['students_updated'] === 1 ? '' : 's') . ' updated';
    }

    if (isset($details['completed_students'])) {
        $parts[] = $details['completed_students'] . ' student' . ((int) $details['completed_students'] === 1 ? '' : 's') . ' marked essay complete';
    }

    if (isset($details['essay_winners'])) {
        $parts[] = $details['essay_winners'] . ' essay winner' . ((int) $details['essay_winners'] === 1 ? '' : 's') . ' selected';
    }

    if (isset($details['gender_entries'])) {
        $parts[] = $details['gender_entries'] . ' gender entr' . ((int) $details['gender_entries'] === 1 ? 'y' : 'ies') . ' set';
    }

    if (isset($details['lesson_title'])) {
        $parts[] = 'Lesson: ' . $details['lesson_title'];
    }

    foreach (['school_year' => 'School year', 'semester' => 'Semester', 'period' => 'Period', 'graduation_date' => 'Graduation date', 'status' => 'Status'] as $key => $label) {
        if (isset($details[$key]) && $details[$key] !== '' && $details[$key] !== null && !$parts) {
            $value = $key === 'status' ? dare_class_status_label((string) $details[$key]) : (string) $details[$key];
            $parts[] = $label . ': ' . $value;
        }
    }

    if (!$parts) {
        foreach ($details as $key => $value) {
            if (is_scalar($value) && $value !== '') {
                $parts[] = ucwords(str_replace('_', ' ', (string) $key)) . ': ' . (string) $value;
            }
        }
    }

    return implode('; ', $parts);
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
        $winnerStudentIds = array_map('intval', (array) ($_POST['essay_winner'] ?? []));
        $genderValues = (array) ($_POST['gender'] ?? []);
        $completedStudentIds = array_values(array_unique(array_merge($completedStudentIds, $winnerStudentIds)));
        $studentsStatement = db()->prepare('SELECT student_id FROM dare_class_students WHERE class_id = :class_id');
        $studentsStatement->execute(['class_id' => $id]);
        $studentIds = array_map('intval', array_column($studentsStatement->fetchAll(), 'student_id'));
        $update = db()->prepare(
            'UPDATE dare_class_students
             SET essay_completed = :essay_completed,
                 essay_winner = :essay_winner,
                 gender = :gender
             WHERE class_id = :class_id
               AND student_id = :student_id'
        );

        $genderEntries = 0;
        foreach ($studentIds as $studentId) {
            $gender = $genderValues[$studentId] ?? '';
            $gender = in_array($gender, ['Female', 'Male'], true) ? $gender : null;
            $genderEntries += $gender ? 1 : 0;

            $update->execute([
                'class_id' => $id,
                'student_id' => $studentId,
                'essay_completed' => in_array($studentId, $completedStudentIds, true) ? 1 : 0,
                'essay_winner' => in_array($studentId, $winnerStudentIds, true) ? 1 : 0,
                'gender' => $gender,
            ]);
        }

        audit_event('essay_completion_updated', 'dare_class', (string) $id, [
            'completed_students' => count($completedStudentIds),
            'essay_winners' => count($winnerStudentIds),
            'gender_entries' => $genderEntries,
        ]);
        flash('success', 'Essay status updated.');
        redirect_to('departments/dare/class-detail.php?id=' . $id);
    }

    if ($action === 'update_status') {
        $newStatus = $_POST['status'] ?? '';
        $statusOptions = dare_class_status_options();

        if (!array_key_exists($newStatus, $statusOptions)) {
            flash('error', 'That class status is not valid.');
            redirect_to('departments/dare/class-detail.php?id=' . $id);
        }

        if ($newStatus !== $class['status']) {
            $statement = db()->prepare(
                'UPDATE dare_classes
                 SET status = :status
                 WHERE id = :id'
            );
            $statement->execute([
                'id' => $id,
                'status' => $newStatus,
            ]);

            audit_event('status_updated', 'dare_class', (string) $id, [
                'from' => $class['status'],
                'to' => $newStatus,
            ]);

            flash('success', 'Class status changed to ' . $statusOptions[$newStatus] . '.');
        }

        redirect_to('departments/dare/class-detail.php?id=' . $id);
    }

}

$studentOrder = $studentSort === 'first'
    ? 'dare_students.first_name, dare_students.last_name'
    : 'dare_students.last_name, dare_students.first_name';
$studentsStatement = db()->prepare(
    'SELECT
        dare_students.*,
        dare_class_students.essay_completed,
        dare_class_students.essay_winner,
        dare_class_students.gender
     FROM dare_class_students
     INNER JOIN dare_students ON dare_students.id = dare_class_students.student_id
     WHERE dare_class_students.class_id = :class_id
     ORDER BY ' . $studentOrder
);
$studentsStatement->execute(['class_id' => $id]);
$students = $studentsStatement->fetchAll();
$graduateCount = count(array_filter($students, fn ($student) => (int) $student['essay_completed'] === 1));
$winnerCount = count(array_filter($students, fn ($student) => (int) $student['essay_winner'] === 1));
$participationCount = count($students) - $graduateCount;
$lessonStatement = db()->prepare(
    'SELECT
        dare_class_lessons.*,
        users.first_name AS completed_first_name,
        users.last_name AS completed_last_name
     FROM dare_class_lessons
     LEFT JOIN users ON users.id = dare_class_lessons.completed_by
     WHERE dare_class_lessons.class_id = :class_id
     ORDER BY dare_class_lessons.sort_order, dare_class_lessons.lesson_title'
);
$lessonStatement->execute(['class_id' => $id]);
$lessons = $lessonStatement->fetchAll();
$lessonProgress = dare_lesson_progress($id);
$historyStatement = db()->prepare(
    'SELECT
        audit_log.*,
        users.first_name,
        users.last_name,
        users.email
     FROM audit_log
     LEFT JOIN users ON users.id = audit_log.user_id
     WHERE audit_log.entity_type = "dare_class"
       AND audit_log.entity_id = :entity_id
     ORDER BY audit_log.created_at DESC, audit_log.id DESC'
);
$historyStatement->execute(['entity_id' => (string) $id]);
$historyEvents = $historyStatement->fetchAll();

$actions = [
    ['label' => 'DARE Home', 'href' => url('departments/dare/index.php'), 'primary' => true],
    ['label' => 'Classes', 'href' => url('departments/dare/classes.php')],
    ['label' => 'Student search', 'href' => url('departments/dare/students.php')],
    ['label' => 'Reports', 'href' => url('departments/dare/report.php')],
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

        <div class="class-header-layout">
            <div>
                <?php $teacherName = trim(($class['teacher_first_name'] ?? '') . ' ' . ($class['teacher_last_name'] ?? '')) ?: 'Unassigned Teacher'; ?>
                <div class="class-title-block">
                    <p class="meta"><?= e($class['school_name']) ?></p>
                    <h1><?= e($teacherName) ?><?= $class['period'] ? ' - Period ' . e($class['period']) : '' ?></h1>
                    <div class="class-title-pills">
                        <span><?= e($class['school_year'] ?: 'School year not set') ?></span>
                        <span><?= e($class['semester'] ?: 'Semester not set') ?></span>
                        <span class="badge"><?= e(dare_class_status_label($class['status'])) ?></span>
                        <span class="badge badge-muted"><?= e(dare_class_end_countdown($class['end_date'])) ?></span>
                    </div>
                </div>
                <?php page_actions($actions); ?>
            </div>
            <aside class="class-header-status" aria-label="Class status">
                <div class="status-panel-header">
                    <strong>Class Status</strong>
                    <span class="badge"><?= e(dare_class_status_label($class['status'])) ?></span>
                </div>
                <div class="actions status-actions">
                    <?php foreach (dare_class_status_options() as $statusValue => $statusLabel): ?>
                        <?php if ($class['status'] !== $statusValue): ?>
                            <form class="inline-edit-form" method="post">
                                <input type="hidden" name="id" value="<?= e((string) $id) ?>">
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="status" value="<?= e($statusValue) ?>">
                                <button type="submit" class="secondary compact-button"><?= e($statusLabel) ?></button>
                            </form>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <?php if ($class['status'] === 'completed' && !$class['graduation_date']): ?>
                    <p class="meta">Set the graduation date before printing final graduation certificates.</p>
                <?php endif; ?>
            </aside>
        </div>
    </section>

    <section class="detail-grid" style="margin-top: 18px;">
        <article class="panel detail-panel">
            <div class="section-heading-row">
                <h2>Class Details</h2>
                <a class="button secondary compact-button" href="<?= e(url('departments/dare/class-edit.php?id=' . $id)) ?>">Edit Class</a>
                <button type="button" class="secondary compact-button" id="class-details-toggle" aria-expanded="false" aria-controls="class-details-content">Show Details</button>
            </div>
            <dl class="detail-list" id="class-details-content" hidden>
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
                <dd>
                    <?php if ($class['start_date'] || $class['end_date']): ?>
                        <?= e($class['start_date'] ?: 'Not set') ?> to <?= e($class['end_date'] ?: 'Not set') ?>
                    <?php else: ?>
                        Not set
                    <?php endif; ?>
                </dd>
                <dt>Graduation</dt>
                <dd><?= e($class['graduation_date'] ?: 'Not set') ?></dd>
                <dt>Students</dt>
                <dd><?= e((string) count($students)) ?> enrolled, <?= e((string) $graduateCount) ?> graduated</dd>
                <dt>Lessons</dt>
                <dd><?= e((string) $lessonProgress['completed']) ?> of <?= e((string) $lessonProgress['total']) ?> taught</dd>
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
            <h1>Certificates</h1>
            <span class="badge"><?= e((string) $graduateCount) ?> graduation</span>
            <span class="badge"><?= e((string) $participationCount) ?> participation</span>
        </div>
        <?php if (!$class['graduation_date']): ?>
            <div class="notice error">Graduation date is not set for this class. Certificates can still print, but graduation certificates will not show a presented date.</div>
        <?php endif; ?>
        <?php if (!$students): ?>
            <p>Add students to this class before printing certificates.</p>
        <?php else: ?>
            <div class="actions">
                <a class="button" href="<?= e(url('departments/dare/certificates-print.php?class_id=' . $id . '&type=all')) ?>">Print all certificates</a>
                <a class="button secondary" href="<?= e(url('departments/dare/certificates-print.php?class_id=' . $id . '&type=graduation')) ?>">Print graduation certificates</a>
                <a class="button secondary" href="<?= e(url('departments/dare/certificates-print.php?class_id=' . $id . '&type=participation')) ?>">Print participation certificates</a>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <div class="section-heading-row">
            <h1>Lessons</h1>
            <button type="button" class="secondary compact-button" id="lessons-toggle" aria-expanded="false" aria-controls="lessons-content">Show Lessons</button>
        </div>
        <div id="lessons-content" hidden>
            <?php if (!$lessons): ?>
                <p>No lessons are attached to this class. Future classes will receive lessons from the active lesson list.</p>
            <?php else: ?>
                <table class="table mobile-card-table">
                    <thead>
                        <tr>
                            <th>Lesson</th>
                            <th>Status</th>
                            <th>Completed By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lessons as $lesson): ?>
                            <tr>
                                <td data-label="Lesson"><?= e($lesson['lesson_title']) ?></td>
                                <td data-label="Status">
                                    <span class="badge <?= $lesson['completed_at'] ? 'badge-success' : 'badge-muted' ?>">
                                        <?= $lesson['completed_at'] ? 'Taught' : 'Not taught' ?>
                                    </span>
                                    <?php if ($lesson['completed_at']): ?>
                                        <br><span class="meta"><?= e($lesson['completed_at']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Completed By"><?= e(trim(($lesson['completed_first_name'] ?? '') . ' ' . ($lesson['completed_last_name'] ?? '')) ?: 'Not completed') ?></td>
                                <td data-label="Actions">
                                    <form method="post" action="<?= e(url('departments/dare/lesson-complete.php')) ?>">
                                        <input type="hidden" name="class_lesson_id" value="<?= e((string) $lesson['id']) ?>">
                                        <input type="hidden" name="return_to" value="class">
                                        <?php if ($lesson['completed_at']): ?>
                                            <input type="hidden" name="action" value="undo_taught">
                                            <button type="submit" class="secondary compact-button">Undo taught</button>
                                        <?php else: ?>
                                            <input type="hidden" name="action" value="mark_taught">
                                            <button type="submit" class="secondary compact-button">Mark taught</button>
                                        <?php endif; ?>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <div class="section-heading-row">
            <h1>Students</h1>
            <span class="badge"><?= e((string) $winnerCount) ?> essay winner<?= $winnerCount === 1 ? '' : 's' ?></span>
            <div class="actions student-panel-actions">
                <span class="meta">Sort</span>
                <a class="button <?= $studentSort === 'last' ? '' : 'secondary' ?> compact-button" href="<?= e(url('departments/dare/class-detail.php?id=' . $id . '&student_sort=last')) ?>">Last Name</a>
                <a class="button <?= $studentSort === 'first' ? '' : 'secondary' ?> compact-button" href="<?= e(url('departments/dare/class-detail.php?id=' . $id . '&student_sort=first')) ?>">First Name</a>
                <button type="submit" class="secondary compact-button" form="mark-all-essays-form">Mark all complete</button>
                <a class="button secondary compact-button" href="<?= e(url('departments/dare/class-roster.php?class_id=' . $id)) ?>">Print roster</a>
                <button type="button" class="secondary compact-button" id="students-toggle" aria-expanded="false" aria-controls="students-content">Show Students</button>
            </div>
        </div>
        <div id="students-content" hidden>
            <?php if (!$students): ?>
                <p>No students have been added to this class yet.</p>
            <?php else: ?>
                <form method="post">
                    <input type="hidden" name="id" value="<?= e((string) $id) ?>">
                    <input type="hidden" name="action" value="update_essays">
                    <table class="table mobile-card-table student-status-table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Gender</th>
                                <th>Essay</th>
                                <th>Winner</th>
                                <th>Note</th>
                                <th>Certificate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $student): ?>
                                <tr>
                                    <td data-label="Student"><?= e(dare_person_name($student)) ?></td>
                                    <td data-label="Gender">
                                        <select name="gender[<?= e((string) $student['id']) ?>]">
                                            <option value="">Not set</option>
                                            <?php foreach (['Female', 'Male'] as $genderOption): ?>
                                                <option value="<?= e($genderOption) ?>" <?= ($student['gender'] ?? '') === $genderOption ? 'selected' : '' ?>><?= e($genderOption) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td data-label="Essay">
                                        <label class="check-label">
                                            <input type="checkbox" name="essay_completed[]" value="<?= e((string) $student['id']) ?>" <?= (int) $student['essay_completed'] === 1 ? 'checked' : '' ?>>
                                            Completed
                                        </label>
                                    </td>
                                    <td data-label="Winner">
                                        <label class="check-label">
                                            <input type="checkbox" name="essay_winner[]" value="<?= e((string) $student['id']) ?>" <?= (int) $student['essay_winner'] === 1 ? 'checked' : '' ?>>
                                            Essay winner
                                        </label>
                                    </td>
                                    <td data-label="Note">
                                        <?php $hasStudentNote = trim($student['notes'] ?? '') !== ''; ?>
                                        <a
                                            class="icon-link <?= $hasStudentNote ? 'note-link-has-note' : '' ?>"
                                            href="<?= e(url('departments/dare/student-note.php?class_id=' . $id . '&student_id=' . $student['id'])) ?>"
                                            title="<?= $hasStudentNote ? 'Edit student note' : 'Add student note' ?>"
                                            aria-label="<?= $hasStudentNote ? 'Edit student note' : 'Add student note' ?>"
                                        >&#9997;</a>
                                    </td>
                                    <td data-label="Certificate">
                                        <div class="table-actions">
                                            <span><?= (int) $student['essay_completed'] === 1 ? 'Graduation' : 'Participation' ?></span>
                                            <a class="icon-link" href="<?= e(url('departments/dare/certificate.php?class_id=' . $id . '&student_id=' . $student['id'])) ?>" title="View certificate" aria-label="View certificate">&#9636;</a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="actions" style="margin-top: 14px;">
                        <button type="submit">Save student updates</button>
                    </div>
                </form>
                <form method="post" id="mark-all-essays-form">
                    <input type="hidden" name="id" value="<?= e((string) $id) ?>">
                    <input type="hidden" name="action" value="mark_all_essays">
                </form>
            <?php endif; ?>
        </div>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <div class="section-heading-row">
            <h1>History</h1>
            <button type="button" class="secondary compact-button" id="history-toggle" aria-expanded="false" aria-controls="history-content">Show History</button>
        </div>
        <div id="history-content" hidden>
            <?php if (!$historyEvents): ?>
                <p>No history has been recorded for this class.</p>
            <?php else: ?>
                <table class="table mobile-card-table">
                    <thead>
                        <tr>
                            <th>Date/Time</th>
                            <th>User</th>
                            <th>Action</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($historyEvents as $event): ?>
                            <tr>
                                <td data-label="Date/Time"><?= e($event['created_at']) ?></td>
                                <td data-label="User">
                                    <?= e(trim(($event['first_name'] ?? '') . ' ' . ($event['last_name'] ?? '')) ?: 'System') ?>
                                    <?php if (!empty($event['email'])): ?>
                                        <br><span class="meta"><?= e($event['email']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Action"><?= e(dare_class_history_action($event['action'])) ?></td>
                                <td data-label="Details"><?= e(dare_class_history_details($event['details'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </section>
</main>
<script>
    const addStudentsToggle = document.getElementById('add-students-toggle');
    const addStudentsContent = document.getElementById('add-students-content');
    const classDetailsToggle = document.getElementById('class-details-toggle');
    const classDetailsContent = document.getElementById('class-details-content');
    const historyToggle = document.getElementById('history-toggle');
    const historyContent = document.getElementById('history-content');
    const lessonsToggle = document.getElementById('lessons-toggle');
    const lessonsContent = document.getElementById('lessons-content');
    const studentsToggle = document.getElementById('students-toggle');
    const studentsContent = document.getElementById('students-content');

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

    historyToggle?.addEventListener('click', () => {
        const willExpand = historyContent.hidden;
        historyContent.hidden = !willExpand;
        historyToggle.setAttribute('aria-expanded', willExpand ? 'true' : 'false');
        historyToggle.textContent = willExpand ? 'Hide History' : 'Show History';
    });

    lessonsToggle?.addEventListener('click', () => {
        const willExpand = lessonsContent.hidden;
        lessonsContent.hidden = !willExpand;
        lessonsToggle.setAttribute('aria-expanded', willExpand ? 'true' : 'false');
        lessonsToggle.textContent = willExpand ? 'Hide Lessons' : 'Show Lessons';
    });

    studentsToggle?.addEventListener('click', () => {
        const willExpand = studentsContent.hidden;
        studentsContent.hidden = !willExpand;
        studentsToggle.setAttribute('aria-expanded', willExpand ? 'true' : 'false');
        studentsToggle.textContent = willExpand ? 'Hide Students' : 'Show Students';
    });
</script>
<?php page_footer(); ?>
