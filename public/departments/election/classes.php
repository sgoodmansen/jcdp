<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_election_access();

$worker = current_election_worker();
$isManager = can_manage_election_module();
$positions = election_positions(false);

$sql = 'SELECT election_training_classes.*,
               election_periods.name AS election_name,
               COUNT(election_training_registrations.worker_id) AS registrations
        FROM election_training_classes
        INNER JOIN election_periods ON election_periods.id = election_training_classes.election_period_id
        LEFT JOIN election_training_registrations ON election_training_registrations.class_id = election_training_classes.id
        WHERE election_training_classes.is_cancelled = 0';
$params = [];

if ($worker) {
    $sql .= ' AND election_training_classes.election_period_id = :period_id
              AND EXISTS (
                  SELECT 1 FROM election_training_class_positions
                  WHERE election_training_class_positions.class_id = election_training_classes.id
                    AND election_training_class_positions.position_id = :position_id
              )';
    $params['period_id'] = (int) $worker['election_period_id'];
    $params['position_id'] = (int) $worker['position_id'];
}

$sql .= ' GROUP BY election_training_classes.id ORDER BY election_training_classes.class_date, election_training_classes.start_time';
$statement = db()->prepare($sql);
$statement->execute($params);
$classes = $statement->fetchAll();

$registeredClassIds = [];
$registeredClassByPeriod = [];
if ($worker) {
    $statement = db()->prepare(
        'SELECT election_training_classes.id, election_training_classes.election_period_id
         FROM election_training_registrations
         INNER JOIN election_training_classes ON election_training_classes.id = election_training_registrations.class_id
         WHERE election_training_registrations.worker_id = :worker_id'
    );
    $statement->execute(['worker_id' => $worker['id']]);
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
    $actions[] = ['label' => 'Workers', 'href' => url('departments/election/workers.php')];
}

page_header('Election Training Classes');
?>
<main class="shell">
    <section class="panel">
        <h1>Training Classes</h1>
        <p><?= $worker ? 'Classes shown here match your assigned position.' : 'Create classes, manage signups, and record attendance.' ?></p>
        <?php page_actions($actions); ?>

        <?php if ($message = flash('success')): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Classes</h1>
        <table class="table mobile-card-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Class</th>
                    <th>Election</th>
                    <th>Location</th>
                    <th>Seats</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($classes as $class): ?>
                    <?php
                    $remainingSeats = max(0, (int) $class['seats_total'] - (int) $class['registrations']);
                    $isRegistered = in_array((int) $class['id'], $registeredClassIds, true);
                    $registeredForPeriodClassId = $registeredClassByPeriod[(int) $class['election_period_id']] ?? null;
                    $isRegisteredElsewhere = $worker && $registeredForPeriodClassId && $registeredForPeriodClassId !== (int) $class['id'];
                    ?>
                    <tr>
                        <td data-label="Date"><?= e($class['class_date']) ?> <?= e(substr($class['start_time'], 0, 5)) ?></td>
                        <td data-label="Class"><?= e($class['class_title']) ?><br><span class="meta"><?= e($class['duration_minutes']) ?> minutes with <?= e($class['instructor_name']) ?></span></td>
                        <td data-label="Election"><?= e($class['election_name']) ?></td>
                        <td data-label="Location"><?= e($class['building_address']) ?><br><span class="meta"><?= e($class['room_location'] ?: 'Room not provided') ?></span></td>
                        <td data-label="Seats"><?= e((string) $remainingSeats) ?> of <?= e((string) $class['seats_total']) ?> open</td>
                        <td data-label="Actions">
                            <div class="table-actions">
                                <a class="icon-link" href="<?= e(url('departments/election/class-detail.php?id=' . $class['id'])) ?>" title="View class" aria-label="View class">&#9636;</a>
                                <?php if ($isManager): ?>
                                    <a class="icon-link" href="<?= e(url('departments/election/class-edit.php?id=' . $class['id'])) ?>" title="Edit class" aria-label="Edit class">&#9998;</a>
                                    <a class="icon-link" href="<?= e(url('departments/election/class-edit.php?copy_id=' . $class['id'])) ?>" title="Copy class" aria-label="Copy class">&#10064;</a>
                                <?php endif; ?>
                                <?php if ($worker && !$isRegistered && !$isRegisteredElsewhere && $remainingSeats > 0): ?>
                                    <form method="post" action="<?= e(url('departments/election/signup.php')) ?>">
                                        <input type="hidden" name="class_id" value="<?= e((string) $class['id']) ?>">
                                        <input type="hidden" name="worker_id" value="<?= e((string) $worker['id']) ?>">
                                        <button type="submit" class="secondary compact-button">Sign up</button>
                                    </form>
                                <?php elseif ($worker && $isRegistered): ?>
                                    <span class="badge badge-success">Signed up</span>
                                    <form method="post" action="<?= e(url('departments/election/leave-class.php')) ?>">
                                        <input type="hidden" name="class_id" value="<?= e((string) $class['id']) ?>">
                                        <input type="hidden" name="worker_id" value="<?= e((string) $worker['id']) ?>">
                                        <button type="submit" class="secondary compact-button">Leave class</button>
                                    </form>
                                <?php elseif ($worker && $isRegisteredElsewhere): ?>
                                    <span class="badge badge-muted">Already signed up</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$classes): ?>
                    <tr><td colspan="6">No classes are available.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
</main>
<?php page_footer(); ?>
