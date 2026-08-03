<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_election_worker_manager();
election_require_assignment_setup();

$currentAssignment = current_election_assignment();
$isManager = can_manage_election_module();

$periods = $isManager
    ? db()->query('SELECT * FROM election_periods ORDER BY is_active DESC, starts_on DESC, name')->fetchAll()
    : [];
if (!$isManager && $currentAssignment) {
    $statement = db()->prepare('SELECT * FROM election_periods WHERE id = :id');
    $statement->execute(['id' => (int) $currentAssignment['election_period_id']]);
    $periods = array_filter([$statement->fetch() ?: null]);
}

$selectedPeriodId = (int) ($_GET['election_period_id'] ?? 0);
if ($selectedPeriodId === 0) {
    foreach ($periods as $period) {
        if ((int) $period['is_active'] === 1) {
            $selectedPeriodId = (int) $period['id'];
            break;
        }
    }
}
if ($selectedPeriodId === 0 && $periods) {
    $selectedPeriodId = (int) $periods[0]['id'];
}

$allowedPeriodIds = array_map(fn($period) => (int) $period['id'], $periods);
if ($selectedPeriodId > 0 && !in_array($selectedPeriodId, $allowedPeriodIds, true)) {
    $selectedPeriodId = (int) ($allowedPeriodIds[0] ?? 0);
}

$precincts = $isManager ? election_precincts() : [];
if (!$isManager && $currentAssignment) {
    $statement = db()->prepare('SELECT * FROM election_precincts WHERE id = :id');
    $statement->execute(['id' => (int) $currentAssignment['precinct_id']]);
    $precincts = array_filter([$statement->fetch() ?: null]);
}

$allPositions = election_positions();
$requiredPositions = [];
$chiefPositionIds = [];
foreach ($allPositions as $position) {
    if ((int) $position['is_assistant_chief_judge'] === 1) {
        continue;
    }
    if ((int) $position['is_chief_judge'] === 1) {
        $chiefPositionIds[] = (int) $position['id'];
    }
    $requiredPositions[(int) $position['id']] = $position;
}
$requiredPositionCount = count($requiredPositions);

$assignmentStats = [];
if ($selectedPeriodId > 0) {
    $statement = db()->prepare(
        'SELECT election_worker_assignments.precinct_id,
                COUNT(DISTINCT CASE WHEN election_worker_assignments.is_extra = 0 THEN election_worker_assignments.position_id END) AS filled_position_count,
                SUM(CASE WHEN election_worker_assignments.is_extra = 1 THEN 1 ELSE 0 END) AS extra_worker_count,
                SUM(CASE WHEN election_positions.is_chief_judge = 1 AND election_worker_assignments.is_extra = 0 THEN 1 ELSE 0 END) AS chief_count,
                GROUP_CONCAT(DISTINCT CASE WHEN election_worker_assignments.is_extra = 0 THEN election_worker_assignments.position_id END SEPARATOR ",") AS filled_position_ids
         FROM election_worker_assignments
         INNER JOIN election_positions ON election_positions.id = election_worker_assignments.position_id
         WHERE election_worker_assignments.election_period_id = :election_period_id
           AND election_worker_assignments.is_active = 1
         GROUP BY election_worker_assignments.precinct_id'
    );
    $statement->execute(['election_period_id' => $selectedPeriodId]);
    foreach ($statement->fetchAll() as $row) {
        $assignmentStats[(int) $row['precinct_id']] = $row;
    }
}

$assistantStats = [];
if ($selectedPeriodId > 0) {
    $statement = db()->prepare(
        'SELECT election_precinct_roles.precinct_id, COUNT(*) AS assistant_count
         FROM election_precinct_roles
         INNER JOIN election_worker_assignments ON election_worker_assignments.id = election_precinct_roles.assignment_id
         WHERE election_precinct_roles.election_period_id = :election_period_id
           AND election_precinct_roles.role_key = :role_key
           AND election_precinct_roles.assignment_id IS NOT NULL
           AND election_worker_assignments.is_active = 1
         GROUP BY election_precinct_roles.precinct_id'
    );
    $statement->execute([
        'election_period_id' => $selectedPeriodId,
        'role_key' => ELECTION_ROLE_ASSISTANT_CHIEF_JUDGE,
    ]);
    foreach ($statement->fetchAll() as $row) {
        $assistantStats[(int) $row['precinct_id']] = (int) $row['assistant_count'];
    }
}

$rows = [];
$completeCount = 0;
$missingPositionCount = 0;
$missingChiefCount = 0;
$missingAssistantCount = 0;
$extraWorkerTotal = 0;

foreach ($precincts as $precinct) {
    $precinctId = (int) $precinct['id'];
    $stats = $assignmentStats[$precinctId] ?? [];
    $filledPositionIds = array_map('intval', array_filter(explode(',', (string) ($stats['filled_position_ids'] ?? ''))));
    $missingPositions = [];

    foreach ($requiredPositions as $positionId => $position) {
        if (!in_array($positionId, $filledPositionIds, true)) {
            $missingPositions[] = $position['name'];
        }
    }

    $chiefCount = (int) ($stats['chief_count'] ?? 0);
    $assistantCount = $assistantStats[$precinctId] ?? 0;
    $extraWorkerCount = (int) ($stats['extra_worker_count'] ?? 0);
    $isComplete = !$missingPositions && $chiefCount === 1 && $assistantCount > 0;

    if ($isComplete) {
        $completeCount++;
    }
    if ($missingPositions) {
        $missingPositionCount++;
    }
    if ($chiefCount === 0) {
        $missingChiefCount++;
    }
    if ($assistantCount === 0) {
        $missingAssistantCount++;
    }
    $extraWorkerTotal += $extraWorkerCount;

    $rows[] = [
        'precinct' => $precinct,
        'filled_position_count' => (int) ($stats['filled_position_count'] ?? 0),
        'missing_positions' => $missingPositions,
        'chief_count' => $chiefCount,
        'assistant_count' => $assistantCount,
        'extra_worker_count' => $extraWorkerCount,
        'is_complete' => $isComplete,
    ];
}

