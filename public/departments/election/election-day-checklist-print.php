<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_election_worker_manager();
election_require_assignment_setup();
election_require_day_checklist_setup();

$currentAssignment = current_election_assignment();
$isManager = can_manage_election_module();
$periodId = (int) ($_GET['election_period_id'] ?? 0);
$precinctId = (int) ($_GET['precinct_id'] ?? 0);

$periodStatement = db()->prepare('SELECT * FROM election_periods WHERE id = :id');
$periodStatement->execute(['id' => $periodId]);
$period = $periodStatement->fetch();

$precinctStatement = db()->prepare('SELECT * FROM election_precincts WHERE id = :id');
$precinctStatement->execute(['id' => $precinctId]);
$precinct = $precinctStatement->fetch();

if (!$period || !$precinct) {
    http_response_code(404);
    page_header('Checklist not found');
    echo '<main class="shell"><section class="panel"><h1>Checklist not found</h1><p>The selected election or precinct could not be found.</p></section></main>';
    page_footer();
    exit;
}

if (!$isManager) {
    if (!$currentAssignment
        || (int) $currentAssignment['election_period_id'] !== $periodId
        || (int) $currentAssignment['precinct_id'] !== $precinctId) {
        http_response_code(403);
        page_header('Access denied');
        echo '<main class="shell"><section class="panel"><h1>Access denied</h1><p>You do not have permission to print this checklist.</p></section></main>';
        page_footer();
        exit;
    }
}

$taskStatement = db()->prepare(
    'SELECT election_day_checklist_tasks.*,
            election_day_checklist_completions.completed_at
     FROM election_day_checklist_tasks
     LEFT JOIN election_day_checklist_completions ON election_day_checklist_completions.task_id = election_day_checklist_tasks.id
        AND election_day_checklist_completions.election_period_id = election_day_checklist_tasks.election_period_id
        AND election_day_checklist_completions.precinct_id = :precinct_id
     WHERE election_day_checklist_tasks.election_period_id = :election_period_id
       AND election_day_checklist_tasks.is_active = 1
     ORDER BY election_day_checklist_tasks.sort_order, election_day_checklist_tasks.task_title'
);
$taskStatement->execute([
    'election_period_id' => $periodId,
    'precinct_id' => $precinctId,
]);
$tasks = $taskStatement->fetchAll();

$equipmentStatement = db()->prepare(
    'SELECT *
     FROM election_day_equipment_schedules
     WHERE election_period_id = :election_period_id
       AND precinct_id = :precinct_id'
);
$equipmentStatement->execute([
    'election_period_id' => $periodId,
    'precinct_id' => $precinctId,
]);
$equipment = $equipmentStatement->fetch() ?: [];

page_header('Print Election Day Checklist');
?>
<main class="shell election-day-print-shell">
    <section class="panel print-hidden">
        <h1>Print Election Day Checklist</h1>
        <?php election_navigation('election-day-checklist'); ?>
        <div class="actions">
            <button type="button" onclick="window.print()">Print checklist</button>
            <a class="button secondary" href="<?= e(url('departments/election/election-day-checklist.php?election_period_id=' . $periodId . '&precinct_id=' . $precinctId)) ?>">Back to checklist</a>
        </div>
    </section>

    <section class="panel election-day-print-card">
        <div class="roster-header">
            <div>
                <p class="meta"><?= e($period['name']) ?></p>
                <h1><?= e($precinct['name']) ?> Election Day Checklist</h1>
                <p>
                    <?= e($precinct['location_name']) ?><br>
                    <?= e($precinct['street_address']) ?>, <?= e($precinct['city']) ?>, <?= e($precinct['state']) ?> <?= e($precinct['zip_code']) ?>
                </p>
            </div>
            <dl class="roster-summary">
                <dt>Delivery</dt>
                <dd><?= !empty($equipment['delivery_date']) ? e(format_display_date($equipment['delivery_date']) . ' ' . format_display_time($equipment['delivery_time'] ?? '')) : 'Not scheduled' ?></dd>
                <dt>Pickup</dt>
                <dd><?= !empty($equipment['pickup_date']) ? e(format_display_date($equipment['pickup_date']) . ' ' . format_display_time($equipment['pickup_time'] ?? '')) : 'Not scheduled' ?></dd>
            </dl>
        </div>

        <?php if (!empty($equipment['notes'])): ?>
            <p><strong>Equipment notes:</strong> <?= nl2br(e($equipment['notes'])) ?></p>
        <?php endif; ?>

        <table class="table election-day-print-table">
            <thead>
                <tr>
                    <th>Done</th>
                    <th>Task</th>
                    <th>Completed online</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tasks as $task): ?>
                    <tr>
                        <td><?= $task['completed_at'] ? '&#9745;' : '&#9744;' ?></td>
                        <td>
                            <strong><?= e($task['task_title']) ?></strong>
                            <?php if (!empty($task['instructions'])): ?>
                                <br><span class="meta"><?= nl2br(e($task['instructions'])) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= $task['completed_at'] ? e(format_display_date($task['completed_at']) . ' ' . format_display_time($task['completed_at'])) : '' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$tasks): ?>
                    <tr><td colspan="3">No checklist tasks have been created.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="election-day-signature-lines">
            <span>Chief Judge signature</span>
            <span>Date</span>
        </div>
    </section>
</main>
<?php page_footer(); ?>
