<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_k9_access();

$period = $_GET['period'] ?? 'this_month';
if (!in_array($period, ['this_week', 'this_month', 'this_year', 'custom'], true)) {
    $period = 'this_month';
}

$periodOptions = [
    'this_week' => 'This Week',
    'this_month' => 'This Month',
    'this_year' => 'This Year',
    'custom' => 'Custom Dates',
];

if ($period === 'this_week') {
    $startDate = date('Y-m-d', strtotime('monday this week'));
    $endDate = date('Y-m-d');
} elseif ($period === 'this_month') {
    $startDate = date('Y-m-01');
    $endDate = date('Y-m-d');
} elseif ($period === 'this_year') {
    $startDate = date('Y-01-01');
    $endDate = date('Y-m-d');
} else {
    $startDate = trim($_GET['start_date'] ?? date('Y-01-01'));
    $endDate = trim($_GET['end_date'] ?? date('Y-m-d'));
}

$recordType = $_GET['record_type'] ?? '';
if (!in_array($recordType, ['', 'training', 'deployment', 'medical', 'expense'], true)) {
    $recordType = '';
}
[$teamWhere, $teamParams] = k9_visible_team_sql('k9_teams');
$teamId = (int) ($_GET['team_id'] ?? 0);
$trainingAreaId = (int) ($_GET['training_area_id'] ?? 0);
$expenseCategoryId = (int) ($_GET['expense_category_id'] ?? 0);

$recordTypeOptions = [
    '' => 'All records',
    'training' => 'Training',
    'deployment' => 'Deployment',
    'medical' => 'Medical',
    'expense' => 'Expense',
];

$teamSql = 'SELECT k9_teams.id, k9_dogs.dog_name, k9_handlers.handler_name
            FROM k9_teams
            INNER JOIN k9_dogs ON k9_dogs.id = k9_teams.dog_id
            INNER JOIN k9_handlers ON k9_handlers.id = k9_teams.handler_id
            WHERE 1 = 1' . $teamWhere . '
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

