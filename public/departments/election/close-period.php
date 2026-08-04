<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_election_manager();
election_require_assignment_setup();

$periodId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

$statement = db()->prepare('SELECT * FROM election_periods WHERE id = :id');
$statement->execute(['id' => $periodId]);
$period = $statement->fetch();

if (!$period) {
    http_response_code(404);
    page_header('Election not found');
    ?>
    <main class="shell">
        <section class="panel">
            <h1>Election not found</h1>
            <p>The selected election period could not be found.</p>
            <?php election_navigation('setup'); ?>
        </section>
    </main>
    <?php
    page_footer();
    exit;
}

$summaryStatement = db()->prepare(
    'SELECT COUNT(*) AS total_assignments,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_assignments,
            COUNT(DISTINCT worker_id) AS assigned_workers
     FROM election_worker_assignments
     WHERE election_period_id = :period_id'
);
$summaryStatement->execute(['period_id' => $periodId]);
$summary = $summaryStatement->fetch() ?: [
    'total_assignments' => 0,
    'active_assignments' => 0,
    'assigned_workers' => 0,
];

$trainingStatement = db()->prepare(
    'SELECT COUNT(*) AS training_registrations,
            SUM(CASE WHEN election_training_registrations.attended = 1 THEN 1 ELSE 0 END) AS completed_training
     FROM election_training_registrations
     INNER JOIN election_training_classes ON election_training_classes.id = election_training_registrations.class_id
     WHERE election_training_classes.election_period_id = :period_id'
);
$trainingStatement->execute(['period_id' => $periodId]);
$trainingSummary = $trainingStatement->fetch() ?: [
    'training_registrations' => 0,
    'completed_training' => 0,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ((int) $period['is_active'] !== 1) {
        flash('error', 'That election is already closed.');
        redirect_to('departments/election/setup.php');
    }

    $closedAssignmentCount = election_close_period($periodId);
    audit_event('closed', 'election_period', (string) $periodId, [
        'closed_assignment_count' => $closedAssignmentCount,
        'worker_contacts_remain_active' => true,
    ]);

    flash('success', 'Election closed. ' . $closedAssignmentCount . ' assignment' . ($closedAssignmentCount === 1 ? '' : 's') . ' closed. Worker contacts remain Active for future elections.');
    redirect_to('departments/election/setup.php');
}

page_header('Close Election Period');
?>
<main class="shell">
    <section class="panel">
        <h1>Close Election Period</h1>
        <p>Review what will happen before closing this election.</p>
        <?php election_navigation('setup'); ?>

        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1><?= e($period['name']) ?></h1>
        <p><?= e(format_display_date($period['starts_on'])) ?> to <?= e(format_display_date($period['ends_on'])) ?></p>

        <?php if ((int) $period['is_active'] !== 1): ?>
            <div class="notice success">This election is already closed.</div>
            <div class="actions">
                <a class="button" href="<?= e(url('departments/election/setup.php')) ?>">Return to setup</a>
            </div>
        <?php else: ?>
            <div class="grid dashboard-stat-grid election-home-stat-grid">
                <article class="card dashboard-stat-card">
                    <h3><?= e((string) (int) $summary['active_assignments']) ?></h3>
                    <p>Active assignments to close</p>
                </article>
                <article class="card dashboard-stat-card">
                    <h3><?= e((string) (int) $summary['assigned_workers']) ?></h3>
                    <p>Worker contacts preserved</p>
                </article>
                <article class="card dashboard-stat-card">
                    <h3><?= e((string) (int) $trainingSummary['training_registrations']) ?></h3>
                    <p>Training records kept</p>
                </article>
            </div>

            <div class="notice warning" style="margin-top: 18px;">
                Closing this election will mark only this election's assignments inactive. Worker contacts will stay Active unless someone intentionally marks them Unavailable or Inactive.
            </div>

            <form method="post" class="actions" style="margin-top: 18px;">
                <input type="hidden" name="id" value="<?= e((string) $periodId) ?>">
                <button type="submit">Close election</button>
                <a class="button secondary" href="<?= e(url('departments/election/setup.php')) ?>">Cancel</a>
            </form>
        <?php endif; ?>
    </section>
</main>
<?php page_footer(); ?>
