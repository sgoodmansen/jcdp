<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_k9_access();

[$teamWhere, $teamParams] = k9_visible_team_sql('k9_teams');
$startDate = trim($_GET['start_date'] ?? date('Y-01-01'));
$endDate = trim($_GET['end_date'] ?? date('Y-m-d'));
$teamId = (int) ($_GET['team_id'] ?? 0);
$expenseCategoryId = (int) ($_GET['expense_category_id'] ?? 0);
$reportTypes = [
    'summary' => 'Summary',
    'training' => 'Training by Team',
    'training_area' => 'Training by Area',
    'deployments' => 'Deployments by Outcome',
    'expenses' => 'Expenses by Category',
    'expense_detail' => 'Expense Detail',
    'shots' => 'Shot Expirations',
];
$reportType = $_GET['report_type'] ?? 'summary';
if (!isset($reportTypes[$reportType])) {
    $reportType = 'summary';
}

$teamSql = 'SELECT k9_teams.id, k9_dogs.dog_name, k9_handlers.handler_name
            FROM k9_teams
            INNER JOIN k9_dogs ON k9_dogs.id = k9_teams.dog_id
            INNER JOIN k9_handlers ON k9_handlers.id = k9_teams.handler_id
            WHERE k9_teams.is_active = 1' . $teamWhere . '
            ORDER BY k9_dogs.dog_name, k9_handlers.handler_name';
$statement = db()->prepare($teamSql);
$statement->execute($teamParams);
$teams = $statement->fetchAll();

$selectedTeam = null;
foreach ($teams as $team) {
    if ((int) $team['id'] === $teamId) {
        $selectedTeam = $team;
        break;
    }
}
if (!$selectedTeam) {
    $teamId = 0;
}

$expenseCategories = k9_lookup_options('k9_expense_categories');
$selectedExpenseCategory = null;
foreach ($expenseCategories as $expenseCategory) {
    if ((int) $expenseCategory['id'] === $expenseCategoryId) {
        $selectedExpenseCategory = $expenseCategory;
        break;
    }
}
if (!$selectedExpenseCategory) {
    $expenseCategoryId = 0;
}

$activityWhere = 'WHERE k9_activity_logs.activity_date BETWEEN :start_date AND :end_date' . $teamWhere;
$medicalWhere = 'WHERE k9_medical_visits.visit_date BETWEEN :start_date AND :end_date' . $teamWhere;
$expenseWhere = 'WHERE k9_expenses.expense_date BETWEEN :start_date AND :end_date' . $teamWhere;
$params = array_merge($teamParams, [
    'start_date' => $startDate,
    'end_date' => $endDate,
]);
if ($teamId > 0) {
    $activityWhere .= ' AND k9_teams.id = :team_id';
    $medicalWhere .= ' AND k9_teams.id = :team_id';
    $expenseWhere .= ' AND k9_teams.id = :team_id';
    $params['team_id'] = $teamId;
}
$expenseParams = $params;
if ($expenseCategoryId > 0) {
    $expenseWhere .= ' AND k9_expenses.expense_category_id = :expense_category_id';
    $expenseParams['expense_category_id'] = $expenseCategoryId;
}
$printQuery = http_build_query([
    'section' => $reportType,
    'start_date' => $startDate,
    'end_date' => $endDate,
    'team_id' => $teamId,
    'expense_category_id' => $expenseCategoryId,
]);

$summarySql = "SELECT
                   COUNT(*) AS total_logs,
                   COALESCE(SUM(k9_activity_logs.training_hours), 0) AS total_hours,
                   COALESCE(SUM(CASE WHEN k9_activity_types.name = 'Training' THEN k9_activity_logs.training_hours ELSE 0 END), 0) AS training_hours,
                   COALESCE(SUM(CASE WHEN k9_activity_types.name = 'Deployed' THEN 1 ELSE 0 END), 0) AS deployments,
                   COALESCE(SUM(CASE WHEN k9_activity_logs.is_post_training = 1 THEN k9_activity_logs.training_hours ELSE 0 END), 0) AS post_hours
               FROM k9_activity_logs
               INNER JOIN k9_teams ON k9_teams.id = k9_activity_logs.team_id
               LEFT JOIN k9_activity_types ON k9_activity_types.id = k9_activity_logs.activity_type_id
               $activityWhere";
