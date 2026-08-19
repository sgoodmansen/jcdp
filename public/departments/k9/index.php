<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_k9_access();

$isManager = k9_user_can_manage();
$handler = current_k9_handler();
$user = current_user();
[$teamWhere, $teamParams] = k9_visible_team_sql('k9_teams');

$periodOptions = [
    'week' => 'This Week',
    'month' => 'This Month',
    'year' => 'This Year',
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
$periodStartSql = match ($summaryPeriod) {
    'week' => 'DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)',
    'month' => 'DATE_FORMAT(CURDATE(), "%Y-%m-01")',
    default => 'DATE_FORMAT(CURDATE(), "%Y-01-01")',
};

$activitySql = 'SELECT k9_activity_logs.*, k9_dogs.dog_name, k9_handlers.handler_name, k9_activity_types.name AS activity_type
                FROM k9_activity_logs
                INNER JOIN k9_teams ON k9_teams.id = k9_activity_logs.team_id
                INNER JOIN k9_dogs ON k9_dogs.id = k9_activity_logs.dog_id
                INNER JOIN k9_handlers ON k9_handlers.id = k9_activity_logs.handler_id
                LEFT JOIN k9_activity_types ON k9_activity_types.id = k9_activity_logs.activity_type_id
                WHERE 1 = 1' . $teamWhere . '
                ORDER BY k9_activity_logs.activity_date DESC, k9_activity_logs.id DESC
                LIMIT 8';
$statement = db()->prepare($activitySql);
$statement->execute($teamParams);
$recentActivity = $statement->fetchAll();

$summarySql = 'SELECT
                   COUNT(*) AS total_logs,
                   COALESCE(SUM(training_hours), 0) AS total_hours,
                   SUM(CASE WHEN k9_activity_types.name = "Deployed" THEN 1 ELSE 0 END) AS deployment_count,
                   SUM(CASE WHEN is_post_training = 1 THEN 1 ELSE 0 END) AS post_count
               FROM k9_activity_logs
               INNER JOIN k9_teams ON k9_teams.id = k9_activity_logs.team_id
               LEFT JOIN k9_activity_types ON k9_activity_types.id = k9_activity_logs.activity_type_id
               WHERE k9_activity_logs.activity_date >= ' . $periodStartSql . '
                 AND k9_activity_logs.activity_date <= CURDATE()' . $teamWhere;
$statement = db()->prepare($summarySql);
$statement->execute($teamParams);
$summary = $statement->fetch() ?: [];

$shotSql = 'SELECT k9_medical_shots.*, k9_dogs.dog_name
            FROM k9_medical_shots
            INNER JOIN k9_dogs ON k9_dogs.id = k9_medical_shots.dog_id
            INNER JOIN k9_teams ON k9_teams.dog_id = k9_dogs.id AND k9_teams.is_active = 1
            WHERE k9_medical_shots.shot_expiration IS NOT NULL
              AND k9_medical_shots.shot_expiration <= DATE_ADD(CURDATE(), INTERVAL COALESCE((SELECT reminder_days FROM k9_handlers WHERE id = k9_teams.handler_id), 30) DAY)' . $teamWhere . '
            ORDER BY k9_medical_shots.shot_expiration
            LIMIT 8';
$statement = db()->prepare($shotSql);
$statement->execute($teamParams);
$shotReminders = $statement->fetchAll();

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
                    <h3><?= e((string) (int) ($summary['total_logs'] ?? 0)) ?></h3>
                    <p>Activity Logs</p>
                </article>
                <article class="card dashboard-stat-card">
                    <h3><?= e(number_format((float) ($summary['total_hours'] ?? 0), 2)) ?></h3>
                    <p>Training Hours</p>
                </article>
                <article class="card dashboard-stat-card">
                    <h3><?= e((string) (int) ($summary['deployment_count'] ?? 0)) ?></h3>
                    <p>Deployments</p>
                </article>
                <article class="card dashboard-stat-card">
                    <h3><?= e((string) (int) ($summary['post_count'] ?? 0)) ?></h3>
                    <p>POST</p>
                </article>
            </div>
        </div>
    </section>

    <section class="k9-reminder-row" style="margin-top: 18px;">
        <article class="card">
            <h2>Shot Reminders</h2>
            <?php if (!$shotReminders): ?>
                <p class="muted">No shots are due in the current reminder window.</p>
            <?php else: ?>
                <?php foreach ($shotReminders as $shot): ?>
                    <p><strong><?= e($shot['dog_name']) ?></strong> - <?= e($shot['shot_description']) ?><br><span class="meta">Expires <?= e(format_display_date($shot['shot_expiration'])) ?></span></p>
                <?php endforeach; ?>
            <?php endif; ?>
        </article>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <div class="section-heading-row">
            <div>
                <h1>Recent Activity</h1>
                <p class="muted">Latest K-9 training and deployment records.</p>
            </div>
            <div class="actions">
                <a class="button compact-button" href="<?= e(url('departments/k9/activity-edit.php')) ?>">Add training</a>
                <a class="button secondary compact-button" href="<?= e(url('departments/k9/deployment-edit.php')) ?>">Add deployment</a>
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
