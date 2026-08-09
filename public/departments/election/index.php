<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_election_access();
election_require_assignment_setup();

$portalUser = current_user();
$worker = current_election_worker();
$assignment = current_election_assignment();

if ($worker && !$assignment) {
    redirect_to('departments/election/select-assignment.php');
}

$isManager = can_manage_election_module();
$isChief = $assignment && election_assignment_has_chief_permissions($assignment);
$isActualChief = $assignment && election_assignment_is_chief_judge($assignment);
$canJoinOptionalTraining = $assignment && election_assignment_has_optional_training_role($assignment);

$activePeriods = election_active_periods();
$activeWorkerCount = (int) db()->query('SELECT COUNT(*) FROM election_worker_assignments WHERE is_active = 1')->fetchColumn();
$upcomingClassCount = (int) db()->query(
    'SELECT COUNT(*) FROM election_training_classes
     WHERE is_cancelled = 0
       AND class_date >= CURDATE()'
)->fetchColumn();

$primaryActions = [];
$toolGroups = [];
if ($isManager || $isChief) {
    $primaryActions = [
        ['label' => 'Needs Attention', 'href' => url('departments/election/needs-attention.php'), 'primary' => true],
        ['label' => 'Contacts', 'href' => url('departments/election/workers.php')],
        ['label' => 'Precinct Staffing', 'href' => url('departments/election/staffing.php')],
        ['label' => 'Training classes', 'href' => url('departments/election/classes.php')],
    ];

    $toolGroups[] = [
        'title' => 'Dashboard',
        'links' => [
            ['label' => 'Staffing Progress', 'href' => url('departments/election/staffing-progress.php')],
        ],
    ];
    if ($isManager) {
        $toolGroups[array_key_last($toolGroups)]['links'][] = ['label' => 'Feedback', 'href' => url('departments/election/chief-feedback.php')];
    }

    $toolGroups[] = [
        'title' => 'Contacts',
        'links' => [
            ['label' => 'Address Book', 'href' => url('departments/election/workers.php')],
        ],
    ];
    if ($isManager) {
        $toolGroups[array_key_last($toolGroups)]['links'][] = ['label' => 'Import Contacts', 'href' => url('departments/election/import-workers.php')];
        $toolGroups[array_key_last($toolGroups)]['links'][] = ['label' => 'Merge Contacts', 'href' => url('departments/election/merge-workers.php')];
        $toolGroups[array_key_last($toolGroups)]['links'][] = ['label' => 'Bulk Email', 'href' => url('departments/election/bulk-email.php')];
    }

    $toolGroups[] = [
        'title' => 'Staffing',
        'links' => [
            ['label' => 'Staffing Sheet', 'href' => url('departments/election/staffing-sheet.php')],
            ['label' => 'Precinct Contact Sheet', 'href' => url('departments/election/precinct-contact-sheet.php')],
            ['label' => 'Reuse past workers', 'href' => url('departments/election/reuse-workers.php')],
        ],
    ];

    $toolGroups[] = [
        'title' => 'Training',
        'links' => [
            ['label' => 'Training Signups', 'href' => url('departments/election/training-signups.php')],
        ],
    ];
}

if ($isManager) {
    $toolGroups[array_key_last($toolGroups)]['links'][] = ['label' => 'New Class', 'href' => url('departments/election/class-edit.php')];
    $toolGroups[array_key_last($toolGroups)]['links'][] = ['label' => 'Email Template', 'href' => url('departments/election/email-template.php')];
}

if ($isManager || $isChief) {
    $toolGroups[] = [
        'title' => 'Election Day',
        'links' => [
            ['label' => 'Checklist', 'href' => url('departments/election/election-day-checklist.php')],
            ['label' => 'Precinct Notes', 'href' => url('departments/election/precinct-notes.php')],
            ['label' => 'Chief Judge Debrief', 'href' => url('departments/election/chief-judge-debrief.php')],
        ],
    ];
}

if ($isManager) {
    $toolGroups[] = [
        'title' => 'Payroll',
        'links' => [
            ['label' => 'Summary', 'href' => url('departments/election/payroll.php')],
            ['label' => 'Election Day Pay', 'href' => url('departments/election/payroll-election-day.php')],
            ['label' => 'Training Pay', 'href' => url('departments/election/payroll-training.php')],
            ['label' => 'Payroll Setup', 'href' => url('departments/election/payroll-setup.php')],
        ],
    ];

    $toolGroups[] = [
        'title' => 'Setup',
        'links' => [
            ['label' => 'Setup Home', 'href' => url('departments/election/setup.php')],
            ['label' => 'Election Periods', 'href' => url('departments/election/election-periods.php')],
            ['label' => 'Precincts', 'href' => url('departments/election/precincts.php')],
            ['label' => 'Positions', 'href' => url('departments/election/positions.php')],
            ['label' => 'Election Day Setup', 'href' => url('departments/election/election-day-setup.php')],
            ['label' => 'Debrief Questions', 'href' => url('departments/election/debrief-setup.php')],
        ],
    ];
}

