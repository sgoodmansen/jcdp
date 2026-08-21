<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_k9_access();

$isManager = k9_user_can_manage();
$handler = current_k9_handler();
$user = current_user();
[$teamWhere, $teamParams] = k9_visible_team_sql('k9_teams');

$periodOptions = [
    'week' => 'This Week',
    'last_week' => 'Last Week',
    'month' => 'This Month',
    'last_month' => 'Last Month',
    'year' => 'This Year',
    'last_year' => 'Last Year',
];
$preferenceStatement = db()->prepare('SELECT default_summary_period FROM k9_user_preferences WHERE user_id = :user_id');
$preferenceStatement->execute(['user_id' => $user['id']]);
$defaultPeriod = (string) ($preferenceStatement->fetchColumn() ?: 'year');
if (!array_key_exists($defaultPeriod, $periodOptions)) {
    $defaultPeriod = 'year';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'save_default_period') {
    $postedPeriod = $_POST['summary_period'] ?? $defaultPeriod;
    if (array_key_exists($postedPeriod, $periodOptions)) {
        $statement = db()->prepare(
            'INSERT INTO k9_user_preferences (user_id, default_summary_period)
             VALUES (:user_id, :default_summary_period)
             ON DUPLICATE KEY UPDATE default_summary_period = VALUES(default_summary_period)'
        );
        $statement->execute([
            'user_id' => $user['id'],
            'default_summary_period' => $postedPeriod,
        ]);
        flash('success', 'Default dashboard view saved.');
        redirect_to('departments/k9/index.php?period=' . urlencode($postedPeriod));
    }
}

$summaryPeriod = $_GET['period'] ?? $defaultPeriod;
if (!array_key_exists($summaryPeriod, $periodOptions)) {
    $summaryPeriod = $defaultPeriod;
}
$periodRangeSql = match ($summaryPeriod) {
    'week' => [
        'DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)',
        'CURDATE()',
    ],
    'last_week' => [
        'DATE_SUB(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 7 DAY)',
        'DATE_SUB(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 1 DAY)',
    ],
    'month' => [
        'DATE_FORMAT(CURDATE(), "%Y-%m-01")',
        'CURDATE()',
    ],
    'last_month' => [
        'DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), "%Y-%m-01")',
        'LAST_DAY(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))',
    ],
    'last_year' => [
        'DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 YEAR), "%Y-01-01")',
        'DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 YEAR), "%Y-12-31")',
    ],
    default => [
        'DATE_FORMAT(CURDATE(), "%Y-01-01")',
        'CURDATE()',
    ],
};
[$periodStartSql, $periodEndSql] = $periodRangeSql;

$activitySql = 'SELECT k9_activity_logs.*, k9_dogs.dog_name, k9_handlers.handler_name, k9_activity_types.name AS activity_type
                FROM k9_activity_logs
                INNER JOIN k9_teams ON k9_teams.id = k9_activity_logs.team_id
                INNER JOIN k9_dogs ON k9_dogs.id = k9_activity_logs.dog_id
                INNER JOIN k9_handlers ON k9_handlers.id = k9_activity_logs.handler_id
                LEFT JOIN k9_activity_types ON k9_activity_types.id = k9_activity_logs.activity_type_id
                WHERE 1 = 1' . k9_not_voided_sql('k9_activity_logs') . $teamWhere . '
                ORDER BY k9_activity_logs.activity_date DESC, k9_activity_logs.id DESC
                LIMIT 5';
$statement = db()->prepare($activitySql);
$statement->execute($teamParams);
$recentActivity = $statement->fetchAll();

