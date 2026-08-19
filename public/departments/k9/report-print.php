<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_k9_access();

$sections = [
    'summary' => 'K-9 Summary',
    'training' => 'Training by Team',
    'deployments' => 'Deployments by Outcome',
    'expenses' => 'Expenses by Category',
    'shots' => 'Shot Expirations',
];
$section = $_GET['section'] ?? 'summary';
if (!isset($sections[$section])) {
    $section = 'summary';
}

[$teamWhere, $teamParams] = k9_visible_team_sql('k9_teams');
$startDate = trim($_GET['start_date'] ?? date('Y-01-01'));
$endDate = trim($_GET['end_date'] ?? date('Y-m-d'));
$teamId = (int) ($_GET['team_id'] ?? 0);

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

$summarySql = "SELECT
                   COUNT(*) AS total_logs,
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
$expenseStatement->execute($params);
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
$statement->execute($params);
$expenseByCategory = $statement->fetchAll();

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

$backQuery = http_build_query([
    'start_date' => $startDate,
    'end_date' => $endDate,
    'team_id' => $teamId,
]);
$dateRange = format_display_date($startDate) . ' to ' . format_display_date($endDate);
$teamLabel = $selectedTeam ? $selectedTeam['dog_name'] . ' - ' . $selectedTeam['handler_name'] : 'All teams';

page_header($sections[$section] . ' Print');
?>
<main class="shell">
    <section class="panel print-hidden">
        <h1><?= e($sections[$section]) ?></h1>
        <p>Print this report or choose Save as PDF in the print window.</p>
        <?php k9_navigation('reports'); ?>
        <div class="actions">
            <button type="button" onclick="window.print()">Print PDF</button>
            <a class="button secondary" href="<?= e(url('departments/k9/reports.php?' . $backQuery)) ?>">Back to reports</a>
        </div>
    </section>

    <section class="panel printable-roster" style="margin-top: 18px;">
        <div class="roster-header">
            <div>
                <p class="meta"><?= e(format_display_date(date('Y-m-d'))) ?></p>
                <h1><?= e($sections[$section]) ?></h1>
                <p><?= e($dateRange) ?> - <?= e($teamLabel) ?></p>
            </div>
        </div>

        <?php if ($section === 'summary'): ?>
            <table class="table roster-table">
                <tbody>
                    <tr><th>Activity logs</th><td><?= e((string) (int) ($summary['total_logs'] ?? 0)) ?></td></tr>
                    <tr><th>Training hours</th><td><?= e(number_format((float) ($summary['training_hours'] ?? 0), 2)) ?></td></tr>
                    <tr><th>Deployments</th><td><?= e((string) (int) ($summary['deployments'] ?? 0)) ?></td></tr>
                    <tr><th>POST hours</th><td><?= e(number_format((float) ($summary['post_hours'] ?? 0), 2)) ?></td></tr>
                    <tr><th>Medical visits</th><td><?= e((string) $medicalCount) ?></td></tr>
                    <tr><th>Expenses</th><td><?= e(k9_money($expenseTotal)) ?></td></tr>
                </tbody>
            </table>
        <?php elseif ($section === 'training'): ?>
            <table class="table roster-table">
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
                            <td><?= e($row['dog_name']) ?><br><span class="meta"><?= e($row['handler_name']) ?></span></td>
                            <td><?= e((string) (int) $row['training_count']) ?></td>
                            <td><?= e(number_format((float) $row['training_hours'], 2)) ?></td>
                            <td><?= e(number_format((float) $row['post_hours'], 2)) ?></td>
                            <td><?= e($row['latest_training'] ? format_display_date($row['latest_training']) : '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$trainingByTeam): ?>
                        <tr><td colspan="5">No training records matched the selected filters.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        <?php elseif ($section === 'deployments'): ?>
            <table class="table roster-table">
                <thead><tr><th>Outcome</th><th>Deployments</th></tr></thead>
                <tbody>
                    <?php foreach ($deploymentOutcomes as $row): ?>
                        <tr><td><?= e($row['outcome']) ?></td><td><?= e((string) (int) $row['deployment_count']) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$deploymentOutcomes): ?>
                        <tr><td colspan="2">No deployment records matched the selected filters.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        <?php elseif ($section === 'expenses'): ?>
            <table class="table roster-table">
                <thead><tr><th>Category</th><th>Entries</th><th>Total</th></tr></thead>
                <tbody>
                    <?php foreach ($expenseByCategory as $row): ?>
                        <tr>
                            <td><?= e($row['category']) ?></td>
                            <td><?= e((string) (int) $row['expense_count']) ?></td>
                            <td><?= e(k9_money($row['expense_total'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$expenseByCategory): ?>
                        <tr><td colspan="3">No expense records matched the selected filters.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        <?php elseif ($section === 'shots'): ?>
            <table class="table roster-table">
                <thead><tr><th>Team</th><th>Shot / Vaccination</th><th>Expiration</th></tr></thead>
                <tbody>
                    <?php foreach ($shotExpirations as $row): ?>
                        <tr>
                            <td><?= e($row['dog_name']) ?><br><span class="meta"><?= e($row['handler_name']) ?></span></td>
                            <td><?= e($row['shot_description']) ?></td>
                            <td><?= e(format_display_date($row['shot_expiration'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$shotExpirations): ?>
                        <tr><td colspan="3">No shot expirations have been recorded.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</main>
<?php page_footer(); ?>
