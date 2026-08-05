<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_election_access();
election_require_assignment_setup();

$worker = current_election_worker();
$assignment = current_election_assignment();
if ($worker && !$assignment) {
    redirect_to('departments/election/select-assignment.php');
}
$isManager = can_manage_election_module();
$positions = election_positions(false);
$query = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? 'active';
$periodFilter = array_key_exists('election_period_id', $_GET) ? (int) $_GET['election_period_id'] : 0;
$allowedStatusFilters = ['active', 'open', 'full', 'cancelled', 'all'];
if (!in_array($statusFilter, $allowedStatusFilters, true)) {
    $statusFilter = 'active';
}
if (!$isManager && in_array($statusFilter, ['cancelled', 'all'], true)) {
    $statusFilter = 'active';
}
$periods = $isManager
    ? db()->query('SELECT * FROM election_periods ORDER BY is_active DESC, starts_on DESC, name')->fetchAll()
    : [];
if ($isManager && !array_key_exists('election_period_id', $_GET)) {
    foreach ($periods as $period) {
        if ((int) $period['is_active'] === 1) {
            $periodFilter = (int) $period['id'];
            break;
        }
    }
}

$sql = 'SELECT election_training_classes.*,
               COUNT(election_training_registrations.worker_id) AS registrations
        FROM election_training_classes
        LEFT JOIN election_training_registrations ON election_training_registrations.class_id = election_training_classes.id
        WHERE 1 = 1';
$params = [];

if ($query !== '') {
    $sql .= ' AND election_training_classes.class_title LIKE :query';
    $params['query'] = '%' . $query . '%';
}

if (!$isManager || in_array($statusFilter, ['active', 'open', 'full'], true)) {
    $sql .= ' AND election_training_classes.is_cancelled = 0';
} elseif ($statusFilter === 'cancelled') {
    $sql .= ' AND election_training_classes.is_cancelled = 1';
}

if ($isManager && $periodFilter > 0) {
    $sql .= ' AND election_training_classes.election_period_id = :filter_period_id';
    $params['filter_period_id'] = $periodFilter;
}

if ($assignment) {
    if (!election_assignment_has_chief_permissions($assignment)) {
        $trainingPositionIds = election_assignment_training_position_ids($assignment);
        $trainingPositionPlaceholders = [];
        foreach ($trainingPositionIds as $index => $trainingPositionId) {
            $placeholder = 'training_position_id_' . $index;
            $trainingPositionPlaceholders[] = ':' . $placeholder;
            $params[$placeholder] = $trainingPositionId;
        }
        $sql .= ' AND EXISTS (
                      SELECT 1 FROM election_training_class_positions
                      WHERE election_training_class_positions.class_id = election_training_classes.id
                        AND election_training_class_positions.position_id IN (' . implode(',', $trainingPositionPlaceholders) . ')
                  )';
    }
    $sql .= ' AND election_training_classes.election_period_id = :period_id
              ';
    $params['period_id'] = (int) $assignment['election_period_id'];
}

$sql .= ' GROUP BY election_training_classes.id';
if ($statusFilter === 'open') {
    $sql .= ' HAVING registrations < election_training_classes.seats_total';
} elseif ($statusFilter === 'full') {
    $sql .= ' HAVING registrations >= election_training_classes.seats_total';
}
$sql .= ' ORDER BY election_training_classes.class_date, election_training_classes.start_time';
$statement = db()->prepare($sql);
$statement->execute($params);
$classes = $statement->fetchAll();

$registeredClassIds = [];
$registeredClassByPeriod = [];
$assignmentCanJoinMultipleClasses = $assignment ? election_assignment_has_optional_training_role($assignment) : false;
if ($assignment) {
    $statement = db()->prepare(
        'SELECT election_training_classes.id, election_training_classes.election_period_id
         FROM election_training_registrations
         INNER JOIN election_training_classes ON election_training_classes.id = election_training_registrations.class_id
         WHERE election_training_registrations.assignment_id = :assignment_id'
    );
    $statement->execute(['assignment_id' => $assignment['id']]);
    foreach ($statement->fetchAll() as $registration) {
        $registeredClassIds[] = (int) $registration['id'];
        $registeredClassByPeriod[(int) $registration['election_period_id']] = (int) $registration['id'];
    }
}

$actions = [
    ['label' => 'Election Home', 'href' => url('departments/election/index.php'), 'primary' => true],
];
if ($isManager) {
    $actions[] = ['label' => 'New class', 'href' => url('departments/election/class-edit.php')];
    $actions[] = ['label' => 'Setup', 'href' => url('departments/election/setup.php')];
}
if (current_election_actor_can_manage_workers()) {
    $actions[] = ['label' => 'Precinct Staffing', 'href' => url('departments/election/staffing.php')];
    $actions[] = ['label' => 'Contacts', 'href' => url('departments/election/workers.php')];
}

