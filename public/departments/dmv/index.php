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

page_header('DMV Home');
?>
<main class="shell">
    <section class="panel">
        <h1>DMV Home</h1>
        <p>Create title request letters, track request status, and maintain reusable lienholder contact information.</p>
        <?php dmv_navigation('home'); ?>
    </section>

    <section class="dashboard-stat-row" style="margin-top: 18px;">
        <div class="dashboard-stat-group status-summary-group">
            <h2>Request Status</h2>
            <div class="grid dashboard-stat-grid">
                <?php foreach ($statusCounts as $status => $count): ?>
                    <a class="card status-card" href="<?= e(url('departments/dmv/title-requests.php?status=' . $status)) ?>">
                        <h3><?= e((string) $count) ?></h3>
                        <p><?= e(ucfirst($status)) ?></p>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="dashboard-stat-group summary-stat-group">
            <h2>Summary</h2>
            <div class="grid dashboard-stat-grid">
                <article class="card dashboard-stat-card">
                    <h3><?= e((string) $totalRequests) ?></h3>
                    <p>Total</p>
                </article>
                <article class="card dashboard-stat-card">
                    <h3><?= e((string) $totalLienholders) ?></h3>
                    <p>Lienholders</p>
                </article>
            </div>
        </div>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Recent Title Requests</h1>
        <?php if (!$recentRecords): ?>
            <p>No title requests have been entered yet.</p>
        <?php else: ?>
            <table class="table mobile-card-table">
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
                            <td data-label="Request Date"><?= e($record['request_date']) ?></td>
                            <td data-label="Registrant"><?= e($record['registrant_name']) ?></td>
                            <td data-label="Lienholder"><?= e($record['company_name']) ?></td>
                            <td data-label="Status"><?= e($record['status']) ?></td>
                            <td data-label="Entered By"><?= e(trim(($record['first_name'] ?? '') . ' ' . ($record['last_name'] ?? ''))) ?></td>
                            <td data-label="Actions">
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
