<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_election_access();
election_require_assignment_setup();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$worker = current_election_worker();
$assignment = current_election_assignment();
$isManager = can_manage_election_module();
$canManageWorkers = current_election_actor_can_manage_workers();
$canManageClassGlobally = $isManager && !$worker;

$statement = db()->prepare(
    'SELECT election_training_classes.*, election_periods.name AS election_name
     FROM election_training_classes
     INNER JOIN election_periods ON election_periods.id = election_training_classes.election_period_id
     WHERE election_training_classes.id = :id'
);
$statement->execute(['id' => $id]);
$class = $statement->fetch();

if (!$class) {
    http_response_code(404);
    page_header('Class not found');
    echo '<main class="shell"><section class="panel"><h1>Class not found</h1><p>The selected training class could not be found.</p></section></main>';
    page_footer();
    exit;
}

$allowedPositionIds = election_class_allowed_position_ids($id);
if ($worker && !$assignment) {
    redirect_to('departments/election/select-assignment.php');
}

if ($assignment && (int) $assignment['election_period_id'] !== (int) $class['election_period_id'] && !$canManageClassGlobally) {
    http_response_code(403);
    page_header('Access denied');
    echo '<main class="shell"><section class="panel"><h1>Access denied</h1><p>This class is not available for your selected election assignment.</p></section></main>';
    page_footer();
    exit;
}

if ($assignment && !array_intersect(election_assignment_training_position_ids($assignment), $allowedPositionIds) && !$canManageWorkers) {
    http_response_code(403);
    page_header('Access denied');
    echo '<main class="shell"><section class="panel"><h1>Access denied</h1><p>This class is not available for your position.</p></section></main>';
    page_footer();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_attendance') {
    if (!$canManageClassGlobally) {
        http_response_code(403);
        page_header('Access denied');
        echo '<main class="shell"><section class="panel"><h1>Access denied</h1><p>You do not have permission to record attendance.</p></section></main>';
        page_footer();
        exit;
    }

    $attendedAssignmentIds = array_map('intval', (array) ($_POST['attended_assignment_ids'] ?? []));
    $registeredStatement = db()->prepare('SELECT assignment_id FROM election_training_registrations WHERE class_id = :class_id');
    $registeredStatement->execute(['class_id' => $id]);
    $registeredAssignmentIds = array_map('intval', array_column($registeredStatement->fetchAll(), 'assignment_id'));

    $updateStatement = db()->prepare(
        'UPDATE election_training_registrations
         SET attended = :attended,
             attended_at = CASE WHEN :attended_again = 1 THEN COALESCE(attended_at, NOW()) ELSE NULL END
         WHERE class_id = :class_id
           AND assignment_id = :assignment_id'
    );

    foreach ($registeredAssignmentIds as $registeredAssignmentId) {
        $attended = in_array($registeredAssignmentId, $attendedAssignmentIds, true) ? 1 : 0;
        $updateStatement->execute([
            'attended' => $attended,
            'attended_again' => $attended,
            'class_id' => $id,
            'assignment_id' => $registeredAssignmentId,
        ]);
    }

    audit_event('recorded_attendance', 'election_training_class', (string) $id, ['attended_count' => count($attendedAssignmentIds)]);
    flash('success', 'Attendance saved.');
    redirect_to('departments/election/class-detail.php?id=' . $id);
}

$positions = [];
if ($allowedPositionIds) {
    $positionStatement = db()->prepare(
        'SELECT name FROM election_positions WHERE id IN (' . implode(',', array_fill(0, count($allowedPositionIds), '?')) . ') ORDER BY sort_order, name'
    );
    $positionStatement->execute($allowedPositionIds);
    $positions = array_column($positionStatement->fetchAll(), 'name');
}

$registrationSql = 'SELECT election_training_registrations.*,
                           election_worker_assignments.id AS assignment_id,
                           election_workers.first_name,
                           election_workers.last_name,
                           election_workers.email,
                           election_workers.phone,
                           election_positions.name AS position_name,
                           election_worker_assignments.precinct_id,
                           election_precincts.name AS precinct_name
                    FROM election_training_registrations
                    INNER JOIN election_workers ON election_workers.id = election_training_registrations.worker_id
                    INNER JOIN election_worker_assignments ON election_worker_assignments.id = election_training_registrations.assignment_id
                    INNER JOIN election_positions ON election_positions.id = election_worker_assignments.position_id
                    INNER JOIN election_precincts ON election_precincts.id = election_worker_assignments.precinct_id
                    WHERE election_training_registrations.class_id = :class_id';