$statement = db()->prepare($summarySql);
$statement->execute($params);
$summary = $statement->fetch() ?: [];

$medicalStatement = db()->prepare(
    "SELECT COUNT(*) AS medical_visits
     FROM k9_medical_visits
     INNER JOIN k9_dogs ON k9_dogs.id = k9_medical_visits.dog_id
     INNER JOIN k9_teams ON k9_teams.dog_id = k9_dogs.id AND k9_teams.is_active = 1
     $medicalWhere"
);
$medicalStatement->execute($params);
$medicalCount = (int) ($medicalStatement->fetchColumn() ?: 0);

$expenseStatement = db()->prepare(
    "SELECT COALESCE(SUM(k9_expenses.amount), 0) AS expense_total
     FROM k9_expenses
     INNER JOIN k9_dogs ON k9_dogs.id = k9_expenses.dog_id
     INNER JOIN k9_teams ON k9_teams.dog_id = k9_dogs.id AND k9_teams.is_active = 1
     $expenseWhere"
);
$expenseStatement->execute($expenseParams);
$expenseTotal = (float) ($expenseStatement->fetchColumn() ?: 0);

$trainingByTeamSql = "SELECT k9_dogs.dog_name,
                             k9_handlers.handler_name,
                             COUNT(*) AS training_count,
                             COALESCE(SUM(k9_activity_logs.training_hours), 0) AS training_hours,
                             COALESCE(SUM(CASE WHEN k9_activity_logs.is_post_training = 1 THEN k9_activity_logs.training_hours ELSE 0 END), 0) AS post_hours,
                             MAX(k9_activity_logs.activity_date) AS latest_training
                      FROM k9_activity_logs
                      INNER JOIN k9_teams ON k9_teams.id = k9_activity_logs.team_id
                      INNER JOIN k9_dogs ON k9_dogs.id = k9_activity_logs.dog_id
                      INNER JOIN k9_handlers ON k9_handlers.id = k9_activity_logs.handler_id
                      LEFT JOIN k9_activity_types ON k9_activity_types.id = k9_activity_logs.activity_type_id
                      $activityWhere
                        AND k9_activity_types.name = 'Training'
                      GROUP BY k9_dogs.dog_name, k9_handlers.handler_name
                      ORDER BY training_hours DESC, k9_dogs.dog_name";
$statement = db()->prepare($trainingByTeamSql);
$statement->execute($params);
$trainingByTeam = $statement->fetchAll();

$trainingByAreaSql = "SELECT COALESCE(k9_training_areas.name, 'Not set') AS training_area,
                             COUNT(*) AS training_count,
                             COALESCE(SUM(k9_activity_logs.training_hours), 0) AS training_hours,
                             COALESCE(SUM(CASE WHEN k9_activity_logs.is_post_training = 1 THEN k9_activity_logs.training_hours ELSE 0 END), 0) AS post_hours,
                             MAX(k9_activity_logs.activity_date) AS latest_training
                      FROM k9_activity_logs
                      INNER JOIN k9_teams ON k9_teams.id = k9_activity_logs.team_id
                      LEFT JOIN k9_activity_types ON k9_activity_types.id = k9_activity_logs.activity_type_id
                      LEFT JOIN k9_training_areas ON k9_training_areas.id = k9_activity_logs.training_area_id
                      $activityWhere
                        AND k9_activity_types.name = 'Training'
                      GROUP BY COALESCE(k9_training_areas.name, 'Not set')
                      ORDER BY training_hours DESC, training_area";
$statement = db()->prepare($trainingByAreaSql);
$statement->execute($params);
$trainingByArea = $statement->fetchAll();

$deploymentOutcomeSql = "SELECT COALESCE(k9_deployment_outcomes.name, 'Not set') AS outcome,
                                COUNT(*) AS deployment_count
                         FROM k9_activity_logs
                         INNER JOIN k9_teams ON k9_teams.id = k9_activity_logs.team_id
                         LEFT JOIN k9_activity_types ON k9_activity_types.id = k9_activity_logs.activity_type_id
                         LEFT JOIN k9_deployment_outcomes ON k9_deployment_outcomes.id = k9_activity_logs.deployment_outcome_id
                         $activityWhere
                           AND k9_activity_types.name = 'Deployed'
                         GROUP BY COALESCE(k9_deployment_outcomes.name, 'Not set')
                         ORDER BY deployment_count DESC, outcome";
