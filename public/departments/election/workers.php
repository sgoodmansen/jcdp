<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_election_worker_manager();
election_require_assignment_setup();

$query = trim($_GET['q'] ?? '');
$periodId = (int) ($_GET['election_period_id'] ?? 0);
$positionId = (int) ($_GET['position_id'] ?? 0);
$statusFilter = $_GET['status'] ?? ELECTION_WORKER_STATUS_ACTIVE;
if (!in_array($statusFilter, [ELECTION_WORKER_STATUS_ACTIVE, ELECTION_WORKER_STATUS_UNAVAILABLE, ELECTION_WORKER_STATUS_INACTIVE, 'all'], true)) {
    $statusFilter = 'active';
}
$sortBy = $_GET['sort'] ?? 'last';
if (!in_array($sortBy, ['last', 'first'], true)) {
    $sortBy = 'last';
}

$isModuleManager = can_manage_election_module();
if (!$isModuleManager && $statusFilter !== ELECTION_WORKER_STATUS_ACTIVE) {
    $statusFilter = ELECTION_WORKER_STATUS_ACTIVE;
}

$periods = db()->query('SELECT * FROM election_periods ORDER BY is_active DESC, starts_on DESC, name')->fetchAll();
$positions = election_positions(false);
$assistantPositionIds = [];
$assistantPositionNames = [];
foreach ($positions as $position) {
    if ((int) $position['is_assistant_chief_judge'] === 1) {
        $assistantPositionIds[] = (int) $position['id'];
        $assistantPositionNames[] = $position['name'];
    }
}
$sql = 'SELECT election_workers.*,
               COUNT(DISTINCT active_assignments.id) AS active_assignment_count,
               GROUP_CONCAT(DISTINCT active_assignments.position_id ORDER BY election_positions.sort_order SEPARATOR ",") AS position_history_ids,
               GROUP_CONCAT(DISTINCT election_positions.name ORDER BY election_positions.sort_order SEPARATOR "\n") AS position_history_names,
               GROUP_CONCAT(DISTINCT election_precinct_roles.role_key SEPARATOR ",") AS extra_role_history_keys
        FROM election_workers
        LEFT JOIN election_worker_assignments active_assignments ON active_assignments.worker_id = election_workers.id
        LEFT JOIN election_periods ON election_periods.id = active_assignments.election_period_id
        LEFT JOIN election_positions ON election_positions.id = active_assignments.position_id
        LEFT JOIN election_precinct_roles ON election_precinct_roles.assignment_id = active_assignments.id
        WHERE 1 = 1';
$params = [];

if ($query !== '') {
    $sql .= ' AND (election_workers.first_name LIKE :query
              OR election_workers.last_name LIKE :query
              OR election_workers.email LIKE :query
              OR election_workers.phone LIKE :query
              OR election_workers.mailing_address LIKE :query
              OR election_workers.city LIKE :query)';
    $params['query'] = '%' . $query . '%';
}

if ($periodId > 0) {
    $sql .= ' AND EXISTS (
        SELECT 1
        FROM election_worker_assignments period_assignments
        WHERE period_assignments.worker_id = election_workers.id
          AND period_assignments.election_period_id = :election_period_id
    )';
    $params['election_period_id'] = $periodId;
}

if ($positionId > 0) {
    if (in_array($positionId, $assistantPositionIds, true)) {
        $sql .= ' AND (
            EXISTS (
                SELECT 1
                FROM election_worker_assignments position_assignments
                WHERE position_assignments.worker_id = election_workers.id
                  AND position_assignments.position_id = :position_id
            )
            OR EXISTS (
                SELECT 1
                FROM election_precinct_roles role_assignments
                INNER JOIN election_worker_assignments role_worker_assignments ON role_worker_assignments.id = role_assignments.assignment_id
                WHERE role_worker_assignments.worker_id = election_workers.id
                  AND role_assignments.role_key = :assistant_role_key
            )
        )';
        $params['assistant_role_key'] = ELECTION_ROLE_ASSISTANT_CHIEF_JUDGE;
    } else {
        $sql .= ' AND EXISTS (
            SELECT 1
            FROM election_worker_assignments position_assignments
            WHERE position_assignments.worker_id = election_workers.id
              AND position_assignments.position_id = :position_id
        )';
    }
    $params['position_id'] = $positionId;
}

