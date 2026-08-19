<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_department_access('dmv');

$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? 'active';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;
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

$countStatement = db()->prepare("SELECT COUNT(*) FROM dmv_lienholders $where");
$countStatement->execute($params);
$totalLienholders = (int) $countStatement->fetchColumn();
$totalPages = max(1, (int) ceil($totalLienholders / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$statement = db()->prepare(
    "SELECT *
     FROM dmv_lienholders
     $where
     ORDER BY company_name
     LIMIT :limit OFFSET :offset"
);

foreach ($params as $key => $value) {
    $statement->bindValue(':' . $key, $value);
}

$statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
$statement->bindValue(':offset', $offset, PDO::PARAM_INT);
$statement->execute();
$lienholders = $statement->fetchAll();

$queryBase = [
    'search' => $search,
    'status' => $status,
];
function lienholder_page_url(array $queryBase, int $page): string
{
    $query = array_filter(
        array_merge($queryBase, ['page' => $page]),
        fn($value) => $value !== '' && $value !== null
    );

    return url('departments/dmv/lienholders.php?' . http_build_query($query));
}

page_header('Lienholders');
?>
<main class="shell">
    <section class="panel">
        <h1>Lienholders</h1>
        <p>Search and maintain lienholder contact information used on title request letters.</p>

        <?php if ($message = flash('success')): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>

        <?php dmv_navigation('lienholders'); ?>

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
            </div>
        </form>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <div class="pagination-summary">
            Showing <?= e((string) ($totalLienholders === 0 ? 0 : $offset + 1)) ?>
            to <?= e((string) min($offset + $perPage, $totalLienholders)) ?>
            of <?= e((string) $totalLienholders) ?> lienholders
        </div>

        <table class="table mobile-card-table">
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
                        <td data-label="Lienholder"><?= e($lienholder['company_name']) ?></td>
                        <td data-label="Address"><?= e($lienholder['mailing_address'] . ', ' . $lienholder['city'] . ', ' . $lienholder['state'] . ' ' . $lienholder['zip_code']) ?></td>
                        <td data-label="Status">
                            <span class="badge <?= (int) $lienholder['is_active'] === 1 ? 'badge-success' : 'badge-muted' ?>">
                                <?= (int) $lienholder['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td data-label="Actions">
                            <div class="table-actions">
                                <?php if (can_manage_department('dmv')): ?>
                                    <a class="icon-link" href="<?= e(url('departments/dmv/lienholder-merge.php?source_id=' . $lienholder['id'])) ?>" title="Merge lienholder" aria-label="Merge lienholder">⇄</a>
                                <?php endif; ?>
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

        <?php if ($totalPages > 1): ?>
            <nav class="pagination" aria-label="Lienholder pages">
                <?php if ($page > 1): ?>
                    <a class="button secondary" href="<?= e(lienholder_page_url($queryBase, $page - 1)) ?>">Previous</a>
                <?php else: ?>
                    <span class="button secondary disabled-button" aria-disabled="true">Previous</span>
                <?php endif; ?>

                <span class="pagination-current">Page <?= e((string) $page) ?> of <?= e((string) $totalPages) ?></span>

                <?php if ($page < $totalPages): ?>
                    <a class="button secondary" href="<?= e(lienholder_page_url($queryBase, $page + 1)) ?>">Next</a>
                <?php else: ?>
                    <span class="button secondary disabled-button" aria-disabled="true">Next</span>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </section>
</main>
<?php page_footer(); ?>