$summarySql = 'SELECT
                   COUNT(*) AS total_logs,
                   COALESCE(SUM(CASE WHEN k9_activity_types.name = "Training" THEN training_hours ELSE 0 END), 0) AS total_hours,
                   COALESCE(SUM(CASE WHEN k9_activity_types.name = "Training" THEN 1 ELSE 0 END), 0) AS training_count,
                   COALESCE(SUM(CASE WHEN k9_activity_types.name = "Deployed" THEN training_hours ELSE 0 END), 0) AS deployment_hours,
                   COALESCE(SUM(CASE WHEN k9_activity_types.name = "Deployed" THEN 1 ELSE 0 END), 0) AS deployment_count,
                   COALESCE(SUM(CASE WHEN k9_activity_types.name = "Training" AND is_post_training = 1 THEN training_hours ELSE 0 END), 0) AS post_hours,
                   COALESCE(SUM(CASE WHEN k9_activity_types.name = "Training" AND is_post_training = 1 THEN 1 ELSE 0 END), 0) AS post_count
               FROM k9_activity_logs
               INNER JOIN k9_teams ON k9_teams.id = k9_activity_logs.team_id
               LEFT JOIN k9_activity_types ON k9_activity_types.id = k9_activity_logs.activity_type_id
               WHERE k9_activity_logs.activity_date >= ' . $periodStartSql . '
                 AND k9_activity_logs.activity_date <= ' . $periodEndSql . k9_not_voided_sql('k9_activity_logs') . $teamWhere;
$statement = db()->prepare($summarySql);
$statement->execute($teamParams);
$summary = $statement->fetch() ?: [];

$teamSummarySql = 'SELECT k9_teams.id, k9_dogs.dog_name, k9_handlers.handler_name
                   FROM k9_teams
                   INNER JOIN k9_dogs ON k9_dogs.id = k9_teams.dog_id
                   INNER JOIN k9_handlers ON k9_handlers.id = k9_teams.handler_id
                   WHERE k9_teams.is_active = 1' . $teamWhere . '
                   ORDER BY k9_dogs.dog_name, k9_handlers.handler_name';
$statement = db()->prepare($teamSummarySql);
$statement->execute($teamParams);
$teamSummaries = [];
foreach ($statement->fetchAll() as $teamRow) {
    $teamSummaries[(int) $teamRow['id']] = [
        'id' => (int) $teamRow['id'],
        'dog_name' => $teamRow['dog_name'],
        'handler_name' => $teamRow['handler_name'],
        'training_hours' => 0.0,
        'training_count' => 0,
        'post_hours' => 0.0,
        'post_count' => 0,
        'deployment_hours' => 0.0,
        'deployment_count' => 0,
        'last_training' => null,
        'medical_alerts' => 0,
    ];
}

$teamActivitySummarySql = 'SELECT k9_teams.id AS team_id,
                                  COALESCE(SUM(CASE WHEN k9_activity_types.name = "Training" THEN k9_activity_logs.training_hours ELSE 0 END), 0) AS training_hours,
                                  COALESCE(SUM(CASE WHEN k9_activity_types.name = "Training" THEN 1 ELSE 0 END), 0) AS training_count,
                                  COALESCE(SUM(CASE WHEN k9_activity_types.name = "Training" AND k9_activity_logs.is_post_training = 1 THEN k9_activity_logs.training_hours ELSE 0 END), 0) AS post_hours,
                                  COALESCE(SUM(CASE WHEN k9_activity_types.name = "Training" AND k9_activity_logs.is_post_training = 1 THEN 1 ELSE 0 END), 0) AS post_count,
                                  COALESCE(SUM(CASE WHEN k9_activity_types.name = "Deployed" THEN k9_activity_logs.training_hours ELSE 0 END), 0) AS deployment_hours,
                                  COALESCE(SUM(CASE WHEN k9_activity_types.name = "Deployed" THEN 1 ELSE 0 END), 0) AS deployment_count,
                                  MAX(CASE WHEN k9_activity_types.name = "Training" THEN k9_activity_logs.activity_date ELSE NULL END) AS last_training
                           FROM k9_teams
                           INNER JOIN k9_dogs ON k9_dogs.id = k9_teams.dog_id
                           INNER JOIN k9_handlers ON k9_handlers.id = k9_teams.handler_id
                           LEFT JOIN k9_activity_logs ON k9_activity_logs.team_id = k9_teams.id
                               AND k9_activity_logs.activity_date >= ' . $periodStartSql . '
                               AND k9_activity_logs.activity_date <= ' . $periodEndSql . '
                               AND k9_activity_logs.voided_at IS NULL
                           LEFT JOIN k9_activity_types ON k9_activity_types.id = k9_activity_logs.activity_type_id
                           WHERE k9_teams.is_active = 1' . $teamWhere . '
                           GROUP BY k9_teams.id';
