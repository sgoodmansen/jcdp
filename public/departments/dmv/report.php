<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_department_access('dmv');

$startDate = trim($_GET['start_date'] ?? '');
$endDate = trim($_GET['end_date'] ?? '');

$whereParts = [];
$params = [];

if ($startDate !== '') {
    $whereParts[] = 'dmv_title_requests.request_date >= :start_date';
    $params['start_date'] = $startDate;
}

if ($endDate !== '') {
    $whereParts[] = 'dmv_title_requests.request_date <= :end_date';
    $params['end_date'] = $endDate;
}

$where = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';

$statusStatement = db()->prepare(
    "SELECT status, COUNT(*) AS request_count
     FROM dmv_title_requests
     $where
     GROUP BY status"
);
$statusStatement->execute($params);
$statusRows = $statusStatement->fetchAll();

$statusCounts = [
    'draft' => 0,
    'sent' => 0,
    'received' => 0,
    'closed' => 0,
];

foreach ($statusRows as $row) {
    $statusCounts[$row['status']] = (int) $row['request_count'];
}

$requestsStatement = db()->prepare(
    "SELECT
        dmv_title_requests.id,
        dmv_title_requests.request_date,
        dmv_title_requests.registrant_name,
        dmv_title_requests.registrant_name_2,
        dmv_title_requests.status,
        dmv_title_requests.vehicle_year,
        dmv_title_requests.vin,
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
     $where
     ORDER BY dmv_title_requests.request_date DESC, dmv_title_requests.created_at DESC
     LIMIT 200"
);
$requestsStatement->execute($params);
$requests = $requestsStatement->fetchAll();

$clerkStatement = db()->prepare(
    "SELECT
        users.id,
        users.first_name,
        users.last_name,
        COUNT(dmv_title_requests.id) AS request_count,
        SUM(dmv_title_requests.status = 'draft') AS draft_count,
        SUM(dmv_title_requests.status = 'sent') AS sent_count,
        SUM(dmv_title_requests.status = 'received') AS received_count,
        SUM(dmv_title_requests.status = 'closed') AS closed_count
     FROM dmv_title_requests
     LEFT JOIN users ON users.id = dmv_title_requests.created_by
     $where
     GROUP BY users.id, users.first_name, users.last_name
     ORDER BY request_count DESC, users.last_name, users.first_name"
);
$clerkStatement->execute($params);
$clerkActivity = $clerkStatement->fetchAll();

$lienholderStatement = db()->prepare(
    "SELECT
        dmv_lienholders.id,
        dmv_lienholders.company_name,
        dmv_lienholders.city,
        dmv_lienholders.state,
        COUNT(dmv_title_requests.id) AS request_count,
        MAX(dmv_title_requests.request_date) AS last_request_date,
        SUM(dmv_title_requests.status = 'draft') AS draft_count,
        SUM(dmv_title_requests.status = 'sent') AS sent_count,
        SUM(dmv_title_requests.status = 'received') AS received_count,
        SUM(dmv_title_requests.status = 'closed') AS closed_count
     FROM dmv_title_requests
     INNER JOIN dmv_lienholders ON dmv_lienholders.id = dmv_title_requests.lienholder_id
     $where
     GROUP BY dmv_lienholders.id, dmv_lienholders.company_name, dmv_lienholders.city, dmv_lienholders.state
     ORDER BY request_count DESC, dmv_lienholders.company_name
     LIMIT 100"
);
$lienholderStatement->execute($params);
$lienholderHistory = $lienholderStatement->fetchAll();

