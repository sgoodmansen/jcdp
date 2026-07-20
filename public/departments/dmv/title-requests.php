<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_department_access('dmv');

$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');
$params = [];
$whereParts = [];

if ($search !== '') {
    $whereParts[] = '(registrant_name LIKE :search OR registrant_name_2 LIKE :search OR vin LIKE :search OR company_name LIKE :search)';
    $params['search'] = '%' . $search . '%';
}

if (in_array($status, ['draft', 'sent', 'received', 'closed'], true)) {
    $whereParts[] = 'dmv_title_requests.status = :status';
    $params['status'] = $status;
}

$where = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';

$statement = db()->prepare(
    "SELECT
        dmv_title_requests.*,
        dmv_lienholders.company_name,
        COALESCE(dmv_vehicle_makes.name, dmv_title_requests.vehicle_make) AS display_vehicle_make,
        COALESCE(dmv_vehicle_models.name, dmv_title_requests.vehicle_model) AS display_vehicle_model
     FROM dmv_title_requests
     INNER JOIN dmv_lienholders ON dmv_lienholders.id = dmv_title_requests.lienholder_id
     LEFT JOIN dmv_vehicle_makes ON dmv_vehicle_makes.id = dmv_title_requests.vehicle_make_id
     LEFT JOIN dmv_vehicle_models ON dmv_vehicle_models.id = dmv_title_requests.vehicle_model_id
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

        <div class="actions" style="margin-bottom: 18px;">
            <a class="button secondary" href="<?= e(url('departments/dmv/index.php')) ?>">DMV home</a>
            <a class="button secondary" href="<?= e(url('departments/dmv/lienholders.php')) ?>">Lienholders</a>
            <a class="button secondary" href="<?= e(url('departments/dmv/lienholder-create.php')) ?>">New lienholder</a>
        </div>

        <form class="form" method="get">
            <?php if ($status !== ''): ?>
                <input type="hidden" name="status" value="<?= e($status) ?>">
                <div class="notice success">Showing <?= e(ucfirst($status)) ?> title requests.</div>
            <?php endif; ?>
            <label>
                Search title requests
                <input name="search" value="<?= e($search) ?>">
            </label>
            <div class="actions">
                <button type="submit">Search</button>
                <a class="button secondary" href="<?= e(url('departments/dmv/title-request-create.php')) ?>">New title request</a>
                <?php if ($status !== ''): ?>
                    <a class="button secondary" href="<?= e(url('departments/dmv/title-requests.php')) ?>">Clear status filter</a>
                <?php endif; ?>
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
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($requests as $request): ?>
                    <tr>
                        <td><?= e($request['request_date']) ?></td>
                        <td>
                            <?= e($request['registrant_name']) ?>
                            <?php if (!empty($request['registrant_name_2'])): ?>
                                <br><span class="meta"><?= e($request['registrant_name_2']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($request['company_name']) ?></td>
                        <td><?= e(trim($request['vehicle_year'] . ' ' . $request['display_vehicle_make'] . ' ' . $request['display_vehicle_model'])) ?></td>
                        <td><?= e(ucfirst($request['status'])) ?></td>
                        <td>
                            <div class="table-actions">
                                <a class="icon-link" href="<?= e(url('departments/dmv/title-request-detail.php?id=' . $request['id'])) ?>" title="View details" aria-label="View title request details">▤</a>
                                <a class="icon-link" href="<?= e(url('departments/dmv/title-request-edit.php?id=' . $request['id'])) ?>" title="Edit title request" aria-label="Edit title request">✎</a>
                            </div>
                        </td>
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
