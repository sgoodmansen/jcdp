<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_department_access('dare');
dare_update_class_statuses();

$schoolYears = db()->query(
    'SELECT DISTINCT school_year
     FROM dare_classes
     WHERE school_year IS NOT NULL AND school_year <> ""
     ORDER BY school_year DESC'
)->fetchAll();
$defaultSchoolYear = $schoolYears[0]['school_year'] ?? '';
$schoolYearFilter = $_GET['school_year'] ?? null;
$schoolYear = $schoolYearFilter === null
    ? $defaultSchoolYear
    : ($schoolYearFilter === 'all' ? '' : trim($schoolYearFilter));
$semester = trim($_GET['semester'] ?? '');
$status = trim($_GET['status'] ?? 'all');
$schoolId = (int) ($_GET['school_id'] ?? 0);
$officerId = (int) ($_GET['officer_id'] ?? 0);
$statusOptions = dare_class_status_options();
$reportedStatusOptions = array_diff_key($statusOptions, ['cancelled' => true]);

if ($status !== 'all' && !array_key_exists($status, $statusOptions)) {
    $status = 'all';
}

$filterParts = [];
$params = [];

if ($schoolYear !== '') {
    $filterParts[] = 'dare_classes.school_year = :school_year';
    $params['school_year'] = $schoolYear;
}

if ($semester !== '') {
    $filterParts[] = 'dare_classes.semester = :semester';
    $params['semester'] = $semester;
}

if ($status !== 'all') {
    $filterParts[] = 'dare_classes.status = :status';
    $params['status'] = $status;
}

if ($schoolId > 0) {
    $filterParts[] = 'dare_classes.school_id = :school_id';
    $params['school_id'] = $schoolId;
}

if ($officerId > 0) {
    $filterParts[] = 'dare_classes.officer_id = :officer_id';
    $params['officer_id'] = $officerId;
}

$where = $filterParts ? 'WHERE ' . implode(' AND ', $filterParts) : '';

$semesters = db()->query(
    'SELECT DISTINCT semester
     FROM dare_classes
     WHERE semester IS NOT NULL AND semester <> ""
     ORDER BY semester'
)->fetchAll();
$schools = db()->query('SELECT id, name FROM dare_schools ORDER BY name')->fetchAll();
$officers = db()->query('SELECT id, first_name, last_name FROM dare_officers ORDER BY last_name, first_name')->fetchAll();

$summaryStatement = db()->prepare(
    "SELECT
        COUNT(DISTINCT dare_classes.id) AS class_count,
        SUM(COALESCE(student_totals.student_count, 0)) AS student_count,
        SUM(COALESCE(student_totals.essay_count, 0)) AS essay_count,
        SUM(COALESCE(student_totals.winner_count, 0)) AS winner_count,
        SUM(COALESCE(student_totals.female_count, 0)) AS female_count,
        SUM(COALESCE(student_totals.male_count, 0)) AS male_count,
        SUM(CASE WHEN dare_classes.graduation_date IS NOT NULL THEN 1 ELSE 0 END) AS graduation_date_count,
        SUM(COALESCE(lesson_totals.lesson_count, 0)) AS lesson_count,
        SUM(COALESCE(lesson_totals.taught_lesson_count, 0)) AS taught_lesson_count
     FROM dare_classes
     LEFT JOIN (
        SELECT class_id, COUNT(*) AS student_count, SUM(essay_completed = 1) AS essay_count, SUM(essay_winner = 1) AS winner_count, SUM(gender = 'Female') AS female_count, SUM(gender = 'Male') AS male_count
        FROM dare_class_students
        GROUP BY class_id
     ) student_totals ON student_totals.class_id = dare_classes.id
     LEFT JOIN (
        SELECT class_id, COUNT(*) AS lesson_count, SUM(completed_at IS NOT NULL) AS taught_lesson_count
        FROM dare_class_lessons
        GROUP BY class_id
     ) lesson_totals ON lesson_totals.class_id = dare_classes.id
     $where"
);
$summaryStatement->execute($params);
$summary = $summaryStatement->fetch() ?: [];

$statusCounts = array_fill_keys(array_keys($statusOptions), 0);
$statusStatement = db()->prepare(
    "SELECT dare_classes.status, COUNT(*) AS total
     FROM dare_classes
     $where
     GROUP BY dare_classes.status"
);
$statusStatement->execute($params);
foreach ($statusStatement->fetchAll() as $row) {
    $statusCounts[$row['status']] = (int) $row['total'];
}

