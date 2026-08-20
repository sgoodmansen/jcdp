<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_k9_access();

$startDate = trim($_GET['start_date'] ?? date('Y-01-01'));
$endDate = trim($_GET['end_date'] ?? date('Y-m-d'));
$recordType = $_GET['record_type'] ?? '';
if (!in_array($recordType, ['', 'training', 'deployment', 'medical', 'expense'], true)) {
    $recordType = '';
}
[$teamWhere, $teamParams] = k9_visible_team_sql('k9_teams');

$recordTypeOptions = [
    '' => 'All records',
    'training' => 'Training',
    'deployment' => 'Deployment',
    'medical' => 'Medical',
    'expense' => 'Expense',
];

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

page_header('K-9 Activity Log');
?>
<main class="shell">
    <section class="panel">
        <h1>Activity Log</h1>
        <p>Review K-9 training, deployments, medical visits, and expenses.</p>
        <?php k9_navigation('activity'); ?>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Filters</h1>
        <form class="form compact-form" method="get">
            <label>
                Start date
                <input type="date" name="start_date" value="<?= e($startDate) ?>">
            </label>
            <label>
                End date
                <input type="date" name="end_date" value="<?= e($endDate) ?>">
            </label>
            <label>
                Record type
                <select name="record_type">
                    <?php foreach ($recordTypeOptions as $typeKey => $typeLabel): ?>
                        <option value="<?= e($typeKey) ?>" <?= $recordType === $typeKey ? 'selected' : '' ?>><?= e($typeLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="actions">
                <button type="submit">Filter</button>
                <a class="button secondary" href="<?= e(url('departments/k9/activity.php')) ?>">Clear</a>
            </div>
        </form>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <div class="section-heading-row">
            <div>
                <h1>Activity</h1>
                <p class="muted"><?= e((string) count($activities)) ?> records shown.</p>
            </div>
            <div class="actions k9-activity-actions">
                <a class="button compact-button" href="<?= e(url('departments/k9/activity-edit.php')) ?>">Add training</a>
                <a class="button secondary compact-button" href="<?= e(url('departments/k9/deployment-edit.php')) ?>">Add deployment</a>
                <a class="button secondary compact-button" href="<?= e(url('departments/k9/medical-edit.php')) ?>">Add medical</a>
                <a class="button secondary compact-button" href="<?= e(url('departments/k9/expense-edit.php')) ?>">Add expense</a>
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
<?php page_footer(); ?>
