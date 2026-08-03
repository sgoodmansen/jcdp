<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_election_access();
election_require_assignment_setup();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$worker = current_election_worker();
$assignment = current_election_assignment();
$isManager = can_manage_election_module();
$canManageWorkers = current_election_actor_can_manage_workers();

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

if ($assignment && (int) $assignment['election_period_id'] !== (int) $class['election_period_id'] && !$isManager) {
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
    if (!$isManager) {
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
                           election_precincts.name AS precinct_name
                    FROM election_training_registrations
                    INNER JOIN election_workers ON election_workers.id = election_training_registrations.worker_id
                    INNER JOIN election_worker_assignments ON election_worker_assignments.id = election_training_registrations.assignment_id
                    INNER JOIN election_positions ON election_positions.id = election_worker_assignments.position_id
                    INNER JOIN election_precincts ON election_precincts.id = election_worker_assignments.precinct_id
                    WHERE election_training_registrations.class_id = :class_id';
$registrationParams = ['class_id' => $id];
if ($assignment && !$isManager) {
    if (election_assignment_has_chief_permissions($assignment)) {
        $registrationSql .= ' AND election_worker_assignments.precinct_id = :precinct_id AND election_worker_assignments.election_period_id = :period_id';
        $registrationParams['precinct_id'] = (int) $assignment['precinct_id'];
        $registrationParams['period_id'] = (int) $assignment['election_period_id'];
    } else {
        $registrationSql .= ' AND election_worker_assignments.id = :assignment_id';
        $registrationParams['assignment_id'] = (int) $assignment['id'];
    }
}
$registrationSql .= ' ORDER BY election_precincts.name, election_workers.last_name, election_workers.first_name';
$registrationStatement = db()->prepare($registrationSql);
$registrationStatement->execute($registrationParams);
$registrations = $registrationStatement->fetchAll();

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
                     AND period_classes.id IS NULL';
    $eligibleParams = [
        'class_id' => $id,
        'period_id' => (int) $class['election_period_id'],
        'period_id_for_existing' => (int) $class['election_period_id'],
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
    if ($assignment && !$isManager) {
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
if ($isManager) {
    $actions[] = ['label' => 'Edit class', 'href' => url('departments/election/class-edit.php?id=' . $id)];
    $actions[] = ['label' => 'Copy class', 'href' => url('departments/election/class-edit.php?copy_id=' . $id)];
}

page_header('Training Class');
?>
<main class="shell">
    <section class="panel">
        <h1><?= e($class['class_title']) ?></h1>
        <p><?= e(format_display_date($class['class_date'])) ?> at <?= e(format_display_time($class['start_time'])) ?> - <?= e($class['duration_minutes']) ?> minutes</p>
        <?php election_navigation('classes'); ?>
        <?php if ($isManager): ?>
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
            <dd><?= e((string) count($registrations)) ?> registered of <?= e((string) $class['seats_total']) ?></dd>
            <dt>Positions</dt>
            <dd><?= e(implode(', ', $positions) ?: 'No positions selected') ?></dd>
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

    <section class="panel" style="margin-top: 18px;">
        <div class="section-heading-row">
            <div>
                <h1>Attendance Roster</h1>
                <p class="muted">Print this page for manual attendance, then record completion here.</p>
            </div>
            <?php if ($isManager): ?>
                <button type="button" class="secondary compact-button" onclick="window.print()">Print</button>
            <?php endif; ?>
        </div>

        <form method="post">
            <input type="hidden" name="action" value="save_attendance">
            <table class="table mobile-card-table">
                <thead>
                    <tr>
                        <th>Attended</th>
                        <th>Name</th>
                        <th>Precinct</th>
                        <th>Position</th>
                        <th>Contact</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($registrations as $registration): ?>
                        <tr>
                            <td data-label="Attended">
                                <?php if ($isManager): ?>
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
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$registrations): ?>
                        <tr><td colspan="5">No workers are signed up yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php if ($isManager && $registrations): ?>
                <div class="actions" style="margin-top: 18px;">
                    <button type="submit">Save attendance</button>
                </div>
            <?php endif; ?>
        </form>
    </section>
</main>
<?php page_footer(); ?>
