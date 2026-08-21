<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_sheriff_training_manager();

$currentFiscalYear = sheriff_training_fiscal_year_for_date();
$statement = db()->prepare('SELECT * FROM sheriff_training_fiscal_years WHERE fiscal_year = :fiscal_year');
$statement->execute(['fiscal_year' => $currentFiscalYear]);
$year = $statement->fetch();

if (!$year) {
    $dates = sheriff_training_fiscal_year_dates($currentFiscalYear);
    $statement = db()->prepare(
        'INSERT INTO sheriff_training_fiscal_years (fiscal_year, label, starts_on, ends_on)
         VALUES (:fiscal_year, :label, :starts_on, :ends_on)'
    );
    $statement->execute([
        'fiscal_year' => $currentFiscalYear,
        'label' => 'FY ' . $currentFiscalYear,
        'starts_on' => $dates['starts_on'],
        'ends_on' => $dates['ends_on'],
    ]);
    $yearId = (int) db()->lastInsertId();
} else {
    $yearId = (int) $year['id'];
}

$budget = sheriff_training_budget_summary($yearId);
$statusCounts = array_fill_keys(array_keys(sheriff_training_status_options()), 0);
$statusRows = db()->query('SELECT status, COUNT(*) AS total FROM sheriff_training_requests GROUP BY status')->fetchAll();
foreach ($statusRows as $row) {
    $statusCounts[$row['status']] = (int) $row['total'];
}

$pendingRequests = db()->query(
    'SELECT sheriff_training_requests.id,
            sheriff_training_requests.class_name,
            sheriff_training_requests.start_date,
            sheriff_training_requests.estimated_training_cost,
            sheriff_training_requests.estimated_lodging_cost,
            sheriff_training_officers.first_name,
            sheriff_training_officers.last_name
     FROM sheriff_training_requests
     INNER JOIN sheriff_training_officers ON sheriff_training_officers.id = sheriff_training_requests.officer_id
     WHERE sheriff_training_requests.status = "pending"
     ORDER BY sheriff_training_requests.start_date, sheriff_training_requests.created_at
     LIMIT 8'
)->fetchAll();

$missingActuals = db()->query(
    'SELECT sheriff_training_requests.id,
            sheriff_training_requests.class_name,
            sheriff_training_requests.start_date,
            sheriff_training_officers.first_name,
            sheriff_training_officers.last_name
     FROM sheriff_training_requests
     INNER JOIN sheriff_training_officers ON sheriff_training_officers.id = sheriff_training_requests.officer_id
     WHERE sheriff_training_requests.status IN ("approved", "completed")
       AND COALESCE(sheriff_training_requests.end_date, sheriff_training_requests.start_date) < CURDATE()
       AND (sheriff_training_requests.actual_training_cost IS NULL OR sheriff_training_requests.actual_lodging_cost IS NULL)
     ORDER BY sheriff_training_requests.end_date DESC
     LIMIT 8'
)->fetchAll();

$pendingReviewCount = $statusCounts['pending'] ?? 0;
$approvedNotCompletedStatement = db()->prepare(
    'SELECT COUNT(*) AS total
     FROM sheriff_training_requests
     WHERE fiscal_year_id = :fiscal_year_id
       AND status = "approved"'
);
$approvedNotCompletedStatement->execute(['fiscal_year_id' => $yearId]);
$approvedNotCompletedCount = (int) ($approvedNotCompletedStatement->fetch()['total'] ?? 0);

$missingActualsCount = (int) (db()->query(
    'SELECT COUNT(*) AS total
     FROM sheriff_training_requests
     WHERE status IN ("approved", "completed")
       AND COALESCE(end_date, start_date) < CURDATE()
       AND (actual_training_cost IS NULL OR actual_lodging_cost IS NULL)'
)->fetch()['total'] ?? 0);

$upcomingTrainingStatement = db()->prepare(
    'SELECT COUNT(*) AS total
     FROM sheriff_training_requests
     WHERE fiscal_year_id = :fiscal_year_id
       AND status = "approved"
       AND start_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)'
);
$upcomingTrainingStatement->execute(['fiscal_year_id' => $yearId]);
$upcomingTrainingCount = (int) ($upcomingTrainingStatement->fetch()['total'] ?? 0);

$trainingUsedPercent = (float) ($budget['training_used_percent'] ?? 0);
$lodgingUsedPercent = (float) ($budget['lodging_used_percent'] ?? 0);
$trainingBudgetLevel = sheriff_training_budget_level_class($trainingUsedPercent);
$lodgingBudgetLevel = sheriff_training_budget_level_class($lodgingUsedPercent);

