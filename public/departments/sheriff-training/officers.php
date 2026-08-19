<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_sheriff_training_manager();

$query = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? 'active';
if (!in_array($status, ['active', 'inactive', 'all'], true)) {
    $status = 'active';
}

$sql = 'SELECT sheriff_training_officers.*,
               COALESCE(training_summary.request_count, 0) AS request_count,
               COALESCE(training_summary.completed_count, 0) AS completed_count
        FROM sheriff_training_officers
        LEFT JOIN (
            SELECT officer_id,
                   COUNT(*) AS request_count,
                   SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) AS completed_count
            FROM sheriff_training_requests
            GROUP BY officer_id
        ) training_summary ON training_summary.officer_id = sheriff_training_officers.id
        WHERE 1 = 1';
$params = [];

if ($query !== '') {
    $sql .= ' AND (sheriff_training_officers.first_name LIKE :query
              OR sheriff_training_officers.last_name LIKE :query
              OR sheriff_training_officers.rank_title LIKE :query
              OR sheriff_training_officers.division LIKE :query)';
    $params['query'] = '%' . $query . '%';
}

if ($status === 'active') {
    $sql .= ' AND sheriff_training_officers.is_active = 1';
} elseif ($status === 'inactive') {
    $sql .= ' AND sheriff_training_officers.is_active = 0';
}

$sql .= ' ORDER BY sheriff_training_officers.last_name, sheriff_training_officers.first_name';
$statement = db()->prepare($sql);
$statement->execute($params);
$officers = $statement->fetchAll();

page_header('Sheriff Training Officers');
?>
<main class="shell">
    <section class="panel">
        <h1>Officers</h1>
        <p>Maintain the Sheriff Training officer list used for request history and email updates.</p>
        <?php sheriff_training_navigation('officers'); ?>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <div class="section-heading-row">
            <h1>Officer List</h1>
            <a class="button" href="<?= e(url('departments/sheriff-training/officer-edit.php')) ?>">Add officer</a>
        </div>

        <form class="form compact-form" method="get" style="margin-bottom: 18px;">
            <label>
                Search
                <input name="q" value="<?= e($query) ?>" placeholder="Name, rank, or division">
            </label>
            <label>
                Status
                <select name="status">
                    <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All officers</option>
                </select>
            </label>
            <div class="actions">
                <button type="submit">Filter</button>
                <a class="button secondary" href="<?= e(url('departments/sheriff-training/officers.php')) ?>">Clear</a>
            </div>
        </form>

        <table class="table mobile-card-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Rank / Title</th>
                    <th>Division</th>
                    <th>Email</th>
                    <th>Training History</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($officers as $officer): ?>
                    <tr>
                        <td data-label="Name"><?= e($officer['last_name'] . ', ' . $officer['first_name']) ?><?= (int) $officer['is_active'] === 0 ? ' (Inactive)' : '' ?></td>
                        <td data-label="Rank / Title"><?= e($officer['rank_title'] ?: 'Not set') ?></td>
                        <td data-label="Division"><?= e($officer['division'] ?: 'Not set') ?></td>
                        <td data-label="Email"><?= e($officer['email'] ?: 'Not set') ?></td>
                        <td data-label="Training History"><?= e((string) (int) $officer['completed_count']) ?> completed / <?= e((string) (int) $officer['request_count']) ?> total</td>
                        <td data-label="Actions">
                            <div class="table-actions">
                                <a class="icon-link" href="<?= e(url('departments/sheriff-training/officer-detail.php?id=' . $officer['id'])) ?>" title="View officer" aria-label="View officer">&#9636;</a>
                                <a class="icon-link" href="<?= e(url('departments/sheriff-training/officer-edit.php?id=' . $officer['id'])) ?>" title="Edit officer" aria-label="Edit officer">&#9998;</a>
                                <a class="button secondary compact-button" href="<?= e(url('departments/sheriff-training/request-edit.php?officer_id=' . $officer['id'])) ?>">New request</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$officers): ?>
                    <tr><td colspan="6">No officers matched the selected filter.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
</main>
<?php page_footer(); ?>