$registrationParams = ['class_id' => $id];
if ($assignment && !$canManageClassGlobally) {
    if (!election_assignment_has_chief_permissions($assignment)) {
        $registrationSql .= ' AND election_worker_assignments.id = :assignment_id';
        $registrationParams['assignment_id'] = (int) $assignment['id'];
    }
}
$registrationSql .= ' ORDER BY election_precincts.name, election_workers.last_name, election_workers.first_name';
$registrationStatement = db()->prepare($registrationSql);
$registrationStatement->execute($registrationParams);
$registrations = $registrationStatement->fetchAll();

$totalRegistrationStatement = db()->prepare(
    'SELECT COUNT(*)
     FROM election_training_registrations
     WHERE class_id = :class_id'
);
$totalRegistrationStatement->execute(['class_id' => $id]);
$totalRegistrations = (int) $totalRegistrationStatement->fetchColumn();
$remainingSeats = max(0, (int) $class['seats_total'] - $totalRegistrations);
$isChiefRosterView = $assignment && !$canManageClassGlobally && election_assignment_has_chief_permissions($assignment);

$eligibleWorkers = [];
if ($canManageWorkers) {
    $eligibleSql = 'SELECT election_workers.*,
                          election_worker_assignments.id AS assignment_id,
                          election_positions.name AS position_name,
                          election_precincts.name AS precinct_name
                   FROM election_worker_assignments
                   INNER JOIN election_workers ON election_workers.id = election_worker_assignments.worker_id
                   INNER JOIN election_positions ON election_positions.id = election_worker_assignments.position_id
                   LEFT JOIN election_precinct_roles ON election_precinct_roles.assignment_id = election_worker_assignments.id
                       AND election_precinct_roles.role_key = "' . ELECTION_ROLE_ASSISTANT_CHIEF_JUDGE . '"
                   INNER JOIN election_precincts ON election_precincts.id = election_worker_assignments.precinct_id
                   LEFT JOIN election_training_registrations ON election_training_registrations.assignment_id = election_worker_assignments.id
                       AND election_training_registrations.class_id = :class_id
                   LEFT JOIN election_training_registrations AS period_registrations ON period_registrations.assignment_id = election_worker_assignments.id
                   LEFT JOIN election_training_classes AS period_classes ON period_classes.id = period_registrations.class_id
                       AND period_classes.election_period_id = :period_id_for_existing
                   WHERE election_workers.availability_status = :availability_status
                     AND election_workers.is_active = 1
                     AND election_worker_assignments.is_active = 1
                     AND election_worker_assignments.election_period_id = :period_id
                     AND election_training_registrations.assignment_id IS NULL
                     AND NOT EXISTS (
                         SELECT 1
                         FROM election_training_registrations AS same_title_registrations
                         INNER JOIN election_training_classes AS same_title_classes ON same_title_classes.id = same_title_registrations.class_id
                         WHERE same_title_registrations.assignment_id = election_worker_assignments.id
                           AND same_title_classes.election_period_id = :same_title_period_id
                           AND same_title_classes.id <> :same_title_class_id
                           AND LOWER(TRIM(same_title_classes.class_title)) = LOWER(TRIM(:same_title_class_title))
                     )
                     AND (
                         period_classes.id IS NULL
                         OR election_positions.is_chief_judge = 1
                         OR election_positions.is_assistant_chief_judge = 1
                         OR election_precinct_roles.assignment_id IS NOT NULL
                     )';
    $eligibleParams = [
        'class_id' => $id,
        'period_id' => (int) $class['election_period_id'],
        'period_id_for_existing' => (int) $class['election_period_id'],
        'same_title_period_id' => (int) $class['election_period_id'],
        'same_title_class_id' => $id,
        'same_title_class_title' => $class['class_title'],
        'availability_status' => ELECTION_WORKER_STATUS_ACTIVE,
    ];
    if ($allowedPositionIds) {
        $assistantPositionIds = election_assistant_chief_position_ids();
        $allowsAssistantChief = (bool) array_intersect($allowedPositionIds, $assistantPositionIds);
        $eligibleSql .= ' AND (election_worker_assignments.position_id IN (' . implode(',', array_map('intval', $allowedPositionIds)) . ')';
        if ($allowsAssistantChief) {
            $eligibleSql .= ' OR election_precinct_roles.assignment_id IS NOT NULL';
        }
        $eligibleSql .= ')';
    } else {
        $eligibleSql .= ' AND 1 = 0';
    }
    if ($assignment && !$canManageClassGlobally) {
        $eligibleSql .= ' AND election_worker_assignments.precinct_id = :precinct_id';
        $eligibleParams['precinct_id'] = (int) $assignment['precinct_id'];
    }
    $eligibleSql .= ' ORDER BY election_precincts.name, election_workers.last_name, election_workers.first_name';
    $eligibleStatement = db()->prepare($eligibleSql);
    $eligibleStatement->execute($eligibleParams);
    $eligibleWorkers = $eligibleStatement->fetchAll();
}