page_header('Sheriff Training');
?>
<main class="shell">
    <section class="panel">
        <h1>Sheriff Training</h1>
        <p>Track paper training requests, review fiscal budget impact, and maintain officer training history.</p>
        <?php sheriff_training_navigation('home'); ?>

        <?php if ($message = flash('success')): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>
    </section>

    <section class="dashboard-stat-row sheriff-action-row" style="margin-top: 18px;">
        <div class="dashboard-stat-group">
            <h2>Needs Attention</h2>
            <div class="grid dashboard-stat-grid">
                <a class="card dashboard-stat-card status-card action-stat-card" href="<?= e(url('departments/sheriff-training/requests.php?status=pending')) ?>">
                    <h3><?= e((string) $pendingReviewCount) ?></h3>
                    <p>Pending Review</p>
                </a>
                <a class="card dashboard-stat-card status-card action-stat-card" href="<?= e(url('departments/sheriff-training/requests.php?status=approved&fiscal_year_id=' . $yearId . '&date_filter=upcoming_30')) ?>">
                    <h3><?= e((string) $approvedNotCompletedCount) ?></h3>
                    <p>Approved Not Completed</p>
                </a>
                <a class="card dashboard-stat-card status-card action-stat-card" href="<?= e(url('departments/sheriff-training/reports.php?report_type=missing_actuals&fiscal_year_id=' . $yearId)) ?>">
                    <h3><?= e((string) $missingActualsCount) ?></h3>
                    <p>Missing Actual Costs</p>
                </a>
                <a class="card dashboard-stat-card status-card action-stat-card" href="<?= e(url('departments/sheriff-training/requests.php?status=approved&fiscal_year_id=' . $yearId)) ?>">
                    <h3><?= e((string) $upcomingTrainingCount) ?></h3>
                    <p>Upcoming 30 Days</p>
                </a>
            </div>
        </div>
    </section>

    <section class="dashboard-stat-row sheriff-stat-row" style="margin-top: 18px;">
        <div class="dashboard-stat-group status-summary-group">
            <h2><?= e($budget['label'] ?? 'Current FY') ?> Budget</h2>
            <div class="grid dashboard-stat-grid">
                <article class="card dashboard-stat-card">
                    <h3><?= e(sheriff_training_money($budget['training_remaining'] ?? 0)) ?></h3>
                    <p>Training Remaining</p>
                    <div class="budget-meter budget-meter-<?= e($trainingBudgetLevel) ?>" aria-label="Training budget <?= e((string) $trainingUsedPercent) ?>% used">
                        <span style="width: <?= e((string) min($trainingUsedPercent, 100)) ?>%;"></span>
                    </div>
                    <p class="meta"><?= e(number_format($trainingUsedPercent, 1)) ?>% used</p>
                </article>
                <article class="card dashboard-stat-card">
                    <h3><?= e(sheriff_training_money($budget['lodging_remaining'] ?? 0)) ?></h3>
                    <p>Lodging Remaining</p>
                    <div class="budget-meter budget-meter-<?= e($lodgingBudgetLevel) ?>" aria-label="Lodging budget <?= e((string) $lodgingUsedPercent) ?>% used">
                        <span style="width: <?= e((string) min($lodgingUsedPercent, 100)) ?>%;"></span>
                    </div>
                    <p class="meta"><?= e(number_format($lodgingUsedPercent, 1)) ?>% used</p>
                </article>
            </div>
        </div>
        <div class="dashboard-stat-group summary-stat-group">
            <h2>Requests</h2>
            <div class="grid dashboard-stat-grid">
                <a class="card dashboard-stat-card status-card" href="<?= e(url('departments/sheriff-training/requests.php?status=pending')) ?>">
                    <h3><?= e((string) $statusCounts['pending']) ?></h3>
                    <p>Pending</p>
                </a>
                <a class="card dashboard-stat-card status-card" href="<?= e(url('departments/sheriff-training/requests.php?status=approved')) ?>">
                    <h3><?= e((string) $statusCounts['approved']) ?></h3>
                    <p>Approved</p>
                </a>
            </div>
        </div>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Pending Review</h1>
        <?php if (!$pendingRequests): ?>
            <p>No training requests are waiting for review.</p>
        <?php else: ?>
            <table class="table mobile-card-table">
                <thead>
                    <tr>
                        <th>Officer</th>
                        <th>Training</th>
                        <th>Date</th>
                        <th>Estimated Cost</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pendingRequests as $request): ?>
                        <tr>
                            <td data-label="Officer"><?= e($request['last_name'] . ', ' . $request['first_name']) ?></td>
                            <td data-label="Training"><?= e($request['class_name']) ?></td>
                            <td data-label="Date"><?= e(format_display_date($request['start_date'])) ?></td>
                            <td data-label="Estimated Cost"><?= e(sheriff_training_money((float) $request['estimated_training_cost'] + (float) $request['estimated_lodging_cost'])) ?></td>
                            <td data-label="Actions"><a class="button compact-button" href="<?= e(url('departments/sheriff-training/request-detail.php?id=' . $request['id'])) ?>">Review</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Needs Actual Costs</h1>
        <?php if (!$missingActuals): ?>
            <p>No past approved trainings are missing actual costs.</p>
        <?php else: ?>
            <table class="table mobile-card-table">
                <thead>
                    <tr>
                        <th>Officer</th>
                        <th>Training</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($missingActuals as $request): ?>
                        <tr>
                            <td data-label="Officer"><?= e($request['last_name'] . ', ' . $request['first_name']) ?></td>
                            <td data-label="Training"><?= e($request['class_name']) ?></td>
                            <td data-label="Date"><?= e(format_display_date($request['start_date'])) ?></td>
                            <td data-label="Actions"><a class="button compact-button secondary" href="<?= e(url('departments/sheriff-training/request-detail.php?id=' . $request['id'] . '#complete-training')) ?>">Complete</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</main>
<?php page_footer(); ?>