$totalRequests = array_sum($statusCounts);
$rangeLabel = 'All dates';
if ($startDate !== '' || $endDate !== '') {
    $rangeLabel = ($startDate ?: 'Beginning') . ' through ' . ($endDate ?: 'Today');
}
page_header('DMV Reports');
?>
<main class="shell">
    <section class="panel">
        <h1>DMV Reports</h1>
        <p>Review title request activity by date range, status, clerk, and lienholder.</p>

        <?php dmv_navigation('reports'); ?>

        <form class="form compact-form" method="get">
            <label>
                Start date
                <input type="date" name="start_date" value="<?= e($startDate) ?>">
            </label>
            <label>
                End date
                <input type="date" name="end_date" value="<?= e($endDate) ?>">
            </label>
            <div class="actions span-2">
                <button type="submit">Run reports</button>
                <a class="button secondary" href="<?= e(url('departments/dmv/report.php')) ?>">Clear dates</a>
            </div>
        </form>
    </section>

    <section class="dashboard-stat-row" style="margin-top: 18px;">
        <div class="dashboard-stat-group status-summary-group">
            <h2>Status</h2>
            <div class="grid dashboard-stat-grid">
                <?php foreach (['draft' => 'Open', 'sent' => 'Sent', 'received' => 'Received', 'closed' => 'Closed'] as $status => $label): ?>
                    <a class="card status-card" href="<?= e(url('departments/dmv/title-requests.php?status=' . $status)) ?>">
                        <h3><?= e((string) $statusCounts[$status]) ?></h3>
                        <p><?= e($label) ?></p>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="dashboard-stat-group summary-stat-group">
            <h2>Summary</h2>
            <div class="grid dashboard-stat-grid">
                <article class="card dashboard-stat-card">
                    <h3><?= e((string) $totalRequests) ?></h3>
                    <p>Requests</p>
                </article>
                <article class="card dashboard-stat-card">
                    <h3><?= e((string) count($clerkActivity)) ?></h3>
                    <p>Clerks</p>
                </article>
            </div>
        </div>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Requests by Date Range</h1>
        <p><?= e($rangeLabel) ?></p>
        <table class="table mobile-card-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Registrant</th>
                    <th>Lienholder</th>
                    <th>Vehicle</th>
                    <th>VIN</th>
                    <th>Status</th>
                    <th>Clerk</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($requests as $request): ?>
                    <tr>
                        <td data-label="Date"><?= e($request['request_date']) ?></td>
                        <td data-label="Registrant">
                            <a href="<?= e(url('departments/dmv/title-request-detail.php?id=' . $request['id'])) ?>">
                                <?= e($request['registrant_name']) ?>
                            </a>
                            <?php if (!empty($request['registrant_name_2'])): ?>
                                <br><span class="meta"><?= e($request['registrant_name_2']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Lienholder"><?= e($request['company_name']) ?></td>
                        <td data-label="Vehicle"><?= e(trim($request['vehicle_year'] . ' ' . $request['display_vehicle_make'] . ' ' . $request['display_vehicle_model'])) ?></td>
                        <td data-label="VIN"><?= e($request['vin'] ? normalize_vin($request['vin']) : '') ?></td>
                        <td data-label="Status"><?= e($request['status'] === 'draft' ? 'Open' : ucfirst($request['status'])) ?></td>
                        <td data-label="Clerk"><?= e(trim(($request['first_name'] ?? '') . ' ' . ($request['last_name'] ?? '')) ?: 'Unknown') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$requests): ?>
                    <tr>
                        <td colspan="7">No title requests found for this date range.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php if (count($requests) === 200): ?>
            <p class="meta" style="margin-top: 12px;">Showing the first 200 matching requests.</p>
        <?php endif; ?>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Clerk Activity</h1>
        <table class="table mobile-card-table">
            <thead>
                <tr>
                    <th>Clerk</th>
                    <th>Total</th>
                    <th>Open</th>
                    <th>Sent</th>
                    <th>Received</th>
                    <th>Closed</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($clerkActivity as $row): ?>
                    <tr>
                        <td data-label="Clerk"><?= e(trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: 'Unknown') ?></td>
                        <td data-label="Total"><?= e((string) $row['request_count']) ?></td>
                        <td data-label="Open"><?= e((string) $row['draft_count']) ?></td>
                        <td data-label="Sent"><?= e((string) $row['sent_count']) ?></td>
                        <td data-label="Received"><?= e((string) $row['received_count']) ?></td>
                        <td data-label="Closed"><?= e((string) $row['closed_count']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$clerkActivity): ?>
                    <tr>
                        <td colspan="6">No clerk activity found for this date range.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Lienholder Request History</h1>
        <table class="table mobile-card-table">
            <thead>
                <tr>
                    <th>Lienholder</th>
                    <th>Last Request</th>
                    <th>Total</th>
                    <th>Open</th>
                    <th>Sent</th>
                    <th>Received</th>
                    <th>Closed</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lienholderHistory as $row): ?>
                    <tr>
                        <td data-label="Lienholder">
                            <?= e($row['company_name']) ?>
                            <br><span class="meta"><?= e($row['city'] . ', ' . $row['state']) ?></span>
                        </td>
                        <td data-label="Last Request"><?= e($row['last_request_date']) ?></td>
                        <td data-label="Total"><?= e((string) $row['request_count']) ?></td>
                        <td data-label="Open"><?= e((string) $row['draft_count']) ?></td>
                        <td data-label="Sent"><?= e((string) $row['sent_count']) ?></td>
                        <td data-label="Received"><?= e((string) $row['received_count']) ?></td>
                        <td data-label="Closed"><?= e((string) $row['closed_count']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$lienholderHistory): ?>
                    <tr>
                        <td colspan="7">No lienholder request history found for this date range.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
</main>
<?php page_footer(); ?>
