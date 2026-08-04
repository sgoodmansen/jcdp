<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_election_worker_manager();
election_require_assignment_setup();

$currentAssignment = current_election_assignment();
$isModuleManager = can_manage_election_module();
$isChief = !$isModuleManager && $currentAssignment && election_assignment_has_chief_permissions($currentAssignment);
$scopePrecinctId = $isChief ? (int) $currentAssignment['precinct_id'] : 0;

$periods = db()->query('SELECT * FROM election_periods ORDER BY starts_on DESC, name')->fetchAll();
$positions = array_values(array_filter(election_positions(false), fn($position) => (int) $position['is_assistant_chief_judge'] !== 1));

$openPeriods = array_values(array_filter($periods, fn($period) => (int) $period['is_active'] === 1));
$closedPeriods = array_values(array_filter($periods, fn($period) => (int) $period['is_active'] !== 1));

$chiefPositionId = 0;
foreach ($positions as $position) {
    if ((int) $position['is_chief_judge'] === 1) {
        $chiefPositionId = (int) $position['id'];
        break;
    }
}

$targetPeriodId = (int) ($_REQUEST['target_period_id'] ?? ($openPeriods[0]['id'] ?? 0));
$sourcePeriodId = (int) ($_REQUEST['source_period_id'] ?? ($closedPeriods[0]['id'] ?? 0));
$positionId = (int) ($_REQUEST['position_id'] ?? $chiefPositionId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'reuse_assignments') {
        $assignmentIds = array_values(array_unique(array_filter(array_map('intval', $_POST['assignment_ids'] ?? []))));

        if ($sourcePeriodId === 0 || $targetPeriodId === 0 || $sourcePeriodId === $targetPeriodId) {
            flash('error', 'Select two different election periods before reusing assignments.');
            redirect_to('departments/election/reuse-workers.php');
        }

        if (!$assignmentIds) {
            flash('error', 'Select at least one worker assignment to reuse.');
            redirect_to('departments/election/reuse-workers.php?source_period_id=' . $sourcePeriodId . '&target_period_id=' . $targetPeriodId . '&position_id=' . $positionId);
        }

        $placeholders = implode(',', array_fill(0, count($assignmentIds), '?'));
        $statement = db()->prepare(
            "SELECT election_worker_assignments.*
             FROM election_worker_assignments
             INNER JOIN election_positions ON election_positions.id = election_worker_assignments.position_id
             INNER JOIN election_workers ON election_workers.id = election_worker_assignments.worker_id
             WHERE election_worker_assignments.election_period_id = ?
               AND election_worker_assignments.id IN ({$placeholders})
               AND election_positions.is_assistant_chief_judge = 0
               AND election_workers.availability_status = 'active'
               AND election_workers.is_active = 1"
                . ($scopePrecinctId > 0 ? ' AND election_worker_assignments.precinct_id = ?' : '')
        );
        $assignmentParams = array_merge([$sourcePeriodId], $assignmentIds);
        if ($scopePrecinctId > 0) {
            $assignmentParams[] = $scopePrecinctId;
        }
        $statement->execute($assignmentParams);
        $sourceAssignments = $statement->fetchAll();

        $insert = db()->prepare(
            'INSERT IGNORE INTO election_worker_assignments (
                worker_id, election_period_id, precinct_id, position_id,
                recruited_by_assignment_id, created_by_user_id, is_active, notes
             )
             VALUES (
                :worker_id, :election_period_id, :precinct_id, :position_id,
                NULL, :created_by_user_id, 1, :notes
             )'
        );
        $reactivateWorker = db()->prepare('UPDATE election_workers SET availability_status = :availability_status, unavailable_reason = "", is_active = 1 WHERE id = :id');

        $copied = 0;
        db()->beginTransaction();
        foreach ($sourceAssignments as $sourceAssignment) {
            $reactivateWorker->execute([
                'availability_status' => ELECTION_WORKER_STATUS_ACTIVE,
                'id' => (int) $sourceAssignment['worker_id'],
            ]);
            $insert->execute([
                'worker_id' => (int) $sourceAssignment['worker_id'],
                'election_period_id' => $targetPeriodId,
                'precinct_id' => (int) $sourceAssignment['precinct_id'],
                'position_id' => (int) $sourceAssignment['position_id'],
                'created_by_user_id' => current_user()['id'] ?? null,
                'notes' => trim((string) ($sourceAssignment['notes'] ?? '')),
            ]);
            $copied += $insert->rowCount();
        }
        db()->commit();

        audit_event('reused_assignments', 'election_period', (string) $targetPeriodId, [
            'source_period_id' => $sourcePeriodId,
            'requested_count' => count($assignmentIds),
            'copied_count' => $copied,
        ]);

        $skipped = count($sourceAssignments) - $copied;
        $message = $copied . ' assignment' . ($copied === 1 ? '' : 's') . ' copied to the selected election.';
        if ($skipped > 0) {
            $message .= ' ' . $skipped . ' already existed and were skipped.';
        }
        flash('success', $message);
        redirect_to('departments/election/reuse-workers.php?source_period_id=' . $sourcePeriodId . '&target_period_id=' . $targetPeriodId . '&position_id=' . $positionId);
    }
}