if ($isManager || $isChief) {
    $guideLinks = [];
    if ($isManager) {
        $guideLinks[] = ['label' => 'Supervisor How To', 'href' => url('departments/election/how-to-supervisor.php')];
    }
    $guideLinks[] = ['label' => 'Chief Judge How To', 'href' => url('departments/election/how-to-chief-judge.php')];

    $toolGroups[] = [
        'title' => 'Guides',
        'links' => $guideLinks,
    ];
}

if (!$primaryActions) {
    $primaryActions = [
        ['label' => 'Training classes', 'href' => url('departments/election/classes.php'), 'primary' => true],
    ];
}

if ($worker) {
    $myAccountLinks = [
        ['label' => 'My information', 'href' => url('departments/election/worker-edit.php?id=' . $worker['id'])],
    ];
    if ($isActualChief) {
        $myAccountLinks[] = ['label' => 'My Feedback', 'href' => url('departments/election/my-feedback.php')];
    }
    $myAccountLinks[] = ['label' => 'Sign out', 'href' => url('departments/election/sign-out.php')];

    $toolGroups[] = [
        'title' => 'My Account',
        'links' => $myAccountLinks,
    ];
}

$registeredClasses = [];
if ($worker) {
    $statement = db()->prepare(
        'SELECT election_training_classes.*, election_training_registrations.attended
         FROM election_training_registrations
         INNER JOIN election_training_classes ON election_training_classes.id = election_training_registrations.class_id
         WHERE election_training_registrations.assignment_id = :assignment_id
         ORDER BY election_training_classes.class_date, election_training_classes.start_time'
    );
    $statement->execute(['assignment_id' => $assignment['id']]);
    $registeredClasses = $statement->fetchAll();
}

$payEstimateRow = null;
$payEstimateLocked = false;
if ($worker && $assignment && election_payroll_tables_exist()) {
    $payCalculation = election_payroll_calculation((int) $assignment['election_period_id']);
    $payEstimateLocked = (int) ($payCalculation['settings']['is_locked'] ?? 0) === 1;
    foreach ($payCalculation['summary_rows'] as $row) {
        if ((int) $row['worker_id'] === (int) $worker['id']) {
            $payEstimateRow = $row;
            break;
        }
    }
}

$unreadFeedback = [];
if ($isActualChief && election_day_checklist_tables_exist()) {
    $statement = db()->prepare(
        'SELECT election_chief_feedback.*,
                election_periods.name AS election_name,
                election_precincts.name AS precinct_name
         FROM election_chief_feedback
         INNER JOIN election_periods ON election_periods.id = election_chief_feedback.election_period_id
         INNER JOIN election_precincts ON election_precincts.id = election_chief_feedback.precinct_id
         WHERE election_chief_feedback.chief_assignment_id = :chief_assignment_id
           AND election_chief_feedback.acknowledged_at IS NULL
         ORDER BY election_chief_feedback.created_at DESC'
    );
    $statement->execute(['chief_assignment_id' => (int) $assignment['id']]);
    $unreadFeedback = $statement->fetchAll();
}