page_header('Election Training Classes');
?>
<main class="shell">
    <section class="panel">
        <h1>Training Classes</h1>
        <p><?= $assignment ? 'Classes shown here match your selected assignment.' : 'Create classes, manage signups, and record attendance.' ?></p>
        <?php election_navigation('classes'); ?>

        <?php if ($message = flash('success')): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <div class="section-heading-row">
            <h1>Classes</h1>
        </div>
        <form class="form compact-form" method="get" style="margin-bottom: 18px;">
            <?php if ($isManager): ?>
                <label>
                    Election
                    <select name="election_period_id">
                        <option value="">All elections</option>
                        <?php foreach ($periods as $period): ?>
                            <option value="<?= e((string) $period['id']) ?>" <?= $periodFilter === (int) $period['id'] ? 'selected' : '' ?>>
                                <?= e($period['name']) ?><?= (int) $period['is_active'] === 1 ? ' (open)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>
            <label>
                Class name
                <input name="q" value="<?= e($query) ?>" placeholder="Search class name">
            </label>
            <label>
                Status
                <select name="status">
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active classes</option>
                    <option value="open" <?= $statusFilter === 'open' ? 'selected' : '' ?>>Open seats</option>
                    <option value="full" <?= $statusFilter === 'full' ? 'selected' : '' ?>>Full classes</option>
                    <?php if ($isManager): ?>
                        <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled classes</option>
                        <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All classes</option>
                    <?php endif; ?>
                </select>
            </label>
            <div class="actions">
                <button type="submit">Filter</button>
                <a class="button secondary" href="<?= e(url('departments/election/classes.php')) ?>">Clear</a>
            </div>
        </form>
        <table class="table mobile-card-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Class</th>
                    <th>Location</th>
                    <th>Seats</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($classes as $class): ?>
                    <?php
                    $remainingSeats = max(0, (int) $class['seats_total'] - (int) $class['registrations']);
                    $isRegistered = in_array((int) $class['id'], $registeredClassIds, true);
                    $registeredForPeriodClassId = $registeredClassByPeriod[(int) $class['election_period_id']] ?? null;
                    $isRegisteredElsewhere = $assignment
                        && !$assignmentCanJoinMultipleClasses
                        && $registeredForPeriodClassId
                        && $registeredForPeriodClassId !== (int) $class['id'];
                    $isCancelled = (int) $class['is_cancelled'] === 1;
                    $classStatus = $isCancelled ? 'Cancelled' : ($remainingSeats > 0 ? 'Open' : 'Full');
                    ?>
                    <tr>
                        <td data-label="Date"><?= e(format_display_date($class['class_date'])) ?> <?= e(format_display_time($class['start_time'])) ?></td>
                        <td data-label="Class"><?= e($class['class_title']) ?><br><span class="meta"><?= e($class['duration_minutes']) ?> minutes with <?= e($class['instructor_name']) ?></span></td>
                        <td data-label="Location"><?= e($class['building_address']) ?><br><span class="meta"><?= e($class['room_location'] ?: 'Room not provided') ?></span></td>
                        <td data-label="Seats"><?= e((string) $remainingSeats) ?> of <?= e((string) $class['seats_total']) ?> open</td>
                        <td data-label="Status">
                            <span class="badge <?= $isCancelled || $remainingSeats === 0 ? 'badge-muted' : 'badge-success' ?>">
                                <?= e($classStatus) ?>
                            </span>
                        </td>
                        <td data-label="Actions">
                            <div class="table-actions">
                                <a class="icon-link" href="<?= e(url('departments/election/class-detail.php?id=' . $class['id'])) ?>" title="View class" aria-label="View class">&#9636;</a>
                                <?php if ($isManager): ?>
                                    <a class="icon-link" href="<?= e(url('departments/election/class-edit.php?id=' . $class['id'])) ?>" title="Edit class" aria-label="Edit class">&#9998;</a>
                                    <a class="icon-link" href="<?= e(url('departments/election/class-edit.php?copy_id=' . $class['id'])) ?>" title="Copy class" aria-label="Copy class">&#10064;</a>
                                <?php endif; ?>
                                <?php if ($assignment && !$isCancelled && !$isRegistered && !$isRegisteredElsewhere && $remainingSeats > 0): ?>
                                    <form method="post" action="<?= e(url('departments/election/signup.php')) ?>">
                                        <input type="hidden" name="class_id" value="<?= e((string) $class['id']) ?>">
                                        <input type="hidden" name="worker_id" value="<?= e((string) $worker['id']) ?>">
                                        <input type="hidden" name="assignment_id" value="<?= e((string) $assignment['id']) ?>">
                                        <button type="submit" class="secondary compact-button">Sign up</button>
                                    </form>
                                <?php elseif ($assignment && $isRegistered): ?>
                                    <span class="badge badge-success">Signed up</span>
                                    <form method="post" action="<?= e(url('departments/election/leave-class.php')) ?>">
                                        <input type="hidden" name="class_id" value="<?= e((string) $class['id']) ?>">
                                        <input type="hidden" name="worker_id" value="<?= e((string) $worker['id']) ?>">
                                        <input type="hidden" name="assignment_id" value="<?= e((string) $assignment['id']) ?>">
                                        <button type="submit" class="secondary compact-button">Leave class</button>
                                    </form>
                                <?php elseif ($assignment && $isRegisteredElsewhere): ?>
                                    <span class="badge badge-muted">Already signed up</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$classes): ?>
                    <tr><td colspan="6">No classes matched the selected filter.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
</main>
<?php page_footer(); ?>