$actions = [
    ['label' => 'Training classes', 'href' => url('departments/election/classes.php'), 'primary' => true],
    ['label' => 'Election Home', 'href' => url('departments/election/index.php')],
];
if ($canManageClassGlobally) {
    $actions[] = ['label' => 'Edit class', 'href' => url('departments/election/class-edit.php?id=' . $id)];
    $actions[] = ['label' => 'Copy class', 'href' => url('departments/election/class-edit.php?copy_id=' . $id)];
}

page_header('Training Class');
?>
<main class="shell election-class-detail-shell">
    <section class="panel">
        <h1><?= e($class['class_title']) ?></h1>
        <p><?= e(format_display_date($class['class_date'])) ?> at <?= e(format_display_time($class['start_time'])) ?> - <?= e($class['duration_minutes']) ?> minutes</p>
        <?php election_navigation('classes'); ?>
        <?php if ($canManageClassGlobally): ?>
            <div class="actions" style="margin-bottom: 18px;">
                <a class="button secondary" href="<?= e(url('departments/election/class-edit.php?id=' . $id)) ?>">Edit class</a>
                <a class="button secondary" href="<?= e(url('departments/election/class-edit.php?copy_id=' . $id)) ?>">Copy class</a>
            </div>
        <?php endif; ?>

        <?php if ($message = flash('success')): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Class Details</h1>
        <dl class="detail-list">
            <dt>Election</dt>
            <dd><?= e($class['election_name']) ?></dd>
            <dt>Location</dt>
            <dd><?= e($class['building_address']) ?><?= $class['room_location'] ? ', ' . e($class['room_location']) : '' ?></dd>
            <dt>Instructor</dt>
            <dd><?= e($class['instructor_name']) ?></dd>
            <dt>Seats</dt>
            <dd>
                <?= e((string) $totalRegistrations) ?> registered of <?= e((string) $class['seats_total']) ?>
                <br><span class="meta"><?= e((string) $remainingSeats) ?> seats remaining</span>
            </dd>
            <dt>Positions</dt>
            <dd><?= e(implode(', ', $positions) ?: 'No positions selected') ?></dd>
            <?php if ($isChiefRosterView): ?>
                <dt>Your precinct</dt>
                <dd><?= e($assignment['precinct_name']) ?> workers can be removed by you before completion.</dd>
            <?php endif; ?>
        </dl>
    </section>

    <?php if ($canManageWorkers && $eligibleWorkers): ?>
        <section class="panel" style="margin-top: 18px;">
            <h1>Sign Up Worker</h1>
            <form class="form compact-form" method="post" action="<?= e(url('departments/election/signup.php')) ?>">
                <input type="hidden" name="class_id" value="<?= e((string) $id) ?>">
                <label>
                    Worker
                    <select name="assignment_id" required>
                        <option value="">Select worker</option>
                        <?php foreach ($eligibleWorkers as $eligibleWorker): ?>
                            <option value="<?= e((string) $eligibleWorker['assignment_id']) ?>">
                                <?= e(election_person_name($eligibleWorker)) ?> - <?= e($eligibleWorker['position_name']) ?>, <?= e($eligibleWorker['precinct_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="actions">
                    <button type="submit">Sign up worker</button>
                </div>
            </form>
        </section>
    <?php endif; ?>

    <section class="panel election-attendance-print" style="margin-top: 18px;">
        <div class="section-heading-row">
            <div>
                <h1>Attendance Roster</h1>
                <p class="muted">
                    <?php if ($isChiefRosterView): ?>
                        This roster shows everyone signed up for the class. You can only remove workers from <?= e($assignment['precinct_name']) ?> before training is complete.
                    <?php else: ?>
                        Print this page for manual attendance, then record completion here.
                    <?php endif; ?>
                </p>
            </div>
            <?php if ($canManageClassGlobally): ?>
                <button type="button" class="secondary compact-button" onclick="window.print()">Print</button>
            <?php endif; ?>
        </div>

        <div class="print-only roster-header election-attendance-print-header">
            <div>
                <p class="meta"><?= e($class['election_name']) ?></p>
                <h1><?= e($class['class_title']) ?></h1>
                <p><?= e(format_display_date($class['class_date'])) ?> at <?= e(format_display_time($class['start_time'])) ?> - <?= e($class['building_address']) ?><?= $class['room_location'] ? ', ' . e($class['room_location']) : '' ?></p>
            </div>
            <dl class="roster-summary">
                <dt>Registered</dt>
                <dd><?= e((string) $totalRegistrations) ?></dd>
                <dt>Instructor</dt>
                <dd><?= e($class['instructor_name']) ?></dd>
                <dt>Positions</dt>
                <dd><?= e(implode(', ', $positions) ?: 'No positions selected') ?></dd>
            </dl>
        </div>

        <form method="post">
            <input type="hidden" name="action" value="save_attendance">
            <table class="table mobile-card-table election-attendance-table">
                <thead>
                    <tr>
                        <th>Attended</th>
                        <th>Name</th>
                        <th>Precinct</th>
                        <th>Position</th>
                        <th>Contact</th>
                        <th class="print-hidden">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($registrations as $registration): ?>
                        <tr>
                            <td data-label="Attended">
                                <?php if ($canManageClassGlobally): ?>
                                    <input type="checkbox" name="attended_assignment_ids[]" value="<?= e((string) $registration['assignment_id']) ?>" <?= (int) $registration['attended'] === 1 ? 'checked' : '' ?>>
                                <?php else: ?>
                                    <?= (int) $registration['attended'] === 1 ? 'Yes' : 'No' ?>
                                <?php endif; ?>
                            </td>
                            <td data-label="Name"><?= e(election_person_name($registration)) ?></td>
                            <td data-label="Precinct"><?= e($registration['precinct_name']) ?></td>
                            <td data-label="Position"><?= e($registration['position_name']) ?></td>
                            <td data-label="Contact">
                                <?= e($registration['email'] ?: 'No email') ?><br>
                                <span class="meta"><?= e($registration['phone'] ?: 'No phone') ?></span>
                            </td>
                            <td data-label="Action" class="print-hidden">
                                <?php
                                $canRemoveRegistration = (int) $registration['attended'] !== 1
                                    && (
                                        $canManageClassGlobally
                                        || (
                                            $assignment
                                            && election_assignment_has_chief_permissions($assignment)
                                            && (int) $assignment['election_period_id'] === (int) $class['election_period_id']
                                            && (int) $assignment['precinct_id'] === (int) $registration['precinct_id']
                                        )
                                    );
                                ?>
                                <?php if ($canRemoveRegistration): ?>
                                    <form method="post" action="<?= e(url('departments/election/leave-class.php')) ?>">
                                        <input type="hidden" name="class_id" value="<?= e((string) $id) ?>">
                                        <input type="hidden" name="worker_id" value="<?= e((string) $registration['worker_id']) ?>">
                                        <input type="hidden" name="assignment_id" value="<?= e((string) $registration['assignment_id']) ?>">
                                        <button type="submit" class="secondary compact-button">Remove</button>
                                    </form>
                                <?php else: ?>
                                    <span class="meta"><?= (int) $registration['attended'] === 1 ? 'Complete' : 'No action' ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$registrations): ?>
                        <tr><td colspan="6">No workers are signed up yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php if ($canManageClassGlobally && $registrations): ?>
                <div class="actions print-hidden" style="margin-top: 18px;">
                    <button type="submit">Save attendance</button>
                </div>
            <?php endif; ?>
        </form>
    </section>
</main>
<?php page_footer(); ?>
