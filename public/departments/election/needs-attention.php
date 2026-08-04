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
$allowedPrecinctIds = array_map(fn($precinct) => (int) $precinct['id'], $precincts);

$allPositions = election_positions();
$requiredPositions = [];
foreach ($allPositions as $position) {
    if ((int) $position['is_assistant_chief_judge'] === 1) {
        continue;
    }
    $requiredPositions[(int) $position['id']] = $position;
}

$precinctPlaceholders = [];
$precinctParams = [];
foreach ($allowedPrecinctIds as $index => $precinctId) {
    $placeholder = ':scope_precinct_' . $index;
    $precinctPlaceholders[] = $placeholder;
    $precinctParams[ltrim($placeholder, ':')] = $precinctId;
}
$precinctScopeSql = $precinctPlaceholders ? ' AND election_worker_assignments.precinct_id IN (' . implode(', ', $precinctPlaceholders) . ')' : ' AND 1 = 0';

$assignmentStats = [];
$assistantStats = [];
$staffingRows = [];
if ($selectedPeriodId > 0 && $allowedPrecinctIds) {
    $statement = db()->prepare(
        'SELECT election_worker_assignments.precinct_id,
                COUNT(DISTINCT CASE WHEN election_worker_assignments.is_extra = 0 THEN election_worker_assignments.position_id END) AS filled_position_count,
                SUM(CASE WHEN election_worker_assignments.is_extra = 1 THEN 1 ELSE 0 END) AS extra_worker_count,
                SUM(CASE WHEN election_positions.is_chief_judge = 1 AND election_worker_assignments.is_extra = 0 THEN 1 ELSE 0 END) AS chief_count,
                GROUP_CONCAT(DISTINCT CASE WHEN election_worker_assignments.is_extra = 0 THEN election_worker_assignments.position_id END SEPARATOR ",") AS filled_position_ids
         FROM election_worker_assignments
         INNER JOIN election_positions ON election_positions.id = election_worker_assignments.position_id
         WHERE election_worker_assignments.election_period_id = :election_period_id
           AND election_worker_assignments.is_active = 1' . $precinctScopeSql . '
         GROUP BY election_worker_assignments.precinct_id'
    );
    $statement->execute(array_merge(['election_period_id' => $selectedPeriodId], $precinctParams));
    foreach ($statement->fetchAll() as $row) {
        $assignmentStats[(int) $row['precinct_id']] = $row;
    }

    $statement = db()->prepare(
        'SELECT election_precinct_roles.precinct_id, COUNT(*) AS assistant_count
         FROM election_precinct_roles
         INNER JOIN election_worker_assignments ON election_worker_assignments.id = election_precinct_roles.assignment_id
         WHERE election_precinct_roles.election_period_id = :election_period_id
           AND election_precinct_roles.role_key = :role_key
           AND election_precinct_roles.assignment_id IS NOT NULL
           AND election_worker_assignments.is_active = 1' . $precinctScopeSql . '
         GROUP BY election_precinct_roles.precinct_id'
    );
    $statement->execute(array_merge([
        'election_period_id' => $selectedPeriodId,
        'role_key' => ELECTION_ROLE_ASSISTANT_CHIEF_JUDGE,
    ], $precinctParams));
    foreach ($statement->fetchAll() as $row) {
        $assistantStats[(int) $row['precinct_id']] = (int) $row['assistant_count'];
    }
}

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
    if ($missingPositions || $chiefCount !== 1 || $assistantCount === 0) {
        $staffingRows[] = [
            'precinct' => $precinct,
            'missing_positions' => $missingPositions,
            'chief_count' => $chiefCount,
            'assistant_count' => $assistantCount,
        ];
    }
}

$classCount = 0;
if ($selectedPeriodId > 0) {
    $statement = db()->prepare(
        'SELECT COUNT(*)
         FROM election_training_classes
         WHERE election_period_id = :election_period_id
           AND is_cancelled = 0'
    );
    $statement->execute(['election_period_id' => $selectedPeriodId]);
    $classCount = (int) $statement->fetchColumn();
}