$statement = db()->prepare($teamActivitySummarySql);
$statement->execute($teamParams);
foreach ($statement->fetchAll() as $row) {
    $teamId = (int) $row['team_id'];
    if (!isset($teamSummaries[$teamId])) {
        continue;
    }

    $teamSummaries[$teamId]['training_hours'] = (float) $row['training_hours'];
    $teamSummaries[$teamId]['training_count'] = (int) $row['training_count'];
    $teamSummaries[$teamId]['post_hours'] = (float) $row['post_hours'];
    $teamSummaries[$teamId]['post_count'] = (int) $row['post_count'];
    $teamSummaries[$teamId]['deployment_hours'] = (float) $row['deployment_hours'];
    $teamSummaries[$teamId]['deployment_count'] = (int) $row['deployment_count'];
    $teamSummaries[$teamId]['last_training'] = $row['last_training'] ?: null;
}

$teamMedicalAlertSql = 'SELECT k9_teams.id AS team_id,
                               COUNT(CASE WHEN k9_medical_shots.medical_visit_id IS NULL OR shot_visits.voided_at IS NULL THEN k9_medical_shots.id ELSE NULL END) AS medical_alerts
                        FROM k9_teams
                        INNER JOIN k9_dogs ON k9_dogs.id = k9_teams.dog_id
                        INNER JOIN k9_handlers ON k9_handlers.id = k9_teams.handler_id
                        LEFT JOIN k9_medical_shots ON k9_medical_shots.dog_id = k9_dogs.id
                            AND k9_medical_shots.shot_expiration IS NOT NULL
                            AND k9_medical_shots.shot_expiration <= DATE_ADD(CURDATE(), INTERVAL COALESCE(k9_handlers.reminder_days, 30) DAY)
                        LEFT JOIN k9_medical_visits AS shot_visits ON shot_visits.id = k9_medical_shots.medical_visit_id
                        WHERE k9_teams.is_active = 1' . $teamWhere . '
                        GROUP BY k9_teams.id';
$statement = db()->prepare($teamMedicalAlertSql);
$statement->execute($teamParams);
foreach ($statement->fetchAll() as $row) {
    $teamId = (int) $row['team_id'];
    if (isset($teamSummaries[$teamId])) {
        $teamSummaries[$teamId]['medical_alerts'] = (int) $row['medical_alerts'];
    }
}

$expiredShotSql = 'SELECT k9_medical_shots.*, k9_dogs.dog_name, k9_handlers.handler_name, DATEDIFF(CURDATE(), k9_medical_shots.shot_expiration) AS days_overdue
            FROM k9_medical_shots
            LEFT JOIN k9_medical_visits AS shot_visits ON shot_visits.id = k9_medical_shots.medical_visit_id
            INNER JOIN k9_dogs ON k9_dogs.id = k9_medical_shots.dog_id
            INNER JOIN k9_teams ON k9_teams.dog_id = k9_dogs.id AND k9_teams.is_active = 1
            INNER JOIN k9_handlers ON k9_handlers.id = k9_teams.handler_id
            WHERE k9_medical_shots.shot_expiration IS NOT NULL
              AND (k9_medical_shots.medical_visit_id IS NULL OR shot_visits.voided_at IS NULL)
              AND k9_medical_shots.shot_expiration < CURDATE()' . $teamWhere . '
            ORDER BY k9_medical_shots.shot_expiration
            LIMIT 6';
$statement = db()->prepare($expiredShotSql);
$statement->execute($teamParams);
$expiredShots = $statement->fetchAll();

