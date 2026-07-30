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
$schoolId = (int) ($_GET['school_id'] ?? 0);
$params = [];
$whereParts = ['dare_class_students.essay_winner = 1'];

if ($schoolYear !== '') {
    $whereParts[] = 'dare_classes.school_year = :school_year';
    $params['school_year'] = $schoolYear;
}

if ($schoolId > 0) {
    $whereParts[] = 'dare_classes.school_id = :school_id';
    $params['school_id'] = $schoolId;
}

$where = 'WHERE ' . implode(' AND ', $whereParts);

$schools = db()->query('SELECT id, name FROM dare_schools ORDER BY name')->fetchAll();

$statement = db()->prepare(
    "SELECT
        dare_students.first_name,
        dare_students.last_name,
        dare_students.notes,
        dare_classes.id AS class_id,
        dare_classes.school_year,
        dare_classes.semester,
        dare_classes.period,
        dare_schools.name AS school_name,
        dare_teachers.first_name AS teacher_first_name,
        dare_teachers.last_name AS teacher_last_name
     FROM dare_class_students
     INNER JOIN dare_students ON dare_students.id = dare_class_students.student_id
     INNER JOIN dare_classes ON dare_classes.id = dare_class_students.class_id
     INNER JOIN dare_schools ON dare_schools.id = dare_classes.school_id
     LEFT JOIN dare_teachers ON dare_teachers.id = dare_classes.teacher_id
     $where
     ORDER BY dare_classes.school_year DESC, dare_schools.name, dare_classes.semester, dare_classes.period, dare_students.last_name, dare_students.first_name"
);
$statement->execute($params);
$winners = $statement->fetchAll();

$winnerGroups = [];
foreach ($winners as $winner) {
    $yearLabel = $winner['school_year'] ?: 'School year not set';
    $schoolLabel = $winner['school_name'];
    $winnerGroups[$yearLabel][$schoolLabel][] = $winner;
}

$actions = [
    ['label' => 'DARE Reports', 'href' => url('departments/dare/report.php'), 'primary' => true],
    ['label' => 'DARE Home', 'href' => url('departments/dare/index.php')],
    ['label' => 'Classes', 'href' => url('departments/dare/classes.php')],
];

page_header('DARE Essay Winners');
?>
<style>
    @page {
        size: portrait;
        margin: 0.45in;
    }
</style>
<main class="shell roster-shell">
    <section class="panel roster-toolbar">
        <h1>Essay Winners</h1>
        <p>Print or review students marked as essay winners by class.</p>
        <div class="letter-action-row">
            <button type="button" class="button desktop-print-button" onclick="window.print()">Print essay winners</button>
            <?php page_actions($actions); ?>
        </div>

        <form class="form compact-form" method="get" style="margin-top: 18px;">
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
                School
                <select name="school_id">
                    <option value="0">All schools</option>
                    <?php foreach ($schools as $school): ?>
                        <option value="<?= e((string) $school['id']) ?>" <?= $schoolId === (int) $school['id'] ? 'selected' : '' ?>><?= e($school['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="actions">
                <button type="submit">Filter</button>
                <a class="button secondary" href="<?= e(url('departments/dare/essay-winners.php')) ?>">Clear</a>
            </div>
        </form>
    </section>

    <section class="panel printable-roster" style="margin-top: 18px;">
        <header class="roster-header">
            <div>
                <p class="meta">Jefferson County DARE</p>
                <h1>Essay Winners</h1>
            </div>
            <dl class="roster-summary">
                <dt>Winners</dt>
                <dd><?= e((string) count($winners)) ?></dd>
                <dt>School Year</dt>
                <dd><?= e($schoolYear ?: 'All') ?></dd>
                <dt>School</dt>
                <dd><?= e($schoolId > 0 ? array_values(array_filter($schools, fn($school) => (int) $school['id'] === $schoolId))[0]['name'] ?? 'Selected' : 'All') ?></dd>
            </dl>
        </header>

        <?php foreach ($winnerGroups as $yearLabel => $schoolGroups): ?>
            <h2 class="report-group-heading"><?= e($yearLabel) ?></h2>
            <?php foreach ($schoolGroups as $schoolLabel => $schoolWinners): ?>
                <h3 class="report-subgroup-heading"><?= e($schoolLabel) ?> <span><?= e((string) count($schoolWinners)) ?> winner<?= count($schoolWinners) === 1 ? '' : 's' ?></span></h3>
                <table class="table roster-table report-compact-table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Semester</th>
                            <th>Period</th>
                            <th>Teacher</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($schoolWinners as $winner): ?>
                            <tr>
                                <td><?= e(dare_person_name($winner)) ?></td>
                                <td><?= e($winner['semester'] ?: 'Not set') ?></td>
                                <td><?= e($winner['period'] ?: 'Not set') ?></td>
                                <td><?= e(trim(($winner['teacher_first_name'] ?? '') . ' ' . ($winner['teacher_last_name'] ?? '')) ?: 'Not assigned') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endforeach; ?>
        <?php endforeach; ?>
        <?php if (!$winners): ?>
            <table class="table roster-table">
                <tbody>
                    <tr>
                        <td>No essay winners matched the selected filters.</td>
                    </tr>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</main>
<?php page_footer(); ?>