$trainingRows = [];
if ($selectedPeriodId > 0 && $allowedPrecinctIds && $classCount > 0) {
    $statement = db()->prepare(
        'SELECT election_worker_assignments.id AS assignment_id,
                election_workers.id AS worker_id,
                election_workers.first_name,
                election_workers.last_name,
                election_workers.email,
                election_workers.phone,
                election_precincts.name AS precinct_name,
                election_positions.name AS position_name,
                COUNT(DISTINCT election_training_classes.id) AS registration_count,
                SUM(CASE WHEN election_training_classes.id IS NOT NULL AND election_training_registrations.attended = 1 THEN 1 ELSE 0 END) AS attended_count
         FROM election_worker_assignments
         INNER JOIN election_workers ON election_workers.id = election_worker_assignments.worker_id
         INNER JOIN election_precincts ON election_precincts.id = election_worker_assignments.precinct_id
         INNER JOIN election_positions ON election_positions.id = election_worker_assignments.position_id
         LEFT JOIN election_training_registrations ON election_training_registrations.assignment_id = election_worker_assignments.id
         LEFT JOIN election_training_classes ON election_training_classes.id = election_training_registrations.class_id
            AND election_training_classes.election_period_id = election_worker_assignments.election_period_id
            AND election_training_classes.is_cancelled = 0
         WHERE election_worker_assignments.election_period_id = :election_period_id
           AND election_worker_assignments.is_active = 1' . $precinctScopeSql . '
         GROUP BY election_worker_assignments.id
         HAVING registration_count = 0 OR attended_count = 0
         ORDER BY election_precincts.name, election_workers.last_name, election_workers.first_name'
    );
    $statement->execute(array_merge(['election_period_id' => $selectedPeriodId], $precinctParams));
    $trainingRows = $statement->fetchAll();
}

$contactRows = [];
if ($selectedPeriodId > 0 && $allowedPrecinctIds) {
    $statement = db()->prepare(
        'SELECT DISTINCT election_workers.id AS worker_id,
                election_workers.first_name,
                election_workers.last_name,
                election_workers.email,
                election_workers.phone,
                election_precincts.name AS precinct_name,
                GROUP_CONCAT(DISTINCT election_positions.name ORDER BY election_positions.sort_order SEPARATOR ", ") AS positions
         FROM election_worker_assignments
         INNER JOIN election_workers ON election_workers.id = election_worker_assignments.worker_id
         INNER JOIN election_precincts ON election_precincts.id = election_worker_assignments.precinct_id
         INNER JOIN election_positions ON election_positions.id = election_worker_assignments.position_id
         WHERE election_worker_assignments.election_period_id = :election_period_id
           AND election_worker_assignments.is_active = 1
           AND (COALESCE(election_workers.email, "") = "" OR COALESCE(election_workers.phone, "") = "")' . $precinctScopeSql . '
         GROUP BY election_workers.id, election_precincts.id
         ORDER BY election_precincts.name, election_workers.last_name, election_workers.first_name'
    );
    $statement->execute(array_merge(['election_period_id' => $selectedPeriodId], $precinctParams));
    $contactRows = $statement->fetchAll();
}

$statusRows = [];
if ($selectedPeriodId > 0 && $allowedPrecinctIds) {
    $statement = db()->prepare(
        'SELECT DISTINCT election_workers.id AS worker_id,
                election_workers.first_name,
                election_workers.last_name,
                election_workers.availability_status,
                election_workers.is_active,
                election_workers.unavailable_reason,
                election_precincts.name AS precinct_name,
                GROUP_CONCAT(DISTINCT election_positions.name ORDER BY election_positions.sort_order SEPARATOR ", ") AS positions
         FROM election_worker_assignments
         INNER JOIN election_workers ON election_workers.id = election_worker_assignments.worker_id
         INNER JOIN election_precincts ON election_precincts.id = election_worker_assignments.precinct_id
         INNER JOIN election_positions ON election_positions.id = election_worker_assignments.position_id
         WHERE election_worker_assignments.election_period_id = :election_period_id
           AND election_worker_assignments.is_active = 1
           AND (election_workers.availability_status <> :availability_status OR election_workers.is_active = 0)' . $precinctScopeSql . '
         GROUP BY election_workers.id, election_precincts.id
         ORDER BY election_precincts.name, election_workers.last_name, election_workers.first_name'
    );
    $statement->execute(array_merge([
        'election_period_id' => $selectedPeriodId,
        'availability_status' => ELECTION_WORKER_STATUS_ACTIVE,
    ], $precinctParams));
    $statusRows = $statement->fetchAll();
}

