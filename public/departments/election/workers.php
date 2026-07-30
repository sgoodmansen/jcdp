<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_election_worker_manager();

$query = trim($_GET['q'] ?? '');
$positionId = (int) ($_GET['position_id'] ?? 0);
[$scopeSql, $scopeParams] = election_worker_scope_sql('election_workers');

$sql = 'SELECT election_workers.*,
               election_positions.name AS position_name,
               election_precincts.name AS precinct_name,
               election_periods.name AS election_name
        FROM election_workers
        INNER JOIN election_positions ON election_positions.id = election_workers.position_id
        INNER JOIN election_precincts ON election_precincts.id = election_workers.precinct_id
        INNER JOIN election_periods ON election_periods.id = election_workers.election_period_id
        WHERE 1 = 1' . $scopeSql;
$params = $scopeParams;

if ($query !== '') {
    $sql .= ' AND (election_workers.first_name LIKE :query
              OR election_workers.last_name LIKE :query
              OR election_workers.email LIKE :query
              OR election_workers.phone LIKE :query)';
    $params['query'] = '%' . $query . '%';
}

if ($positionId > 0) {
    $sql .= ' AND election_workers.position_id = :position_id';
    $params['position_id'] = $positionId;
}

$sql .= ' ORDER BY election_periods.starts_on DESC, election_precincts.name, election_positions.sort_order, election_workers.last_name, election_workers.first_name';
$statement = db()->prepare($sql);
$statement->execute($params);
$workers = $statement->fetchAll();
$positions = election_positions();

$actions = [
    ['label' => 'Add worker', 'href' => url('departments/election/worker-edit.php'), 'primary' => true],
    ['label' => 'Election Home', 'href' => url('departments/election/index.php')],
    ['label' => 'Training classes', 'href' => url('departments/election/classes.php')],
];

page_header('Election Workers');
?>
<main class="shell">
    <section class="panel">
        <h1>Election Workers</h1>
        <p>Search current and past workers, add recruits, and manage access links.</p>
        <?php page_actions($actions); ?>

        <?php if ($message = flash('success')): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Search</h1>
        <form class="form compact-form" method="get">
            <label>
                Worker search
                <input name="q" value="<?= e($query) ?>" placeholder="Name, email, or phone">
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
                <button type="submit">Search</button>
                <a class="button secondary" href="<?= e(url('departments/election/workers.php')) ?>">Clear</a>
            </div>
        </form>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Workers</h1>
        <table class="table mobile-card-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Election</th>
                    <th>Precinct</th>
                    <th>Position</th>
                    <th>Contact</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($workers as $worker): ?>
                    <tr>
                        <td data-label="Name"><?= e(election_person_name($worker)) ?></td>
                        <td data-label="Election"><?= e($worker['election_name']) ?></td>
                        <td data-label="Precinct"><?= e($worker['precinct_name']) ?></td>
                        <td data-label="Position"><?= e($worker['position_name']) ?></td>
                        <td data-label="Contact">
                            <?= e($worker['email'] ?: 'No email') ?><br>
                            <span class="meta"><?= e($worker['phone'] ?: 'No phone') ?></span>
                        </td>
                        <td data-label="Status">
                            <span class="badge <?= (int) $worker['is_active'] === 1 ? 'badge-success' : 'badge-muted' ?>">
                                <?= (int) $worker['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td data-label="Actions">
                            <div class="table-actions">
                                <a class="icon-link" href="<?= e(url('departments/election/worker-edit.php?id=' . $worker['id'])) ?>" title="Edit worker" aria-label="Edit worker">&#9998;</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$workers): ?>
                    <tr><td colspan="7">No workers matched the search.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
</main>
<?php page_footer(); ?>
