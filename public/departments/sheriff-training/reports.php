<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_sheriff_training_manager();

$yearId = (int) ($_GET['fiscal_year_id'] ?? 0);
$years = db()->query('SELECT * FROM sheriff_training_fiscal_years ORDER BY fiscal_year DESC')->fetchAll();
if ($yearId === 0 && $years) {
    $yearId = (int) $years[0]['id'];
}
$selectedYearLabel = 'Selected fiscal year';
foreach ($years as $year) {
    if ((int) $year['id'] === $yearId) {
        $selectedYearLabel = (string) $year['label'];
        break;
    }
}
$reportType = $_GET['report_type'] ?? 'budget';
if (!in_array($reportType, ['budget', 'approved', 'officer_summary', 'missing_actuals', 'denied'], true)) {
    $reportType = 'budget';
}
$reportTypeOptions = [
    'budget' => 'Budget Summary',
    'approved' => 'Approved / Completed Training',
    'officer_summary' => 'Officer Training Summary',
    'missing_actuals' => 'Missing Actual Costs',
    'denied' => 'Denied Requests',
];
$officerSummaryFilter = $_GET['officer_summary_filter'] ?? '';
if (!in_array($officerSummaryFilter, ['', 'completed', 'not_completed'], true)) {
    $officerSummaryFilter = '';
}
$officerSummaryFilterOptions = [
    '' => 'All officers',
    'completed' => 'With completed training',
    'not_completed' => 'No completed training',
];

$budget = $yearId > 0 ? sheriff_training_budget_summary($yearId) : null;

$approvedStatement = db()->prepare(
    'SELECT sheriff_training_requests.*,
            sheriff_training_officers.first_name,
            sheriff_training_officers.last_name
     FROM sheriff_training_requests
     INNER JOIN sheriff_training_officers ON sheriff_training_officers.id = sheriff_training_requests.officer_id
     WHERE sheriff_training_requests.fiscal_year_id = :fiscal_year_id
       AND sheriff_training_requests.status IN ("approved", "completed")
     ORDER BY sheriff_training_requests.start_date'
);
$approvedStatement->execute(['fiscal_year_id' => $yearId]);
$approvedRequests = $yearId > 0 ? $approvedStatement->fetchAll() : [];

$missingActuals = db()->query(
    'SELECT sheriff_training_requests.*,
            sheriff_training_officers.first_name,
            sheriff_training_officers.last_name,
            sheriff_training_fiscal_years.label AS fiscal_year_label
     FROM sheriff_training_requests
     INNER JOIN sheriff_training_officers ON sheriff_training_officers.id = sheriff_training_requests.officer_id
     INNER JOIN sheriff_training_fiscal_years ON sheriff_training_fiscal_years.id = sheriff_training_requests.fiscal_year_id
     WHERE sheriff_training_requests.status IN ("approved", "completed")
       AND COALESCE(sheriff_training_requests.end_date, sheriff_training_requests.start_date) < CURDATE()
       AND (sheriff_training_requests.actual_training_cost IS NULL OR sheriff_training_requests.actual_lodging_cost IS NULL)
     ORDER BY sheriff_training_requests.end_date DESC'
)->fetchAll();

$officerSummaryWhere = '';
if ($officerSummaryFilter === 'completed') {
    $officerSummaryWhere = ' WHERE COALESCE(training_summary.completed_trainings, 0) > 0';
} elseif ($officerSummaryFilter === 'not_completed') {
    $officerSummaryWhere = ' WHERE COALESCE(training_summary.completed_trainings, 0) = 0';
}
$officerSummary = db()->query(
    'SELECT sheriff_training_officers.id,
            sheriff_training_officers.first_name,
            sheriff_training_officers.last_name,
            sheriff_training_officers.rank_title,
            sheriff_training_officers.division,
            COALESCE(training_summary.total_requests, 0) AS total_requests,
            COALESCE(training_summary.completed_trainings, 0) AS completed_trainings,
            COALESCE(training_summary.approved_cost, 0) AS approved_cost
     FROM sheriff_training_officers
     LEFT JOIN (
         SELECT officer_id,
                COUNT(*) AS total_requests,
                SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) AS completed_trainings,
                SUM(CASE WHEN status IN ("approved", "completed") THEN COALESCE(actual_training_cost, estimated_training_cost) + COALESCE(actual_lodging_cost, estimated_lodging_cost) ELSE 0 END) AS approved_cost
         FROM sheriff_training_requests
         GROUP BY officer_id
     ) training_summary ON training_summary.officer_id = sheriff_training_officers.id
     ' . $officerSummaryWhere . '
     ORDER BY completed_trainings DESC, approved_cost DESC, sheriff_training_officers.last_name'
)->fetchAll();