$extraRows = [];
if ($selectedPeriodId > 0 && $allowedPrecinctIds) {
    $statement = db()->prepare(
        'SELECT election_worker_assignments.id AS assignment_id,
                election_workers.id AS worker_id,
                election_workers.first_name,
                election_workers.last_name,
                election_precincts.name AS precinct_name,
                election_positions.name AS position_name
         FROM election_worker_assignments
         INNER JOIN election_workers ON election_workers.id = election_worker_assignments.worker_id
         INNER JOIN election_precincts ON election_precincts.id = election_worker_assignments.precinct_id
         INNER JOIN election_positions ON election_positions.id = election_worker_assignments.position_id
         WHERE election_worker_assignments.election_period_id = :election_period_id
           AND election_worker_assignments.is_active = 1
           AND election_worker_assignments.is_extra = 1' . $precinctScopeSql . '
         ORDER BY election_precincts.name, election_positions.sort_order, election_workers.last_name, election_workers.first_name'
    );
    $statement->execute(array_merge(['election_period_id' => $selectedPeriodId], $precinctParams));
    $extraRows = $statement->fetchAll();
}

$duplicateRows = [];
if ($isManager) {
    $duplicateSql = 'SELECT duplicate_key,
                           GROUP_CONCAT(CONCAT(id, ": ", first_name, " ", last_name) ORDER BY last_name, first_name, id SEPARATOR "\n") AS contacts,
                           COUNT(*) AS contact_count
                    FROM (
                        SELECT CONCAT("Email: ", email_normalized) AS duplicate_key, id, first_name, last_name
                        FROM election_workers
                        WHERE availability_status = :active_email_status
                          AND is_active = 1
                          AND COALESCE(email_normalized, "") <> ""
                        UNION ALL
                        SELECT CONCAT("Phone: ", phone_digits) AS duplicate_key, id, first_name, last_name
                        FROM election_workers
                        WHERE availability_status = :active_phone_status
                          AND is_active = 1
                          AND COALESCE(phone_digits, "") <> ""
                        UNION ALL
                        SELECT CONCAT("Name: ", name_key) AS duplicate_key, id, first_name, last_name
                        FROM election_workers
                        WHERE availability_status = :active_name_status
                          AND is_active = 1
                          AND COALESCE(name_key, "") <> ""
                    ) duplicate_contacts
                    GROUP BY duplicate_key
                    HAVING contact_count > 1
                    ORDER BY contact_count DESC, duplicate_key
                    LIMIT 25';
    $statement = db()->prepare($duplicateSql);
    $statement->execute([
        'active_email_status' => ELECTION_WORKER_STATUS_ACTIVE,
        'active_phone_status' => ELECTION_WORKER_STATUS_ACTIVE,
        'active_name_status' => ELECTION_WORKER_STATUS_ACTIVE,
    ]);
    $duplicateRows = $statement->fetchAll();
}

$closeElectionItem = null;
if ($isManager && $selectedPeriod) {
    $periodEndDate = trim((string) ($selectedPeriod['ends_on'] ?? ''));
    if ((int) $selectedPeriod['is_active'] === 1 && $periodEndDate !== '' && $periodEndDate < date('Y-m-d')) {
        $statement = db()->prepare(
            'SELECT COUNT(*)
             FROM election_worker_assignments
             WHERE election_period_id = :election_period_id
               AND is_active = 1'
        );
        $statement->execute(['election_period_id' => $selectedPeriodId]);
        $closeElectionItem = [
            'period' => $selectedPeriod,
            'active_assignment_count' => (int) $statement->fetchColumn(),
        ];
    }
}

