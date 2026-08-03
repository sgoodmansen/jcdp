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

$selectedPeriod = null;
foreach ($periods as $period) {
    if ((int) $period['id'] === $selectedPeriodId) {
        $selectedPeriod = $period;
        break;
    }
}

$precincts = $isManager ? election_precincts() : [];
if (!$isManager && $currentAssignment) {
    $statement = db()->prepare('SELECT * FROM election_precincts WHERE id = :id');
    $statement->execute(['id' => (int) $currentAssignment['precinct_id']]);
    $precincts = array_filter([$statement->fetch() ?: null]);
}

$selectedPrecinctId = (int) ($_GET['precinct_id'] ?? 0);
$allowedPrecinctIds = array_map(fn($precinct) => (int) $precinct['id'], $precincts);
if ($selectedPrecinctId > 0 && !in_array($selectedPrecinctId, $allowedPrecinctIds, true)) {
    $selectedPrecinctId = 0;
}
if (!$isManager && $allowedPrecinctIds) {
    $selectedPrecinctId = (int) $allowedPrecinctIds[0];
}

$visiblePrecincts = array_values(array_filter(
    $precincts,
    fn($precinct) => $selectedPrecinctId === 0 || (int) $precinct['id'] === $selectedPrecinctId
));
$visiblePrecinctIds = array_map(fn($precinct) => (int) $precinct['id'], $visiblePrecincts);

$positions = [];
foreach (election_positions() as $position) {
    if ((int) $position['is_assistant_chief_judge'] === 1) {
        continue;
    }
    $positions[(int) $position['id']] = $position;
}

$assignmentsBySlot = [];
$assistantAssignmentIds = [];

if ($selectedPeriodId > 0 && $visiblePrecinctIds) {
    $placeholders = [];
    $params = ['election_period_id' => $selectedPeriodId];
    foreach ($visiblePrecinctIds as $index => $precinctId) {
        $key = 'precinct_id_' . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = $precinctId;
    }

    $statement = db()->prepare(
        'SELECT election_worker_assignments.*,
                election_workers.first_name,
                election_workers.last_name,
                election_workers.email,
                election_workers.phone,
                election_positions.name AS position_name,
                election_positions.sort_order
         FROM election_worker_assignments
         INNER JOIN election_workers ON election_workers.id = election_worker_assignments.worker_id
         INNER JOIN election_positions ON election_positions.id = election_worker_assignments.position_id
         WHERE election_worker_assignments.election_period_id = :election_period_id
           AND election_worker_assignments.precinct_id IN (' . implode(',', $placeholders) . ')
           AND election_worker_assignments.is_active = 1
           AND election_positions.is_assistant_chief_judge = 0
         ORDER BY election_worker_assignments.precinct_id,
                  election_positions.sort_order,
                  election_worker_assignments.is_extra,
                  election_workers.last_name,
                  election_workers.first_name'
    );
    $statement->execute($params);
    foreach ($statement->fetchAll() as $assignment) {
        $precinctId = (int) $assignment['precinct_id'];
        $positionId = (int) $assignment['position_id'];
        $assignmentsBySlot[$precinctId][$positionId][] = $assignment;
    }

    $statement = db()->prepare(
        'SELECT election_precinct_roles.assignment_id
         FROM election_precinct_roles
         INNER JOIN election_worker_assignments ON election_worker_assignments.id = election_precinct_roles.assignment_id
         WHERE election_precinct_roles.election_period_id = :election_period_id
           AND election_precinct_roles.precinct_id IN (' . implode(',', $placeholders) . ')
           AND election_precinct_roles.role_key = :role_key
           AND election_worker_assignments.is_active = 1'
    );
    $params['role_key'] = ELECTION_ROLE_ASSISTANT_CHIEF_JUDGE;
    $statement->execute($params);
    $assistantAssignmentIds = array_map('intval', array_column($statement->fetchAll(), 'assignment_id'));
}

$sheetRows = [];
foreach ($visiblePrecincts as $precinct) {
    $precinctId = (int) $precinct['id'];
    foreach ($positions as $positionId => $position) {
        $slotAssignments = $assignmentsBySlot[$precinctId][$positionId] ?? [];
        $mainAssignment = null;
        $extraAssignments = [];

        foreach ($slotAssignments as $assignment) {
            if (!$mainAssignment && (int) ($assignment['is_extra'] ?? 0) === 0) {
                $mainAssignment = $assignment;
            } else {
                $extraAssignments[] = $assignment;
            }
        }

        $sheetRows[] = [
            'precinct' => $precinct,
            'position' => $position,
            'assignment' => $mainAssignment,
            'assignment_type' => 'Main',
            'assistant_chief' => $mainAssignment && in_array((int) $mainAssignment['id'], $assistantAssignmentIds, true),
        ];

        foreach ($extraAssignments as $extraAssignment) {
            $sheetRows[] = [
                'precinct' => $precinct,
                'position' => $position,
                'assignment' => $extraAssignment,
                'assignment_type' => 'Extra',
                'assistant_chief' => in_array((int) $extraAssignment['id'], $assistantAssignmentIds, true),
            ];
        }
    }
}

