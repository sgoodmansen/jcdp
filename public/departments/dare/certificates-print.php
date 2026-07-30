<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_department_access('dare');

$classId = (int) ($_GET['class_id'] ?? 0);
$type = $_GET['type'] ?? 'all';
$type = in_array($type, ['all', 'graduation', 'participation'], true) ? $type : 'all';

$where = 'WHERE dare_classes.id = :class_id';
if ($type === 'graduation') {
    $where .= ' AND dare_class_students.essay_completed = 1';
} elseif ($type === 'participation') {
    $where .= ' AND dare_class_students.essay_completed = 0';
}

$statement = db()->prepare(
    'SELECT
        dare_classes.*,
        dare_schools.name AS school_name,
        dare_schools.principal_name,
        dare_students.first_name AS student_first_name,
        dare_students.last_name AS student_last_name,
        dare_class_students.essay_completed,
        dare_officers.first_name AS officer_first_name,
        dare_officers.last_name AS officer_last_name
     FROM dare_class_students
     INNER JOIN dare_classes ON dare_classes.id = dare_class_students.class_id
     INNER JOIN dare_schools ON dare_schools.id = dare_classes.school_id
     INNER JOIN dare_students ON dare_students.id = dare_class_students.student_id
     LEFT JOIN dare_officers ON dare_officers.id = dare_classes.officer_id
     ' . $where . '
     ORDER BY dare_students.last_name, dare_students.first_name'
);
$statement->execute(['class_id' => $classId]);
$certificates = $statement->fetchAll();

$classStatement = db()->prepare(
    'SELECT dare_classes.*, dare_schools.name AS school_name
     FROM dare_classes
     INNER JOIN dare_schools ON dare_schools.id = dare_classes.school_id
     WHERE dare_classes.id = :id'
);
$classStatement->execute(['id' => $classId]);
$class = $classStatement->fetch();

if (!$class) {
    http_response_code(404);
    page_header('Class not found');
    echo '<main class="shell"><section class="panel"><h1>Class not found</h1><p>The selected DARE class could not be found.</p></section></main>';
    page_footer();
    exit;
}

$sheriffName = dare_setting('sheriff_name');
$typeLabel = match ($type) {
    'graduation' => 'Graduation Certificates',
    'participation' => 'Participation Certificates',
    default => 'All Certificates',
};
$actions = [
    ['label' => 'Class details', 'href' => url('departments/dare/class-detail.php?id=' . $classId), 'primary' => true],
    ['label' => 'DARE Home', 'href' => url('departments/dare/index.php')],
];

function dare_certificate_markup(array $certificate, string $sheriffName): void
{
    $studentName = dare_person_name([
        'first_name' => $certificate['student_first_name'],
        'last_name' => $certificate['student_last_name'],
    ]);
    $officerName = trim(($certificate['officer_first_name'] ?? '') . ' ' . ($certificate['officer_last_name'] ?? ''));
    $principalName = trim($certificate['principal_name'] ?? '');
    $isGraduationCertificate = (int) $certificate['essay_completed'] === 1;
    $graduationDate = $certificate['graduation_date']
        ? date('F j, Y', strtotime($certificate['graduation_date']))
        : null;
    $certificateTitle = $isGraduationCertificate ? 'Certificate of Graduation' : 'Certificate of Participation';
    $programLine = $isGraduationCertificate
        ? 'who has completed the DARE program at ' . $certificate['school_name'] . "\n" . 'and has made a personal commitment to resist the pressures of drug use and make safe, responsible choices.'
        : "has participated in the\nDrug Abuse Resistance Education (DARE) program\nat " . $certificate['school_name'] . '.';
    $commitmentLine = $isGraduationCertificate
        ? ''
        : 'This student may submit the final essay to receive a graduation certificate.';
    ?>
    <section class="panel dare-certificate certificate-print-page">
        <div class="certificate-border<?= $isGraduationCertificate ? ' template-certificate' : ' participation-certificate' ?>">
            <header class="certificate-header">
                <img class="certificate-logo" src="<?= e(url('assets/dare-logo.png')) ?>" alt="DARE logo">
                <div>
                    <h1><?= e($certificateTitle) ?></h1>
                    <strong>To Resist Drugs and Violence</strong>
                </div>
                <img class="certificate-flag-mark" src="<?= e(url('assets/united-states-flag-icon.webp')) ?>" alt="United States flag">
            </header>

            <div class="certificate-stars" aria-hidden="true">
                <span>★</span><span>★</span><span>★</span><span>★</span><span>★</span>
            </div>
            <p class="certificate-student-name"><?= e($studentName) ?></p>
            <p class="certificate-program-line"><?= nl2br(e($programLine)) ?></p>
            <?php if ($graduationDate): ?>
                <p class="certificate-date-line">Presented on <?= e($graduationDate) ?></p>
            <?php endif; ?>
            <?php if ($commitmentLine !== ''): ?>
                <p class="certificate-body"><?= e($commitmentLine) ?></p>
            <?php endif; ?>

            <div class="certificate-signatures">
                <div>
                    <span></span>
                    <strong><?= e($officerName ?: 'DARE Officer') ?></strong>
                    <p>DARE Officer</p>
                </div>
                <?php if ($isGraduationCertificate): ?>
                    <div>
                        <span></span>
                        <strong><?= e($sheriffName ?: 'Sheriff') ?></strong>
                        <p>Jefferson County Sheriff</p>
                    </div>
                    <div>
                        <span></span>
                        <strong><?= e($principalName ?: 'Principal') ?></strong>
                        <p>School Principal</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>
    <?php
}

page_header($typeLabel);
?>
<style>
    @page {
        size: landscape;
        margin: 0;
    }
</style>
<main class="shell certificate-shell">
    <section class="panel">
        <div class="letter-action-row">
            <button type="button" class="button desktop-print-button" onclick="window.print()">Print certificates</button>
            <?php page_actions($actions); ?>
        </div>
        <p><?= e($typeLabel) ?> for <?= e($class['school_name']) ?>, <?= e(dare_class_label($class)) ?>.</p>
        <?php if (!$class['graduation_date']): ?>
            <div class="notice error">Graduation date is not set for this class. Graduation certificates will not show a presented date.</div>
        <?php endif; ?>
    </section>

    <?php if (!$certificates): ?>
        <section class="panel" style="margin-top: 18px;">
            <h1>No Certificates</h1>
            <p>No students match this certificate selection.</p>
        </section>
    <?php else: ?>
        <?php foreach ($certificates as $certificate): ?>
            <?php dare_certificate_markup($certificate, $sheriffName); ?>
        <?php endforeach; ?>
    <?php endif; ?>
</main>
<?php page_footer(); ?>