$dueSoonShotSql = 'SELECT k9_medical_shots.*, k9_dogs.dog_name, k9_handlers.handler_name, DATEDIFF(k9_medical_shots.shot_expiration, CURDATE()) AS days_until_due
                   FROM k9_medical_shots
                   LEFT JOIN k9_medical_visits AS shot_visits ON shot_visits.id = k9_medical_shots.medical_visit_id
                   INNER JOIN k9_dogs ON k9_dogs.id = k9_medical_shots.dog_id
                   INNER JOIN k9_teams ON k9_teams.dog_id = k9_dogs.id AND k9_teams.is_active = 1
                   INNER JOIN k9_handlers ON k9_handlers.id = k9_teams.handler_id
                   WHERE k9_medical_shots.shot_expiration IS NOT NULL
                     AND (k9_medical_shots.medical_visit_id IS NULL OR shot_visits.voided_at IS NULL)
                     AND k9_medical_shots.shot_expiration >= CURDATE()
                     AND k9_medical_shots.shot_expiration <= DATE_ADD(CURDATE(), INTERVAL COALESCE(k9_handlers.reminder_days, 30) DAY)' . $teamWhere . '
                   ORDER BY k9_medical_shots.shot_expiration
                   LIMIT 6';
$statement = db()->prepare($dueSoonShotSql);
$statement->execute($teamParams);
$dueSoonShots = $statement->fetchAll();

$appointmentSql = 'SELECT k9_medical_visits.id, k9_medical_visits.next_appointment_date, k9_medical_visits.next_appointment_time,
                          k9_medical_visits.next_appointment_scheduled, k9_medical_visits.reason_for_visit,
                          k9_dogs.dog_name, k9_handlers.handler_name
                   FROM k9_medical_visits
                   INNER JOIN k9_dogs ON k9_dogs.id = k9_medical_visits.dog_id
                   INNER JOIN k9_teams ON k9_teams.dog_id = k9_dogs.id AND k9_teams.is_active = 1
                   INNER JOIN k9_handlers ON k9_handlers.id = k9_teams.handler_id
                   WHERE k9_medical_visits.next_appointment_date IS NOT NULL
                     AND k9_medical_visits.next_appointment_date >= CURDATE()' . k9_not_voided_sql('k9_medical_visits') . $teamWhere . '
                   ORDER BY k9_medical_visits.next_appointment_date, k9_medical_visits.next_appointment_time
                   LIMIT 6';
$statement = db()->prepare($appointmentSql);
$statement->execute($teamParams);
$nextAppointments = $statement->fetchAll();
$hasMedicalAlerts = $expiredShots || $dueSoonShots || $nextAppointments;