$trainingAreas = k9_lookup_options('k9_training_areas');
$selectedTrainingArea = null;
foreach ($trainingAreas as $trainingArea) {
    if ((int) $trainingArea['id'] === $trainingAreaId) {
        $selectedTrainingArea = $trainingArea;
        break;
    }
}
if (!$selectedTrainingArea) {
    $trainingAreaId = 0;
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

$params = [];
$activityWhere = 'WHERE 1 = 1' . $teamWhere;
$medicalWhere = 'WHERE 1 = 1' . $teamWhere;
$expenseWhere = 'WHERE 1 = 1' . $teamWhere;
foreach ($teamParams as $key => $value) {
    $params[$key] = $value;
}
if ($startDate !== '') {
    $activityWhere .= ' AND k9_activity_logs.activity_date >= :start_date';
    $medicalWhere .= ' AND k9_medical_visits.visit_date >= :start_date';
    $expenseWhere .= ' AND k9_expenses.expense_date >= :start_date';
    $params['start_date'] = $startDate;
}
if ($endDate !== '') {
    $activityWhere .= ' AND k9_activity_logs.activity_date <= :end_date';
    $medicalWhere .= ' AND k9_medical_visits.visit_date <= :end_date';
    $expenseWhere .= ' AND k9_expenses.expense_date <= :end_date';
    $params['end_date'] = $endDate;
}
if ($teamId > 0) {
    $activityWhere .= ' AND k9_teams.id = :team_id';
    $medicalWhere .= ' AND k9_teams.id = :team_id';
    $expenseWhere .= ' AND k9_teams.id = :team_id';
    $params['team_id'] = $teamId;
}
if ($trainingAreaId > 0) {
    $activityWhere .= ' AND k9_activity_logs.training_area_id = :training_area_id';
    $medicalWhere .= ' AND 1 = 0';
    $expenseWhere .= ' AND 1 = 0';
    $params['training_area_id'] = $trainingAreaId;
}
if ($expenseCategoryId > 0) {
    $activityWhere .= ' AND 1 = 0';
    $medicalWhere .= ' AND 1 = 0';
    $expenseWhere .= ' AND k9_expenses.expense_category_id = :expense_category_id';
    $params['expense_category_id'] = $expenseCategoryId;
}
if ($recordType === 'training') {
    $activityWhere .= ' AND k9_activity_types.name = "Training"';
    $medicalWhere .= ' AND 1 = 0';
    $expenseWhere .= ' AND 1 = 0';
} elseif ($recordType === 'deployment') {
    $activityWhere .= ' AND k9_activity_types.name = "Deployed"';
    $medicalWhere .= ' AND 1 = 0';
    $expenseWhere .= ' AND 1 = 0';
} elseif ($recordType === 'medical') {
    $activityWhere .= ' AND 1 = 0';
    $expenseWhere .= ' AND 1 = 0';
} elseif ($recordType === 'expense') {
    $activityWhere .= ' AND 1 = 0';
    $medicalWhere .= ' AND 1 = 0';
}

$sql = "SELECT *
        FROM (
            SELECT
                k9_activity_logs.id,
                CASE WHEN k9_activity_types.name = 'Deployed' THEN 'deployment' ELSE 'training' END AS record_type,
                k9_activity_logs.activity_date AS record_date,
                k9_dogs.dog_name,
                k9_handlers.handler_name,
                COALESCE(k9_activity_types.name, 'Activity') AS record_title,
                COALESCE(k9_training_areas.name, k9_indications.name, k9_deployment_outcomes.name, 'Not set') AS detail,
                COALESCE(k9_locations.name, k9_assisting_agencies.name, '') AS secondary_detail,
                k9_activity_logs.incident_number,
                k9_activity_logs.training_hours,
                k9_activity_logs.is_post_training,
                NULL AS amount,
                k9_activity_logs.notes
            FROM k9_activity_logs
            INNER JOIN k9_teams ON k9_teams.id = k9_activity_logs.team_id
            INNER JOIN k9_dogs ON k9_dogs.id = k9_activity_logs.dog_id
            INNER JOIN k9_handlers ON k9_handlers.id = k9_activity_logs.handler_id
            LEFT JOIN k9_activity_types ON k9_activity_types.id = k9_activity_logs.activity_type_id
            LEFT JOIN k9_training_areas ON k9_training_areas.id = k9_activity_logs.training_area_id
            LEFT JOIN k9_locations ON k9_locations.id = k9_activity_logs.location_id
            LEFT JOIN k9_indications ON k9_indications.id = k9_activity_logs.indication_id
            LEFT JOIN k9_assisting_agencies ON k9_assisting_agencies.id = k9_activity_logs.assisting_agency_id
            LEFT JOIN k9_deployment_outcomes ON k9_deployment_outcomes.id = k9_activity_logs.deployment_outcome_id
            $activityWhere

            UNION ALL

            SELECT
                k9_medical_visits.id,
                'medical' AS record_type,
                k9_medical_visits.visit_date AS record_date,
                k9_dogs.dog_name,
                k9_handlers.handler_name,
                'Medical Visit' AS record_title,
                COALESCE(k9_medical_visits.reason_for_visit, 'Not set') AS detail,
                COALESCE(k9_medical_visits.vet_office_name, '') AS secondary_detail,
                NULL AS incident_number,
                NULL AS training_hours,
                0 AS is_post_training,
                NULL AS amount,
                k9_medical_visits.notes
            FROM k9_medical_visits
            INNER JOIN k9_dogs ON k9_dogs.id = k9_medical_visits.dog_id
            INNER JOIN k9_teams ON k9_teams.dog_id = k9_dogs.id AND k9_teams.is_active = 1
            INNER JOIN k9_handlers ON k9_handlers.id = k9_teams.handler_id
            $medicalWhere

            UNION ALL

            SELECT
                k9_expenses.id,
                'expense' AS record_type,
                k9_expenses.expense_date AS record_date,
                k9_dogs.dog_name,
                k9_handlers.handler_name,
                'Expense' AS record_title,
                COALESCE(k9_expense_categories.name, 'Not set') AS detail,
                COALESCE(k9_expenses.vendor, '') AS secondary_detail,
                NULL AS incident_number,
                NULL AS training_hours,
                0 AS is_post_training,
                k9_expenses.amount,
                k9_expenses.notes
            FROM k9_expenses
            INNER JOIN k9_dogs ON k9_dogs.id = k9_expenses.dog_id
            INNER JOIN k9_teams ON k9_teams.dog_id = k9_dogs.id AND k9_teams.is_active = 1
            INNER JOIN k9_handlers ON k9_handlers.id = k9_teams.handler_id
            LEFT JOIN k9_expense_categories ON k9_expense_categories.id = k9_expenses.expense_category_id
            $expenseWhere
        ) records
        ORDER BY record_date DESC, id DESC
        LIMIT 300";
$statement = db()->prepare($sql);
$statement->execute($params);
$activities = $statement->fetchAll();

$filterQuery = http_build_query([
    'period' => $period,
    'start_date' => $startDate,
    'end_date' => $endDate,
    'record_type' => $recordType,
    'team_id' => $teamId,
    'training_area_id' => $trainingAreaId,
    'expense_category_id' => $expenseCategoryId,
]);
$filterSummary = [
    format_display_date($startDate) . ' to ' . format_display_date($endDate),
    $recordTypeOptions[$recordType] ?? 'All records',
    $selectedTeam ? $selectedTeam['dog_name'] . ' - ' . $selectedTeam['handler_name'] : 'All teams',
];
$screenSummary = [
    $period === 'custom' ? format_display_date($startDate) . ' to ' . format_display_date($endDate) : ($periodOptions[$period] ?? 'This Month'),
    $recordTypeOptions[$recordType] ?? 'All records',
    $selectedTeam ? $selectedTeam['dog_name'] . ' - ' . $selectedTeam['handler_name'] : 'All teams',
    count($activities) . ' records shown',
];
if ($selectedTrainingArea) {
    $filterSummary[] = 'Training area: ' . $selectedTrainingArea['name'];
    $screenSummary[] = $selectedTrainingArea['name'];
}
if ($selectedExpenseCategory) {
    $filterSummary[] = 'Expense category: ' . $selectedExpenseCategory['name'];
    $screenSummary[] = $selectedExpenseCategory['name'];
}
$screenSummaryText = implode(' | ', $screenSummary);

if (($_GET['format'] ?? '') === 'csv') {
    $filename = 'k9-activity-log-' . preg_replace('/[^0-9a-z-]+/i', '-', $startDate . '-to-' . $endDate) . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Date', 'Team', 'Record', 'Incident Number', 'Details', 'Secondary Detail', 'Hours', 'Cost', 'POST', 'Notes']);
    foreach ($activities as $activity) {
        fputcsv($output, [
            format_display_date($activity['record_date']),
            $activity['dog_name'] . ' / ' . $activity['handler_name'],
            $activity['record_title'],
            $activity['incident_number'] ?? '',
            $activity['detail'] ?: 'Not set',
            $activity['secondary_detail'] ?? '',
            $activity['record_type'] === 'expense' ? '' : ($activity['training_hours'] !== null ? number_format((float) $activity['training_hours'], 2) : ''),
            $activity['record_type'] === 'expense' ? k9_money($activity['amount']) : '',
            (int) $activity['is_post_training'] === 1 ? 'Yes' : ($activity['record_type'] === 'training' ? 'No' : ''),
            $activity['notes'] ?? '',
        ]);
    }
    fclose($output);
    exit;
}

page_header('K-9 Activity Log');
?>
<main class="shell">
    <section class="panel print-hidden">
        <h1>Activity Log</h1>
        <p>Review K-9 training, deployments, medical visits, and expenses.</p>
        <?php k9_navigation('activity'); ?>
    </section>

    <details class="panel k9-filter-panel print-hidden" style="margin-top: 18px;">
        <summary>
            <span>Filters</span>
            <span class="meta"><?= e($screenSummaryText) ?></span>
        </summary>
        <form class="form compact-form" method="get">
            <label>
                Period
                <select name="period" id="k9-activity-period">
                    <?php foreach ($periodOptions as $periodKey => $periodLabel): ?>
                        <option value="<?= e($periodKey) ?>" <?= $period === $periodKey ? 'selected' : '' ?>><?= e($periodLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label data-k9-date-field <?= $period !== 'custom' ? 'hidden' : '' ?>>
                Start date
                <input type="date" name="start_date" value="<?= e($startDate) ?>" data-k9-custom-date>
            </label>
            <label data-k9-date-field <?= $period !== 'custom' ? 'hidden' : '' ?>>
                End date
                <input type="date" name="end_date" value="<?= e($endDate) ?>" data-k9-custom-date>
            </label>
            <label>
                Record type
                <select name="record_type">
                    <?php foreach ($recordTypeOptions as $typeKey => $typeLabel): ?>
                        <option value="<?= e($typeKey) ?>" <?= $recordType === $typeKey ? 'selected' : '' ?>><?= e($typeLabel) ?></option>
                    <?php endforeach; ?>
                </select>
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
                Training area
                <select name="training_area_id">
                    <option value="">All training areas</option>
                    <?php foreach ($trainingAreas as $trainingArea): ?>
                        <option value="<?= e((string) $trainingArea['id']) ?>" <?= $trainingAreaId === (int) $trainingArea['id'] ? 'selected' : '' ?>><?= e($trainingArea['name']) ?></option>
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
                <button type="submit">Filter</button>
                <a class="button secondary" href="<?= e(url('departments/k9/activity.php')) ?>">Clear</a>
            </div>
        </form>
    </details>

    <section class="panel printable-roster" style="margin-top: 18px;">
        <div class="k9-activity-print-heading roster-header">
            <div>
                <p class="meta"><?= e(format_display_date(date('Y-m-d'))) ?></p>
                <h1>K-9 Activity Log</h1>
                <p><?= e(implode(' - ', $filterSummary)) ?></p>
            </div>
        </div>
        <div class="k9-activity-log-header print-hidden">
            <div>
                <h1>Activity Log</h1>
                <p class="muted"><?= e($screenSummaryText) ?></p>
            </div>
            <div class="k9-activity-action-groups">
                <div class="actions k9-activity-actions">
                    <button type="button" class="secondary compact-button" onclick="window.print()">Print</button>
                    <a class="button secondary compact-button" href="<?= e(url('departments/k9/activity.php?' . $filterQuery . '&format=csv')) ?>">Export CSV</a>
                </div>
                <div class="actions k9-activity-actions">
                    <a class="button secondary compact-button" href="<?= e(url('departments/k9/activity-edit.php')) ?>">Add training</a>
                    <a class="button secondary compact-button" href="<?= e(url('departments/k9/deployment-edit.php')) ?>">Add deployment</a>
                    <a class="button secondary compact-button" href="<?= e(url('departments/k9/medical-edit.php')) ?>">Add medical</a>
                    <a class="button secondary compact-button" href="<?= e(url('departments/k9/expense-edit.php')) ?>">Add expense</a>
                </div>
            </div>
        </div>
        <table class="table mobile-card-table k9-activity-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Team</th>
                    <th>Record</th>
                    <th>Details</th>
                    <th>Hours / Cost</th>
                    <th>POST</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($activities as $activity): ?>
                    <tr>
                        <td data-label="Date"><?= e(format_display_date($activity['record_date'])) ?></td>
                        <td data-label="Team">
                            <span class="k9-team-inline"><?= e($activity['dog_name']) ?> <span aria-hidden="true">/</span> <?= e($activity['handler_name']) ?></span>
                        </td>
                        <td data-label="Record">
                            <?= e($activity['record_title']) ?>
                            <?php if ($activity['incident_number']): ?>
                                <br><span class="meta"><?= e($activity['incident_number']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Details">
                            <?= e($activity['detail'] ?: 'Not set') ?>
                            <?php if ($activity['secondary_detail']): ?>
                                <br><span class="meta k9-activity-secondary-detail"><?= e($activity['secondary_detail']) ?></span>
                            <?php endif; ?>
                            <?php if ($activity['notes']): ?>
                                <br><span class="meta k9-activity-notes-preview"><?= e($activity['notes']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Hours / Cost">
                            <?= $activity['record_type'] === 'expense' ? e(k9_money($activity['amount'])) : e($activity['training_hours'] !== null ? number_format((float) $activity['training_hours'], 2) : '') ?>
                        </td>
                        <td data-label="POST">
                            <?php if ((int) $activity['is_post_training'] === 1): ?>
                                <span class="badge badge-success">Yes</span>
                            <?php elseif ($activity['record_type'] === 'training'): ?>
                                <span class="badge badge-muted">No</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Actions">
                            <a class="button secondary compact-button" href="<?= e(url('departments/k9/record-detail.php?type=' . urlencode($activity['record_type']) . '&id=' . (int) $activity['id'])) ?>">Open</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$activities): ?>
                    <tr><td colspan="7">No activity matched the selected filters.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
</main>
<script>
    (function () {
        const periodSelect = document.getElementById('k9-activity-period');
        if (!periodSelect) {
            return;
        }

        const dateFields = Array.from(document.querySelectorAll('[data-k9-date-field]'));
        const syncDateFields = function () {
            dateFields.forEach(function (field) {
                field.hidden = periodSelect.value !== 'custom';
            });
        };

        periodSelect.addEventListener('change', syncDateFields);
        syncDateFields();

        document.querySelectorAll('[data-k9-custom-date]').forEach(function (dateInput) {
            dateInput.addEventListener('change', function () {
                periodSelect.value = 'custom';
                syncDateFields();
            });
        });
    })();
</script>
<?php page_footer(); ?>
