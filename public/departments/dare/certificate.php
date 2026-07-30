<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_department_access('dare');

$classId = (int) ($_GET['class_id'] ?? 0);
$studentId = (int) ($_GET['student_id'] ?? 0);

$statement = db()->prepare(
    'SELECT
        dare_classes.*,
        dare_schools.name AS school_name,
        dare_schools.principal_name,
        dare_schools.sheriff_name AS school_sheriff_name,
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
     WHERE dare_class_students.class_id = :class_id
       AND dare_class_students.student_id = :student_id'
);
$statement->execute([
    'class_id' => $classId,
    'student_id' => $studentId,
]);
$certificate = $statement->fetch();

if (!$certificate) {
    http_response_code(404);
    page_header('Certificate not found');
    echo '<main class="shell"><section class="panel"><h1>Certificate not found</h1><p>The selected student certificate could not be found.</p></section></main>';
    page_footer();
    exit;
}

$studentName = dare_person_name([
    'first_name' => $certificate['student_first_name'],
    'last_name' => $certificate['student_last_name'],
]);
$officerName = trim(($certificate['officer_first_name'] ?? '') . ' ' . ($certificate['officer_last_name'] ?? ''));
$sheriffName = trim($certificate['school_sheriff_name'] ?? '') ?: dare_setting('sheriff_name');
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

$actions = [
    ['label' => 'Class details', 'href' => url('departments/dare/class-detail.php?id=' . $classId), 'primary' => true],
    ['label' => 'Student search', 'href' => url('departments/dare/students.php')],
    ['label' => 'DARE Home', 'href' => url('departments/dare/index.php')],
];

page_header($certificateTitle);
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
            <button type="button" class="button desktop-print-button" onclick="window.print()">Print certificate</button>
            <?php page_actions($actions); ?>
        </div>
    </section>

    <section class="panel dare-certificate" style="margin-top: 18px;">
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
                        <p>County Sheriff</p>
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
</main>
<?php page_footer(); ?>