$totalAttentionCount = count($staffingRows)
    + count($trainingRows)
    + count($contactRows)
    + count($statusRows)
    + count($extraRows)
    + count($duplicateRows)
    + ($closeElectionItem ? 1 : 0);

$problemCounts = [
    'staffing' => count($staffingRows),
    'training' => count($trainingRows),
    'contact' => count($contactRows),
    'status' => count($statusRows),
    'extra' => count($extraRows),
];
if ($isManager) {
    $problemCounts['duplicates'] = count($duplicateRows);
    $problemCounts['close'] = $closeElectionItem ? 1 : 0;
}

$problemFilter = $_GET['problem'] ?? 'all';
if ($problemFilter !== 'all' && $problemFilter !== 'review' && !array_key_exists($problemFilter, $problemCounts)) {
    $problemFilter = 'all';
}

$reviewFilterKeys = ['extra', 'duplicates', 'close'];
$problemFilterUrl = fn(string $filter): string => url(
    'departments/election/needs-attention.php?election_period_id=' . $selectedPeriodId . '&problem=' . urlencode($filter)
);
$showProblemSection = fn(string $filter): bool => $problemFilter === 'all'
    || $problemFilter === $filter
    || ($problemFilter === 'review' && in_array($filter, $reviewFilterKeys, true));
$openProblemSection = fn(string $filter, int $count): bool => $problemFilter === $filter
    || ($problemFilter === 'review' && in_array($filter, $reviewFilterKeys, true) && $count > 0)
    || ($problemFilter === 'all' && $count > 0);
$isReviewFilterActive = $problemFilter === 'review' || in_array($problemFilter, $reviewFilterKeys, true);

$actions = [
    ['label' => 'Precinct Staffing', 'href' => url('departments/election/staffing.php?election_period_id=' . $selectedPeriodId), 'primary' => true],
    ['label' => 'Staffing Progress', 'href' => url('departments/election/staffing-progress.php?election_period_id=' . $selectedPeriodId)],
    ['label' => 'Staffing Sheet', 'href' => url('departments/election/staffing-sheet.php?election_period_id=' . $selectedPeriodId)],
    ['label' => 'Worker list', 'href' => url('departments/election/workers.php')],
    ['label' => 'Election Home', 'href' => url('departments/election/index.php')],
];
if ($isManager) {
    $actions[] = ['label' => 'Bulk Email', 'href' => url('departments/election/bulk-email.php?election_period_id=' . $selectedPeriodId)];
    $actions[] = ['label' => 'Merge contacts', 'href' => url('departments/election/merge-workers.php')];
}