page_header('K-9 Activity & Records');
?>
<main class="shell">
    <section class="panel">
        <h1>K-9 Activity & Records</h1>
        <p><?= $isManager ? 'Review K-9 activity, teams, reminders, and program records.' : 'Enter K-9 activity and review your team records from your phone.' ?></p>
        <?php k9_navigation('home'); ?>
        <?php if ($message = flash('success')): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>
    </section>

    <?php if (!$isManager && !$handler): ?>
        <section class="panel" style="margin-top: 18px;">
            <h1>Handler Setup Needed</h1>
            <p>Your account is not connected to a K-9 handler record yet. Ask a supervisor to connect your portal user to your handler profile.</p>
        </section>
    <?php endif; ?>

    <section class="k9-quick-actions" aria-label="K-9 quick actions" style="margin-top: 18px;">
        <a class="k9-quick-action-card" href="<?= e(url('departments/k9/activity-edit.php')) ?>">
            <strong>Add Training</strong>
            <span>Record training hours, aids, and performance details.</span>
        </a>
        <a class="k9-quick-action-card" href="<?= e(url('departments/k9/deployment-edit.php')) ?>">
            <strong>Add Deployment</strong>
            <span>Record a callout, incident, indication, and outcome.</span>
        </a>
        <a class="k9-quick-action-card" href="<?= e(url('departments/k9/medical-edit.php')) ?>">
            <strong>Add Medical</strong>
            <span>Record vet visits, shots, and follow-up reminders.</span>
        </a>
        <a class="k9-quick-action-card" href="<?= e(url('departments/k9/expense-edit.php')) ?>">
            <strong>Add Expense</strong>
            <span>Record food, vet, equipment, and other costs.</span>
        </a>
    </section>

    <section class="k9-dashboard-stat-row" style="margin-top: 18px;">
        <div class="dashboard-stat-group summary-stat-group">
            <div class="k9-summary-heading">
                <form class="k9-period-form" method="get">
                    <select name="period" aria-label="Summary period" onchange="this.form.submit()">
                        <?php foreach ($periodOptions as $periodKey => $periodLabel): ?>
                            <option value="<?= e($periodKey) ?>" <?= $summaryPeriod === $periodKey ? 'selected' : '' ?>><?= e($periodLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <form method="post">
                    <input type="hidden" name="action" value="save_default_period">
                    <input type="hidden" name="summary_period" value="<?= e($summaryPeriod) ?>">
                    <button type="submit" class="secondary compact-button" <?= $summaryPeriod === $defaultPeriod ? 'disabled' : '' ?>>Set as default</button>
                </form>
            </div>
            <div class="grid dashboard-stat-grid">
                <article class="card dashboard-stat-card">
                    <h3><?= e(number_format((float) ($summary['total_hours'] ?? 0), 2)) ?></h3>
                    <p>Training Hours</p>
                    <p class="meta"><?= e((string) (int) ($summary['training_count'] ?? 0)) ?> entries</p>
                </article>
                <article class="card dashboard-stat-card">
                    <h3><?= e(number_format((float) ($summary['post_hours'] ?? 0), 2)) ?></h3>
                    <p>POST Hours</p>
                    <p class="meta"><?= e((string) (int) ($summary['post_count'] ?? 0)) ?> entries</p>
                </article>
                <article class="card dashboard-stat-card">
                    <h3><?= e(number_format((float) ($summary['deployment_hours'] ?? 0), 2)) ?></h3>
                    <p>Deployment Hours</p>
                    <p class="meta"><?= e((string) (int) ($summary['deployment_count'] ?? 0)) ?> entries</p>
                </article>
                <article class="card dashboard-stat-card">
                    <h3><?= e((string) (int) ($summary['total_logs'] ?? 0)) ?></h3>
                    <p>Activity Logs</p>
                </article>
            </div>
        </div>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <div class="section-heading-row">
            <div>
                <h1>Team Summary</h1>
                <p class="muted"><?= e($periodOptions[$summaryPeriod] ?? 'Selected period') ?> comparison by active K-9 team.</p>
            </div>
        </div>
        <table class="table mobile-card-table k9-report-table">
            <thead>
                <tr>
                    <th>Team</th>
                    <th>Training</th>
                    <th>POST</th>
                    <th>Deployments</th>
                    <th>Last Training</th>
                    <th>Medical Alerts</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($teamSummaries as $teamSummary): ?>
                    <tr>
                        <td data-label="Team">
                            <?= e($teamSummary['dog_name']) ?>
                            <br><span class="meta"><?= e($teamSummary['handler_name']) ?></span>
                        </td>
                        <td data-label="Training">
                            <?= e(number_format((float) $teamSummary['training_hours'], 2)) ?> hrs
                            <br><span class="meta"><?= e((string) (int) $teamSummary['training_count']) ?> entries</span>
                        </td>
                        <td data-label="POST">
                            <?= e(number_format((float) $teamSummary['post_hours'], 2)) ?> hrs
                            <br><span class="meta"><?= e((string) (int) $teamSummary['post_count']) ?> entries</span>
                        </td>
                        <td data-label="Deployments">
                            <?= e(number_format((float) $teamSummary['deployment_hours'], 2)) ?> hrs
                            <br><span class="meta"><?= e((string) (int) $teamSummary['deployment_count']) ?> entries</span>
                        </td>
                        <td data-label="Last Training"><?= e($teamSummary['last_training'] ? format_display_date($teamSummary['last_training']) : 'None') ?></td>
                        <td data-label="Medical Alerts">
                            <?php if ((int) $teamSummary['medical_alerts'] > 0): ?>
                                <span class="badge badge-warning"><?= e((string) (int) $teamSummary['medical_alerts']) ?></span>
                            <?php else: ?>
                                0
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$teamSummaries): ?>
                    <tr><td colspan="6">No active K-9 teams are available.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>

    <?php if ($hasMedicalAlerts): ?>
        <section class="panel k9-medical-alert-panel" style="margin-top: 18px;">
            <div class="section-heading-row">
                <div>
                    <h1>Medical Alerts</h1>
                    <p class="muted">Items needing attention or follow-up.</p>
                </div>
            </div>
            <div class="k9-reminder-row">
                <?php if ($expiredShots): ?>
                    <article class="card k9-reminder-card k9-alert-card k9-alert-danger">
                        <div class="section-heading-row">
                            <h2>Expired Shots</h2>
                            <span class="badge badge-warning"><?= e((string) count($expiredShots)) ?></span>
                        </div>
                        <div class="k9-reminder-list">
                            <?php foreach ($expiredShots as $shot): ?>
                                <p>
                                    <strong><?= e($shot['dog_name']) ?></strong> - <?= e($shot['shot_description']) ?>
                                    <br><span class="meta"><?= e($shot['handler_name']) ?> | Expired <?= e(format_display_date($shot['shot_expiration'])) ?><?= (int) $shot['days_overdue'] > 0 ? ' | ' . e((string) (int) $shot['days_overdue']) . ' days overdue' : '' ?></span>
                                </p>
                            <?php endforeach; ?>
                        </div>
                    </article>
                <?php endif; ?>

                <?php if ($dueSoonShots): ?>
                    <article class="card k9-reminder-card k9-alert-card">
                        <div class="section-heading-row">
                            <h2>Shots Due Soon</h2>
                            <span class="badge"><?= e((string) count($dueSoonShots)) ?></span>
                        </div>
                        <div class="k9-reminder-list">
                            <?php foreach ($dueSoonShots as $shot): ?>
                                <p>
                                    <strong><?= e($shot['dog_name']) ?></strong> - <?= e($shot['shot_description']) ?>
                                    <br><span class="meta"><?= e($shot['handler_name']) ?> | Due <?= e(format_display_date($shot['shot_expiration'])) ?><?= (int) $shot['days_until_due'] === 0 ? ' | Due today' : ' | In ' . e((string) (int) $shot['days_until_due']) . ' days' ?></span>
                                </p>
                            <?php endforeach; ?>
                        </div>
                    </article>
                <?php endif; ?>

                <?php if ($nextAppointments): ?>
                    <article class="card k9-reminder-card k9-alert-card">
                        <div class="section-heading-row">
                            <h2>Next Vet Appointment</h2>
                            <span class="badge"><?= e((string) count($nextAppointments)) ?></span>
                        </div>
                        <div class="k9-reminder-list">
                            <?php foreach ($nextAppointments as $appointment): ?>
                                <p>
                                    <strong><?= e($appointment['dog_name']) ?></strong><?= $appointment['reason_for_visit'] ? ' - ' . e($appointment['reason_for_visit']) : '' ?>
                                    <br><span class="meta">
                                        <?= e($appointment['handler_name']) ?> |
                                        <?= e(format_display_date($appointment['next_appointment_date'])) ?><?= $appointment['next_appointment_time'] ? ' at ' . e(date('g:i A', strtotime($appointment['next_appointment_time']))) : '' ?>
                                        <?= $appointment['next_appointment_scheduled'] ? ' | ' . e($appointment['next_appointment_scheduled']) : '' ?>
                                    </span>
                                </p>
                            <?php endforeach; ?>
                        </div>
                    </article>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="panel" style="margin-top: 18px;">
        <div class="section-heading-row">
            <div>
                <h1>Recent Activity</h1>
                <p class="muted">Latest 5 K-9 training and deployment records.</p>
            </div>
        </div>
        <table class="table mobile-card-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Dog</th>
                    <th>Handler</th>
                    <th>Type</th>
                    <th>Hours</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentActivity as $activity): ?>
                    <tr>
                        <td data-label="Date"><?= e(format_display_date($activity['activity_date'])) ?></td>
                        <td data-label="Dog"><?= e($activity['dog_name']) ?></td>
                        <td data-label="Handler"><?= e($activity['handler_name']) ?></td>
                        <td data-label="Type"><?= e($activity['activity_type'] ?: 'Not set') ?></td>
                        <td data-label="Hours"><?= e(number_format((float) $activity['training_hours'], 2)) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$recentActivity): ?>
                    <tr><td colspan="5">No activity has been entered yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
</main>
<?php page_footer(); ?>