$actions = [
    ['label' => 'Precinct Staffing', 'href' => url('departments/election/staffing.php?election_period_id=' . $selectedPeriodId), 'primary' => true],
    ['label' => 'Needs Attention', 'href' => url('departments/election/needs-attention.php?election_period_id=' . $selectedPeriodId)],
    ['label' => 'Staffing Sheet', 'href' => url('departments/election/staffing-sheet.php?election_period_id=' . $selectedPeriodId)],
    ['label' => 'Bulk Email', 'href' => url('departments/election/bulk-email.php?election_period_id=' . $selectedPeriodId)],
    ['label' => 'Worker list', 'href' => url('departments/election/workers.php')],
    ['label' => 'Election Home', 'href' => url('departments/election/index.php')],
    ['label' => 'Training classes', 'href' => url('departments/election/classes.php')],
];

page_header('Staffing Progress');
?>
<main class="shell">
    <section class="panel">
        <h1>Staffing Progress</h1>
        <p>Review which precincts are fully staffed and which still need attention.</p>
        <?php election_navigation('staffing-progress'); ?>

        <?php if ($message = flash('success')): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Election</h1>
        <form class="form compact-form" method="get">
            <label>
                Election
                <select name="election_period_id" required>
                    <?php foreach ($periods as $period): ?>
                        <option value="<?= e((string) $period['id']) ?>" <?= $selectedPeriodId === (int) $period['id'] ? 'selected' : '' ?>>
                            <?= e($period['name']) ?><?= (int) $period['is_active'] === 1 ? ' (open)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="actions">
                <button type="submit">View progress</button>
            </div>
        </form>
    </section>

    <section class="dashboard-stat-row staffing-progress-stat-row" style="margin-top: 18px;">
        <div class="dashboard-stat-group summary-stat-group">
            <h2>Progress Summary</h2>
            <div class="grid dashboard-stat-grid staffing-progress-stat-grid">
                <article class="card dashboard-stat-card">
                    <h3><?= e((string) $completeCount) ?></h3>
                    <p>Complete precincts</p>
                </article>
                <article class="card dashboard-stat-card">
                    <h3><?= e((string) $missingPositionCount) ?></h3>
                    <p>Missing positions</p>
                </article>
                <article class="card dashboard-stat-card">
                    <h3><?= e((string) $missingChiefCount) ?></h3>
                    <p>No Chief Judge</p>
                </article>
                <article class="card dashboard-stat-card">
                    <h3><?= e((string) $missingAssistantCount) ?></h3>
                    <p>No Assistant Chief</p>
                </article>
                <article class="card dashboard-stat-card">
                    <h3><?= e((string) $extraWorkerTotal) ?></h3>
                    <p>Extra workers</p>
                </article>
            </div>
        </div>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <div class="section-heading-row">
            <h1>Precincts</h1>
            <span class="badge badge-muted"><?= e((string) count($rows)) ?> shown</span>
        </div>
        <table class="table mobile-card-table">
            <thead>
                <tr>
                    <th>Precinct</th>
                    <th>Required Positions</th>
                    <th>Chief Judge</th>
                    <th>Assistant Chief</th>
                    <th>Extra Workers</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php $precinct = $row['precinct']; ?>
                    <tr>
                        <td data-label="Precinct"><?= e($precinct['name']) ?></td>
                        <td data-label="Required Positions">
                            <?= e((string) $row['filled_position_count']) ?> of <?= e((string) $requiredPositionCount) ?> filled
                            <?php if ($row['missing_positions']): ?>
                                <br><span class="meta">Missing: <?= e(implode(', ', $row['missing_positions'])) ?></span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Chief Judge">
                            <span class="badge <?= $row['chief_count'] === 1 ? 'badge-success' : 'badge-warning' ?>">
                                <?= $row['chief_count'] === 1 ? 'Assigned' : 'Missing' ?>
                            </span>
                        </td>
                        <td data-label="Assistant Chief">
                            <span class="badge <?= $row['assistant_count'] > 0 ? 'badge-success' : 'badge-warning' ?>">
                                <?= $row['assistant_count'] > 0 ? 'Assigned' : 'Missing' ?>
                            </span>
                        </td>
                        <td data-label="Extra Workers"><?= e((string) $row['extra_worker_count']) ?></td>
                        <td data-label="Status">
                            <span class="badge <?= $row['is_complete'] ? 'badge-success' : 'badge-muted' ?>">
                                <?= $row['is_complete'] ? 'Complete' : 'Needs attention' ?>
                            </span>
                        </td>
                        <td data-label="Action">
                            <a class="button secondary compact-button" href="<?= e(url('departments/election/staffing.php?election_period_id=' . $selectedPeriodId . '&precinct_id=' . (int) $precinct['id'])) ?>">Open staffing</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="7">No precincts are available for this election.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
</main>
<?php page_footer(); ?>
