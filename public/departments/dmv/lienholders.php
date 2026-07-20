<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_department_access('dmv');

$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? 'active';
$params = [];
$whereParts = [];

if ($search !== '') {
    $whereParts[] = '(company_name LIKE :search OR contact_name LIKE :search OR email LIKE :search OR city LIKE :search)';
    $params['search'] = '%' . $search . '%';
}

if ($status === 'inactive') {
    $whereParts[] = 'is_active = 0';
} elseif ($status !== 'all') {
    $status = 'active';
    $whereParts[] = 'is_active = 1';
}

$where = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';

$statement = db()->prepare(
    "SELECT *
     FROM dmv_lienholders
     $where
     ORDER BY company_name
     LIMIT 100"
);
$statement->execute($params);
$lienholders = $statement->fetchAll();

page_header('Lienholders');
?>
<main class="shell">
    <section class="panel">
        <h1>Lienholders</h1>
        <p>Search and maintain lienholder contact information used on title request letters.</p>

        <?php if ($message = flash('success')): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>

        <div class="actions" style="margin-bottom: 18px;">
            <a class="button secondary" href="<?= e(url('departments/dmv/index.php')) ?>">DMV home</a>
            <a class="button secondary" href="<?= e(url('departments/dmv/title-request-create.php')) ?>">New title request</a>
            <a class="button secondary" href="<?= e(url('departments/dmv/title-requests.php')) ?>">Title requests</a>
        </div>

        <form class="form" method="get">
            <label>
                Search lienholders
                <input name="search" value="<?= e($search) ?>">
            </label>
            <label>
                Status
                <select name="status">
                    <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All</option>
                </select>
            </label>
            <div class="actions">
                <button type="submit">Search</button>
                <a class="button" href="<?= e(url('departments/dmv/lienholder-create.php')) ?>">New lienholder</a>
            </div>
        </form>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <table class="table">
            <thead>
                <tr>
                    <th>Lienholder</th>
                    <th>Address</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lienholders as $lienholder): ?>
                    <tr>
                        <td><?= e($lienholder['company_name']) ?></td>
                        <td><?= e($lienholder['mailing_address'] . ', ' . $lienholder['city'] . ', ' . $lienholder['state'] . ' ' . $lienholder['zip_code']) ?></td>
                        <td>
                            <span class="badge <?= (int) $lienholder['is_active'] === 1 ? 'badge-success' : 'badge-muted' ?>">
                                <?= (int) $lienholder['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td>
                            <div class="table-actions">
                                <a class="icon-link" href="<?= e(url('departments/dmv/lienholder-edit.php?id=' . $lienholder['id'])) ?>" title="Edit lienholder" aria-label="Edit lienholder">✎</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$lienholders): ?>
                    <tr>
                        <td colspan="4">No lienholders found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
</main>
<?php page_footer(); ?>