if ($statusFilter === ELECTION_WORKER_STATUS_ACTIVE) {
    $sql .= ' AND election_workers.availability_status = :availability_status
              AND election_workers.is_active = 1';
    $params['availability_status'] = ELECTION_WORKER_STATUS_ACTIVE;
} elseif ($statusFilter === ELECTION_WORKER_STATUS_UNAVAILABLE || $statusFilter === ELECTION_WORKER_STATUS_INACTIVE) {
    $sql .= ' AND election_workers.availability_status = :availability_status';
    $params['availability_status'] = $statusFilter;
}

$orderSql = $sortBy === 'first'
    ? 'election_workers.first_name, election_workers.last_name'
    : 'election_workers.last_name, election_workers.first_name';
$sql .= ' GROUP BY election_workers.id
          ORDER BY ' . $orderSql . '
          LIMIT 500';
$statement = db()->prepare($sql);
$statement->execute($params);
$workers = $statement->fetchAll();

$sortQuery = [
    'q' => $query,
    'election_period_id' => $periodId > 0 ? (string) $periodId : '',
    'position_id' => $positionId > 0 ? (string) $positionId : '',
    'status' => $statusFilter,
];
$sortByLastUrl = url('departments/election/workers.php?' . http_build_query(array_merge($sortQuery, ['sort' => 'last'])));
$sortByFirstUrl = url('departments/election/workers.php?' . http_build_query(array_merge($sortQuery, ['sort' => 'first'])));

$actions = [
    ['label' => 'Precinct Staffing', 'href' => url('departments/election/staffing.php'), 'primary' => true],
    ['label' => 'Needs Attention', 'href' => url('departments/election/needs-attention.php')],
    ['label' => 'Staffing Progress', 'href' => url('departments/election/staffing-progress.php')],
    ['label' => 'Staffing Sheet', 'href' => url('departments/election/staffing-sheet.php')],
    ['label' => 'Add worker', 'href' => url('departments/election/worker-edit.php')],
    ['label' => 'Reuse past workers', 'href' => url('departments/election/reuse-workers.php')],
    ['label' => 'Election Home', 'href' => url('departments/election/index.php')],
    ['label' => 'Training classes', 'href' => url('departments/election/classes.php')],
];
if ($isModuleManager) {
    array_splice($actions, 4, 0, [
        ['label' => 'Import CSV', 'href' => url('departments/election/import-workers.php')],
        ['label' => 'Merge contacts', 'href' => url('departments/election/merge-workers.php')],
        ['label' => 'Bulk Email', 'href' => url('departments/election/bulk-email.php')],
    ]);
}