$deniedRequests = db()->query(
    'SELECT sheriff_training_requests.*,
            sheriff_training_officers.first_name,
            sheriff_training_officers.last_name,
            sheriff_training_fiscal_years.label AS fiscal_year_label
     FROM sheriff_training_requests
     INNER JOIN sheriff_training_officers ON sheriff_training_officers.id = sheriff_training_requests.officer_id
     INNER JOIN sheriff_training_fiscal_years ON sheriff_training_fiscal_years.id = sheriff_training_requests.fiscal_year_id
     WHERE sheriff_training_requests.status = "denied"
     ORDER BY sheriff_training_requests.decision_at DESC, sheriff_training_requests.start_date DESC
     LIMIT 25'
)->fetchAll();

page_header('Sheriff Training Reports');
?>
<main class="shell">
    <section class="panel">
        <h1>Reports</h1>
        <p>Review fiscal budget use, officer training history, missing actual costs, and denied requests.</p>
        <?php sheriff_training_navigation('reports'); ?>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Report Filters</h1>
        <form class="form compact-form" method="get">
            <label>
                Report type
                <select name="report_type">
                    <?php foreach ($reportTypeOptions as $typeKey => $typeLabel): ?>
                        <option value="<?= e($typeKey) ?>" <?= $reportType === $typeKey ? 'selected' : '' ?>><?= e($typeLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Fiscal year
                <select name="fiscal_year_id">
                    <?php foreach ($years as $year): ?>
                        <option value="<?= e((string) $year['id']) ?>" <?= $yearId === (int) $year['id'] ? 'selected' : '' ?>><?= e($year['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Officer summary
                <select name="officer_summary_filter">
                    <?php foreach ($officerSummaryFilterOptions as $filterKey => $filterLabel): ?>
                        <option value="<?= e($filterKey) ?>" <?= $officerSummaryFilter === $filterKey ? 'selected' : '' ?>><?= e($filterLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="actions">
                <button type="submit">View</button>
                <a class="button secondary" href="<?= e(url('departments/sheriff-training/reports.php')) ?>">Reset</a>
            </div>
        </form>
    </section>

    <?php if ($reportType === 'budget'): ?>
        <section class="panel" style="margin-top: 18px;">
            <h1>Fiscal Year Budget Summary</h1>
            <p class="meta"><?= e($selectedYearLabel) ?></p>
            <?php if ($budget): ?>
                <div class="grid dashboard-stat-grid sheriff-budget-grid">
                    <article class="card dashboard-stat-card">
                        <h3><?= e(sheriff_training_money($budget['training_used'])) ?></h3>
                        <p>Training used of <?= e(sheriff_training_money($budget['training_budget'])) ?></p>
                    </article>
                    <article class="card dashboard-stat-card">
                        <h3><?= e(sheriff_training_money($budget['lodging_used'])) ?></h3>
                        <p>Lodging used of <?= e(sheriff_training_money($budget['lodging_budget'])) ?></p>
                    </article>
                </div>
            <?php endif; ?>
        </section>

    <?php elseif ($reportType === 'approved'): ?>
        <section class="panel" style="margin-top: 18px;">
            <h1>Approved / Completed Training</h1>
            <p class="meta"><?= e($selectedYearLabel) ?></p>
            <table class="table mobile-card-table" style="margin-top: 18px;">
            <thead>
                <tr>
                    <th>Officer</th>
                    <th>Training</th>
                    <th>Date</th>
                    <th>Training Cost</th>
                    <th>Lodging Cost</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($approvedRequests as $request): ?>
                    <tr>
                        <td data-label="Officer"><?= e($request['last_name'] . ', ' . $request['first_name']) ?></td>
                        <td data-label="Training"><?= e($request['class_name']) ?></td>
                        <td data-label="Date"><?= e(format_display_date($request['start_date'])) ?></td>
                        <td data-label="Training Cost"><?= e(sheriff_training_money(sheriff_training_effective_training_cost($request))) ?></td>
                        <td data-label="Lodging Cost"><?= e(sheriff_training_money(sheriff_training_effective_lodging_cost($request))) ?></td>
                        <td data-label="Status"><?= e(sheriff_training_status_label($request['status'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$approvedRequests): ?>
                    <tr><td colspan="6">No approved requests are using this fiscal year budget.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>

    <?php elseif ($reportType === 'officer_summary'): ?>
    <section class="panel" style="margin-top: 18px;">
        <h1>Officer Training Summary</h1>
        <form class="form compact-form" method="get" style="margin-bottom: 18px;">
            <input type="hidden" name="report_type" value="officer_summary">
            <input type="hidden" name="fiscal_year_id" value="<?= e((string) $yearId) ?>">
            <label>
                Summary filter
                <select name="officer_summary_filter">
                    <?php foreach ($officerSummaryFilterOptions as $filterKey => $filterLabel): ?>
                        <option value="<?= e($filterKey) ?>" <?= $officerSummaryFilter === $filterKey ? 'selected' : '' ?>><?= e($filterLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="actions">
                <button type="submit">Apply</button>
                <a class="button secondary" href="<?= e(url('departments/sheriff-training/reports.php?report_type=officer_summary&fiscal_year_id=' . $yearId)) ?>">Clear</a>
            </div>
        </form>
        <p class="meta"><?= e($officerSummaryFilterOptions[$officerSummaryFilter]) ?> | <?= e((string) count($officerSummary)) ?> officers shown</p>
        <table class="table mobile-card-table">
            <thead>
                <tr>
                    <th>Officer</th>
                    <th>Rank / Division</th>
                    <th>Completed</th>
                    <th>Total Requests</th>
                    <th>Approved Cost</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($officerSummary as $officer): ?>
                    <tr>
                        <td data-label="Officer"><?= e($officer['last_name'] . ', ' . $officer['first_name']) ?></td>
                        <td data-label="Rank / Division"><?= e(trim(($officer['rank_title'] ?? '') . ' ' . ($officer['division'] ?? '')) ?: 'Not set') ?></td>
                        <td data-label="Completed"><?= e((string) (int) $officer['completed_trainings']) ?></td>
                        <td data-label="Total Requests"><?= e((string) (int) $officer['total_requests']) ?></td>
                        <td data-label="Approved Cost"><?= e(sheriff_training_money($officer['approved_cost'])) ?></td>
                        <td data-label="Actions"><a class="button compact-button secondary" href="<?= e(url('departments/sheriff-training/officer-detail.php?id=' . $officer['id'])) ?>">History</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$officerSummary): ?>
                    <tr><td colspan="6">No officers have been entered yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>

    <?php elseif ($reportType === 'missing_actuals'): ?>
    <section class="panel" style="margin-top: 18px;">
        <h1>Completed or Past Trainings Missing Actual Costs</h1>
        <table class="table mobile-card-table">
            <thead>
                <tr>
                    <th>Officer</th>
                    <th>Training</th>
                    <th>Payment FY</th>
                    <th>End Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($missingActuals as $request): ?>
                    <tr>
                        <td data-label="Officer"><?= e($request['last_name'] . ', ' . $request['first_name']) ?></td>
                        <td data-label="Training"><?= e($request['class_name']) ?></td>
                        <td data-label="Payment FY"><?= e($request['fiscal_year_label']) ?></td>
                        <td data-label="End Date"><?= e(format_display_date($request['end_date'])) ?></td>
                        <td data-label="Actions"><a class="button compact-button secondary" href="<?= e(url('departments/sheriff-training/request-edit.php?id=' . $request['id'])) ?>">Enter actuals</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$missingActuals): ?>
                    <tr><td colspan="5">No past approved trainings are missing actual costs.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>

    <?php elseif ($reportType === 'denied'): ?>
    <section class="panel" style="margin-top: 18px;">
        <h1>Recent Denied Requests</h1>
        <table class="table mobile-card-table">
            <thead>
                <tr>
                    <th>Officer</th>
                    <th>Training</th>
                    <th>Payment FY</th>
                    <th>Reason / Comment</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($deniedRequests as $request): ?>
                    <tr>
                        <td data-label="Officer"><?= e($request['last_name'] . ', ' . $request['first_name']) ?></td>
                        <td data-label="Training"><?= e($request['class_name']) ?></td>
                        <td data-label="Payment FY"><?= e($request['fiscal_year_label']) ?></td>
                        <td data-label="Reason / Comment"><?= e($request['decision_comment'] ?: '') ?></td>
                        <td data-label="Actions"><a class="button compact-button secondary" href="<?= e(url('departments/sheriff-training/request-detail.php?id=' . $request['id'])) ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$deniedRequests): ?>
                    <tr><td colspan="5">No denied requests have been recorded.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
    <?php endif; ?>
</main>
<?php page_footer(); ?>
