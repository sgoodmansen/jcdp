<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_department_access('dmv');

$search = trim($_GET['search'] ?? '');
$params = [];
$where = '';

if ($search !== '') {
    $where = 'WHERE registrant_name LIKE :search OR vin LIKE :search OR company_name LIKE :search';
    $params['search'] = '%' . $search . '%';
}

$statement = db()->prepare(
    "SELECT dmv_title_requests.*, dmv_lienholders.company_name
     FROM dmv_title_requests
     INNER JOIN dmv_lienholders ON dmv_lienholders.id = dmv_title_requests.lienholder_id
     $where
     ORDER BY request_date DESC, dmv_title_requests.created_at DESC
     LIMIT 100"
);
$statement->execute($params);
$requests = $statement->fetchAll();

page_header('Title Requests');
?>
<main class="shell">
    <section class="panel">
        <h1>Title Requests</h1>
        <p>Search by registrant, lienholder, or VIN.</p>

        <?php if ($message = flash('success')): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>

        <form class="form" method="get">
            <label>
                Search title requests
                <input name="search" value="<?= e($search) ?>">
            </label>
            <div class="actions">
                <button type="submit">Search</button>
                <a class="button secondary" href="<?= e(url('departments/dmv/title-request-create.php')) ?>">New title request</a>
            </div>
        </form>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <table class="table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Registrant</th>
                    <th>Lienholder</th>
                    <th>Vehicle</th>
                    <th>Status</th>
                    <th>Letter</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($requests as $request): ?>
                    <tr>
                        <td><?= e($request['request_date']) ?></td>
                        <td><?= e($request['registrant_name']) ?></td>
                        <td><?= e($request['company_name']) ?></td>
                        <td><?= e(trim($request['vehicle_year'] . ' ' . $request['vehicle_make'] . ' ' . $request['vehicle_model'])) ?></td>
                        <td><?= e($request['status']) ?></td>
                        <td><a href="<?= e(url('departments/dmv/letter.php?id=' . $request['id'])) ?>">View</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$requests): ?>
                    <tr>
                        <td colspan="6">No title requests found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
</main>
<?php page_footer(); ?>
