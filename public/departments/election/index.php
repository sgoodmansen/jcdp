<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_election_access();

$portalUser = current_user();
$worker = current_election_worker();
$isManager = can_manage_election_module();
$isChief = $worker && ((int) $worker['is_chief_judge'] === 1 || (int) $worker['is_assistant_chief_judge'] === 1);

$activePeriods = election_active_periods();
$activeWorkerCount = (int) db()->query('SELECT COUNT(*) FROM election_workers WHERE is_active = 1')->fetchColumn();
$upcomingClassCount = (int) db()->query(
    'SELECT COUNT(*) FROM election_training_classes
     WHERE is_cancelled = 0
       AND class_date >= CURDATE()'
)->fetchColumn();

$actions = [
    ['label' => 'Training classes', 'href' => url('departments/election/classes.php'), 'primary' => true],
];

if ($isManager || $isChief) {
    $actions[] = ['label' => 'Workers', 'href' => url('departments/election/workers.php')];
}

if ($isManager) {
    $actions[] = ['label' => 'New class', 'href' => url('departments/election/class-edit.php')];
    $actions[] = ['label' => 'Setup', 'href' => url('departments/election/setup.php')];
}

if ($worker) {
    $actions[] = ['label' => 'My information', 'href' => url('departments/election/worker-edit.php?id=' . $worker['id'])];
    $actions[] = ['label' => 'Sign out', 'href' => url('departments/election/sign-out.php')];
}

$registeredClasses = [];
if ($worker) {
    $statement = db()->prepare(
        'SELECT election_training_classes.*, election_training_registrations.attended
         FROM election_training_registrations
         INNER JOIN election_training_classes ON election_training_classes.id = election_training_registrations.class_id
         WHERE election_training_registrations.worker_id = :worker_id
         ORDER BY election_training_classes.class_date, election_training_classes.start_time'
    );
    $statement->execute(['worker_id' => $worker['id']]);
    $registeredClasses = $statement->fetchAll();
}

page_header('Election Training');
?>
<main class="shell">
    <section class="panel">
        <h1>Election Training</h1>
        <?php if ($worker): ?>
            <p><?= e(election_person_name($worker)) ?> - <?= e($worker['position_name']) ?>, <?= e($worker['precinct_name']) ?></p>
        <?php else: ?>
            <p>Manage election workers, precinct assignments, training classes, and attendance.</p>
        <?php endif; ?>
        <?php page_actions($actions); ?>

        <?php if ($message = flash('success')): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>
    </section>

    <?php if ($portalUser): ?>
        <section class="dashboard-stat-row" style="margin-top: 18px;">
            <div class="dashboard-stat-group summary-stat-group">
                <h2>Current Work</h2>
                <div class="grid dashboard-stat-grid">
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

    <?php if ($worker): ?>
        <section class="panel" style="margin-top: 18px;">
            <h1>My Training</h1>
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
                                <td data-label="Date"><?= e($class['class_date']) ?> <?= e(substr($class['start_time'], 0, 5)) ?></td>
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
