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

$contactsByPrecinct = [];
if ($selectedPeriodId > 0 && $visiblePrecinctIds) {
    $placeholders = [];
    $params = ['election_period_id' => $selectedPeriodId];
    foreach ($visiblePrecinctIds as $index => $precinctId) {
        $key = 'precinct_id_' . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = $precinctId;
    }

    $statement = db()->prepare(
        'SELECT election_worker_assignments.id AS assignment_id,
                election_worker_assignments.precinct_id,
                election_worker_assignments.is_extra,
                election_workers.first_name,
                election_workers.last_name,
                election_workers.email,
                election_workers.phone,
                election_positions.name AS position_name,
                election_positions.sort_order,
                election_positions.is_chief_judge,
                CASE WHEN election_precinct_roles.assignment_id IS NULL THEN 0 ELSE 1 END AS is_assistant_chief
         FROM election_worker_assignments
         INNER JOIN election_workers ON election_workers.id = election_worker_assignments.worker_id
         INNER JOIN election_positions ON election_positions.id = election_worker_assignments.position_id
         LEFT JOIN election_precinct_roles ON election_precinct_roles.assignment_id = election_worker_assignments.id
            AND election_precinct_roles.role_key = :assistant_role_key
         WHERE election_worker_assignments.election_period_id = :election_period_id
           AND election_worker_assignments.precinct_id IN (' . implode(',', $placeholders) . ')
           AND election_worker_assignments.is_active = 1
         ORDER BY election_worker_assignments.precinct_id,
                  election_positions.is_chief_judge DESC,
                  is_assistant_chief DESC,
                  election_positions.sort_order,
                  election_workers.last_name,
                  election_workers.first_name'
    );
    $params['assistant_role_key'] = ELECTION_ROLE_ASSISTANT_CHIEF_JUDGE;
    $statement->execute($params);
    foreach ($statement->fetchAll() as $contact) {
        $contactsByPrecinct[(int) $contact['precinct_id']][] = $contact;
    }
}

if (($_GET['format'] ?? '') === 'csv') {
    $filenameElection = preg_replace('/[^A-Za-z0-9]+/', '-', (string) ($selectedPeriod['name'] ?? 'election'));
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="precinct-contact-sheet-' . trim($filenameElection, '-') . '.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Election', 'Precinct', 'Role', 'Worker Name', 'Phone', 'Email', 'Precinct Location']);
    foreach ($visiblePrecincts as $precinct) {
        foreach ($contactsByPrecinct[(int) $precinct['id']] ?? [] as $contact) {
            $role = $contact['position_name'];
            if ((int) $contact['is_assistant_chief'] === 1) {
                $role .= ' / Assistant Chief';
            }
            if ((int) $contact['is_extra'] === 1) {
                $role .= ' / Extra';
            }

            fputcsv($output, [
                $selectedPeriod['name'] ?? '',
                $precinct['name'],
                $role,
                election_person_name($contact),
                $contact['phone'] ?? '',
                $contact['email'] ?? '',
                election_precinct_location($precinct),
            ]);
        }
    }
    fclose($output);
    exit;
}

$sheetQuery = [
    'election_period_id' => $selectedPeriodId,
    'precinct_id' => $selectedPrecinctId > 0 ? $selectedPrecinctId : '',
];
$exportUrl = url('departments/election/precinct-contact-sheet.php?' . http_build_query(array_merge($sheetQuery, ['format' => 'csv'])));

page_header('Precinct Contact Sheet');
?>
<main class="shell">
    <section class="panel">
        <h1>Precinct Contact Sheet</h1>
        <p>Print or export election-day contact lists by precinct.</p>
        <?php election_navigation('precinct-contact-sheet'); ?>
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

    <section class="panel printable-roster contact-sheet-print" style="margin-top: 18px;">
        <div class="roster-header">
            <div>
                <h1><?= e($selectedPeriod['name'] ?? 'Election Contacts') ?></h1>
                <p><?= $selectedPrecinctId > 0 ? 'Selected precinct' : 'All precincts' ?> - generated <?= e(format_display_date(date('Y-m-d'))) ?></p>
            </div>
            <div>
                <p><strong>Precinct Contact Sheet</strong></p>
                <p><?= e((string) count($visiblePrecincts)) ?> precinct<?= count($visiblePrecincts) === 1 ? '' : 's' ?></p>
            </div>
        </div>

        <?php foreach ($visiblePrecincts as $precinct): ?>
            <?php $contacts = $contactsByPrecinct[(int) $precinct['id']] ?? []; ?>
            <section class="contact-sheet-precinct">
                <div class="contact-sheet-heading">
                    <div>
                        <h2><?= e($precinct['name']) ?></h2>
                        <p><?= nl2br(e(election_precinct_location($precinct))) ?></p>
                    </div>
                    <div class="contact-sheet-count">
                        <strong><?= e((string) count($contacts)) ?></strong>
                        <span>assigned</span>
                    </div>
                </div>

                <table class="table roster-table contact-sheet-table">
                    <thead>
                        <tr>
                            <th>Role</th>
                            <th>Worker</th>
                            <th>Phone</th>
                            <th>Email</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($contacts as $contact): ?>
                            <?php
                            $roleParts = [$contact['position_name']];
                            if ((int) $contact['is_assistant_chief'] === 1) {
                                $roleParts[] = 'Assistant Chief';
                            }
                            if ((int) $contact['is_extra'] === 1) {
                                $roleParts[] = 'Extra';
                            }
                            ?>
                            <tr class="<?= (int) $contact['is_chief_judge'] === 1 || (int) $contact['is_assistant_chief'] === 1 ? 'contact-sheet-lead-row' : '' ?>">
                                <td><?= e(implode(' / ', $roleParts)) ?></td>
                                <td><?= e(election_person_name($contact)) ?></td>
                                <td><?= e($contact['phone'] ?: 'No phone') ?></td>
                                <td><?= e($contact['email'] ?: 'No email') ?></td>
                                <td></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$contacts): ?>
                            <tr><td colspan="5">No assigned workers for this precinct.</td></tr>
                        <?php endif; ?>
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