$selectedSourcePeriod = null;
$selectedTargetPeriod = null;
foreach ($periods as $period) {
    if ((int) $period['id'] === $sourcePeriodId) {
        $selectedSourcePeriod = $period;
    }
    if ((int) $period['id'] === $targetPeriodId) {
        $selectedTargetPeriod = $period;
    }
}

$assignments = [];
$groupedAssignments = [];
if ($sourcePeriodId > 0 && $targetPeriodId > 0 && $sourcePeriodId !== $targetPeriodId) {
    $sql = 'SELECT source_assignments.*,
                   election_workers.first_name,
                   election_workers.last_name,
                   election_workers.email,
                   election_workers.phone,
                   election_workers.is_active AS worker_is_active,
                   election_positions.name AS position_name,
                   election_positions.sort_order AS position_sort_order,
                   election_precincts.name AS precinct_name,
                   existing_assignments.id AS existing_assignment_id
            FROM election_worker_assignments source_assignments
            INNER JOIN election_workers ON election_workers.id = source_assignments.worker_id
            INNER JOIN election_positions ON election_positions.id = source_assignments.position_id
            INNER JOIN election_precincts ON election_precincts.id = source_assignments.precinct_id
            LEFT JOIN election_worker_assignments existing_assignments
                ON existing_assignments.worker_id = source_assignments.worker_id
               AND existing_assignments.election_period_id = :target_period_id
               AND existing_assignments.precinct_id = source_assignments.precinct_id
               AND existing_assignments.position_id = source_assignments.position_id
            WHERE source_assignments.election_period_id = :source_period_id
              AND election_positions.is_assistant_chief_judge = 0
              AND election_workers.availability_status = :availability_status
              AND election_workers.is_active = 1';
    $params = [
        'source_period_id' => $sourcePeriodId,
        'target_period_id' => $targetPeriodId,
        'availability_status' => ELECTION_WORKER_STATUS_ACTIVE,
    ];

    if ($positionId > 0) {
        $sql .= ' AND source_assignments.position_id = :position_id';
        $params['position_id'] = $positionId;
    }

    if ($scopePrecinctId > 0) {
        $sql .= ' AND source_assignments.precinct_id = :scope_precinct_id';
        $params['scope_precinct_id'] = $scopePrecinctId;
    }

    $sql .= ' ORDER BY election_precincts.name, election_positions.sort_order, election_workers.last_name, election_workers.first_name';
    $statement = db()->prepare($sql);
    $statement->execute($params);
    $assignments = $statement->fetchAll();

    foreach ($assignments as $assignment) {
        $precinctKey = (string) $assignment['precinct_id'];
        if (!isset($groupedAssignments[$precinctKey])) {
            $groupedAssignments[$precinctKey] = [
                'name' => $assignment['precinct_name'],
                'copyable_count' => 0,
                'total_count' => 0,
                'assignments' => [],
            ];
        }

        $groupedAssignments[$precinctKey]['total_count']++;
        if (empty($assignment['existing_assignment_id'])) {
            $groupedAssignments[$precinctKey]['copyable_count']++;
        }
        $groupedAssignments[$precinctKey]['assignments'][] = $assignment;
    }
}

$actions = [
    ['label' => 'Workers', 'href' => url('departments/election/workers.php'), 'primary' => true],
    ['label' => 'Election Home', 'href' => url('departments/election/index.php')],
];
if ($isModuleManager) {
    $actions[] = ['label' => 'Setup', 'href' => url('departments/election/setup.php')];
}

