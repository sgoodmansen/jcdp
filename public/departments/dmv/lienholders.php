<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_department_access('dmv');

$search = trim($_GET['search'] ?? '');
$params = [];
$where = '';

if ($search !== '') {
    $where = 'WHERE company_name LIKE :search OR contact_name LIKE :search OR city LIKE :search';
    $params['search'] = '%' . $search . '%';
}

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

        <form class="form" method="get">
            <label>
                Search lienholders
                <input name="search" value="<?= e($search) ?>">
            </label>
            <div class="actions">
                <button type="submit">Search</button>
                <a class="button secondary" href="<?= e(url('departments/dmv/lienholder-create.php')) ?>">New lienholder</a>
            </div>
        </form>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <table class="table">
            <thead>
                <tr>
                    <th>Lienholder</th>
                    <th>Contact</th>
                    <th>Address</th>
                    <th>Phone</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lienholders as $lienholder): ?>
                    <tr>
                        <td><?= e($lienholder['company_name']) ?></td>
                        <td><?= e($lienholder['contact_name']) ?></td>
                        <td><?= e($lienholder['mailing_address'] . ', ' . $lienholder['city'] . ', ' . $lienholder['state'] . ' ' . $lienholder['zip_code']) ?></td>
                        <td><?= e($lienholder['phone']) ?></td>
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
