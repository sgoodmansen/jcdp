<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_k9_access();

$startDate = trim($_GET['start_date'] ?? date('Y-01-01'));
$endDate = trim($_GET['end_date'] ?? date('Y-m-d'));
$activityTypeId = (int) ($_GET['activity_type_id'] ?? 0);
[$teamWhere, $teamParams] = k9_visible_team_sql('k9_teams');

$params = $teamParams;
$where = 'WHERE 1 = 1' . $teamWhere;
if ($startDate !== '') {
    $where .= ' AND k9_activity_logs.activity_date >= :start_date';
    $params['start_date'] = $startDate;
}
if ($endDate !== '') {
    $where .= ' AND k9_activity_logs.activity_date <= :end_date';
    $params['end_date'] = $endDate;
}
if ($activityTypeId > 0) {
    $where .= ' AND k9_activity_logs.activity_type_id = :activity_type_id';
    $params['activity_type_id'] = $activityTypeId;
}

$sql = "SELECT k9_activity_logs.*, k9_dogs.dog_name, k9_handlers.handler_name, k9_activity_types.name AS activity_type,
               k9_training_areas.name AS training_area, k9_locations.name AS location_name, k9_indications.name AS indication
        FROM k9_activity_logs
        INNER JOIN k9_teams ON k9_teams.id = k9_activity_logs.team_id
        INNER JOIN k9_dogs ON k9_dogs.id = k9_activity_logs.dog_id
        INNER JOIN k9_handlers ON k9_handlers.id = k9_activity_logs.handler_id
        LEFT JOIN k9_activity_types ON k9_activity_types.id = k9_activity_logs.activity_type_id
        LEFT JOIN k9_training_areas ON k9_training_areas.id = k9_activity_logs.training_area_id
        LEFT JOIN k9_locations ON k9_locations.id = k9_activity_logs.location_id
        LEFT JOIN k9_indications ON k9_indications.id = k9_activity_logs.indication_id
        $where
        ORDER BY k9_activity_logs.activity_date DESC, k9_activity_logs.id DESC
        LIMIT 300";
$statement = db()->prepare($sql);
$statement->execute($params);
$activities = $statement->fetchAll();
$activityTypes = k9_lookup_options('k9_activity_types');

page_header('K-9 Activity Log');
?>
<main class="shell">
    <section class="panel">
        <h1>Activity Log</h1>
        <p>Review K-9 training and deployment records.</p>
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
                Activity type
                <select name="activity_type_id">
                    <option value="">Any type</option>
                    <?php foreach ($activityTypes as $type): ?>
                        <option value="<?= e((string) $type['id']) ?>" <?= $activityTypeId === (int) $type['id'] ? 'selected' : '' ?>><?= e($type['name']) ?></option>
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
            <div class="actions">
                <a class="button compact-button" href="<?= e(url('departments/k9/activity-edit.php')) ?>">Add training</a>
                <a class="button secondary compact-button" href="<?= e(url('departments/k9/deployment-edit.php')) ?>">Add deployment</a>
            </div>
        </div>
        <table class="table mobile-card-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Team</th>
                    <th>Activity</th>
                    <th>Area</th>
                    <th>Location</th>
                    <th>Hours</th>
                    <th>POST</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($activities as $activity): ?>
                    <tr>
                        <td data-label="Date"><?= e(format_display_date($activity['activity_date'])) ?></td>
                        <td data-label="Team"><?= e($activity['dog_name']) ?><br><span class="meta"><?= e($activity['handler_name']) ?></span></td>
                        <td data-label="Activity">
                            <?= e($activity['activity_type'] ?: 'Not set') ?>
                            <?php if ($activity['incident_number']): ?>
                                <br><span class="meta"><?= e($activity['incident_number']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Area"><?= e($activity['training_area'] ?: 'Not set') ?></td>
                        <td data-label="Location"><?= e($activity['location_name'] ?: 'Not set') ?></td>
                        <td data-label="Hours"><?= e(number_format((float) $activity['training_hours'], 2)) ?></td>
                        <td data-label="POST"><?= (int) $activity['is_post_training'] === 1 ? '<span class="badge badge-success">Yes</span>' : '<span class="badge badge-muted">No</span>' ?></td>
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
