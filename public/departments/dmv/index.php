<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_department_access('dmv');

$totalLienholders = (int) db()->query('SELECT COUNT(*) FROM dmv_lienholders')->fetchColumn();
$totalRequests = (int) db()->query('SELECT COUNT(*) FROM dmv_title_requests')->fetchColumn();
$statusRows = db()->query(
    'SELECT status, COUNT(*) AS request_count
     FROM dmv_title_requests
     GROUP BY status'
)->fetchAll();
$statusCounts = [
    'draft' => 0,
    'sent' => 0,
    'received' => 0,
    'closed' => 0,
];

foreach ($statusRows as $row) {
    $statusCounts[$row['status']] = (int) $row['request_count'];
}

$recentRecords = db()->query(
    'SELECT
        dmv_title_requests.*,
        dmv_lienholders.company_name,
        COALESCE(dmv_vehicle_makes.name, dmv_title_requests.vehicle_make) AS display_vehicle_make,
        COALESCE(dmv_vehicle_models.name, dmv_title_requests.vehicle_model) AS display_vehicle_model,
        users.first_name,
        users.last_name
     FROM dmv_title_requests
     INNER JOIN dmv_lienholders ON dmv_lienholders.id = dmv_title_requests.lienholder_id
     LEFT JOIN dmv_vehicle_makes ON dmv_vehicle_makes.id = dmv_title_requests.vehicle_make_id
     LEFT JOIN dmv_vehicle_models ON dmv_vehicle_models.id = dmv_title_requests.vehicle_model_id
     LEFT JOIN users ON users.id = dmv_title_requests.created_by
     ORDER BY dmv_title_requests.created_at DESC
     LIMIT 5'
)->fetchAll();

page_header('DMV');
?>
<main class="shell">
    <section class="panel">
        <h1>DMV Title Requests</h1>
        <p>Create title request letters, track request status, and maintain reusable lienholder contact information.</p>
        <div class="actions">
            <a class="button" href="<?= e(url('departments/dmv/title-request-create.php')) ?>">New title request</a>
            <a class="button secondary" href="<?= e(url('departments/dmv/lienholder-create.php')) ?>">New lienholder</a>
            <a class="button secondary" href="<?= e(url('departments/dmv/title-requests.php')) ?>">Title requests</a>
            <a class="button secondary" href="<?= e(url('departments/dmv/lienholders.php')) ?>">Lienholders</a>
            <?php if (can_manage_department('dmv')): ?>
                <a class="button secondary" href="<?= e(url('departments/dmv/vehicle-lookups.php')) ?>">Vehicle lookups</a>
            <?php endif; ?>
            <a class="button secondary" href="<?= e(url('departments/dmv/report.php')) ?>">Reports</a>
        </div>
    </section>

    <section class="grid status-grid" style="margin-top: 18px;">
        <?php foreach ($statusCounts as $status => $count): ?>
            <a class="card status-card" href="<?= e(url('departments/dmv/title-requests.php?status=' . $status)) ?>">
                <h2><?= e((string) $count) ?></h2>
                <p><?= e(ucfirst($status)) ?> title requests</p>
            </a>
        <?php endforeach; ?>
    </section>

    <section class="grid" style="margin-top: 18px;">
        <article class="card">
            <h2><?= e((string) $totalRequests) ?></h2>
            <p>Total title requests entered.</p>
        </article>
        <article class="card">
            <h2><?= e((string) $totalLienholders) ?></h2>
            <p>Lienholder contacts available for letters.</p>
        </article>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Recent Title Requests</h1>
        <?php if (!$recentRecords): ?>
            <p>No title requests have been entered yet.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Request Date</th>
                        <th>Registrant</th>
                        <th>Lienholder</th>
                        <th>Status</th>
                        <th>Entered By</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentRecords as $record): ?>
                        <tr>
                            <td><?= e($record['request_date']) ?></td>
                            <td><?= e($record['registrant_name']) ?></td>
                            <td><?= e($record['company_name']) ?></td>
                            <td><?= e($record['status']) ?></td>
                            <td><?= e(trim(($record['first_name'] ?? '') . ' ' . ($record['last_name'] ?? ''))) ?></td>
                            <td>
                                <div class="table-actions">
                                    <a class="icon-link" href="<?= e(url('departments/dmv/title-request-detail.php?id=' . $record['id'])) ?>" title="View details" aria-label="View title request details">▤</a>
                                    <a class="icon-link" href="<?= e(url('departments/dmv/title-request-edit.php?id=' . $record['id'])) ?>" title="Edit title request" aria-label="Edit title request">✎</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="meta" style="margin-top: 12px;">
                Showing the 5 most recent title requests.
                <a href="<?= e(url('departments/dmv/title-requests.php')) ?>">View all title requests</a>.
            </p>
        <?php endif; ?>
    </section>
</main>
<?php page_footer(); ?>