page_header('Election Readiness');
?>
<main class="shell">
    <section class="panel">
        <h1>Election Readiness</h1>
        <?php if ($worker): ?>
            <p><?= e(election_person_name($worker)) ?> - <?= e($assignment['position_name']) ?>, <?= e($assignment['precinct_name']) ?></p>
        <?php else: ?>
            <p>Prepare for election day with staffing, training, checklists, contacts, and follow-up tools.</p>
        <?php endif; ?>
        <?php election_navigation('home'); ?>

        <?php if ($message = flash('success')): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>
    </section>

    <?php if ($portalUser): ?>
        <section class="dashboard-stat-row election-home-stat-row" style="margin-top: 18px;">
            <div class="dashboard-stat-group summary-stat-group">
                <h2>Current Work</h2>
                <div class="grid dashboard-stat-grid election-home-stat-grid">
                    <article class="card dashboard-stat-card">
                        <h3><?= e((string) count($activePeriods)) ?></h3>
                        <p>Active elections</p>
                    </article>
                    <article class="card dashboard-stat-card">
                        <h3><?= e((string) $activeWorkerCount) ?></h3>
                        <p>Active workers</p>
                    </article>
                    <article class="card dashboard-stat-card">
                        <h3><?= e((string) $upcomingClassCount) ?></h3>
                        <p>Upcoming classes</p>
                    </article>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($unreadFeedback): ?>
        <section class="panel" style="margin-top: 18px;">
            <div class="section-heading-row">
                <div>
                    <h1>New Feedback</h1>
                    <p class="muted">Review feedback from the Election Supervisor. Call the Election Supervisor if you would like to discuss anything listed here.</p>
                </div>
                <a class="button secondary" href="<?= e(url('departments/election/my-feedback.php')) ?>">View all feedback</a>
            </div>
            <div class="precinct-note-list">
                <?php foreach ($unreadFeedback as $feedback): ?>
                    <article class="card precinct-note-card">
                        <div class="section-heading-row">
                            <div>
                                <h2><?= e(election_feedback_categories()[$feedback['category']] ?? 'Other') ?></h2>
                                <p class="muted"><?= e($feedback['election_name']) ?> - <?= e(format_display_date($feedback['created_at'])) ?></p>
                            </div>
                            <span class="badge badge-warning">New</span>
                        </div>
                        <p><?= nl2br(e($feedback['message_text'])) ?></p>
                        <form method="post" action="<?= e(url('departments/election/my-feedback.php')) ?>" class="actions">
                            <input type="hidden" name="action" value="acknowledge_feedback">
                            <input type="hidden" name="feedback_id" value="<?= e((string) $feedback['id']) ?>">
                            <button type="submit" class="secondary compact-button">Acknowledge</button>
                        </form>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($worker && $payEstimateRow): ?>
        <section class="panel" style="margin-top: 18px;">
            <div class="section-heading-row">
                <div>
                    <h1>My Pay Estimate</h1>
                    <p class="muted"><?= $payEstimateLocked ? 'Payroll has been finalized for this election period.' : 'This estimate may change until payroll is finalized.' ?></p>
                </div>
                <a class="button secondary" href="<?= e(url('departments/election/my-pay.php')) ?>">View details</a>
            </div>
            <div class="grid dashboard-stat-grid election-home-stat-grid">
                <article class="card dashboard-stat-card">
                    <h3><?= e(election_payroll_money((float) $payEstimateRow['election_day_pay'])) ?></h3>
                    <p>Election day</p>
                </article>
                <article class="card dashboard-stat-card">
                    <h3><?= e(election_payroll_money((float) $payEstimateRow['training_pay'])) ?></h3>
                    <p>Training</p>
                </article>
                <article class="card dashboard-stat-card">
                    <h3><?= e(election_payroll_money((float) $payEstimateRow['total_pay'])) ?></h3>
                    <p>Estimated total</p>
                </article>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($worker): ?>
        <section class="panel" style="margin-top: 18px;">
            <div class="section-heading-row">
                <div>
                    <h1>My Training</h1>
                    <?php if ($canJoinOptionalTraining): ?>
                        <p class="muted">Chief Judges and Assistant Chief Judges may join additional classes for optional training.</p>
                    <?php endif; ?>
                </div>
                <?php if ($canJoinOptionalTraining): ?>
                    <a class="button secondary" href="<?= e(url('departments/election/classes.php')) ?>">Find more classes</a>
                <?php endif; ?>
            </div>
            <?php if (!$registeredClasses): ?>
                <p>You are not signed up for a class yet.</p>
                <div class="actions">
                    <a class="button" href="<?= e(url('departments/election/classes.php')) ?>">Find a class</a>
                </div>
            <?php else: ?>
                <table class="table mobile-card-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Class</th>
                            <th>Location</th>
                            <th>Attendance</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($registeredClasses as $class): ?>
                            <tr>
                                <td data-label="Date"><?= e(format_display_date($class['class_date'])) ?> <?= e(format_display_time($class['start_time'])) ?></td>
                                <td data-label="Class"><?= e($class['class_title']) ?></td>
                                <td data-label="Location"><?= e($class['building_address']) ?><br><span class="meta"><?= e($class['room_location'] ?: 'Room not provided') ?></span></td>
                                <td data-label="Attendance">
                                    <span class="badge <?= (int) $class['attended'] === 1 ? 'badge-success' : 'badge-muted' ?>">
                                        <?= (int) $class['attended'] === 1 ? 'Complete' : 'Pending' ?>
                                    </span>
                                </td>
                                <td data-label="Actions">
                                    <?php if ((int) $class['attended'] !== 1): ?>
                                        <form method="post" action="<?= e(url('departments/election/leave-class.php')) ?>">
                                            <input type="hidden" name="class_id" value="<?= e((string) $class['id']) ?>">
                                            <input type="hidden" name="worker_id" value="<?= e((string) $worker['id']) ?>">
                                            <input type="hidden" name="assignment_id" value="<?= e((string) $assignment['id']) ?>">
                                            <button type="submit" class="secondary compact-button">Leave class</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>
<?php page_footer(); ?>