$schoolStatement = db()->prepare(
    "SELECT
        dare_schools.name AS school_name,
        COUNT(DISTINCT dare_classes.id) AS class_count,
        SUM(COALESCE(student_totals.student_count, 0)) AS student_count,
        SUM(COALESCE(student_totals.essay_count, 0)) AS essay_count,
        SUM(COALESCE(student_totals.winner_count, 0)) AS winner_count,
        SUM(COALESCE(student_totals.female_count, 0)) AS female_count,
        SUM(COALESCE(student_totals.male_count, 0)) AS male_count,
        SUM(COALESCE(lesson_totals.taught_lesson_count, 0)) AS taught_lesson_count,
        SUM(COALESCE(lesson_totals.lesson_count, 0)) AS lesson_count
     FROM dare_classes
     INNER JOIN dare_schools ON dare_schools.id = dare_classes.school_id
     LEFT JOIN (
        SELECT class_id, COUNT(*) AS student_count, SUM(essay_completed = 1) AS essay_count, SUM(essay_winner = 1) AS winner_count, SUM(gender = 'Female') AS female_count, SUM(gender = 'Male') AS male_count
        FROM dare_class_students
        GROUP BY class_id
     ) student_totals ON student_totals.class_id = dare_classes.id
     LEFT JOIN (
        SELECT class_id, COUNT(*) AS lesson_count, SUM(completed_at IS NOT NULL) AS taught_lesson_count
        FROM dare_class_lessons
        GROUP BY class_id
     ) lesson_totals ON lesson_totals.class_id = dare_classes.id
     $where
     GROUP BY dare_schools.id, dare_schools.name
     ORDER BY dare_schools.name"
);
$schoolStatement->execute($params);
$schoolRows = $schoolStatement->fetchAll();

$officerStatement = db()->prepare(
    "SELECT
        dare_officers.first_name,
        dare_officers.last_name,
        COUNT(DISTINCT dare_classes.id) AS class_count,
        SUM(COALESCE(student_totals.student_count, 0)) AS student_count,
        SUM(COALESCE(student_totals.essay_count, 0)) AS essay_count,
        SUM(COALESCE(student_totals.winner_count, 0)) AS winner_count,
        SUM(COALESCE(student_totals.female_count, 0)) AS female_count,
        SUM(COALESCE(student_totals.male_count, 0)) AS male_count,
        SUM(COALESCE(lesson_totals.taught_lesson_count, 0)) AS taught_lesson_count,
        SUM(COALESCE(lesson_totals.lesson_count, 0)) AS lesson_count
     FROM dare_classes
     LEFT JOIN dare_officers ON dare_officers.id = dare_classes.officer_id
     LEFT JOIN (
        SELECT class_id, COUNT(*) AS student_count, SUM(essay_completed = 1) AS essay_count, SUM(essay_winner = 1) AS winner_count, SUM(gender = 'Female') AS female_count, SUM(gender = 'Male') AS male_count
        FROM dare_class_students
        GROUP BY class_id
     ) student_totals ON student_totals.class_id = dare_classes.id
     LEFT JOIN (
        SELECT class_id, COUNT(*) AS lesson_count, SUM(completed_at IS NOT NULL) AS taught_lesson_count
        FROM dare_class_lessons
        GROUP BY class_id
     ) lesson_totals ON lesson_totals.class_id = dare_classes.id
     $where
     GROUP BY dare_officers.id, dare_officers.first_name, dare_officers.last_name
     ORDER BY dare_officers.last_name, dare_officers.first_name"
);
$officerStatement->execute($params);
$officerRows = $officerStatement->fetchAll();

$classStatement = db()->prepare(
    "SELECT
        dare_classes.*,
        dare_schools.name AS school_name,
        dare_teachers.first_name AS teacher_first_name,
        dare_teachers.last_name AS teacher_last_name,
        dare_officers.first_name AS officer_first_name,
        dare_officers.last_name AS officer_last_name,
        COALESCE(student_totals.student_count, 0) AS student_count,
        COALESCE(student_totals.essay_count, 0) AS essay_count,
        COALESCE(student_totals.winner_count, 0) AS winner_count,
        COALESCE(student_totals.female_count, 0) AS female_count,
        COALESCE(student_totals.male_count, 0) AS male_count,
        COALESCE(lesson_totals.lesson_count, 0) AS lesson_count,
        COALESCE(lesson_totals.taught_lesson_count, 0) AS taught_lesson_count
     FROM dare_classes
     INNER JOIN dare_schools ON dare_schools.id = dare_classes.school_id
     LEFT JOIN dare_teachers ON dare_teachers.id = dare_classes.teacher_id
     LEFT JOIN dare_officers ON dare_officers.id = dare_classes.officer_id
     LEFT JOIN (
        SELECT class_id, COUNT(*) AS student_count, SUM(essay_completed = 1) AS essay_count, SUM(essay_winner = 1) AS winner_count, SUM(gender = 'Female') AS female_count, SUM(gender = 'Male') AS male_count
        FROM dare_class_students
        GROUP BY class_id
     ) student_totals ON student_totals.class_id = dare_classes.id
     LEFT JOIN (
        SELECT class_id, COUNT(*) AS lesson_count, SUM(completed_at IS NOT NULL) AS taught_lesson_count
        FROM dare_class_lessons
        GROUP BY class_id
     ) lesson_totals ON lesson_totals.class_id = dare_classes.id
     $where
     GROUP BY dare_classes.id
     ORDER BY dare_classes.school_year DESC, dare_classes.semester, dare_schools.name, dare_classes.period"
);
$classStatement->execute($params);
$classRows = $classStatement->fetchAll();