page_header('Needs Attention');
?>
<main class="shell">
    <section class="panel">
        <h1>Needs Attention</h1>
        <p>Review the open staffing, training, and contact items that may need supervisor follow-up.</p>
        <?php election_navigation('needs-attention'); ?>

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
                <button type="submit">View dashboard</button>
            </div>
        </form>
    </section>

    <section class="dashboard-stat-row attention-stat-row" style="margin-top: 18px;">
        <div class="dashboard-stat-group summary-stat-group">
            <h2>Attention Summary</h2>
            <div class="grid dashboard-stat-grid attention-stat-grid">
                <a class="card dashboard-stat-card dashboard-filter-card <?= $problemFilter === 'all' ? 'active' : '' ?>" href="<?= e($problemFilterUrl('all')) ?>">
                    <h3><?= e((string) $totalAttentionCount) ?></h3>
                    <p>Total items</p>
                </a>
                <a class="card dashboard-stat-card dashboard-filter-card <?= $problemFilter === 'staffing' ? 'active' : '' ?>" href="<?= e($problemFilterUrl('staffing')) ?>">
                    <h3><?= e((string) count($staffingRows)) ?></h3>
                    <p>Precinct staffing gaps</p>
                </a>
                <a class="card dashboard-stat-card dashboard-filter-card <?= $problemFilter === 'training' ? 'active' : '' ?>" href="<?= e($problemFilterUrl('training')) ?>">
                    <h3><?= e((string) count($trainingRows)) ?></h3>
                    <p>Training follow-ups</p>
                </a>
                <a class="card dashboard-stat-card dashboard-filter-card <?= $problemFilter === 'contact' ? 'active' : '' ?>" href="<?= e($problemFilterUrl('contact')) ?>">
                    <h3><?= e((string) count($contactRows)) ?></h3>
                    <p>Missing contact info</p>
                </a>
                <a class="card dashboard-stat-card dashboard-filter-card <?= $problemFilter === 'status' ? 'active' : '' ?>" href="<?= e($problemFilterUrl('status')) ?>">
                    <h3><?= e((string) count($statusRows)) ?></h3>
                    <p>Status conflicts</p>
                </a>
                <a class="card dashboard-stat-card dashboard-filter-card <?= $isReviewFilterActive ? 'active' : '' ?>" href="<?= e($problemFilterUrl('review')) ?>">
                    <h3><?= e((string) (count($extraRows) + count($duplicateRows) + ($closeElectionItem ? 1 : 0))) ?></h3>
                    <p>Review items</p>
                </a>
            </div>
        </div>
    </section>

    <?php if ($showProblemSection('staffing')): ?>
    <details class="panel setup-section attention-section" style="margin-top: 18px;" <?= $openProblemSection('staffing', count($staffingRows)) ? 'open' : '' ?>>
        <summary class="section-heading-row">
            <h1>Precinct Staffing Gaps</h1>
            <span class="badge <?= $staffingRows ? 'badge-warning' : 'badge-success' ?>"><?= e((string) count($staffingRows)) ?></span>
            <span class="button secondary compact-button">View section</span>
        </summary>
        <table class="table mobile-card-table">
            <thead>
                <tr>
                    <th>Precinct</th>
                    <th>Missing Items</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($staffingRows as $row): ?>
                    <?php
                    $items = $row['missing_positions'];
                    if ($row['chief_count'] === 0) {
                        $items[] = 'Chief Judge';
                    } elseif ($row['chief_count'] > 1) {
                        $items[] = 'More than one Chief Judge';
                    }
                    if ($row['assistant_count'] === 0) {
                        $items[] = 'Assistant Chief';
                    }
                    ?>
                    <tr>
                        <td data-label="Precinct"><?= e($row['precinct']['name']) ?></td>
                        <td data-label="Missing Items"><?= e(implode(', ', array_unique($items))) ?></td>
                        <td data-label="Action">
                            <a class="button secondary compact-button" href="<?= e(url('departments/election/staffing.php?election_period_id=' . $selectedPeriodId . '&precinct_id=' . (int) $row['precinct']['id'])) ?>">Open staffing</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$staffingRows): ?>
                    <tr><td colspan="3">No precinct staffing gaps found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </details>
    <?php endif; ?>

    <?php if ($showProblemSection('training')): ?>
    <details class="panel setup-section attention-section" style="margin-top: 18px;" <?= $openProblemSection('training', count($trainingRows)) ? 'open' : '' ?>>
        <summary class="section-heading-row">
            <h1>Training Follow-Up</h1>
            <span class="badge <?= $trainingRows ? 'badge-warning' : 'badge-success' ?>"><?= e((string) count($trainingRows)) ?></span>
            <span class="button secondary compact-button">View section</span>
        </summary>
        <?php if ($classCount === 0): ?>
            <p>No active training classes exist for this election yet.</p>
        <?php else: ?>
            <table class="table mobile-card-table">
                <thead>
                    <tr>
                        <th>Worker</th>
                        <th>Precinct</th>
                        <th>Position</th>
                        <th>Training</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($trainingRows as $row): ?>
                        <?php $trainingLabel = (int) $row['registration_count'] === 0 ? 'Not signed up' : 'Signed up, not complete'; ?>
                        <tr>
                            <td data-label="Worker"><?= e(election_person_name($row)) ?></td>
                            <td data-label="Precinct"><?= e($row['precinct_name']) ?></td>
                            <td data-label="Position"><?= e($row['position_name']) ?></td>
                            <td data-label="Training"><span class="badge badge-warning"><?= e($trainingLabel) ?></span></td>
                            <td data-label="Action">
                                <a class="button secondary compact-button" href="<?= e(url('departments/election/worker-edit.php?id=' . (int) $row['worker_id'])) ?>">Open worker</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$trainingRows): ?>
                        <tr><td colspan="5">No training follow-up found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </details>
    <?php endif; ?>

    <?php if ($showProblemSection('contact')): ?>
    <details class="panel setup-section attention-section" style="margin-top: 18px;" <?= $openProblemSection('contact', count($contactRows)) ? 'open' : '' ?>>
        <summary class="section-heading-row">
            <h1>Missing Contact Info</h1>
            <span class="badge <?= $contactRows ? 'badge-warning' : 'badge-success' ?>"><?= e((string) count($contactRows)) ?></span>
            <span class="button secondary compact-button">View section</span>
        </summary>
        <table class="table mobile-card-table">
            <thead>
                <tr>
                    <th>Worker</th>
                    <th>Precinct</th>
                    <th>Position</th>
                    <th>Missing</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($contactRows as $row): ?>
                    <?php
                    $missing = [];
                    if (trim((string) ($row['email'] ?? '')) === '') {
                        $missing[] = 'Email';
                    }
                    if (trim((string) ($row['phone'] ?? '')) === '') {
                        $missing[] = 'Phone';
                    }
                    ?>
                    <tr>
                        <td data-label="Worker"><?= e(election_person_name($row)) ?></td>
                        <td data-label="Precinct"><?= e($row['precinct_name']) ?></td>
                        <td data-label="Position"><?= e($row['positions']) ?></td>
                        <td data-label="Missing"><?= e(implode(', ', $missing)) ?></td>
                        <td data-label="Action">
                            <a class="button secondary compact-button" href="<?= e(url('departments/election/worker-edit.php?id=' . (int) $row['worker_id'])) ?>">Open worker</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$contactRows): ?>
                    <tr><td colspan="5">No missing contact information found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </details>
    <?php endif; ?>

    <?php if ($showProblemSection('status')): ?>
    <details class="panel setup-section attention-section" style="margin-top: 18px;" <?= $openProblemSection('status', count($statusRows)) ? 'open' : '' ?>>
        <summary class="section-heading-row">
            <h1>Status Conflicts</h1>
            <span class="badge <?= $statusRows ? 'badge-warning' : 'badge-success' ?>"><?= e((string) count($statusRows)) ?></span>
            <span class="button secondary compact-button">View section</span>
        </summary>
        <table class="table mobile-card-table">
            <thead>
                <tr>
                    <th>Worker</th>
                    <th>Precinct</th>
                    <th>Position</th>
                    <th>Worker Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($statusRows as $row): ?>
                    <tr>
                        <td data-label="Worker"><?= e(election_person_name($row)) ?></td>
                        <td data-label="Precinct"><?= e($row['precinct_name']) ?></td>
                        <td data-label="Position"><?= e($row['positions']) ?></td>
                        <td data-label="Worker Status">
                            <span class="badge <?= e(election_worker_status_badge_class($row)) ?>"><?= e(election_worker_status_label($row)) ?></span>
                            <?php if (trim((string) ($row['unavailable_reason'] ?? '')) !== ''): ?>
                                <br><span class="meta"><?= e($row['unavailable_reason']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Action">
                            <a class="button secondary compact-button" href="<?= e(url('departments/election/worker-edit.php?id=' . (int) $row['worker_id'])) ?>">Open worker</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$statusRows): ?>
                    <tr><td colspan="5">No assigned workers have unavailable or inactive contact status.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </details>
    <?php endif; ?>

    <?php if ($showProblemSection('extra')): ?>
    <details class="panel setup-section attention-section" style="margin-top: 18px;" <?= $openProblemSection('extra', count($extraRows)) ? 'open' : '' ?>>
        <summary class="section-heading-row">
            <h1>Extra Workers</h1>
            <span class="badge badge-muted"><?= e((string) count($extraRows)) ?></span>
            <span class="button secondary compact-button">View section</span>
        </summary>
        <table class="table mobile-card-table">
            <thead>
                <tr>
                    <th>Worker</th>
                    <th>Precinct</th>
                    <th>Position</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($extraRows as $row): ?>
                    <tr>
                        <td data-label="Worker"><?= e(election_person_name($row)) ?></td>
                        <td data-label="Precinct"><?= e($row['precinct_name']) ?></td>
                        <td data-label="Position"><?= e($row['position_name']) ?></td>
                        <td data-label="Action">
                            <a class="button secondary compact-button" href="<?= e(url('departments/election/staffing.php?election_period_id=' . $selectedPeriodId)) ?>">Review staffing</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$extraRows): ?>
                    <tr><td colspan="4">No extra workers assigned.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </details>
    <?php endif; ?>

    <?php if ($showProblemSection('close')): ?>
        <details class="panel setup-section attention-section" style="margin-top: 18px;" <?= $openProblemSection('close', $problemCounts['close'] ?? 0) ? 'open' : '' ?>>
            <summary class="section-heading-row">
                <h1>Election Period Ready to Close</h1>
                <span class="badge <?= $closeElectionItem ? 'badge-warning' : 'badge-success' ?>"><?= e((string) ($problemCounts['close'] ?? 0)) ?></span>
                <span class="button secondary compact-button">View section</span>
            </summary>
            <?php if ($closeElectionItem): ?>
                <table class="table mobile-card-table">
                    <thead>
                        <tr>
                            <th>Election</th>
                            <th>Ended</th>
                            <th>Active Assignments</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td data-label="Election"><?= e($closeElectionItem['period']['name']) ?></td>
                            <td data-label="Ended"><?= e(format_display_date($closeElectionItem['period']['ends_on'])) ?></td>
                            <td data-label="Active Assignments"><?= e((string) $closeElectionItem['active_assignment_count']) ?></td>
                            <td data-label="Action">
                                <a class="button secondary compact-button" href="<?= e(url('departments/election/close-period.php?id=' . (int) $closeElectionItem['period']['id'])) ?>">Review close election</a>
                            </td>
                        </tr>
                    </tbody>
                </table>
            <?php else: ?>
                <p>This election period does not need to be closed yet.</p>
            <?php endif; ?>
        </details>
    <?php endif; ?>

    <?php if ($isManager && $showProblemSection('duplicates')): ?>
        <details class="panel setup-section attention-section" style="margin-top: 18px;" <?= $openProblemSection('duplicates', count($duplicateRows)) ? 'open' : '' ?>>
            <summary class="section-heading-row">
                <h1>Possible Duplicate Contacts</h1>
                <span class="badge <?= $duplicateRows ? 'badge-warning' : 'badge-success' ?>"><?= e((string) count($duplicateRows)) ?></span>
                <span class="button secondary compact-button">View section</span>
            </summary>
            <table class="table mobile-card-table">
                <thead>
                    <tr>
                        <th>Matched On</th>
                        <th>Contacts</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($duplicateRows as $row): ?>
                        <tr>
                            <td data-label="Matched On"><?= e($row['duplicate_key']) ?></td>
                            <td data-label="Contacts">
                                <?php foreach (explode("\n", (string) $row['contacts']) as $contact): ?>
                                    <?= e($contact) ?><br>
                                <?php endforeach; ?>
                            </td>
                            <td data-label="Action">
                                <a class="button secondary compact-button" href="<?= e(url('departments/election/merge-workers.php')) ?>">Review merge</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$duplicateRows): ?>
                        <tr><td colspan="3">No duplicate-looking active contacts found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </details>
    <?php endif; ?>
</main>
<?php page_footer(); ?>