$statement = db()->prepare($deploymentOutcomeSql);
$statement->execute($params);
$deploymentOutcomes = $statement->fetchAll();

$expenseByCategorySql = "SELECT COALESCE(k9_expense_categories.name, 'Not set') AS category,
                                COUNT(*) AS expense_count,
                                COALESCE(SUM(k9_expenses.amount), 0) AS expense_total
                         FROM k9_expenses
                         INNER JOIN k9_dogs ON k9_dogs.id = k9_expenses.dog_id
                         INNER JOIN k9_teams ON k9_teams.dog_id = k9_dogs.id AND k9_teams.is_active = 1
                         LEFT JOIN k9_expense_categories ON k9_expense_categories.id = k9_expenses.expense_category_id
                         $expenseWhere
                         GROUP BY COALESCE(k9_expense_categories.name, 'Not set')
                         ORDER BY expense_total DESC, category";
$statement = db()->prepare($expenseByCategorySql);
$statement->execute($expenseParams);
$expenseByCategory = $statement->fetchAll();

$expenseDetailSql = "SELECT k9_expenses.id,
                            k9_expenses.expense_date,
                            k9_dogs.dog_name,
                            k9_handlers.handler_name,
                            COALESCE(k9_expense_categories.name, 'Not set') AS category,
                            k9_expenses.vendor,
                            k9_expenses.amount,
                            k9_expenses.notes
                     FROM k9_expenses
                     INNER JOIN k9_dogs ON k9_dogs.id = k9_expenses.dog_id
                     INNER JOIN k9_teams ON k9_teams.dog_id = k9_dogs.id AND k9_teams.is_active = 1
                     INNER JOIN k9_handlers ON k9_handlers.id = k9_teams.handler_id
                     LEFT JOIN k9_expense_categories ON k9_expense_categories.id = k9_expenses.expense_category_id
                     $expenseWhere
                     ORDER BY k9_expenses.expense_date DESC, k9_expenses.id DESC
                     LIMIT 300";
$statement = db()->prepare($expenseDetailSql);
$statement->execute($expenseParams);
$expenseDetails = $statement->fetchAll();

$shotParams = $teamParams;
$shotWhere = 'WHERE k9_medical_shots.shot_expiration IS NOT NULL' . $teamWhere;
if ($teamId > 0) {
    $shotWhere .= ' AND k9_teams.id = :team_id';
    $shotParams['team_id'] = $teamId;
}
$shotSql = "SELECT k9_dogs.dog_name,
                   k9_handlers.handler_name,
                   k9_medical_shots.shot_description,
                   k9_medical_shots.shot_expiration
            FROM k9_medical_shots
            INNER JOIN k9_dogs ON k9_dogs.id = k9_medical_shots.dog_id
            INNER JOIN k9_teams ON k9_teams.dog_id = k9_dogs.id AND k9_teams.is_active = 1
            INNER JOIN k9_handlers ON k9_handlers.id = k9_teams.handler_id
            $shotWhere
            ORDER BY k9_medical_shots.shot_expiration, k9_dogs.dog_name
            LIMIT 25";
$statement = db()->prepare($shotSql);
$statement->execute($shotParams);
$shotExpirations = $statement->fetchAll();