page_header('Election Workers');
?>
<main class="shell">
    <section class="panel">
        <h1>Election Workers</h1>
        <p>Search the worker address book and manage contact records.</p>
        <?php election_navigation('workers'); ?>

        <?php if ($message = flash('success')): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Filters</h1>
        <form class="form compact-form" method="get">
            <label>
                Worker search
                <input name="q" value="<?= e($query) ?>" placeholder="Name, email, phone, city, or address">
            </label>
            <label>
                Election assignment
                <select name="election_period_id">
                    <option value="">Any election</option>
                    <?php foreach ($periods as $period): ?>
                        <option value="<?= e((string) $period['id']) ?>" <?= $periodId === (int) $period['id'] ? 'selected' : '' ?>>
                            <?= e($period['name']) ?><?= (int) $period['is_active'] === 1 ? ' (open)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Position assignment
                <select name="position_id">
                    <option value="">Any position</option>
                    <?php foreach ($positions as $position): ?>
                        <option value="<?= e((string) $position['id']) ?>" <?= $positionId === (int) $position['id'] ? 'selected' : '' ?>><?= e($position['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Status
                <select name="status">
                    <option value="active" <?= $statusFilter === ELECTION_WORKER_STATUS_ACTIVE ? 'selected' : '' ?>>Active contacts</option>
                    <?php if ($isModuleManager): ?>
                        <option value="unavailable" <?= $statusFilter === ELECTION_WORKER_STATUS_UNAVAILABLE ? 'selected' : '' ?>>Unavailable contacts</option>
                        <option value="inactive" <?= $statusFilter === ELECTION_WORKER_STATUS_INACTIVE ? 'selected' : '' ?>>Inactive contacts</option>
                        <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All contacts</option>
                    <?php endif; ?>
                </select>
            </label>
            <div class="actions">
                <button type="submit">Filter</button>
                <a class="button secondary" href="<?= e(url('departments/election/workers.php')) ?>">Clear</a>
            </div>
        </form>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <div class="section-heading-row">
            <div>
                <h1>Address Book</h1>
                <p class="muted">Sorted by <?= $sortBy === 'first' ? 'first name' : 'last name' ?>.</p>
            </div>
            <div class="address-book-tools">
                <div class="segmented-actions" aria-label="Sort address book">
                    <a class="button secondary compact-button <?= $sortBy === 'last' ? 'active' : '' ?>" href="<?= e($sortByLastUrl) ?>">Last name</a>
                    <a class="button secondary compact-button <?= $sortBy === 'first' ? 'active' : '' ?>" href="<?= e($sortByFirstUrl) ?>">First name</a>
                </div>
                <span class="badge badge-muted"><?= e((string) count($workers)) ?> shown</span>
            </div>
        </div>
        <table class="table mobile-card-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Contact</th>
                    <th>Position history</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($workers as $worker): ?>
                    <tr>
                        <td data-label="Name"><?= e(election_person_name($worker)) ?></td>
                        <td data-label="Contact">
                            <?= e($worker['email'] ?: 'No email') ?><br>
                            <span class="meta"><?= e($worker['phone'] ?: 'No phone') ?></span>
                        </td>
                        <td data-label="Position history">
                            <?php
                            $historyPositionNames = array_values(array_filter(explode("\n", (string) ($worker['position_history_names'] ?? ''))));
                            $extraRoleKeys = array_filter(explode(',', (string) ($worker['extra_role_history_keys'] ?? '')));
                            if (in_array(ELECTION_ROLE_ASSISTANT_CHIEF_JUDGE, $extraRoleKeys, true)) {
                                $historyPositionNames = array_values(array_unique(array_merge($historyPositionNames, $assistantPositionNames)));
                            }
                            ?>
                            <?php if ($historyPositionNames): ?>
                                <span class="position-history-list"><?= e(implode(', ', $historyPositionNames)) ?></span>
                            <?php else: ?>
                                <span class="meta">No position history</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Status">
                            <span class="badge <?= e(election_worker_status_badge_class($worker)) ?>">
                                <?= e(election_worker_status_label($worker)) ?>
                            </span>
                            <?php if (election_worker_status($worker) === ELECTION_WORKER_STATUS_UNAVAILABLE && trim((string) ($worker['unavailable_reason'] ?? '')) !== ''): ?>
                                <br><span class="meta"><?= e($worker['unavailable_reason']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Actions">
                            <div class="table-actions">
                                <a class="icon-link" href="<?= e(url('departments/election/worker-edit.php?id=' . $worker['id'])) ?>" title="Edit worker" aria-label="Edit worker">&#9998;</a>
                                <a class="button secondary compact-button" href="<?= e(url('departments/election/worker-edit.php?id=' . $worker['id'] . '&new_assignment=1')) ?>">Add assignment</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$workers): ?>
                    <tr><td colspan="5">No workers matched the selected filters.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
</main>
<?php page_footer(); ?>