function dare_report_percent(int $part, int $total): string
{
    if ($total <= 0) {
        return '0%';
    }

    return (string) round(($part / $total) * 100) . '%';
}

$actions = [
    ['label' => 'DARE Home', 'href' => url('departments/dare/index.php'), 'primary' => true],
    ['label' => 'Classes', 'href' => url('departments/dare/classes.php')],
    ['label' => 'Student search', 'href' => url('departments/dare/students.php')],
    ['label' => 'Essay winners', 'href' => url('departments/dare/essay-winners.php')],
];

page_header('DARE Reports');
?>
<main class="shell">
    <section class="panel">
        <h1>DARE Reports</h1>
        <p>Review class totals, student progress, essay completion, and certificate readiness.</p>
        <?php page_actions($actions); ?>

        <form class="form compact-form" method="get">
            <label>
                School year
                <select name="school_year">
                    <option value="all" <?= $schoolYear === '' ? 'selected' : '' ?>>All school years</option>
                    <?php foreach ($schoolYears as $year): ?>
                        <option value="<?= e($year['school_year']) ?>" <?= $schoolYear === $year['school_year'] ? 'selected' : '' ?>><?= e($year['school_year']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Semester
                <select name="semester">
                    <option value="">All semesters</option>
                    <?php foreach ($semesters as $semesterOption): ?>
                        <option value="<?= e($semesterOption['semester']) ?>" <?= $semester === $semesterOption['semester'] ? 'selected' : '' ?>><?= e($semesterOption['semester']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Status
                <select name="status">
                    <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All statuses</option>
                    <?php foreach ($statusOptions as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                School
                <select name="school_id">
                    <option value="0">All schools</option>
                    <?php foreach ($schools as $school): ?>
                        <option value="<?= e((string) $school['id']) ?>" <?= $schoolId === (int) $school['id'] ? 'selected' : '' ?>><?= e($school['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                DARE officer
                <select name="officer_id">
                    <option value="0">All officers</option>
                    <?php foreach ($officers as $officer): ?>
                        <option value="<?= e((string) $officer['id']) ?>" <?= $officerId === (int) $officer['id'] ? 'selected' : '' ?>><?= e(dare_person_name($officer)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="actions span-2">
                <button type="submit">Run report</button>
                <a class="button secondary" href="<?= e(url('departments/dare/report.php')) ?>">Clear filters</a>
            </div>
        </form>
    </section>

    <section class="grid dashboard-stat-grid" style="margin-top: 18px;">
        <article class="card dashboard-stat-card">
            <h3><?= e((string) ((int) ($summary['class_count'] ?? 0))) ?></h3>
            <p>Classes</p>
        </article>
        <article class="card dashboard-stat-card">
            <h3><?= e((string) ((int) ($summary['student_count'] ?? 0))) ?></h3>
            <p>Students</p>
        </article>
        <article class="card dashboard-stat-card">
            <h3><?= e((string) ((int) ($summary['female_count'] ?? 0))) ?> / <?= e((string) ((int) ($summary['male_count'] ?? 0))) ?></h3>
            <p>Female / Male</p>
        </article>
        <article class="card dashboard-stat-card">
            <h3><?= e((string) ((int) ($summary['essay_count'] ?? 0))) ?></h3>
            <p>Essays</p>
        </article>
        <article class="card dashboard-stat-card">
            <h3><?= e((string) ((int) ($summary['winner_count'] ?? 0))) ?></h3>
            <p>Essay Winners</p>
        </article>
    </section>

    <section class="dashboard-stat-group status-summary-group" style="margin-top: 18px;">
        <h2>Class Status</h2>
        <div class="grid dashboard-stat-grid">
            <?php foreach ($reportedStatusOptions as $value => $label): ?>
                <a class="card dashboard-stat-card status-card" href="<?= e(url('departments/dare/classes.php?status=' . $value)) ?>">
                    <h3><?= e((string) $statusCounts[$value]) ?></h3>
                    <p><?= e($label) ?></p>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>By School</h1>
        <table class="table mobile-card-table">
            <thead>
                <tr>
                    <th>School</th>
                    <th>Classes</th>
                    <th>Students</th>
                    <th>Gender</th>
                    <th>Essays</th>
                    <th>Winners</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($schoolRows as $row): ?>
                    <?php
                    $essayCount = (int) ($row['essay_count'] ?? 0);
                    $winnerCount = (int) ($row['winner_count'] ?? 0);
                    $studentCount = (int) ($row['student_count'] ?? 0);
                    $femaleCount = (int) ($row['female_count'] ?? 0);
                    $maleCount = (int) ($row['male_count'] ?? 0);
                    ?>
                    <tr>
                        <td data-label="School"><?= e($row['school_name']) ?></td>
                        <td data-label="Classes"><?= e((string) $row['class_count']) ?></td>
                        <td data-label="Students"><?= e((string) $studentCount) ?></td>
                        <td data-label="Gender"><?= e((string) $femaleCount) ?> F / <?= e((string) $maleCount) ?> M</td>
                        <td data-label="Essays"><?= e((string) $essayCount) ?> (<?= e(dare_report_percent($essayCount, $studentCount)) ?>)</td>
                        <td data-label="Winners"><?= e((string) $winnerCount) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$schoolRows): ?>
                    <tr><td colspan="6">No school report results found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>By Officer</h1>
        <table class="table mobile-card-table">
            <thead>
                <tr>
                    <th>Officer</th>
                    <th>Classes</th>
                    <th>Students</th>
                    <th>Gender</th>
                    <th>Essays</th>
                    <th>Winners</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($officerRows as $row): ?>
                    <?php
                    $essayCount = (int) ($row['essay_count'] ?? 0);
                    $winnerCount = (int) ($row['winner_count'] ?? 0);
                    $studentCount = (int) ($row['student_count'] ?? 0);
                    $femaleCount = (int) ($row['female_count'] ?? 0);
                    $maleCount = (int) ($row['male_count'] ?? 0);
                    ?>
                    <tr>
                        <td data-label="Officer"><?= e(dare_person_name($row) ?: 'Unassigned') ?></td>
                        <td data-label="Classes"><?= e((string) $row['class_count']) ?></td>
                        <td data-label="Students"><?= e((string) $studentCount) ?></td>
                        <td data-label="Gender"><?= e((string) $femaleCount) ?> F / <?= e((string) $maleCount) ?> M</td>
                        <td data-label="Essays"><?= e((string) $essayCount) ?> (<?= e(dare_report_percent($essayCount, $studentCount)) ?>)</td>
                        <td data-label="Winners"><?= e((string) $winnerCount) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$officerRows): ?>
                    <tr><td colspan="6">No officer report results found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Class Progress</h1>
        <table class="table mobile-card-table">
            <thead>
                <tr>
                    <th>Class</th>
                    <th>School</th>
                    <th>Teacher</th>
                    <th>Officer</th>
                    <th>Students</th>
                    <th>Gender</th>
                    <th>Essays</th>
                    <th>Winners</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($classRows as $class): ?>
                    <?php
                    $studentCount = (int) ($class['student_count'] ?? 0);
                    $essayCount = (int) ($class['essay_count'] ?? 0);
                    $winnerCount = (int) ($class['winner_count'] ?? 0);
                    $femaleCount = (int) ($class['female_count'] ?? 0);
                    $maleCount = (int) ($class['male_count'] ?? 0);
                    ?>
                    <tr>
                        <td data-label="Class"><?= e(dare_class_label($class)) ?></td>
                        <td data-label="School"><?= e($class['school_name']) ?></td>
                        <td data-label="Teacher"><?= e(trim(($class['teacher_first_name'] ?? '') . ' ' . ($class['teacher_last_name'] ?? '')) ?: 'Not assigned') ?></td>
                        <td data-label="Officer"><?= e(trim(($class['officer_first_name'] ?? '') . ' ' . ($class['officer_last_name'] ?? '')) ?: 'Not assigned') ?></td>
                        <td data-label="Students"><?= e((string) $studentCount) ?></td>
                        <td data-label="Gender"><?= e((string) $femaleCount) ?> F / <?= e((string) $maleCount) ?> M</td>
                        <td data-label="Essays"><?= e((string) $essayCount) ?> (<?= e(dare_report_percent($essayCount, $studentCount)) ?>)</td>
                        <td data-label="Winners"><?= e((string) $winnerCount) ?></td>
                        <td data-label="Status"><?= e(dare_class_status_label($class['status'])) ?></td>
                        <td data-label="Actions">
                            <div class="table-actions">
                                <a class="icon-link" href="<?= e(url('departments/dare/class-detail.php?id=' . $class['id'])) ?>" title="View class" aria-label="View DARE class">&#9636;</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$classRows): ?>
                    <tr><td colspan="10">No class report results found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
</main>
<?php page_footer(); ?>