if (($_GET['format'] ?? '') === 'csv') {
    $filenameElection = preg_replace('/[^A-Za-z0-9]+/', '-', (string) ($selectedPeriod['name'] ?? 'election'));
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="staffing-sheet-' . trim($filenameElection, '-') . '.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Election', 'Precinct', 'Position', 'Assignment Type', 'Worker Name', 'Assistant Chief', 'Email', 'Phone']);
    foreach ($sheetRows as $row) {
        $assignment = $row['assignment'];
        fputcsv($output, [
            $selectedPeriod['name'] ?? '',
            $row['precinct']['name'],
            $row['position']['name'],
            $row['assignment_type'],
            $assignment ? election_person_name($assignment) : '',
            $row['assistant_chief'] ? 'Yes' : '',
            $assignment['email'] ?? '',
            $assignment['phone'] ?? '',
        ]);
    }
    fclose($output);
    exit;
}

$sheetQuery = [
    'election_period_id' => $selectedPeriodId,
    'precinct_id' => $selectedPrecinctId > 0 ? $selectedPrecinctId : '',
];
$exportUrl = url('departments/election/staffing-sheet.php?' . http_build_query(array_merge($sheetQuery, ['format' => 'csv'])));

$actions = [
    ['label' => 'Staffing Progress', 'href' => url('departments/election/staffing-progress.php?election_period_id=' . $selectedPeriodId), 'primary' => true],
    ['label' => 'Precinct Staffing', 'href' => url('departments/election/staffing.php?election_period_id=' . $selectedPeriodId)],
    ['label' => 'Election Home', 'href' => url('departments/election/index.php')],
];

page_header('Staffing Sheet');
?>
<main class="shell">
    <section class="panel">
        <h1>Staffing Sheet</h1>
        <p>Print or export the staffing list for an election.</p>
        <?php election_navigation('staffing-sheet'); ?>
    </section>

    <section class="panel roster-toolbar" style="margin-top: 18px;">
        <h1>Options</h1>
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
            <label>
                Precinct
                <select name="precinct_id">
                    <?php if ($isManager): ?>
                        <option value="">All precincts</option>
                    <?php endif; ?>
                    <?php foreach ($precincts as $precinct): ?>
                        <option value="<?= e((string) $precinct['id']) ?>" <?= $selectedPrecinctId === (int) $precinct['id'] ? 'selected' : '' ?>>
                            <?= e($precinct['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="actions">
                <button type="submit">View sheet</button>
                <button type="button" class="secondary" onclick="window.print()">Print</button>
                <a class="button secondary" href="<?= e($exportUrl) ?>">Export CSV</a>
            </div>
        </form>
    </section>

    <section class="panel printable-roster staffing-sheet-print" style="margin-top: 18px;">
        <div class="roster-header">
            <div>
                <h1><?= e($selectedPeriod['name'] ?? 'Election Staffing') ?></h1>
                <p><?= $selectedPrecinctId > 0 ? 'Selected precinct' : 'All precincts' ?> - generated <?= e(format_display_date(date('Y-m-d'))) ?></p>
            </div>
            <div>
                <p><strong>Staffing Sheet</strong></p>
                <p><?= e((string) count($visiblePrecincts)) ?> precinct<?= count($visiblePrecincts) === 1 ? '' : 's' ?></p>
            </div>
        </div>

        <?php foreach ($visiblePrecincts as $precinct): ?>
            <section class="staffing-sheet-precinct">
                <h2><?= e($precinct['name']) ?></h2>
                <table class="table roster-table staffing-sheet-table">
                    <thead>
                        <tr>
                            <th>Position</th>
                            <th>Worker</th>
                            <th>Asst. Chief</th>
                            <th>Email</th>
                            <th>Phone</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sheetRows as $row): ?>
                            <?php if ((int) $row['precinct']['id'] !== (int) $precinct['id']) {
                                continue;
                            } ?>
                            <?php $assignment = $row['assignment']; ?>
                            <tr>
                                <td><?= e($row['position']['name']) ?></td>
                                <td><?= $assignment ? e(election_person_name($assignment)) : '<span class="meta">Open</span>' ?></td>
                                <td><?= $row['assistant_chief'] ? 'Yes' : '' ?></td>
                                <td><?= e($assignment['email'] ?? '') ?></td>
                                <td><?= e($assignment['phone'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        <?php endforeach; ?>

        <?php if (!$visiblePrecincts): ?>
            <p>No precincts are available for this election.</p>
        <?php endif; ?>
    </section>
</main>
<?php page_footer(); ?>