page_header('Reuse Past Workers');
?>
<main class="shell">
    <section class="panel">
        <h1>Reuse Past Workers</h1>
        <?php if ($isChief): ?>
            <p>Copy prior assignments for <?= e($currentAssignment['precinct_name']) ?> into the next election without re-entering contact information.</p>
        <?php else: ?>
            <p>Copy prior election assignments into the next election without re-entering contact information.</p>
        <?php endif; ?>
        <?php election_navigation('reuse-workers'); ?>

        <?php if ($message = flash('success')): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Select Elections</h1>
        <form class="form compact-form" method="get">
            <label>
                Copy from
                <select name="source_period_id" required>
                    <option value="">Select past election</option>
                    <?php foreach ($periods as $period): ?>
                        <option value="<?= e((string) $period['id']) ?>" <?= $sourcePeriodId === (int) $period['id'] ? 'selected' : '' ?>>
                            <?= e($period['name']) ?><?= (int) $period['is_active'] === 1 ? ' (open)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Copy to
                <select name="target_period_id" required>
                    <option value="">Select new election</option>
                    <?php foreach ($periods as $period): ?>
                        <option value="<?= e((string) $period['id']) ?>" <?= $targetPeriodId === (int) $period['id'] ? 'selected' : '' ?>>
                            <?= e($period['name']) ?><?= (int) $period['is_active'] === 1 ? ' (open)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Position
                <select name="position_id">
                    <option value="">All positions</option>
                    <?php foreach ($positions as $position): ?>
                        <option value="<?= e((string) $position['id']) ?>" <?= $positionId === (int) $position['id'] ? 'selected' : '' ?>><?= e($position['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="actions">
                <button type="submit">Review workers</button>
            </div>
        </form>
    </section>

    <?php if ($sourcePeriodId > 0 && $targetPeriodId > 0 && $sourcePeriodId === $targetPeriodId): ?>
        <section class="panel" style="margin-top: 18px;">
            <div class="notice error">Select two different election periods.</div>
        </section>
    <?php elseif ($sourcePeriodId > 0 && $targetPeriodId > 0): ?>
        <form method="post">
            <input type="hidden" name="action" value="reuse_assignments">
            <input type="hidden" name="source_period_id" value="<?= e((string) $sourcePeriodId) ?>">
            <input type="hidden" name="target_period_id" value="<?= e((string) $targetPeriodId) ?>">
            <input type="hidden" name="position_id" value="<?= e((string) $positionId) ?>">

            <section class="panel" style="margin-top: 18px;">
                <div class="section-heading-row">
                    <h1>Assignments</h1>
                    <span class="badge badge-muted">
                        <?= e((string) count(array_filter($assignments, fn($assignment) => empty($assignment['existing_assignment_id'])))) ?> available to copy
                    </span>
                </div>
                <p>
                    From <?= e($selectedSourcePeriod['name'] ?? 'selected election') ?>
                    to <?= e($selectedTargetPeriod['name'] ?? 'selected election') ?>.
                </p>
                <div class="actions">
                    <button type="submit">Copy selected assignments</button>
                </div>
            </section>

            <?php foreach ($groupedAssignments as $precinctGroup): ?>
                <details class="panel setup-section reuse-precinct-section" style="margin-top: 12px;">
                    <summary class="section-heading-row">
                        <div class="precinct-worker-title">
                            <h2><?= e($precinctGroup['name']) ?></h2>
                            <span class="badge badge-muted">
                                <?= e((string) $precinctGroup['copyable_count']) ?> available / <?= e((string) $precinctGroup['total_count']) ?> total
                            </span>
                        </div>
                        <span class="button secondary compact-button">View section</span>
                    </summary>
                    <table class="table mobile-card-table">
                        <thead>
                            <tr>
                                <th>Copy</th>
                                <th>Name</th>
                                <th>Position</th>
                                <th>Contact</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($precinctGroup['assignments'] as $assignment): ?>
                                <?php $alreadyExists = !empty($assignment['existing_assignment_id']); ?>
                                <tr>
                                    <td data-label="Copy">
                                        <?php if ($alreadyExists): ?>
                                            <span class="badge badge-muted">Exists</span>
                                        <?php else: ?>
                                            <label class="check-label">
                                                <input type="checkbox" name="assignment_ids[]" value="<?= e((string) $assignment['id']) ?>" checked>
                                                Select
                                            </label>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Name"><?= e(election_person_name($assignment)) ?></td>
                                    <td data-label="Position"><?= e($assignment['position_name']) ?></td>
                                    <td data-label="Contact">
                                        <?= e($assignment['email'] ?: 'No email') ?><br>
                                        <span class="meta"><?= e($assignment['phone'] ?: 'No phone') ?></span>
                                    </td>
                                    <td data-label="Status">
                                        <span class="badge <?= (int) $assignment['worker_is_active'] === 1 ? 'badge-success' : 'badge-muted' ?>">
                                            <?= (int) $assignment['worker_is_active'] === 1 ? 'Worker available' : 'Worker archived' ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </details>
            <?php endforeach; ?>

            <?php if (!$assignments): ?>
                <section class="panel" style="margin-top: 18px;">
                    <h1>No Assignments Found</h1>
                    <p>No past assignments matched the selected election and position.</p>
                </section>
            <?php endif; ?>
        </form>
    <?php endif; ?>
</main>
<?php page_footer(); ?>