page_header('K-9 Reports');
?>
<main class="shell">
    <section class="panel">
        <h1>Reports</h1>
        <p>Review K-9 training totals, deployments, medical visits, shot expirations, and expenses.</p>
        <?php k9_navigation('reports'); ?>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Report Filters</h1>
        <form class="form compact-form" method="get">
            <label>
                Report type
                <select name="report_type">
                    <?php foreach ($reportTypes as $typeKey => $typeLabel): ?>
                        <option value="<?= e($typeKey) ?>" <?= $reportType === $typeKey ? 'selected' : '' ?>><?= e($typeLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Start date
                <input type="date" name="start_date" value="<?= e($startDate) ?>">
            </label>
            <label>
                End date
                <input type="date" name="end_date" value="<?= e($endDate) ?>">
            </label>
            <label>
                K-9 team
                <select name="team_id">
                    <option value="">All teams</option>
                    <?php foreach ($teams as $team): ?>
                        <option value="<?= e((string) $team['id']) ?>" <?= $teamId === (int) $team['id'] ? 'selected' : '' ?>><?= e($team['dog_name'] . ' - ' . $team['handler_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Expense category
                <select name="expense_category_id">
                    <option value="">All expense categories</option>
                    <?php foreach ($expenseCategories as $expenseCategory): ?>
                        <option value="<?= e((string) $expenseCategory['id']) ?>" <?= $expenseCategoryId === (int) $expenseCategory['id'] ? 'selected' : '' ?>><?= e($expenseCategory['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="actions">
                <button type="submit">Run report</button>
                <a class="button secondary" href="<?= e(url('departments/k9/reports.php')) ?>">Clear</a>
            </div>
        </form>
    </section>

    <?php if ($reportType === 'summary'): ?>
    <section class="dashboard-stat-group summary-stat-group" style="margin-top: 18px;">
        <div class="section-heading-row">
            <h2><?= e(format_display_date($startDate)) ?> to <?= e(format_display_date($endDate)) ?></h2>
            <a class="button secondary compact-button" href="<?= e(url('departments/k9/report-print.php?' . $printQuery)) ?>">Print PDF</a>
        </div>
        <div class="grid dashboard-stat-grid sheriff-budget-grid">
            <article class="card dashboard-stat-card">
                <h3><?= e((string) (int) ($summary['total_logs'] ?? 0)) ?></h3>
                <p>Activity logs</p>
            </article>
            <article class="card dashboard-stat-card">
                <h3><?= e(number_format((float) ($summary['training_hours'] ?? 0), 2)) ?></h3>
                <p>Training hours</p>
            </article>
            <article class="card dashboard-stat-card">
                <h3><?= e((string) (int) ($summary['deployments'] ?? 0)) ?></h3>
                <p>Deployments</p>
            </article>
            <article class="card dashboard-stat-card">
                <h3><?= e(number_format((float) ($summary['post_hours'] ?? 0), 2)) ?></h3>
                <p>POST hours</p>
            </article>
            <article class="card dashboard-stat-card">
                <h3><?= e((string) $medicalCount) ?></h3>
                <p>Medical visits</p>
            </article>
            <article class="card dashboard-stat-card">
                <h3><?= e(k9_money($expenseTotal)) ?></h3>
                <p>Expenses</p>
            </article>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($reportType === 'training'): ?>
    <section class="panel" style="margin-top: 18px;">
        <div class="section-heading-row">
            <h1>Training by Team</h1>
            <a class="button secondary compact-button" href="<?= e(url('departments/k9/report-print.php?' . $printQuery)) ?>">Print PDF</a>
        </div>
        <table class="table mobile-card-table">
            <thead>
                <tr>
                    <th>Team</th>
                    <th>Training Logs</th>
                    <th>Training Hours</th>
                    <th>POST Hours</th>
                    <th>Latest Training</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($trainingByTeam as $row): ?>
                    <tr>
                        <td data-label="Team"><?= e($row['dog_name']) ?><br><span class="meta"><?= e($row['handler_name']) ?></span></td>
                        <td data-label="Training Logs"><?= e((string) (int) $row['training_count']) ?></td>
                        <td data-label="Training Hours"><?= e(number_format((float) $row['training_hours'], 2)) ?></td>
                        <td data-label="POST Hours"><?= e(number_format((float) $row['post_hours'], 2)) ?></td>
                        <td data-label="Latest Training"><?= e($row['latest_training'] ? format_display_date($row['latest_training']) : '') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$trainingByTeam): ?>
                    <tr><td colspan="5">No training records matched the selected filters.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
    <?php endif; ?>

    <?php if ($reportType === 'training_area'): ?>
    <section class="panel" style="margin-top: 18px;">
        <div class="section-heading-row">
            <h1>Training by Area</h1>
            <a class="button secondary compact-button" href="<?= e(url('departments/k9/report-print.php?' . $printQuery)) ?>">Print PDF</a>
        </div>
        <table class="table mobile-card-table">
            <thead>
                <tr>
                    <th>Training Area</th>
                    <th>Training Logs</th>
                    <th>Training Hours</th>
                    <th>POST Hours</th>
                    <th>Latest Training</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($trainingByArea as $row): ?>
                    <tr>
                        <td data-label="Training Area"><?= e($row['training_area']) ?></td>
                        <td data-label="Training Logs"><?= e((string) (int) $row['training_count']) ?></td>
                        <td data-label="Training Hours"><?= e(number_format((float) $row['training_hours'], 2)) ?></td>
                        <td data-label="POST Hours"><?= e(number_format((float) $row['post_hours'], 2)) ?></td>
                        <td data-label="Latest Training"><?= e($row['latest_training'] ? format_display_date($row['latest_training']) : '') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$trainingByArea): ?>
                    <tr><td colspan="5">No training records matched the selected filters.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
    <?php endif; ?>

    <?php if ($reportType === 'deployments'): ?>
    <section class="panel" style="margin-top: 18px;">
        <div class="section-heading-row">
            <h1>Deployments by Outcome</h1>
            <a class="button secondary compact-button" href="<?= e(url('departments/k9/report-print.php?' . $printQuery)) ?>">Print PDF</a>
        </div>
        <table class="table mobile-card-table">
            <thead>
                <tr>
                    <th>Outcome</th>
                    <th>Deployments</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($deploymentOutcomes as $row): ?>
                    <tr>
                        <td data-label="Outcome"><?= e($row['outcome']) ?></td>
                        <td data-label="Deployments"><?= e((string) (int) $row['deployment_count']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$deploymentOutcomes): ?>
                    <tr><td colspan="2">No deployment records matched the selected filters.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
    <?php endif; ?>

    <?php if ($reportType === 'expenses'): ?>
    <section class="panel" style="margin-top: 18px;">
        <div class="section-heading-row">
            <h1>Expenses by Category</h1>
            <a class="button secondary compact-button" href="<?= e(url('departments/k9/report-print.php?' . $printQuery)) ?>">Print PDF</a>
        </div>
        <table class="table mobile-card-table">
            <thead>
                <tr>
                    <th>Category</th>
                    <th>Entries</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($expenseByCategory as $row): ?>
                    <tr>
                        <td data-label="Category"><?= e($row['category']) ?></td>
                        <td data-label="Entries"><?= e((string) (int) $row['expense_count']) ?></td>
                        <td data-label="Total"><?= e(k9_money($row['expense_total'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$expenseByCategory): ?>
                    <tr><td colspan="3">No expense records matched the selected filters.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
    <?php endif; ?>

    <?php if ($reportType === 'expense_detail'): ?>
    <section class="panel" style="margin-top: 18px;">
        <div class="section-heading-row">
            <h1>Expense Detail</h1>
            <a class="button secondary compact-button" href="<?= e(url('departments/k9/report-print.php?' . $printQuery)) ?>">Print PDF</a>
        </div>
        <table class="table mobile-card-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Team</th>
                    <th>Category</th>
                    <th>Vendor</th>
                    <th>Amount</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($expenseDetails as $row): ?>
                    <tr>
                        <td data-label="Date"><?= e(format_display_date($row['expense_date'])) ?></td>
                        <td data-label="Team"><?= e($row['dog_name']) ?><br><span class="meta"><?= e($row['handler_name']) ?></span></td>
                        <td data-label="Category"><?= e($row['category']) ?></td>
                        <td data-label="Vendor"><?= e($row['vendor'] ?: '') ?></td>
                        <td data-label="Amount"><?= e(k9_money($row['amount'])) ?></td>
                        <td data-label="Notes"><?= e($row['notes'] ?: '') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$expenseDetails): ?>
                    <tr><td colspan="6">No expense detail records matched the selected filters.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
    <?php endif; ?>

    <?php if ($reportType === 'shots'): ?>
    <section class="panel" style="margin-top: 18px;">
        <div class="section-heading-row">
            <h1>Shot Expirations</h1>
            <a class="button secondary compact-button" href="<?= e(url('departments/k9/report-print.php?' . $printQuery)) ?>">Print PDF</a>
        </div>
        <table class="table mobile-card-table">
            <thead>
                <tr>
                    <th>Team</th>
                    <th>Shot / Vaccination</th>
                    <th>Expiration</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($shotExpirations as $row): ?>
                    <tr>
                        <td data-label="Team"><?= e($row['dog_name']) ?><br><span class="meta"><?= e($row['handler_name']) ?></span></td>
                        <td data-label="Shot / Vaccination"><?= e($row['shot_description']) ?></td>
                        <td data-label="Expiration"><?= e(format_display_date($row['shot_expiration'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$shotExpirations): ?>
                    <tr><td colspan="3">No shot expirations have been recorded.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
    <?php endif; ?>
</main>
<?php page_footer(); ?>
